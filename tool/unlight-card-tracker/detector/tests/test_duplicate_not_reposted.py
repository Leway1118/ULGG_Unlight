from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from raid_bot.services.raid_service import RaidService
from tests.raid_bot_test_support import FakeNotifier, FakeRaidSource, raid, snapshot


class DuplicateNotificationTests(unittest.IsolatedAsyncioTestCase):
    async def test_updates_and_reappearance_are_not_reposted(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            source = FakeRaidSource([
                snapshot(),
                snapshot(raid("raid-a")),
                snapshot(raid("raid-a", hp=1000, members=60)),
                snapshot(),
                snapshot(raid("raid-a", hp=500, members=70)),
            ])
            service = RaidService(
                source,
                notifier=notifier,
                state_file=Path(directory) / "state.json",
            )

            for _ in range(5):
                await service.update_once()

            self.assertEqual(len(notifier.messages), 1)


if __name__ == "__main__":
    unittest.main()
