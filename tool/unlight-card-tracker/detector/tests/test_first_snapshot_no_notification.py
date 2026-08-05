from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from raid_bot.services.raid_service import RaidService
from tests.raid_bot_test_support import FakeNotifier, FakeRaidSource, raid, snapshot


class FirstSnapshotTests(unittest.IsolatedAsyncioTestCase):
    async def test_first_snapshot_only_creates_baseline(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_file = Path(directory) / "raid_state.json"
            notifier = FakeNotifier()
            service = RaidService(
                FakeRaidSource([snapshot(raid("raid-a"))]),
                notifier=notifier,
                state_file=state_file,
            )

            await service.update_once()

            self.assertEqual(notifier.messages, [])
            state = json.loads(state_file.read_text(encoding="utf-8"))
            self.assertEqual(state["seen_raid_ids"], ["raid-a"])


if __name__ == "__main__":
    unittest.main()
