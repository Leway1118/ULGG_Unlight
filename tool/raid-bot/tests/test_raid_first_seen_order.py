from __future__ import annotations

import json
import tempfile
import time
import unittest
from pathlib import Path

from raid_bot.services.raid_service import RaidService
from tests.raid_bot_test_support import FakeNotifier, FakeRaidSource, raid, snapshot


class RaidFirstSeenOrderTests(unittest.IsolatedAsyncioTestCase):
    async def test_existing_raids_sort_before_latest_new_raid(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            notifier = FakeNotifier()
            source = FakeRaidSource([
                snapshot(raid("raid-old"), captured_at=10.0),
                snapshot(
                    raid("raid-new", boss_name="龍鯰", monster_code="mc1012_02"),
                    raid("raid-old"),
                    captured_at=20.0,
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

            self.assertEqual(
                notifier.messages,
                [
                    "🆕 Founder 🐟 龍鯰 2759/6000",
                ],
            )

    async def test_first_seen_order_survives_restart(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_file = Path(directory) / "state.json"
            first_notifier = FakeNotifier()
            first_service = RaidService(
                FakeRaidSource([
                    snapshot(raid("raid-old"), captured_at=10.0),
                    snapshot(
                        raid("raid-old"),
                        raid(
                            "raid-middle",
                            boss_name="龍鯰",
                            monster_code="mc1012_02",
                        ),
                        captured_at=20.0,
                    ),
                ]),
                notifier=first_notifier,
                state_file=state_file,
            )
            first_service.diff.mark_full_summary(time.time())
            await first_service.update_once()
            await first_service.update_once()
            await first_service.dispatcher.flush_pending()

            restarted_notifier = FakeNotifier()
            restarted_service = RaidService(
                FakeRaidSource([
                    snapshot(
                        raid(
                            "raid-latest",
                            boss_name="黑死獸",
                            monster_code="mc1003_02",
                        ),
                        raid("raid-old"),
                        raid(
                            "raid-middle",
                            boss_name="龍鯰",
                            monster_code="mc1012_02",
                        ),
                        captured_at=30.0,
                    ),
                ]),
                notifier=restarted_notifier,
                state_file=state_file,
            )
            await restarted_service.update_once()
            await restarted_service.dispatcher.flush_pending()

            self.assertEqual(
                restarted_notifier.messages,
                [
                    "🆕 Founder 🐶 黑死獸 2759/6000",
                ],
            )

    async def test_version_one_state_is_migrated_without_reposting(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_file = Path(directory) / "state.json"
            state_file.write_text(
                json.dumps({
                    "version": 1,
                    "seen_raid_ids": ["raid-old"],
                }),
                encoding="utf-8",
            )
            notifier = FakeNotifier()
            service = RaidService(
                FakeRaidSource([
                    snapshot(raid("raid-old"), captured_at=20.0),
                ]),
                notifier=notifier,
                state_file=state_file,
            )

            await service.update_once()

            self.assertEqual(notifier.messages, [])
            self.assertEqual(service.diff.first_seen_at("raid-old"), 0.0)


if __name__ == "__main__":
    unittest.main()
