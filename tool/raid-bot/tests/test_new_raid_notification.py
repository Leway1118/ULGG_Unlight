from __future__ import annotations

import tempfile
import time
import unittest
from pathlib import Path

from raid_bot.domain.reward_rules import RewardPrediction
from raid_bot.infrastructure.public_raid_enrichment_client import (
    PublicRaidEnrichmentResult,
)
from raid_bot.infrastructure.treasure_level_cache import TreasureLevelCache
from raid_bot.services.raid_service import RaidService
from raid_bot.services.treasure_level_enricher import TreasureLevelEnricher
from tests.raid_bot_test_support import FakeNotifier, FakeRaidSource, raid, snapshot


class FakeFragmentResolver:
    def __init__(self) -> None:
        self.calls: list[list[str]] = []

    def resolve_many(self, raids):
        raids = list(raids)
        self.calls.append([raid.profound_id for raid in raids])
        return {
            raid.profound_id: RewardPrediction(
                reward_name="死亡碎片（紫）",
                quantity=None,
                confidence="api",
                reason="fake Reward API",
            )
            for raid in raids
            if raid.treasure_level == 2161
        }


class FakeEnrichmentClient:
    def __init__(self, result: PublicRaidEnrichmentResult) -> None:
        self.result = result
        self.calls: list[str] = []

    async def enrich(self, prf_code: str) -> PublicRaidEnrichmentResult:
        self.calls.append(prf_code)
        return self.result


class NewRaidNotificationTests(unittest.IsolatedAsyncioTestCase):
    async def test_new_marker_only_appears_on_new_raid(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            source = FakeRaidSource([
                snapshot(raid("raid-a")),
                snapshot(raid("raid-a"), raid("raid-b", boss_name="龍魚")),
                snapshot(raid("raid-a"), raid("raid-b", boss_name="龍魚")),
            ])
            service = RaidService(
                source,
                notifier=notifier,
                state_file=Path(directory) / "state.json",
            )
            service.diff.mark_full_summary(time.time())

            await service.update_once()
            await service.update_once()
            await service.update_once()
            await service.dispatcher.flush_pending()

            self.assertEqual(len(notifier.messages), 1)
            self.assertEqual(
                notifier.messages[0],
                "🆕 Founder 🐢 龍魚 2759/6000",
            )

    async def test_db_raid_metadata_reaches_combined_discord_message(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            existing = raid(
                "raid-a",
                boss_name="黃海",
                monster_code="mc1007_02",
                founder="Owlic",
                rarity=1,
                stage_id=2,
                hp=5283,
                hp_max=6000,
                source="db_raid",
            )
            added = raid(
                "raid-b",
                boss_name="黃海",
                monster_code="mc1007_02",
                founder="billyzx",
                rarity=1,
                stage_id=2,
                hp=6000,
                hp_max=6000,
                source="db_raid",
            )
            source = FakeRaidSource([
                snapshot(existing),
                snapshot(existing, added),
            ])
            service = RaidService(
                source,
                notifier=notifier,
                state_file=Path(directory) / "state.json",
                source_name="db_raid",
            )
            service.diff.mark_full_summary(time.time())

            await service.update_once()
            await service.update_once()
            await service.dispatcher.flush_pending()

            self.assertEqual(notifier.messages, [
                "🆕 billyzx 綠海🟢🐙 6000/6000",
            ])

    async def test_reward_api_prediction_is_injected_before_notification(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            resolver = FakeFragmentResolver()
            existing = raid(
                "raid-a",
                boss_name="黃海",
                monster_code="mc1007_02",
                rarity=1,
                stage_id=2,
            )
            added = raid(
                "raid-b",
                boss_name="黃海",
                monster_code="mc1007_02",
                rarity=1,
                stage_id=2,
                treasure_level=2161,
            )
            service = RaidService(
                FakeRaidSource([
                    snapshot(existing),
                    snapshot(existing, added),
                ]),
                notifier=notifier,
                state_file=Path(directory) / "state.json",
                fragment_resolver=resolver,
            )
            service.diff.mark_full_summary(time.time())

            await service.update_once()
            await service.update_once()
            await service.dispatcher.flush_pending()

            self.assertEqual(resolver.calls, [["raid-b"]])
            self.assertEqual(notifier.messages, [
                "🆕 Founder 紫海🟣🐙 2759/6000",
            ])

    async def test_public_new_raid_waits_for_enrichment_then_notifies_once(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            resolver = FakeFragmentResolver()
            worker_result = PublicRaidEnrichmentResult(
                status="success",
                metadata={
                    "profound_id": "joined-id",
                    "profound_mons": "mc1008_02",
                    "name_tcn": "靈龜",
                    "profound_founder": "xup6",
                    "treasure_level": 2161,
                    "rarity": 1,
                    "map": 8,
                    "stage": 1,
                    "state": [
                        {"type": "bers", "turn": 1},
                    ],
                },
                cleanup_attempted=True,
                cleanup_confirmed=True,
            )
            enrichment_client = FakeEnrichmentClient(worker_result)
            enricher = TreasureLevelEnricher(
                enrichment_client,
                TreasureLevelCache(Path(directory) / "enrichment.json"),
            )
            service = RaidService(
                FakeRaidSource([
                    snapshot(raid("existing")),
                    snapshot(
                        raid("existing"),
                        raid(
                            "new-public-code",
                            founder="xup6",
                            hp=5364,
                            hp_max=6000,
                        ),
                    ),
                ]),
                notifier=notifier,
                state_file=Path(directory) / "state.json",
                fragment_resolver=resolver,
                treasure_enricher=enricher,
            )
            service.diff.mark_full_summary(time.time())

            await service.update_once()
            await service.update_once()
            await service.dispatcher.flush_pending()
            await enricher.close()

        self.assertEqual(len(notifier.messages), 1)
        self.assertEqual(
            enrichment_client.calls,
            ["new-public-code", "existing"],
        )
        self.assertIn(
            "🆕 xup6 紫龜🟣🐢 5364/6000｜狂",
            notifier.messages[0],
        )

    async def test_failed_enrichment_sends_basic_notification(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            enricher = TreasureLevelEnricher(
                FakeEnrichmentClient(
                    PublicRaidEnrichmentResult(status="join_rejected")
                ),
                TreasureLevelCache(Path(directory) / "enrichment.json"),
            )
            service = RaidService(
                FakeRaidSource([
                    snapshot(raid("existing")),
                    snapshot(
                        raid("existing"),
                        raid(
                            "friend-only-code",
                            founder="xup6",
                            hp=5364,
                            hp_max=6000,
                        ),
                    ),
                ]),
                notifier=notifier,
                state_file=Path(directory) / "state.json",
                treasure_enricher=enricher,
            )
            service.diff.mark_full_summary(time.time())

            await service.update_once()
            await service.update_once()
            await service.dispatcher.flush_pending()
            await enricher.close()

        self.assertEqual(len(notifier.messages), 1)
        self.assertIn(
            "🆕 xup6 🐢 靈龜 5364/6000",
            notifier.messages[0],
        )


if __name__ == "__main__":
    unittest.main()
