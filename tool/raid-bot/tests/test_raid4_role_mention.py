from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
from unittest import mock

from raid_bot.domain.raid_event import RaidEvent, RaidEventType
from raid_bot.infrastructure.reward_cache import RewardCache
from raid_bot.notifications.discord import DiscordWebhook
from raid_bot.notifications.notifier import RaidNotifier
from raid_bot.services.fragment_resolver import FragmentResolver
from raid_bot.services.raid_diff import RaidDiff
from raid_bot.services.raid_notification_policy import RaidNotificationPolicy
from tests.raid_bot_test_support import raid
import run_raid_bot


ROLE_ID = "123456789012345678"


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


class CaptureWebhook:
    def __init__(self) -> None:
        self.calls: list[tuple[str, tuple[str, ...]]] = []

    def send(self, content: str, *, allowed_role_ids=()) -> bool:
        self.calls.append((content, tuple(allowed_role_ids)))
        return True


def new_event(item) -> RaidEvent:
    return RaidEvent(
        event_type=RaidEventType.NEW,
        raid=item,
        is_new=True,
    )


class Raid4RoleMentionTests(unittest.IsolatedAsyncioTestCase):
    def build_policy(
        self,
        directory: str,
        records: dict[int, dict],
        *,
        role_id: str = ROLE_ID,
        summary_due: bool = False,
    ):
        webhook = CaptureWebhook()
        notifier = RaidNotifier(webhook, raid4_role_id=role_id)
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
        return policy, webhook

    async def test_normal_raid_does_not_mention_role(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            item = raid("normal", treasure_level=2100)
            policy, webhook = self.build_policy(
                directory,
                {2100: reward_record(2100, "涡II/III")},
            )
            await policy.submit([new_event(item)], active_raids=[item])
            await policy.flush_pending()
            await policy.close()

        self.assertNotIn("<@&", webhook.calls[0][0])
        self.assertEqual(webhook.calls[0][1], ())

    async def test_one_raid4_mentions_role_once(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            item = raid("raid4", treasure_level=2200)
            policy, webhook = self.build_policy(
                directory,
                {2200: reward_record(2200, "涡IV")},
            )
            await policy.submit([new_event(item)], active_raids=[item])
            await policy.flush_pending()
            await policy.close()

        content, roles = webhook.calls[0]
        self.assertTrue(content.endswith(f"<@&{ROLE_ID}>"))
        self.assertEqual(content.count(f"<@&{ROLE_ID}>"), 1)
        self.assertEqual(roles, (ROLE_ID,))

    async def test_three_raid4_in_one_batch_mention_once(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            items = [
                raid(f"raid4-{level}", treasure_level=level)
                for level in (2201, 2202, 2203)
            ]
            policy, webhook = self.build_policy(
                directory,
                {
                    level: reward_record(level, "渦IV")
                    for level in (2201, 2202, 2203)
                },
            )
            await policy.submit(
                [new_event(item) for item in items],
                active_raids=items,
            )
            await policy.flush_pending()
            await policy.close()

        content, roles = webhook.calls[0]
        self.assertEqual(content.count(f"<@&{ROLE_ID}>"), 1)
        self.assertEqual(roles, (ROLE_ID,))

    async def test_unset_role_id_does_not_mention(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            item = raid("raid4", treasure_level=2200)
            policy, webhook = self.build_policy(
                directory,
                {2200: reward_record(2200, "涡IV")},
                role_id="",
            )
            await policy.submit([new_event(item)], active_raids=[item])
            await policy.flush_pending()
            await policy.close()

        self.assertNotIn("<@&", webhook.calls[0][0])
        self.assertEqual(webhook.calls[0][1], ())

    async def test_raid4_in_full_summary_does_not_trigger_mention(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            new = raid("normal", treasure_level=2100)
            old_raid4 = raid("old-raid4", treasure_level=2200)
            policy, webhook = self.build_policy(
                directory,
                {
                    2100: reward_record(2100, "涡II/III"),
                    2200: reward_record(2200, "涡IV"),
                },
                summary_due=True,
            )
            await policy.submit(
                [new_event(new)],
                active_raids=[new, old_raid4],
            )
            await policy.flush_pending()
            await policy.close()

        content, roles = webhook.calls[0]
        self.assertIn("📡 目前支援", content)
        self.assertNotIn("<@&", content)
        self.assertEqual(roles, ())


class DiscordAllowedMentionsTests(unittest.TestCase):
    def test_payload_allows_only_explicit_role(self) -> None:
        response = mock.Mock(status_code=204, text="")
        with mock.patch(
            "raid_bot.notifications.discord.requests.post",
            return_value=response,
        ) as post:
            sent = DiscordWebhook("https://example.invalid/webhook").send(
                f"message <@&{ROLE_ID}> <@&999>",
                allowed_role_ids=[ROLE_ID],
            )

        self.assertTrue(sent)
        payload = post.call_args.kwargs["json"]
        self.assertEqual(payload["allowed_mentions"], {"roles": [ROLE_ID]})
        self.assertNotIn("parse", payload["allowed_mentions"])


class Raid4RoleWiringTests(unittest.TestCase):
    def test_notifier_uses_configured_raid4_role_id(self) -> None:
        with mock.patch.object(
            run_raid_bot,
            "DISCORD_RAID4_ROLE_ID",
            ROLE_ID,
        ):
            notifier = run_raid_bot._notifier()

        self.assertEqual(notifier.raid4_role_id, ROLE_ID)


if __name__ == "__main__":
    unittest.main()
