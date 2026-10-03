from __future__ import annotations

import asyncio
import json
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path

from db_raid_probe import ALLOWED_OUTBOUND_EVENTS
from raid_db_watcher import RaidDbWatcher, project_db_raid_rows


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


class RaidDbWatcherTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.fixed_now = datetime(
            2026,
            8,
            5,
            20,
            0,
            0,
            tzinfo=timezone.utc,
        )

    def test_projects_only_requested_raid_fields(self) -> None:
        rows = project_db_raid_rows([[
            {
                "profound_id": "raid-id",
                "name_tcn": "靈龜",
                "hp": 5727,
                "hp_max": 6000,
                "map": 4,
                "stage": 2,
                "rarity": 6,
                "treasure_level": 10,
                "points": [{"name": "private-player"}],
            },
        ]])

        self.assertEqual(rows, [{
            "profound_id": "raid-id",
            "boss": "靈龜",
            "hp": 5727,
            "hp_max": 6000,
            "map": 4,
            "stage": 2,
            "rarity": 6,
            "treasure_level": 10,
        }])
        self.assertNotIn("points", rows[0])

    async def test_fetches_once_and_saves_raw_and_summary(self) -> None:
        raw_rows = [[{
            "profound_id": "raid-id",
            "name_tcn": "靈龜",
            "hp": 5727,
            "hp_max": 6000,
            "map": 4,
            "stage": 2,
            "rarity": 6,
            "treasure_level": 10,
        }]]
        websocket = FakeWebSocket([
            json.dumps({
                "event": "__handshake_s",
                "args": [],
            }),
            json.dumps({
                "meta": {"id": "server-db-raid"},
                "event": "db_raid",
                "args": raw_rows,
            }),
        ])
        watcher = RaidDbWatcher("private-unlight-id")

        with tempfile.TemporaryDirectory() as directory:
            output_root = Path(directory) / "runs"
            result = await watcher.run_on_socket(
                websocket,
                timeout_seconds=0.1,
                register_settle_seconds=0,
                output_root=output_root,
                now=self.fixed_now,
            )

            sent_events = [message["event"] for message in websocket.sent]
            self.assertEqual(
                sent_events,
                ["register", "db_raid", "__handshake_c"],
            )
            self.assertTrue(set(sent_events) <= ALLOWED_OUTBOUND_EVENTS)
            self.assertNotIn("raid_code_input", sent_events)
            self.assertNotIn("db_raid_delete", sent_events)
            self.assertTrue(result["ok"])
            self.assertTrue(result["request_sent"])
            self.assertEqual(result["request_args_shape"], ["id"])
            self.assertEqual(result["response_event"], "db_raid")
            self.assertEqual(result["raid_count"], 1)
            self.assertEqual(result["raids"][0]["boss"], "靈龜")

            run_directory = output_root / "20260805"
            raw_path = next(run_directory.glob("*_db_raid_raw.json"))
            summary_path = next(
                run_directory.glob("*_db_raid_summary.json")
            )
            raw = json.loads(raw_path.read_text(encoding="utf-8"))
            saved_summary = json.loads(
                summary_path.read_text(encoding="utf-8")
            )
            self.assertEqual(raw["raw_args"], raw_rows)
            self.assertEqual(saved_summary["raid_count"], 1)
            self.assertNotIn("private-unlight-id", json.dumps(
                saved_summary,
                ensure_ascii=False,
            ))

    async def test_timeout_writes_summary_without_raw_payload(self) -> None:
        websocket = FakeWebSocket()
        watcher = RaidDbWatcher("private-unlight-id")

        with tempfile.TemporaryDirectory() as directory:
            output_root = Path(directory) / "runs"
            result = await watcher.run_on_socket(
                websocket,
                timeout_seconds=0.01,
                register_settle_seconds=0,
                output_root=output_root,
                now=self.fixed_now,
            )

            self.assertFalse(result["ok"])
            self.assertTrue(result["request_sent"])
            self.assertEqual(result["raid_count"], 0)
            self.assertIsNone(result["raw_payload_file"])
            self.assertEqual(
                [message["event"] for message in websocket.sent],
                ["register", "db_raid"],
            )
            run_directory = output_root / "20260805"
            self.assertEqual(
                list(run_directory.glob("*_db_raid_raw.json")),
                [],
            )
            self.assertEqual(
                len(list(run_directory.glob("*_db_raid_summary.json"))),
                1,
            )


if __name__ == "__main__":
    unittest.main()
