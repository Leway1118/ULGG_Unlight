from __future__ import annotations

import asyncio
import json
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path

from raid_metadata_scanner import (
    ALLOWED_OUTBOUND_EVENTS,
    MUTATING_OUTBOUND_EVENTS,
    RaidMetadataScanner,
    profound_id_is_absent,
    select_cleanup_candidate,
)


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


class RaidMetadataScannerTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.unlight_id = "private-account-id"
        self.prf_code = "private-raid-code"
        self.fixed_now = datetime(
            2026,
            8,
            5,
            20,
            30,
            40,
            123456,
            tzinfo=timezone.utc,
        )

    async def test_dry_run_sends_nothing_and_creates_no_output(self) -> None:
        websocket = FakeWebSocket()
        scanner = RaidMetadataScanner(
            self.unlight_id,
            self.prf_code,
        )
        with tempfile.TemporaryDirectory() as directory:
            output_root = Path(directory) / "scanner_runs"

            result = await scanner.run_on_socket(
                websocket,
                output_root=output_root,
            )

            self.assertEqual(result["mode"], "dry-run")
            self.assertFalse(result["connected"])
            self.assertEqual(websocket.sent, [])
            self.assertFalse(output_root.exists())

    async def test_single_success_captures_raw_and_cleans_up(self) -> None:
        raid_row = {
            "profound_id": "internal-profound-id",
            "pass": self.prf_code,
            "profound_mons": "mc1007_02",
            "map": 4,
            "stage": 3,
            "rarity": 6,
            "treasure_level": 10,
        }
        websocket = FakeWebSocket([
            server_event(
                "raid_found",
                ["title", "mons", 1, 123456789, 4],
            ),
            server_event("db_raid", [[raid_row]]),
            server_event("db_raid_reward", [[{"item": 1}]]),
            server_event("db_raid", [[]]),
        ])
        scanner = RaidMetadataScanner(
            self.unlight_id,
            self.prf_code,
            execute=True,
            clock_ms=lambda: 1000,
        )
        with tempfile.TemporaryDirectory() as directory:
            output_root = Path(directory) / "scanner_runs"

            result = await scanner.run_on_socket(
                websocket,
                timeout_seconds=0.1,
                register_settle_seconds=0,
                delete_settle_seconds=0,
                output_root=output_root,
                now=self.fixed_now,
            )

            sent_events = [item["event"] for item in websocket.sent]
            self.assertEqual(sent_events, [
                "register",
                "raid_code_input",
                "db_raid",
                "db_raid_reward",
                "db_raid_delete",
                "db_raid",
            ])
            delete_message = websocket.sent[4]
            self.assertEqual(
                delete_message["args"],
                [self.unlight_id, "internal-profound-id"],
            )
            self.assertTrue(result["ok"])
            self.assertTrue(result["cleanup_attempted"])
            self.assertTrue(result["cleanup_confirmed"])

            run_directory = output_root / "20260805"
            files = sorted(path.name for path in run_directory.iterdir())
            self.assertEqual(len(files), 5)
            self.assertTrue(any("_db_raid.json" in name for name in files))
            self.assertTrue(any(
                "_db_raid_reward.json" in name
                for name in files
            ))
            raw_path = next(
                path
                for path in run_directory.iterdir()
                if path.name.endswith("_db_raid.json")
            )
            raw = json.loads(raw_path.read_text(encoding="utf-8"))
            self.assertEqual(raw["response"]["args"], [[raid_row]])

    async def test_raid_code_error_never_deletes(self) -> None:
        websocket = FakeWebSocket([
            server_event("raid_code_error", [2]),
            server_event("db_raid", [[{
                "profound_id": "preexisting-id",
                "pass": self.prf_code,
            }]]),
            server_event("db_raid_reward", [[]]),
        ])
        scanner = RaidMetadataScanner(
            self.unlight_id,
            self.prf_code,
            execute=True,
        )
        with tempfile.TemporaryDirectory() as directory:
            result = await scanner.run_on_socket(
                websocket,
                timeout_seconds=0.1,
                register_settle_seconds=0,
                delete_settle_seconds=0,
                output_root=Path(directory),
                now=self.fixed_now,
            )

        sent_events = [item["event"] for item in websocket.sent]
        self.assertNotIn("db_raid_delete", sent_events)
        self.assertEqual(result["join_outcome"], "raid_code_error")
        self.assertEqual(
            result["cleanup_candidate_reason"],
            "join_not_confirmed",
        )

    async def test_ambiguous_rows_are_not_deleted(self) -> None:
        websocket = FakeWebSocket([
            server_event("raid_found", ["title", "mons", 1, 1, 1]),
            server_event("db_raid", [[
                {"profound_id": "id-a", "pass": "other-a"},
                {"profound_id": "id-b", "pass": "other-b"},
            ]]),
            server_event("db_raid_reward", [[]]),
        ])
        scanner = RaidMetadataScanner(
            self.unlight_id,
            self.prf_code,
            execute=True,
        )
        with tempfile.TemporaryDirectory() as directory:
            result = await scanner.run_on_socket(
                websocket,
                timeout_seconds=0.1,
                register_settle_seconds=0,
                output_root=Path(directory),
                now=self.fixed_now,
            )

        self.assertFalse(result["cleanup_attempted"])
        self.assertFalse(result["ok"])
        self.assertEqual(
            result["cleanup_candidate_reason"],
            "ambiguous_db_raid_rows",
        )
        self.assertNotIn(
            "db_raid_delete",
            [item["event"] for item in websocket.sent],
        )

    async def test_join_timeout_is_bounded_and_never_deletes(self) -> None:
        websocket = FakeWebSocket([
            server_event("db_raid", [[]]),
            server_event("db_raid_reward", [[]]),
        ])
        scanner = RaidMetadataScanner(
            self.unlight_id,
            self.prf_code,
            execute=True,
        )
        with tempfile.TemporaryDirectory() as directory:
            result = await scanner.run_on_socket(
                websocket,
                timeout_seconds=0.01,
                register_settle_seconds=0,
                output_root=Path(directory),
                now=self.fixed_now,
            )

        self.assertEqual(result["join_outcome"], "timeout")
        self.assertFalse(result["cleanup_attempted"])
        self.assertNotIn(
            "db_raid_delete",
            [item["event"] for item in websocket.sent],
        )

    def test_outbound_allowlist_blocks_gameplay_and_claims(self) -> None:
        scanner = RaidMetadataScanner(
            self.unlight_id,
            self.prf_code,
            execute=True,
        )
        for event in (
            "raid_turn",
            "raid_ready",
            "attack",
            "battle_start",
            "raid_reward_claim",
        ):
            with self.subTest(event=event), self.assertRaises(ValueError):
                scanner._build_message(event, [])

        self.assertTrue(
            MUTATING_OUTBOUND_EVENTS <= ALLOWED_OUTBOUND_EVENTS
        )

    def test_mutations_require_execute_mode(self) -> None:
        scanner = RaidMetadataScanner(
            self.unlight_id,
            self.prf_code,
        )

        with self.assertRaises(ValueError):
            scanner._build_message(
                "raid_code_input",
                [self.unlight_id, self.prf_code],
            )
        with self.assertRaises(ValueError):
            scanner._build_message(
                "db_raid_delete",
                [self.unlight_id, "internal-id"],
            )

    def test_cleanup_candidate_prefers_unique_pass_match(self) -> None:
        candidate = select_cleanup_candidate(
            [[
                {"profound_id": "id-a", "pass": "other"},
                {"profound_id": "id-b", "pass": self.prf_code},
            ]],
            prf_code=self.prf_code,
        )

        self.assertEqual(candidate.profound_id, "id-b")
        self.assertEqual(candidate.reason, "unique_pass_match")
        self.assertTrue(profound_id_is_absent([[]], "id-b"))

    def test_malformed_verification_does_not_confirm_cleanup(self) -> None:
        candidate = select_cleanup_candidate(
            {"not": "event args"},
            prf_code=self.prf_code,
        )

        self.assertIsNone(candidate.profound_id)
        self.assertEqual(candidate.reason, "malformed_db_raid")
        self.assertFalse(
            profound_id_is_absent({"not": "event args"}, "id-b")
        )


if __name__ == "__main__":
    unittest.main()
