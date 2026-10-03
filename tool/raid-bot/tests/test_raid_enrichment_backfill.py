from __future__ import annotations

import asyncio
import tempfile
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


def success_result(
    *,
    level: int = 2161,
    monster_code: str = "mc1008_02",
) -> PublicRaidEnrichmentResult:
    return PublicRaidEnrichmentResult(
        status="success",
        metadata={
            "profound_id": f"{level}-joined-id",
            "profound_mons": monster_code,
            "name_tcn": "靈龜",
            "profound_founder": "worker-founder",
            "treasure_level": level,
            "rarity": 1,
            "map": 8,
            "stage": 1,
        },
        cleanup_attempted=True,
        cleanup_confirmed=True,
    )


class PurpleFragmentResolver:
    def resolve_many(self, raids):
        return {
            item.profound_id: RewardPrediction(
                reward_name="死亡碎片（紫）",
                quantity=None,
                confidence="api",
                reason="fake Reward API",
            )
            for item in raids
            if item.treasure_level == 2161
        }


class ImmediateClient:
    def __init__(self, monster_codes: dict[str, str] | None = None) -> None:
        self.monster_codes = monster_codes or {}
        self.calls: list[str] = []

    async def enrich(self, prf_code: str) -> PublicRaidEnrichmentResult:
        self.calls.append(prf_code)
        return success_result(
            monster_code=self.monster_codes.get(prf_code, "mc1008_02")
        )


class BlockingOldClient(ImmediateClient):
    def __init__(self, blocked_code: str = "old") -> None:
        super().__init__({"new": "mc1012_02"})
        self.blocked_code = blocked_code
        self.blocked_started = asyncio.Event()
        self.release_blocked = asyncio.Event()

    async def enrich(self, prf_code: str) -> PublicRaidEnrichmentResult:
        self.calls.append(prf_code)
        if prf_code == self.blocked_code:
            self.blocked_started.set()
            await self.release_blocked.wait()
        return success_result(
            monster_code=self.monster_codes.get(prf_code, "mc1008_02")
        )


class RaidEnrichmentBackfillTests(unittest.IsolatedAsyncioTestCase):
    async def test_new_and_cached_old_both_show_fragments(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            cache = TreasureLevelCache(Path(directory) / "enrichment.json")
            cache.put("old", success_result().metadata or {})
            client = ImmediateClient({"new": "mc1012_02"})
            enricher = TreasureLevelEnricher(client, cache)
            notifier = FakeNotifier()
            old = raid("old", founder="Owlic")
            new = raid(
                "new",
                founder="咕嚕．挖2朵",
                boss_name="龍鯰",
                monster_code="mc1012_02",
            )
            service = RaidService(
                FakeRaidSource([snapshot(old), snapshot(old, new)]),
                notifier=notifier,
                state_file=Path(directory) / "state.json",
                fragment_resolver=PurpleFragmentResolver(),
                treasure_enricher=enricher,
            )

            await service.update_once()
            await service.update_once()
            await service.dispatcher.flush_pending()
            await enricher.close()

        self.assertEqual(client.calls, ["new"])
        self.assertEqual(len(notifier.messages), 1)
        self.assertIn("Owlic 紫龜", notifier.messages[0])
        self.assertIn("🆕 咕嚕．挖2朵 紫魚", notifier.messages[0])

    async def test_uncached_old_backfill_does_not_block_new_notification(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = BlockingOldClient()
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "enrichment.json"),
            )
            notifier = FakeNotifier()
            old = raid("old", founder="Owlic")
            new = raid(
                "new",
                founder="咕嚕．挖2朵",
                boss_name="龍鯰",
                monster_code="mc1012_02",
            )
            service = RaidService(
                FakeRaidSource([snapshot(old), snapshot(old, new)]),
                notifier=notifier,
                state_file=Path(directory) / "state.json",
                fragment_resolver=PurpleFragmentResolver(),
                treasure_enricher=enricher,
            )

            await service.update_once()
            await asyncio.wait_for(service.update_once(), timeout=0.2)
            await asyncio.wait_for(client.blocked_started.wait(), timeout=0.2)
            await service.dispatcher.flush_pending()

            self.assertEqual(len(notifier.messages), 1)
            self.assertIn("Owlic 🐢 靈龜", notifier.messages[0])
            self.assertIn("🆕 咕嚕．挖2朵 紫魚", notifier.messages[0])

            client.release_blocked.set()
            await asyncio.wait_for(enricher._queue.join(), timeout=0.2)
            await enricher.close()

    async def test_completed_old_backfill_colors_next_notification(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = ImmediateClient({
                "new-1": "mc1012_02",
                "new-2": "mc1012_02",
            })
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "enrichment.json"),
            )
            notifier = FakeNotifier()
            old = raid("old", founder="Owlic")
            new_1 = raid("new-1", monster_code="mc1012_02", boss_name="龍鯰")
            new_2 = raid("new-2", monster_code="mc1012_02", boss_name="龍鯰")
            service = RaidService(
                FakeRaidSource([
                    snapshot(old),
                    snapshot(old, new_1),
                    snapshot(old, new_1, new_2),
                ]),
                notifier=notifier,
                state_file=Path(directory) / "state.json",
                fragment_resolver=PurpleFragmentResolver(),
                treasure_enricher=enricher,
            )

            await service.update_once()
            await service.update_once()
            await service.dispatcher.flush_pending()
            await asyncio.wait_for(enricher._queue.join(), timeout=0.2)
            service.diff.last_full_summary_at = 0
            await service.update_once()
            await service.dispatcher.flush_pending()
            await enricher.close()

        self.assertEqual(client.calls, ["new-1", "old", "new-2"])
        self.assertEqual(len(notifier.messages), 2)
        self.assertIn("Owlic 紫龜", notifier.messages[1])

    async def test_cache_hit_never_calls_join_client(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            cache = TreasureLevelCache(Path(directory) / "enrichment.json")
            cache.put("old", success_result().metadata or {})
            client = ImmediateClient()
            enricher = TreasureLevelEnricher(client, cache)

            hydrated = enricher.hydrate_cached(raid("old"))
            awaited = await enricher.enrich(raid("old"))
            await enricher.close()

        self.assertTrue(hydrated.cached)
        self.assertTrue(awaited.cached)
        self.assertEqual(hydrated.raid.treasure_level, 2161)
        self.assertEqual(client.calls, [])

    async def test_multiple_old_raids_are_not_queued_twice(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = BlockingOldClient("old-1")
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "enrichment.json"),
            )
            old_raids = [raid("old-1"), raid("old-2")]

            first_count = await enricher.backfill_many(old_raids)
            await asyncio.wait_for(client.blocked_started.wait(), timeout=0.2)
            second_count = await enricher.backfill_many(old_raids)
            client.release_blocked.set()
            await asyncio.wait_for(enricher._queue.join(), timeout=0.2)
            await enricher.close()

        self.assertEqual(first_count, 2)
        self.assertEqual(second_count, 0)
        self.assertEqual(client.calls, ["old-1", "old-2"])

    async def test_new_raid_jumps_ahead_of_queued_old_backlog(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = BlockingOldClient("old-1")
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "enrichment.json"),
            )

            await enricher.backfill_many([raid("old-1"), raid("old-2")])
            await asyncio.wait_for(client.blocked_started.wait(), timeout=0.2)
            new_task = asyncio.create_task(enricher.enrich(raid("new")))
            await asyncio.sleep(0)
            client.release_blocked.set()
            result = await asyncio.wait_for(new_task, timeout=0.2)
            await asyncio.wait_for(enricher._queue.join(), timeout=0.2)
            await enricher.close()

        self.assertEqual(result.status, "success")
        self.assertEqual(client.calls, ["old-1", "new", "old-2"])

    async def test_all_new_raids_jump_ahead_of_old_backlog(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = BlockingOldClient("old-1")
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "enrichment.json"),
            )

            await enricher.backfill_many([raid("old-1"), raid("old-2")])
            await asyncio.wait_for(client.blocked_started.wait(), timeout=0.2)
            new_task = asyncio.create_task(
                enricher.enrich_many([raid("new-1"), raid("new-2")])
            )
            await asyncio.sleep(0)
            client.release_blocked.set()
            results = await asyncio.wait_for(new_task, timeout=0.2)
            await asyncio.wait_for(enricher._queue.join(), timeout=0.2)
            await enricher.close()

        self.assertTrue(all(result.status == "success" for result in results))
        self.assertEqual(
            client.calls,
            ["old-1", "new-1", "new-2", "old-2"],
        )

    async def test_close_cancels_running_background_backfill(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = BlockingOldClient()
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "enrichment.json"),
            )

            await enricher.enqueue_backfill(raid("old"))
            await asyncio.wait_for(client.blocked_started.wait(), timeout=0.2)
            await asyncio.wait_for(enricher.close(), timeout=0.2)

        self.assertEqual(enricher._pending, {})


if __name__ == "__main__":
    unittest.main()
