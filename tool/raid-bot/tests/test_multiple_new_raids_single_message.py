from __future__ import annotations

import tempfile
import time
import unittest
from pathlib import Path

from raid_bot.services.raid_service import RaidService
from tests.raid_bot_test_support import FakeNotifier, FakeRaidSource, raid, snapshot


class MultipleRaidNotificationTests(unittest.IsolatedAsyncioTestCase):
    async def test_multiple_new_raids_use_one_message(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            source = FakeRaidSource([
                snapshot(),
                snapshot(
                    raid("raid-a", boss_name="靈龜"),
                    raid("raid-b", boss_name="龍魚"),
                ),
            ])
            service = RaidService(
                source,
                notifier=notifier,
                state_file=Path(directory) / "state.json",
            )
            service.diff.mark_full_summary(time.time())

            await service.update_once()
            await service.update_once()
            await service.dispatcher.flush_pending()

            self.assertEqual(len(notifier.messages), 1)
            self.assertEqual(
            notifier.messages[0],
            "🆕 新增 2 個公開渦\n"
            "Founder 🐢 靈龜 2759/6000\n"
            "Founder 🐢 龍魚 2759/6000",
        )


if __name__ == "__main__":
    unittest.main()
