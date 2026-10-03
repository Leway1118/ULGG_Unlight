from __future__ import annotations

import asyncio
import json
import tempfile
import unittest
from pathlib import Path

from raid_bot.domain.raid_event import RaidEvent, RaidEventType
from raid_bot.services.raid_diff import RaidDiff
from raid_bot.services.raid_notification_policy import RaidNotificationPolicy
from run_raid_bot import _validate_notification_intervals
from tests.raid_bot_test_support import FakeNotifier, raid


class ControlledSleep:
    def __init__(self) -> None:
        self.started = asyncio.Event()
        self.release = asyncio.Event()
        self.delays: list[float] = []

    async def __call__(self, delay: float) -> None:
        self.delays.append(delay)
        self.started.set()
        await self.release.wait()


def event(item, event_type=RaidEventType.NEW, *, is_new=True):
    return RaidEvent(event_type=event_type, raid=item, is_new=is_new)


class RaidNotificationPolicyTests(unittest.IsolatedAsyncioTestCase):
    def policy(self, directory, *, now=20_000.0, summary_at=19_000.0):
        notifier = FakeNotifier()
        state = RaidDiff(Path(directory) / "state.json")
        state.mark_full_summary(summary_at)
        sleeper = ControlledSleep()
        policy = RaidNotificationPolicy(
            notifier=notifier,
            fragment_resolver=None,
            state=state,
            batch_seconds=30,
            full_summary_interval_seconds=10_800,
            clock=lambda: now,
            sleep=sleeper,
        )
        return policy, notifier, state, sleeper

    async def test_single_new_waits_for_fixed_window_then_sends_once(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, _, sleeper = self.policy(directory)
            item = raid("one", founder="Owlic", hp=6000, hp_max=6000)
            await policy.submit([event(item)], active_raids=[item])
            await sleeper.started.wait()
            self.assertEqual(notifier.messages, [])
            self.assertEqual(sleeper.delays, [30])
            task = policy.batch_task
            sleeper.release.set()
            await task
            await policy.close()

        self.assertEqual(notifier.messages, ["🆕 Owlic 🐢 靈龜 6000/6000"])

    async def test_three_new_raids_share_one_non_resetting_batch(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, _, sleeper = self.policy(directory)
            first = raid("one", founder="A")
            second = raid("two", founder="A")
            third = raid("three", founder="C")
            await policy.submit([event(first)], active_raids=[first])
            await sleeper.started.wait()
            task = policy.batch_task
            await policy.submit(
                [event(second), event(third)],
                active_raids=[first, second, third],
            )
            self.assertIs(policy.batch_task, task)
            self.assertEqual(sleeper.delays, [30])
            sleeper.release.set()
            await task
            await policy.close()

        self.assertEqual(len(notifier.messages), 1)
        self.assertIn("🆕 新增 3 個公開渦", notifier.messages[0])
        self.assertEqual(notifier.messages[0].count("A 🐢 靈龜"), 2)

    async def test_duplicate_pending_raid_uses_latest_entry(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, _, sleeper = self.policy(directory)
            original = raid("same", hp=6000, hp_max=6000)
            updated = raid("same", hp=4321, hp_max=6000)
            await policy.submit([event(original)], active_raids=[original])
            await sleeper.started.wait()
            task = policy.batch_task
            await policy.submit(
                [event(updated, RaidEventType.UPDATED, is_new=False)],
                active_raids=[updated],
            )
            self.assertEqual(policy.pending_count, 1)
            sleeper.release.set()
            await task
            await policy.close()

        self.assertIn("4321/6000", notifier.messages[0])
        self.assertNotIn("6000/6000", notifier.messages[0])

    async def test_new_pending_survives_temporary_snapshot_absence(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, _, sleeper = self.policy(directory)
            item = raid("temporary", founder="Owlic", hp=6000, hp_max=6000)
            await policy.submit([event(item)], active_raids=[item])
            await sleeper.started.wait()
            task = policy.batch_task

            await policy.submit([], active_raids=[])

            self.assertEqual(policy.pending_count, 1)
            sleeper.release.set()
            await task
            self.assertEqual(policy.pending_count, 0)
            await policy.close()

        self.assertEqual(len(notifier.messages), 1)
        self.assertIn("Owlic", notifier.messages[0])

    async def test_removed_event_does_not_cancel_existing_new_pending(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, _, sleeper = self.policy(directory)
            item = raid("removed", founder="Owlic")
            await policy.submit([event(item)], active_raids=[item])
            await sleeper.started.wait()
            task = policy.batch_task

            await policy.submit(
                [event(item, RaidEventType.REMOVED, is_new=False)],
                active_raids=[],
            )

            self.assertEqual(policy.pending_count, 1)
            sleeper.release.set()
            await task
            await policy.close()

        self.assertEqual(len(notifier.messages), 1)
        self.assertIn("Owlic", notifier.messages[0])

    async def test_updated_and_removed_only_never_send(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, _, _ = self.policy(directory)
            item = raid("one")
            await policy.submit(
                [event(item, RaidEventType.UPDATED, is_new=False)],
                active_raids=[item],
            )
            await policy.submit(
                [event(item, RaidEventType.REMOVED, is_new=False)],
                active_raids=[],
            )
            await asyncio.sleep(0)
            await policy.close()

        self.assertEqual(notifier.messages, [])

    async def test_summary_gate_not_due_omits_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, _, _ = self.policy(
                directory,
                now=20_000,
                summary_at=19_999,
            )
            new = raid("new", founder="New")
            old = raid("old", founder="Old")
            await policy.submit([event(new)], active_raids=[old, new])
            await policy.flush_pending()
            await policy.close()

        self.assertNotIn("📡 目前支援", notifier.messages[0])
        self.assertNotIn("Old", notifier.messages[0])

    async def test_due_summary_is_appended_and_persisted(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_path = Path(directory) / "state.json"
            policy, notifier, state, _ = self.policy(
                directory,
                now=20_000,
                summary_at=9_200,
            )
            new = raid("new", founder="New")
            old = raid("old", founder="Old")
            await policy.submit([event(new)], active_raids=[old, new])
            await policy.flush_pending()
            await policy.close()
            restarted = RaidDiff(state_path)

        self.assertIn("📡 目前支援", notifier.messages[0])
        self.assertIn("Old 🐢 靈龜", notifier.messages[0])
        self.assertEqual(state.last_full_summary_at, 20_000)
        self.assertEqual(restarted.last_full_summary_at, 20_000)

    async def test_summary_is_not_repeated_until_next_three_hour_gate(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            state = RaidDiff(Path(directory) / "state.json")
            now = [20_000.0]
            policy = RaidNotificationPolicy(
                notifier=notifier,
                fragment_resolver=None,
                state=state,
                batch_seconds=30,
                full_summary_interval_seconds=10_800,
                clock=lambda: now[0],
            )
            first = raid("first", founder="First")
            await policy.submit([event(first)], active_raids=[first])
            await policy.flush_pending()

            now[0] += 100
            second = raid("second", founder="Second")
            await policy.submit([event(second)], active_raids=[first, second])
            await policy.flush_pending()

            now[0] += 10_800
            third = raid("third", founder="Third")
            await policy.submit(
                [event(third)],
                active_raids=[first, second, third],
            )
            await policy.flush_pending()
            await policy.close()

        self.assertIn("📡 目前支援", notifier.messages[0])
        self.assertNotIn("📡 目前支援", notifier.messages[1])
        self.assertIn("📡 目前支援", notifier.messages[2])

    async def test_due_without_new_does_not_start_timer_or_send(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, state, _ = self.policy(
                directory,
                now=20_000,
                summary_at=0,
            )
            old = raid("old")
            await policy.submit(
                [event(old, RaidEventType.UPDATED, is_new=False)],
                active_raids=[old],
            )
            await asyncio.sleep(0)
            await policy.close()

        self.assertEqual(notifier.messages, [])
        self.assertEqual(state.last_full_summary_at, 0)

    async def test_shutdown_cancels_and_drops_pending_batch(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            policy, notifier, _, sleeper = self.policy(directory)
            item = raid("one")
            await policy.submit([event(item)], active_raids=[item])
            await sleeper.started.wait()
            task = policy.batch_task
            await policy.close()

        self.assertTrue(task.done())
        self.assertEqual(policy.pending_count, 0)
        self.assertEqual(notifier.messages, [])

    def test_version_two_state_without_summary_is_backward_compatible(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            path.write_text(json.dumps({
                "version": 2,
                "seen_raid_ids": ["old"],
                "first_seen_at": {"old": 10.0},
            }), encoding="utf-8")
            state = RaidDiff(path)

        self.assertTrue(state.initialized)
        self.assertIsNone(state.last_full_summary_at)

    def test_notification_intervals_must_be_positive(self) -> None:
        for batch, summary in ((0, 10_800), (30, 0), (-1, 10_800)):
            with self.subTest(batch=batch, summary=summary):
                with self.assertRaises(ValueError):
                    _validate_notification_intervals(batch, summary)


if __name__ == "__main__":
    unittest.main()
