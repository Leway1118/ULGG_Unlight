from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from raid_bot.infrastructure.websocket_raid_source import WebSocketRaidSource


class WebSocketEventCaptureTests(unittest.IsolatedAsyncioTestCase):
    def make_source(
        self,
        capture_file: Path,
        *,
        max_bytes: int = 10 * 1024 * 1024,
        backups: int = 3,
    ) -> WebSocketRaidSource:
        return WebSocketRaidSource(
            endpoint="wss://example.invalid/",
            owner_id="test-owner",
            event_capture_file=capture_file,
            event_capture_max_bytes=max_bytes,
            event_capture_backups=backups,
        )

    async def test_captures_complete_args_before_normalization(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            capture_file = Path(directory) / "data" / "websocket_events.jsonl"
            source = self.make_source(capture_file)
            args = [[{
                "prf_code": "raid-1",
                "prf_name": "boss",
                "prf_mons": "mc1008_02",
                "prf_founder": "founder",
                "prf_hp": "2,759/6,000",
                "prf_member": "50/100",
                "prf_limit": 1785874217741,
                "unknown_field": {"kept": True},
            }]]

            processed = await source.process_message(json.dumps({
                "event": "raid_support_list",
                "args": args,
                "meta": {"id": "not-captured"},
            }))

            self.assertTrue(processed)
            record = json.loads(capture_file.read_text(encoding="utf-8"))
            self.assertEqual(record["event"], "raid_support_list")
            self.assertEqual(record["args"], args)
            self.assertEqual(record["args"][0][0]["unknown_field"], {"kept": True})
            self.assertNotIn("meta", record)
            self.assertIn("time", record)

    async def test_captures_non_raid_event(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            capture_file = Path(directory) / "websocket_events.jsonl"
            source = self.make_source(capture_file)

            processed = await source.process_message(json.dumps({
                "event": "future_raid_detail",
                "args": [{"new_field": 6}],
            }))

            self.assertTrue(processed)
            record = json.loads(capture_file.read_text(encoding="utf-8"))
            self.assertEqual(record["event"], "future_raid_detail")
            self.assertEqual(record["args"], [{"new_field": 6}])

    async def test_capture_failure_does_not_stop_snapshot_processing(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            blocked_parent = Path(directory) / "not-a-directory"
            blocked_parent.write_text("file", encoding="utf-8")
            source = self.make_source(blocked_parent / "events.jsonl")
            args = [[{
                "prf_code": "raid-1",
                "prf_name": "boss",
                "prf_mons": "mc1008_02",
                "prf_founder": "founder",
                "prf_hp": "1/2",
                "prf_member": "3/100",
                "prf_limit": 1785874217741,
            }]]

            processed = await source.process_message(json.dumps({
                "event": "raid_support_list",
                "args": args,
            }))

            self.assertTrue(processed)
            self.assertEqual(source._queue.qsize(), 1)
            self.assertIsNone(source.last_error)

    async def test_rotates_capture_file_at_size_limit(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            capture_file = Path(directory) / "websocket_events.jsonl"
            source = self.make_source(capture_file, max_bytes=1, backups=2)

            await source.process_message(json.dumps({
                "event": "first",
                "args": [1],
            }))
            await source.process_message(json.dumps({
                "event": "second",
                "args": [2],
            }))
            await source.process_message(json.dumps({
                "event": "third",
                "args": [3],
            }))

            current = json.loads(capture_file.read_text(encoding="utf-8"))
            previous = json.loads(Path(f"{capture_file}.1").read_text(
                encoding="utf-8",
            ))
            oldest = json.loads(Path(f"{capture_file}.2").read_text(
                encoding="utf-8",
            ))
            self.assertEqual(current["event"], "third")
            self.assertEqual(previous["event"], "second")
            self.assertEqual(oldest["event"], "first")


if __name__ == "__main__":
    unittest.main()
