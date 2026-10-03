from __future__ import annotations

import asyncio
import io
import json
import unittest
from contextlib import redirect_stdout

from raid_bot.infrastructure.websocket_raid_source import (
    RECONNECT_DELAYS,
    WebSocketRaidSource,
)


class FakeWebSocket:
    def __init__(self) -> None:
        self.messages: list[str] = []

    async def send(self, message: str) -> None:
        self.messages.append(message)


class WebSocketRaidProtocolTests(unittest.IsolatedAsyncioTestCase):
    async def test_register_and_poll_use_verified_event_shape(self) -> None:
        source = WebSocketRaidSource(
            endpoint="wss://example.invalid/",
            owner_id="test-owner",
            interval_seconds=10,
        )
        websocket = FakeWebSocket()

        output = io.StringIO()
        with redirect_stdout(output):
            await source._emit(websocket, "register", [source.owner_id])
            await source._emit(
                websocket,
                "raid_get_support",
                [source.owner_id],
            )

        register, poll = map(json.loads, websocket.messages)
        self.assertEqual(register["event"], "register")
        self.assertEqual(register["args"], ["test-owner"])
        self.assertEqual(poll["event"], "raid_get_support")
        self.assertEqual(poll["args"], ["test-owner"])
        self.assertEqual(source.interval_seconds, 10)
        self.assertNotIn("test-owner", output.getvalue())

    async def test_close_cancels_background_task_cleanly(self) -> None:
        source = WebSocketRaidSource(
            endpoint="wss://example.invalid/",
            owner_id="test-owner",
        )
        source.connected = True
        source._runner = asyncio.create_task(asyncio.sleep(3600))

        await source.close()

        self.assertFalse(source.connected)
        self.assertIsNone(source._runner)

    def test_reconnect_delay_schedule_is_bounded(self) -> None:
        self.assertEqual(RECONNECT_DELAYS, (2, 5, 10, 30, 60))


if __name__ == "__main__":
    unittest.main()
