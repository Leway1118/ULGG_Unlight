from __future__ import annotations

import unittest

from raid_bot.domain.reward_rules import RewardPrediction
from raid_bot.formatter.fragment_display import get_fragment_display


class FragmentDisplayTests(unittest.TestCase):
    def test_maps_verified_fragment_names_to_compact_discord_values(self) -> None:
        cases = {
            "記憶碎片（黃）": ("黃", "🟡"),
            "時間碎片（綠）": ("綠", "🟢"),
            "靈魂碎片（藍）": ("藍", "🔵"),
            "生命碎片（紅）": ("紅", "🔴"),
            "死亡碎片（紫）": ("紫", "🟣"),
        }
        for reward_name, expected in cases.items():
            with self.subTest(reward_name=reward_name):
                display = get_fragment_display(RewardPrediction(
                    reward_name=reward_name,
                    quantity=None,
                    confidence="partial",
                    reason="test",
                ))
                self.assertIsNotNone(display)
                self.assertEqual((display.short_name, display.icon), expected)

    def test_unknown_prediction_has_no_color_guess(self) -> None:
        display = get_fragment_display(RewardPrediction(
            reward_name="未知",
            quantity=None,
            confidence="unknown",
            reason="missing metadata",
        ))

        self.assertIsNone(display)


if __name__ == "__main__":
    unittest.main()
