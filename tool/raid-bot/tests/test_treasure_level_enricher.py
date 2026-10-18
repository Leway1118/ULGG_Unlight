from __future__ import annotations

import asyncio
import tempfile
import unittest
from pathlib import Path

from raid_bot.infrastructure.public_raid_enrichment_client import (
    PublicRaidEnrichmentResult,
)
from raid_bot.infrastructure.treasure_level_cache import TreasureLevelCache
from raid_bot.services.treasure_level_enricher import TreasureLevelEnricher
from tests.raid_bot_test_support import raid


def success_result(level: int = 2091) -> PublicRaidEnrichmentResult:
    return PublicRaidEnrichmentResult(
        status="success",
        metadata={
            "profound_id": "joined-id",
            "profound_mons": "mc1008_02",
            "name_tcn": "靈龜",
            "profound_founder": "xup6",
            "treasure_level": level,
            "rarity": 1,
            "map": 8,
            "stage": 1,
        },
        cleanup_attempted=True,
        cleanup_confirmed=True,
    )


class FakeClient:
    def __init__(self, results) -> None:
        self.results = list(results)
        self.calls: list[str] = []

    async def enrich(self, prf_code: str) -> PublicRaidEnrichmentResult:
        self.calls.append(prf_code)
        return self.results.pop(0)


class BlockingClient:
    def __init__(self) -> None:
        self.calls = 0
        self.started = asyncio.Event()
        self.release = asyncio.Event()

    async def enrich(self, prf_code: str) -> PublicRaidEnrichmentResult:
        del prf_code
        self.calls += 1
        self.started.set()
        await self.release.wait()
        return success_result()


class SerialClient:
    def __init__(self) -> None:
        self.active = 0
        self.max_active = 0

    async def enrich(self, prf_code: str) -> PublicRaidEnrichmentResult:
        del prf_code
        self.active += 1
        self.max_active = max(self.max_active, self.active)
        await asyncio.sleep(0.01)
        self.active -= 1
        return success_result()


class SerialClient:
    def __init__(self) -> None:
        self.active = 0
        self.max_active = 0

    async def enrich(self, prf_code: str) -> PublicRaidEnrichmentResult:
        del prf_code
        self.active += 1
        self.max_active = max(self.max_active, self.active)
        await asyncio.sleep(0.01)
        self.active -= 1
        return success_result()


class TreasureLevelEnricherTests(unittest.IsolatedAsyncioTestCase):
    async def test_success_enriches_and_persists_cache(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "cache.json"
            client = FakeClient([success_result()])
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(path),
            )

            result = await enricher.enrich(raid("public-code"))
            await enricher.close()
            restarted = TreasureLevelEnricher(
                FakeClient([]),
                TreasureLevelCache(path),
            )
            cached = await restarted.enrich(raid("public-code"))
            await restarted.close()

        self.assertEqual(result.raid.treasure_level, 2091)
        self.assertEqual(result.status, "success")
        self.assertTrue(cached.cached)
        self.assertEqual(cached.raid.treasure_level, 2091)

    async def test_duplicate_prf_code_is_queued_once(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = BlockingClient()
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "cache.json"),
            )
            first = asyncio.create_task(enricher.enrich(raid("same-code")))
            await client.started.wait()
            second = asyncio.create_task(enricher.enrich(raid("same-code")))
            await asyncio.sleep(0)
            client.release.set()
            results = await asyncio.gather(first, second)
            await enricher.close()

        self.assertEqual(client.calls, 1)
        self.assertTrue(all(result.raid.treasure_level == 2091 for result in results))

    async def test_failures_fallback_and_next_item_still_runs(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = FakeClient([
                PublicRaidEnrichmentResult(status="join_rejected"),
                PublicRaidEnrichmentResult(
                    status="cleanup_failed",
                    metadata=success_result().metadata,
                    cleanup_lookup_attempted=True,
                    cleanup_attempted=True,
                    cleanup_confirmed=False,
                    error="cleanup_verification_timeout",
                ),
                success_result(),
            ])
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "cache.json"),
            )

            rejected = await enricher.enrich(raid("friend-only"))
            with self.assertLogs(
                "raid_bot.services.treasure_level_enricher",
                level="ERROR",
            ) as logs:
                cleanup_failed = await enricher.enrich(raid("cleanup-failed"))
            recovered = await enricher.enrich(raid("next-code"))
            await enricher.close()

        self.assertIsNone(rejected.raid.treasure_level)
        self.assertIsNone(cleanup_failed.raid.treasure_level)
        self.assertEqual(recovered.raid.treasure_level, 2091)
        self.assertEqual(len(client.calls), 3)
        log_text = "\n".join(logs.output)
        self.assertIn("status=cleanup_failed", log_text)
        self.assertIn("error=cleanup_verification_timeout", log_text)
        self.assertIn("cleanup_attempted=True", log_text)
        self.assertNotIn("cleanup-failed", log_text)

    async def test_timeout_returns_basic_raid(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = BlockingClient()
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "cache.json"),
                timeout_seconds=0.01,
            )

            result = await enricher.enrich(raid("slow-code"))
            await enricher.close()

        self.assertEqual(result.status, "timeout")
        self.assertIsNone(result.raid.treasure_level)

    async def test_different_prf_codes_are_processed_serially(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = SerialClient()
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "cache.json"),
            )

            results = await asyncio.gather(
                enricher.enrich(raid("code-a")),
                enricher.enrich(raid("code-b")),
            )
            await enricher.close()

        self.assertEqual(client.max_active, 1)
        self.assertTrue(all(result.status == "success" for result in results))

    async def test_different_prf_codes_are_processed_serially(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            client = SerialClient()
            enricher = TreasureLevelEnricher(
                client,
                TreasureLevelCache(Path(directory) / "cache.json"),
            )

            results = await asyncio.gather(
                enricher.enrich(raid("code-a")),
                enricher.enrich(raid("code-b")),
            )
            await enricher.close()

        self.assertEqual(client.max_active, 1)
        self.assertTrue(all(result.status == "success" for result in results))


if __name__ == "__main__":
    unittest.main()
