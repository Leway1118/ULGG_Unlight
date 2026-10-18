from __future__ import annotations

import asyncio
import json
import tempfile
import unittest
from pathlib import Path

from raid_bot.infrastructure.public_raid_enrichment_client import (
    ALLOWED_OUTBOUND_EVENTS,
    DEFAULT_DELETE_SETTLE_SECONDS,
    PublicRaidEnrichmentClient,
)
from raid_bot.infrastructure.reward_cache import RewardCache
from raid_bot.infrastructure.treasure_level_cache import TreasureLevelCache
from raid_bot.services.fragment_resolver import FragmentResolver
from raid_bot.services.treasure_level_enricher import TreasureLevelEnricher
from raid_metadata_scanner import (
    DEFAULT_DELETE_SETTLE_SECONDS as SCANNER_DELETE_SETTLE_SECONDS,
)
from tests.raid_bot_test_support import raid


def server_event(event: str, args: list) -> str:
    return json.dumps({
        "meta": {"id": f"server-{event}"},
        "event": event,
        "args": args,
    })


def metadata(prf_code: str) -> dict:
    return {
        "profound_id": "joined-id",
        "pass": prf_code,
        "profound_founder": "xup6",
        "profound_mons": "mc1008_02",
        "name_tcn": "靈龜",
        "treasure_level": 2091,
        "rarity": 1,
        "map": 8,
        "stage": 1,
    }


class FakeWebSocket:
    def __init__(self, incoming: list[str]) -> None:
        self.incoming: asyncio.Queue[str] = asyncio.Queue()
        for message in incoming:
            self.incoming.put_nowait(message)
        self.sent: list[dict] = []

    async def send(self, message: str) -> None:
        self.sent.append(json.loads(message))

    async def recv(self) -> str:
        return await self.incoming.get()


class FirstReceiveTimeoutWebSocket(FakeWebSocket):
    def __init__(self, incoming: list[str]) -> None:
        super().__init__(incoming)
        self.first_receive = True

    async def recv(self) -> str:
        if self.first_receive:
            self.first_receive = False
            await asyncio.Future()
        return await super().recv()


class DelayedDeleteWebSocket(FakeWebSocket):
    def __init__(
        self,
        incoming: list[str],
        *,
        prf_code: str,
        server_delay: float,
    ) -> None:
        super().__init__(incoming)
        self.prf_code = prf_code
        self.server_delay = server_delay
        self.delete_sent_at: float | None = None
        self.verification_elapsed: float | None = None

    async def send(self, message: str) -> None:
        await super().send(message)
        if self.sent[-1]["event"] == "db_raid_delete":
            self.delete_sent_at = asyncio.get_running_loop().time()

    async def recv(self) -> str:
        if not self.incoming.empty() or self.delete_sent_at is None:
            return await super().recv()
        self.verification_elapsed = (
            asyncio.get_running_loop().time() - self.delete_sent_at
        )
        rows = (
            []
            if self.verification_elapsed >= self.server_delay
            else [metadata(self.prf_code)]
        )
        return server_event("db_raid", [rows])


class FakeConnection:
    def __init__(self, websocket: FakeWebSocket) -> None:
        self.websocket = websocket

    async def __aenter__(self) -> FakeWebSocket:
        return self.websocket

    async def __aexit__(self, *args) -> None:
        return None


class FakeRewardClient:
    def __init__(self) -> None:
        self.calls: list[set[int]] = []

    def query(self, levels, *, locale: str):
        del locale
        levels = set(levels)
        self.calls.append(levels)
        return {
            2091: {
                "treasureLevel": 2091,
                "bossDisplayName": "M10 靈龜",
                "rarity": 1,
                "mapLevel": 8,
                "whirlpoolTier": "渦I",
                "discovery": [],
                "participation": [],
                "ranking": [
                    {
                        "itemName": "記憶碎片",
                        "quantity": 1,
                        "rankMin": 1,
                        "rankMax": 30,
                    }
                ],
                "defeat": [],
            }
        }


def client(
    websocket: FakeWebSocket,
    *,
    delete_settle_seconds: float = 0,
) -> PublicRaidEnrichmentClient:
    return PublicRaidEnrichmentClient(
        "wss://example.invalid/",
        "worker-account",
        timeout_seconds=0.01,
        register_settle_seconds=0,
        delete_settle_seconds=delete_settle_seconds,
        connect_factory=lambda *args, **kwargs: FakeConnection(websocket),
        clock_ms=lambda: 1000,
    )


class PublicRaidEnrichmentClientTests(unittest.IsolatedAsyncioTestCase):
    def test_production_and_scanner_share_verified_delete_settle(self) -> None:
        self.assertEqual(DEFAULT_DELETE_SETTLE_SECONDS, 0.25)
        self.assertEqual(
            DEFAULT_DELETE_SETTLE_SECONDS,
            SCANNER_DELETE_SETTLE_SECONDS,
        )

    async def test_success_extracts_metadata_and_confirms_cleanup(self) -> None:
        prf_code = "verified-public-code"
        websocket = FakeWebSocket([
            server_event("raid_found", []),
            server_event("db_raid", [[metadata(prf_code)]]),
            server_event("db_raid", [[]]),
        ])

        result = await client(websocket).enrich(prf_code)

        self.assertTrue(result.ok)
        self.assertEqual(result.metadata["treasure_level"], 2091)
        self.assertTrue(result.cleanup_attempted)
        self.assertTrue(result.cleanup_confirmed)
        self.assertEqual(
            [message["event"] for message in websocket.sent],
            [
                "register",
                "raid_code_input",
                "db_raid",
                "db_raid_delete",
                "db_raid",
            ],
        )

    async def test_join_timeout_still_uses_unique_match_for_cleanup(self) -> None:
        prf_code = "fresh-code"
        websocket = FirstReceiveTimeoutWebSocket([
            server_event("db_raid", [[metadata(prf_code)]]),
            server_event("db_raid", [[]]),
        ])

        result = await client(websocket).enrich(prf_code)

        self.assertTrue(result.ok)
        self.assertTrue(result.cleanup_confirmed)

    async def test_join_rejected_does_not_delete(self) -> None:
        websocket = FakeWebSocket([
            server_event("raid_code_error", [2]),
        ])

        result = await client(websocket).enrich("friend-only-code")

        self.assertEqual(result.status, "join_rejected")
        self.assertFalse(result.cleanup_attempted)
        self.assertNotIn(
            "db_raid_delete",
            [message["event"] for message in websocket.sent],
        )

    async def test_cleanup_timeout_is_reported_without_exception(self) -> None:
        prf_code = "fresh-code"
        websocket = FakeWebSocket([
            server_event("raid_found", []),
            server_event("db_raid", [[metadata(prf_code)]]),
        ])

        result = await client(websocket).enrich(prf_code)

        self.assertEqual(result.status, "cleanup_failed")
        self.assertTrue(result.cleanup_attempted)
        self.assertFalse(result.cleanup_confirmed)
        self.assertEqual(result.error, "cleanup_verification_timeout")

    async def test_delayed_server_delete_is_verified_after_settle(self) -> None:
        prf_code = "delayed-delete-code"
        websocket = DelayedDeleteWebSocket(
            [
                server_event("raid_found", []),
                server_event("db_raid", [[metadata(prf_code)]]),
            ],
            prf_code=prf_code,
            server_delay=0.015,
        )

        result = await client(
            websocket,
            delete_settle_seconds=0.02,
        ).enrich(prf_code)

        self.assertTrue(result.cleanup_confirmed)
        self.assertGreaterEqual(websocket.verification_elapsed, 0.015)

    async def test_confirmed_cleanup_allows_treasure_cache_write(self) -> None:
        prf_code = "cache-after-cleanup-code"
        websocket = DelayedDeleteWebSocket(
            [
                server_event("raid_found", []),
                server_event("db_raid", [[metadata(prf_code)]]),
            ],
            prf_code=prf_code,
            server_delay=0.005,
        )
        production_client = client(
            websocket,
            delete_settle_seconds=0.01,
        )
        with tempfile.TemporaryDirectory() as directory:
            cache_path = Path(directory) / "cache.json"
            enricher = TreasureLevelEnricher(
                production_client,
                TreasureLevelCache(cache_path),
                timeout_seconds=0.1,
            )

            result = await enricher.enrich(raid(prf_code))
            await enricher.close()
            cached = TreasureLevelCache(cache_path).get(prf_code)

        self.assertEqual(result.status, "success")
        self.assertEqual(cached["treasure_level"], 2091)

    async def test_join_protocol_timeout_can_finish_before_overall_budget(self) -> None:
        prf_code = "near-protocol-timeout-code"
        websocket = FirstReceiveTimeoutWebSocket([
            server_event("db_raid", [[metadata(prf_code)]]),
            server_event("db_raid", [[]]),
        ])
        production_client = PublicRaidEnrichmentClient(
            "wss://example.invalid/",
            "worker-account",
            timeout_seconds=0.02,
            register_settle_seconds=0,
            delete_settle_seconds=0,
            connect_factory=lambda *args, **kwargs: FakeConnection(websocket),
        )
        with tempfile.TemporaryDirectory() as directory:
            directory = Path(directory)
            enricher = TreasureLevelEnricher(
                production_client,
                TreasureLevelCache(directory / "enrichment.json"),
                timeout_seconds=0.5,
            )

            enriched = await enricher.enrich(raid(prf_code))
            reward_client = FakeRewardClient()
            resolver = FragmentResolver(
                reward_client,
                RewardCache(directory / "rewards.json"),
            )
            predictions = resolver.resolve_many([enriched.raid])
            await enricher.close()

        self.assertEqual(enriched.status, "success")
        self.assertEqual(enriched.raid.treasure_level, 2091)
        self.assertEqual(reward_client.calls, [{2091}])
        self.assertEqual(
            predictions[prf_code].reward_name,
            "記憶碎片（黃）",
        )

    async def test_cancellation_after_join_still_runs_cleanup(self) -> None:
        prf_code = "fresh-code"
        websocket = FakeWebSocket([])
        task = asyncio.create_task(client(websocket).enrich(prf_code))
        while not any(
            message["event"] == "raid_code_input"
            for message in websocket.sent
        ):
            await asyncio.sleep(0)

        task.cancel()
        websocket.incoming.put_nowait(
            server_event("db_raid", [[metadata(prf_code)]])
        )
        websocket.incoming.put_nowait(server_event("db_raid", [[]]))

        with self.assertRaises(asyncio.CancelledError):
            await task
        self.assertIn(
            "db_raid_delete",
            [message["event"] for message in websocket.sent],
        )

    async def test_cancellation_after_join_still_runs_cleanup(self) -> None:
        prf_code = "fresh-code"
        websocket = FakeWebSocket([])
        task = asyncio.create_task(client(websocket).enrich(prf_code))
        while not any(
            message["event"] == "raid_code_input"
            for message in websocket.sent
        ):
            await asyncio.sleep(0)

        task.cancel()
        websocket.incoming.put_nowait(
            server_event("db_raid", [[metadata(prf_code)]])
        )
        websocket.incoming.put_nowait(server_event("db_raid", [[]]))

        with self.assertRaises(asyncio.CancelledError):
            await task
        self.assertIn(
            "db_raid_delete",
            [message["event"] for message in websocket.sent],
        )

    def test_allowlist_has_no_battle_attack_or_claim_events(self) -> None:
        self.assertEqual(
            ALLOWED_OUTBOUND_EVENTS,
            {
                "__handshake_c",
                "__pong_s",
                "register",
                "raid_code_input",
                "db_raid",
                "db_raid_delete",
            },
        )


if __name__ == "__main__":
    unittest.main()
