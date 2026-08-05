from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from raid_bot.services.raid_service import RaidService
from tests.raid_bot_test_support import FakeNotifier, FakeRaidSource, raid, snapshot


class RestartPersistenceTests(unittest.IsolatedAsyncioTestCase):
    async def test_restart_does_not_repost_seen_raid(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_file = Path(directory) / "state.json"
            first = RaidService(
                FakeRaidSource([snapshot(raid("raid-a"))]),
                notifier=FakeNotifier(),
                state_file=state_file,
            )
            await first.update_once()

            notifier = FakeNotifier()
            restarted = RaidService(
                FakeRaidSource([snapshot(raid("raid-a"))]),
                notifier=notifier,
                state_file=state_file,
            )
            await restarted.update_once()

            self.assertEqual(notifier.messages, [])

    async def test_corrupt_state_rebuilds_without_notification(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_file = Path(directory) / "state.json"
            state_file.write_text("not json", encoding="utf-8")
            notifier = FakeNotifier()
            service = RaidService(
                FakeRaidSource([snapshot(raid("raid-a"))]),
                notifier=notifier,
                state_file=state_file,
            )

            await service.update_once()

            self.assertEqual(notifier.messages, [])
            self.assertNotEqual(state_file.read_text(encoding="utf-8"), "not json")
            self.assertEqual(list(Path(directory).glob("*.tmp")), [])


if __name__ == "__main__":
    unittest.main()
