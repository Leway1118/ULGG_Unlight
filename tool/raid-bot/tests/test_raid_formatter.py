from __future__ import annotations

import unittest

from raid_bot.formatter.raid_text import format_raid_list
from tests.raid_bot_test_support import raid


class RaidFormatterTests(unittest.TestCase):
    def test_formats_player_view_without_title_or_details(self) -> None:
        text = format_raid_list(
            [
                raid(
                    "raid-new",
                    boss_name="龍鯰",
                    monster_code="mc1012_02",
                    founder="OWLIC",
                ),
                raid(
                    "raid-old",
                    boss_name="靈龜",
                    monster_code="mc1008_02",
                    founder="咕嚕．挖2朵",
                ),
            ],
            new_ids={"raid-new"},
            first_seen_at={"raid-old": 10.0, "raid-new": 20.0},
        )

        self.assertEqual(
            text,
            "👤 咕嚕．挖2朵 🐢 靈龜 ❤️2759/6000\n"
            "🆕 👤 OWLIC 🐟 龍鯰 ❤️2759/6000",
        )
        self.assertNotIn("Raid List", text)
        self.assertNotIn("HP", text)
        self.assertNotIn("Members", text)
        self.assertNotIn("☠️", text)

    def test_unknown_monster_code_uses_question_mark(self) -> None:
        text = format_raid_list([
            raid(
                "raid-unknown",
                boss_name="黃海",
                monster_code="mc-unknown",
                founder="billyzx",
            ),
        ])

        self.assertEqual(text, "👤 billyzx ❓ 黃海 ❤️2759/6000")

    def test_duplicate_raid_id_is_rendered_once(self) -> None:
        text = format_raid_list(
            [
                raid(
                    "raid-new",
                    boss_name="龍鯰",
                    monster_code="mc1012_02",
                    founder="澍星",
                ),
                raid(
                    "raid-old",
                    boss_name="靈龜",
                    founder="Owlic",
                ),
                raid(
                    "raid-new",
                    boss_name="龍鯰",
                    monster_code="mc1012_02",
                    founder="澍星",
                ),
            ],
            new_ids={"raid-new"},
            first_seen_at={"raid-old": 10.0, "raid-new": 20.0},
        )

        self.assertEqual(
            text,
            "👤 Owlic 🐢 靈龜 ❤️2759/6000\n"
            "🆕 👤 澍星 🐟 龍鯰 ❤️2759/6000",
        )

    def test_formats_db_raid_metadata_as_compact_fragment_and_boss(self) -> None:
        text = format_raid_list([
            raid(
                "raid-db",
                boss_name="黃海",
                monster_code="mc1007_02",
                founder="billyzx",
                rarity=1,
                stage_id=2,
                hp=6000,
                hp_max=6000,
                source="db_raid",
            ),
        ], new_ids={"raid-db"})

        self.assertEqual(
            text,
            "🆕 👤 billyzx 綠海🟢🐙 ❤️6000/6000",
        )

    def test_six_star_fragment_uses_existing_shifted_rule(self) -> None:
        text = format_raid_list([
            raid(
                "raid-six",
                boss_name="龍鯰",
                monster_code="mc1012_02",
                rarity=6,
                stage_id=1,
                hp=270,
                hp_max=1200,
                source="db_raid",
            ),
        ])

        self.assertEqual(text, "👤 Founder 綠魚🟢🐟 ❤️270/1200")


if __name__ == "__main__":
    unittest.main()
