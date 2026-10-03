from __future__ import annotations

import asyncio
import json
import tempfile
import unittest
from pathlib import Path

from public_raid_enrichment_probe import PublicRaidEnricher
from raid_metadata_scanner import RaidMetadataScanner


def server_event(event: str, args: list) -> str:
    return json.dumps({
        "meta": {"id": f"server-{event}"},
        "event": event,
        "args": args,
    })


class FakeWebSocket:
    def __init__(self, incoming: list[str] | None = None) -> None:
        self.incoming: asyncio.Queue[str] = asyncio.Queue()
        for message in incoming or []:
            self.incoming.put_nowait(message)
        self.sent: list[dict] = []

    async def send(self, message: str) -> None:
        self.sent.append(json.loads(message))

    async def recv(self) -> str:
        return await self.incoming.get()


class FirstReceiveTimeoutWebSocket(FakeWebSocket):
    def __init__(self, incoming: list[str]) -> None:
        super().__init__(incoming)
        self._first_receive = True

    async def recv(self) -> str:
        if self._first_receive:
            self._first_receive = False
            await asyncio.Future()
        return await super().recv()


class FakeFragmentResolver:
    def __init__(self, prediction=None) -> None:
        self.prediction = prediction
        self.raids = []

    def resolve_many(self, raids):
        self.raids.extend(raids)
        if self.prediction is None:
            return {}
        return {raids[0].profound_id: self.prediction}


class Prediction:
    reward_name = "生命碎片（紅）"


def db_row(prf_code: str) -> dict:
    return {
        "profound_id": "joined-profound-id",
        "pass": prf_code,
        "profound_founder": "伊迪歐特",
        "profound_mons": "mc1003_02",
        "name_tcn": "黑死獸",
        "treasure_level": 2079,
        "rarity": 1,
        "map": 5,
        "stage": 4,
        "hp": 3500,
        "hp_max": 3500,
    }


class PublicRaidEnrichmentProbeTests(unittest.IsolatedAsyncioTestCase):
    async def test_dry_run_omits_legacy_db_raid_reward_request(self) -> None:
        scanner = RaidMetadataScanner(
            "worker-account",
            "fresh-public-code",
        )

        result = await scanner.run(
            "wss://example.invalid/",
            request_db_raid_reward=False,
        )

        self.assertNotIn("db_raid_reward", result["planned_events"])

    async def test_join_match_resolve_preview_and_cleanup(self) -> None:
        prf_code = "fresh-public-code"
        resolver = FakeFragmentResolver(Prediction())
        websocket = FakeWebSocket([
            server_event("raid_found", ["title", "mons", 1, 1, 1]),
            server_event("db_raid", [[db_row(prf_code)]]),
            server_event("db_raid", [[]]),
        ])
        scanner = RaidMetadataScanner(
            "worker-account",
            prf_code,
            execute=True,
            clock_ms=lambda: 1000,
        )

        with tempfile.TemporaryDirectory() as directory:
            result = await scanner.run_on_socket(
                websocket,
                timeout_seconds=0.1,
                register_settle_seconds=0,
                delete_settle_seconds=0,
                output_root=Path(directory),
                enrich_target=PublicRaidEnricher(resolver).enrich,
                request_db_raid_reward=False,
            )

        self.assertEqual(result["join_outcome"], "raid_found")
        self.assertEqual(result["enrichment"]["treasure_level"], 2079)
        self.assertEqual(
            result["enrichment"]["fragment_reward_name"],
            "生命碎片（紅）",
        )
        self.assertIn("紅狗🔴🐶", result["enrichment"]["discord_preview"])
        self.assertEqual(resolver.raids[0].map_id, 5)
        self.assertEqual(resolver.raids[0].stage_id, 4)
        self.assertTrue(result["cleanup_attempted"])
        self.assertTrue(result["cleanup_confirmed"])
        delete = next(
            message
            for message in websocket.sent
            if message["event"] == "db_raid_delete"
        )
        self.assertEqual(
            delete["args"],
            ["worker-account", "joined-profound-id"],
        )

    async def test_join_response_timeout_still_cleans_unique_pass_match(self) -> None:
        prf_code = "fresh-public-code"
        websocket = FirstReceiveTimeoutWebSocket([
            server_event("db_raid", [[db_row(prf_code)]]),
            server_event("db_raid", [[]]),
        ])
        scanner = RaidMetadataScanner(
            "worker-account",
            prf_code,
            execute=True,
        )

        with tempfile.TemporaryDirectory() as directory:
            result = await scanner.run_on_socket(
                websocket,
                timeout_seconds=0.01,
                register_settle_seconds=0,
                delete_settle_seconds=0,
                output_root=Path(directory),
                enrich_target=PublicRaidEnricher(
                    FakeFragmentResolver(Prediction())
                ).enrich,
                request_db_raid_reward=False,
            )

        self.assertEqual(result["join_outcome"], "timeout")
        self.assertEqual(
            result["cleanup_candidate_reason"],
            "unique_pass_match",
        )
        self.assertTrue(result["cleanup_attempted"])
        self.assertTrue(result["cleanup_confirmed"])

    async def test_timeout_never_deletes_nonmatching_raid(self) -> None:
        row = db_row("different-code")
        websocket = FirstReceiveTimeoutWebSocket([
            server_event("db_raid", [[row]]),
        ])
        scanner = RaidMetadataScanner(
            "worker-account",
            "fresh-public-code",
            execute=True,
        )

        with tempfile.TemporaryDirectory() as directory:
            result = await scanner.run_on_socket(
                websocket,
                timeout_seconds=0.01,
                register_settle_seconds=0,
                output_root=Path(directory),
                request_db_raid_reward=False,
            )

        self.assertFalse(result["cleanup_attempted"])
        self.assertNotIn(
            "db_raid_delete",
            [message["event"] for message in websocket.sent],
        )

    async def test_cleanup_verification_timeout_is_recorded(self) -> None:
        prf_code = "fresh-public-code"
        websocket = FakeWebSocket([
            server_event("raid_found", ["title", "mons", 1, 1, 1]),
            server_event("db_raid", [[db_row(prf_code)]]),
        ])
        scanner = RaidMetadataScanner(
            "worker-account",
            prf_code,
            execute=True,
        )

        with tempfile.TemporaryDirectory() as directory:
            result = await scanner.run_on_socket(
                websocket,
                timeout_seconds=0.01,
                register_settle_seconds=0,
                delete_settle_seconds=0,
                output_root=Path(directory),
                enrich_target=PublicRaidEnricher(
                    FakeFragmentResolver(Prediction())
                ).enrich,
                request_db_raid_reward=False,
            )

        self.assertTrue(result["cleanup_attempted"])
        self.assertFalse(result["cleanup_confirmed"])


if __name__ == "__main__":
    unittest.main()
