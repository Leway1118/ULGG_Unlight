from __future__ import annotations

import asyncio
import json
import tempfile
import unittest
from pathlib import Path

from db_raid_probe import (
    ALLOWED_OUTBOUND_EVENTS,
    DbRaidReadOnlyProbe,
    build_debug_output,
    inspect_nested_structure,
    parse_db_raid_args,
    summarize_db_raid,
    write_result,
)


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


class DbRaidProbeTests(unittest.IsolatedAsyncioTestCase):
    def test_schema_parsing_accepts_nested_event_args(self) -> None:
        rows = parse_db_raid_args([[
            {
                "profound_id": "internal-id",
                "pass": "support-code",
                "map": 5,
                "stage": 1,
                "rarity": 6,
                "treasure_level": 2091,
            },
        ]])

        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["map"], 5)

    def test_nested_schema_reports_keys_and_list_lengths(self) -> None:
        structure = inspect_nested_structure([[
            {
                "profound_id": "internal-id",
                "points": [
                    {"name": "player-a", "damage": 1},
                    {"name": "player-b", "damage": 2},
                ],
            },
        ]])

        self.assertIn("args[][].profound_id", structure["nested_keys"])
        self.assertIn("args[][].points[].name", structure["nested_keys"])
        lengths = {
            item["path"]: item["observed_lengths"]
            for item in structure["list_lengths"]
        }
        self.assertEqual(lengths["args"], [1])
        self.assertEqual(lengths["args[]"], [1])
        self.assertEqual(lengths["args[][].points"], [2])

    def test_payload_filter_outputs_schema_but_not_values(self) -> None:
        secret_owner = "private-owner-id"
        secret_pass = "private-support-code"
        secret_profound = "private-profound-id"
        result = summarize_db_raid(
            [[{
                "profound_id": secret_profound,
                "pass": secret_pass,
                "map": 5,
                "stage": 1,
                "rarity": 6,
                "treasure_level": 2091,
                "points": [{"name": "private-player"}],
            }]],
            owner_id=secret_owner,
        )
        encoded = json.dumps(result, ensure_ascii=False)

        self.assertEqual(result["response_event"], "db_raid")
        self.assertEqual(result["raid_count"], 1)
        self.assertIn("pass", result["schema_keys"])
        self.assertIn("treasure_level", result["schema_keys"])
        self.assertNotIn(secret_owner, encoded)
        self.assertNotIn(secret_pass, encoded)
        self.assertNotIn(secret_profound, encoded)
        self.assertNotIn("private-player", encoded)

    def test_empty_response_does_not_claim_relation_mismatch(self) -> None:
        result = summarize_db_raid([[]], owner_id="private-owner-id")

        self.assertEqual(result["response_event"], "db_raid")
        self.assertEqual(result["raid_count"], 0)
        self.assertEqual(
            result["relation_candidates"][1]["status"],
            "not_testable",
        )
        self.assertEqual(
            result["relation_candidates"][2]["status"],
            "not_testable",
        )
        self.assertEqual(
            result["relation_candidates"][3]["status"],
            "not_observed",
        )

    async def test_missing_response_returns_empty_result(self) -> None:
        websocket = FakeWebSocket()
        probe = DbRaidReadOnlyProbe("private-owner-id")

        result = await probe.run_on_socket(
            websocket,
            timeout_seconds=0.01,
            register_settle_seconds=0,
        )

        self.assertEqual(result["response_event"], "")
        self.assertTrue(result["request_sent"])
        self.assertEqual(result["request_args_shape"], ["id"])
        self.assertEqual(result["schema_keys"], [])
        self.assertEqual(result["raid_count"], 0)
        self.assertEqual(
            [message["event"] for message in websocket.sent],
            ["register", "db_raid"],
        )

    async def test_probe_sends_only_read_only_events(self) -> None:
        response = json.dumps({
            "meta": {"id": "response-1"},
            "event": "db_raid",
            "args": [[]],
        })
        websocket = FakeWebSocket([response])
        probe = DbRaidReadOnlyProbe("private-owner-id")

        result = await probe.run_on_socket(
            websocket,
            timeout_seconds=0.1,
            register_settle_seconds=0,
        )

        sent_events = [message["event"] for message in websocket.sent]
        self.assertEqual(sent_events, ["register", "db_raid"])
        self.assertEqual(
            websocket.sent[1]["args"],
            ["private-owner-id"],
        )
        self.assertTrue(set(sent_events) <= ALLOWED_OUTBOUND_EVENTS)
        self.assertTrue(result["request_sent"])
        self.assertEqual(result["response_event"], "db_raid")

        with self.assertRaises(ValueError):
            probe._build_message("raid_code_input", ["forbidden"])
        with self.assertRaises(ValueError):
            probe._build_message("db_raid_delete", ["forbidden"])

    async def test_handshake_uses_only_protocol_control_event(self) -> None:
        handshake = json.dumps({
            "event": "__handshake_s",
            "args": [],
        })
        response = json.dumps({
            "meta": {"id": "response-1"},
            "event": "db_raid",
            "args": [[]],
        })
        websocket = FakeWebSocket([handshake, response])
        probe = DbRaidReadOnlyProbe("private-owner-id")

        await probe.run_on_socket(
            websocket,
            timeout_seconds=0.1,
            register_settle_seconds=0,
        )

        self.assertEqual(
            [message["event"] for message in websocket.sent],
            ["register", "db_raid", "__handshake_c"],
        )

    async def test_ignores_db_raid_received_before_request(self) -> None:
        unsolicited = json.dumps({
            "meta": {"id": "unsolicited"},
            "event": "db_raid",
            "args": [[{"profound_id": "stale-id", "map": 1}]],
        })
        requested = json.dumps({
            "meta": {"id": "requested"},
            "event": "db_raid",
            "args": [[{"profound_id": "fresh-id", "map": 2}]],
        })
        websocket = FakeWebSocket([unsolicited])
        probe = DbRaidReadOnlyProbe("private-owner-id")

        async def add_requested_response() -> None:
            await asyncio.sleep(0.02)
            websocket.incoming.put_nowait(requested)

        response_task = asyncio.create_task(add_requested_response())
        result = await probe.run_on_socket(
            websocket,
            timeout_seconds=0.1,
            register_settle_seconds=0.01,
        )
        await response_task

        self.assertEqual(
            [message["event"] for message in websocket.sent],
            ["register", "db_raid"],
        )
        self.assertTrue(result["request_sent"])
        self.assertEqual(result["response_event"], "db_raid")
        self.assertIn("map", result["schema_keys"])

    async def test_debug_mode_writes_raw_args_to_separate_file(self) -> None:
        raw_args = [[{
            "profound_id": "internal-id",
            "pass": "support-code",
            "map": 5,
            "stage": 1,
            "rarity": 6,
            "treasure_level": 2091,
            "points": [{"name": "private-player"}],
        }]]
        response = json.dumps({
            "meta": {"id": "response-1"},
            "event": "db_raid",
            "args": raw_args,
        })
        websocket = FakeWebSocket([response])
        probe = DbRaidReadOnlyProbe("private-owner-id")
        with tempfile.TemporaryDirectory() as directory:
            debug_path = Path(directory) / "db_raid_probe_debug.json"

            result = await probe.run_on_socket(
                websocket,
                timeout_seconds=0.1,
                register_settle_seconds=0,
                debug_output=debug_path,
            )

            debug = json.loads(debug_path.read_text(encoding="utf-8"))
            self.assertEqual(debug["raw_args"], raw_args)
            self.assertEqual(debug["schema_keys"], result["schema_keys"])
            self.assertIn(
                "args[][].points[].name",
                debug["nested_keys"],
            )
            self.assertIn("Sensitive", debug["warning"])

    def test_debug_output_builder_keeps_raw_values_opt_in(self) -> None:
        raw_args = [[{"pass": "sensitive-pass", "map": 1}]]
        summary = summarize_db_raid(raw_args, owner_id="owner")

        debug = build_debug_output(raw_args, summary)
        safe_summary = json.dumps(summary, ensure_ascii=False)

        self.assertEqual(debug["raw_args"], raw_args)
        self.assertNotIn("sensitive-pass", safe_summary)

    def test_result_file_contains_filtered_summary_only(self) -> None:
        result = summarize_db_raid(
            [[{"profound_id": "id", "pass": "secret", "map": 1}]],
            owner_id="owner",
        )
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "db_raid_probe_result.json"

            write_result(path, result)

            saved = path.read_text(encoding="utf-8")
            self.assertIn('"request_event": "db_raid"', saved)
            self.assertNotIn('"secret"', saved)


if __name__ == "__main__":
    unittest.main()
