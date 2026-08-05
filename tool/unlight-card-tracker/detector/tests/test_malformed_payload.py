from __future__ import annotations

import json
import unittest

from raid_bot.infrastructure.websocket_raid_source import WebSocketRaidSource


class MalformedPayloadTests(unittest.IsolatedAsyncioTestCase):
    async def test_malformed_event_is_ignored_and_next_event_is_processed(self) -> None:
        source = WebSocketRaidSource(
            endpoint="wss://example.invalid/",
            owner_id="test-owner",
        )

        self.assertFalse(await source.process_message("not json"))
        valid_message = json.dumps({
            "event": "raid_support_list",
            "meta": {"id": "message-1"},
            "args": [[{
                "prf_code": "raid-a",
                "prf_name": "靈龜",
                "prf_mons": "mc1008_02",
                "prf_founder": "Founder",
                "prf_hp": "2,759/6,000",
                "prf_member": "50/100",
                "prf_limit": 1785874217741,
            }]],
        })
        self.assertTrue(await source.process_message(valid_message))
        snapshot = source._queue.get_nowait()
        self.assertEqual(snapshot.raids[0].profound_id, "raid-a")
        self.assertIsNone(source.last_error)


if __name__ == "__main__":
    unittest.main()
