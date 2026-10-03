from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from raid_bot.domain.raid_event import RaidEvent, RaidEventType
from raid_bot.infrastructure.reward_cache import RewardCache
from raid_bot.services.fragment_resolver import (
    FragmentResolver,
    is_whirlpool_tier_one,
)
from raid_bot.services.raid_diff import RaidDiff
from raid_bot.services.raid_notification_policy import RaidNotificationPolicy
from tests.raid_bot_test_support import FakeNotifier, raid


def reward_record(level: int, whirlpool_tier: str) -> dict:
    return {
        "treasureLevel": level,
        "bossDisplayName": "M10 靈龜",
        "rarity": 1,
        "mapLevel": 5,
        "whirlpoolTier": whirlpool_tier,
        "discovery": [],
        "participation": [],
        "ranking": [{"itemName": "記憶碎片", "quantity": 1}],
        "defeat": [],
    }


class FakeRewardClient:
    def __init__(self, records: dict[int, dict]) -> None:
        self.records = records

    def query(self, levels, *, locale: str):
        del locale
        return {
            level: self.records[level]
            for level in levels
            if level in self.records
        }


def new_event(item) -> RaidEvent:
    return RaidEvent(
        event_type=RaidEventType.NEW,
        raid=item,
        is_new=True,
    )


class Raid1NotificationFilterTests(unittest.IsolatedAsyncioTestCase):
    def build_policy(
        self,
        directory: str,
        records: dict[int, dict],
        *,
        summary_due: bool = False,
    ):
        notifier = FakeNotifier()
        resolver = FragmentResolver(
            FakeRewardClient(records),
            RewardCache(Path(directory) / "reward-cache.json"),
        )
        state = RaidDiff(Path(directory) / "state.json")
        state.mark_full_summary(0 if summary_due else 19_999)
        policy = RaidNotificationPolicy(
            notifier=notifier,
            fragment_resolver=resolver,
            state=state,
            batch_seconds=30,
            full_summary_interval_seconds=10_800,
            clock=lambda: 20_000,
        )
        return policy, notifier

    def test_formal_tier_one_value_only(self) -> None:
        self.assertTrue(is_whirlpool_tier_one("渦I"))
        self.assertTrue(is_whirlpool_tier_one("涡I"))
        self.assertFalse(is_whirlpool_tier_one("渦II/III"))
        self.assertFalse(is_whirlpool_tier_one("渦IV"))
        self.assertFalse(is_whirlpool_tier_one(None))

    async def test_confirmed_raid1_does_not_send(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            item = raid(
                "raid1",
                founder="彧雪",
                boss_name="赤死獸",
                hp=505,
                hp_max=550,
                treasure_level=1079,
            )
            policy, notifier = self.build_policy(
                directory,
                {1079: reward_record(1079, "涡I")},
            )
            await policy.submit([new_event(item)], active_raids=[item])
            await policy.flush_pending()
            await policy.close()

        self.assertEqual(notifier.messages, [])

    async def test_mixed_batch_sends_only_non_raid1(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            tier1 = raid("raid1", founder="TierOne", treasure_level=2101)
            tier2 = raid("raid2", founder="TierTwo", treasure_level=2102)
            policy, notifier = self.build_policy(
                directory,
                {
                    2101: reward_record(2101, "渦I"),
                    2102: reward_record(2102, "渦II/III"),
                },
            )
            await policy.submit(
                [new_event(tier1), new_event(tier2)],
                active_raids=[tier1, tier2],
            )
            await policy.flush_pending()
            await policy.close()

        self.assertEqual(len(notifier.messages), 1)
        self.assertNotIn("TierOne", notifier.messages[0])
        self.assertIn("TierTwo", notifier.messages[0])

    async def test_full_summary_excludes_confirmed_raid1(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            tier1 = raid("raid1", founder="TierOne", treasure_level=2101)
            tier2 = raid("raid2", founder="TierTwo", treasure_level=2102)
            policy, notifier = self.build_policy(
                directory,
                {
                    2101: reward_record(2101, "渦I"),
                    2102: reward_record(2102, "渦II/III"),
                },
                summary_due=True,
            )
            await policy.submit(
                [new_event(tier2)],
                active_raids=[tier1, tier2],
            )
            await policy.flush_pending()
            await policy.close()

        self.assertEqual(len(notifier.messages), 1)
        self.assertIn("📡 目前支援", notifier.messages[0])
        self.assertNotIn("TierOne", notifier.messages[0])
        self.assertIn("TierTwo", notifier.messages[0])

    async def test_unconfirmed_tier_remains_notifiable(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            item = raid("unknown", founder="UnknownTier")
            policy, notifier = self.build_policy(directory, {})
            await policy.submit([new_event(item)], active_raids=[item])
            await policy.flush_pending()
            await policy.close()

        self.assertEqual(len(notifier.messages), 1)
        self.assertIn("UnknownTier", notifier.messages[0])


if __name__ == "__main__":
    unittest.main()
