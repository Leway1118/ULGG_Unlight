from __future__ import annotations

import unittest

from raid_bot.infrastructure.websocket_raid_source import (
    normalize_raid_support_list,
)


class WebSocketNormalizerTests(unittest.TestCase):
    def test_verified_payload_is_normalized(self) -> None:
        raids = normalize_raid_support_list([[
            {
                "prf_code": "SAatqyJNzbSa",
                "prf_name": "靈龜",
                "prf_mons": "mc1008_02",
                "prf_founder": "Owlic",
                "prf_hp": "2,759/6,000",
                "prf_member": "50/100",
                "prf_limit": 1785874217741,
            }
        ]])

        self.assertEqual(len(raids), 1)
        value = raids[0]
        self.assertEqual(value.profound_id, "SAatqyJNzbSa")
        self.assertEqual(value.boss_name, "靈龜")
        self.assertEqual(value.monster_code, "mc1008_02")
        self.assertEqual(value.founder, "Owlic")
        self.assertEqual((value.hp, value.hp_max), (2759, 6000))
        self.assertEqual((value.participant_count, value.member_limit), (50, 100))
        self.assertEqual(value.expires_at, 1785874217741)
        self.assertEqual(value.source, "websocket")


if __name__ == "__main__":
    unittest.main()
