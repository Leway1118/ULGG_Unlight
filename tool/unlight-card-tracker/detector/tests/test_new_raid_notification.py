from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from raid_bot.services.raid_service import RaidService
from tests.raid_bot_test_support import FakeNotifier, FakeRaidSource, raid, snapshot


class NewRaidNotificationTests(unittest.IsolatedAsyncioTestCase):
    async def test_only_new_raid_is_notified(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            source = FakeRaidSource([
                snapshot(raid("raid-a")),
                snapshot(raid("raid-a"), raid("raid-b", boss_name="龍魚")),
            ])
            service = RaidService(
                source,
                notifier=notifier,
                state_file=Path(directory) / "state.json",
            )

            await service.update_once()
            await service.update_once()

            self.assertEqual(len(notifier.messages), 1)
            self.assertIn("龍魚", notifier.messages[0])
            self.assertNotIn("靈龜", notifier.messages[0])


if __name__ == "__main__":
    unittest.main()
