from __future__ import annotations

import asyncio
import sys
import unittest
from pathlib import Path


TOOL_ROOT = Path(__file__).resolve().parents[2]
DETECTOR_ROOT = TOOL_ROOT / "unlight-card-tracker" / "detector"
for import_root in (TOOL_ROOT, DETECTOR_ROOT):
    if str(import_root) not in sys.path:
        sys.path.insert(0, str(import_root))

from raid_bot.infrastructure.raid_reader import READ_RAID_JS, RaidReader


class FakeCdpClient:
    connected = True

    async def evaluate(self, expression: str):
        if expression != READ_RAID_JS:
            raise AssertionError("RaidReader used an unexpected expression")
        return {
            "ok": True,
            "player_name": "LocalPlayer",
            "raids": [
                {
                    "profound_id": "raid-1",
                    "founder": "Owlic",
                    "monster_code": "mc1008_02",
                    "monster_id": 1008,
                    "name": "靈龜",
                    "rarity": 1,
                    "level": 10,
                    "map": 8,
                    "stage": 4,
                    "treasure_level": 2161,
                    "hp": 5283,
                    "hp_max": 6000,
                    "member_limit": 100,
                    "pass": "pass-code",
                    "expires_at": 1785874217741,
                    "state": [
                        {"type": "defD9", "turn": 2_000_000},
                        {"type": "curse", "turn": 9},
                    ],
                    "points": [
                        {
                            "name": "LocalPlayer",
                            "point": 10,
                            "damage": 20,
                        }
                    ],
                }
            ],
        }


class RaidReaderTreasureLevelTest(unittest.TestCase):
    def test_read_snapshot_preserves_treasure_level(self) -> None:
        snapshot = asyncio.run(
            RaidReader(FakeCdpClient()).read_snapshot()
        )

        self.assertEqual(len(snapshot.raids), 1)
        self.assertEqual(snapshot.raids[0].treasure_level, 2161)
        self.assertTrue(snapshot.raids[0].already_joined)
        self.assertEqual(snapshot.raids[0].statuses[0].base_type, "defD")
        self.assertEqual(snapshot.raids[0].statuses[0].level, 9)
        self.assertEqual(snapshot.raids[0].statuses[1].value, 9)

    def test_javascript_projection_reads_treasure_level(self) -> None:
        self.assertIn(
            "row?.treasure_level ?? null",
            READ_RAID_JS,
        )


if __name__ == "__main__":
    unittest.main()
