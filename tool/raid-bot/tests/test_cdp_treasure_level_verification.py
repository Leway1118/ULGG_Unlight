from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path


PROBE_PATH = (
    Path(__file__).resolve().parents[1]
    / "research"
    / "verify_cdp_treasure_level.py"
)
SPEC = importlib.util.spec_from_file_location(
    "verify_cdp_treasure_level",
    PROBE_PATH,
)
assert SPEC is not None and SPEC.loader is not None
PROBE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PROBE)


class CdpTreasureLevelVerificationTest(unittest.TestCase):
    def test_report_counts_joined_and_unjoined_tl_combinations(self) -> None:
        report = PROBE.build_verification_report(
            {
                "ok": True,
                "captured_at": "2026-08-08T12:00:00Z",
                "raids": [
                    {
                        "profound_id": "joined-tl",
                        "treasure_level": 2161,
                        "already_joined": True,
                    },
                    {
                        "profound_id": "joined-no-tl",
                        "treasure_level": None,
                        "already_joined": True,
                    },
                    {
                        "profound_id": "unjoined-tl",
                        "pass": "fresh-code",
                        "founder": "Owlic",
                        "monster_code": "mc1008_02",
                        "treasure_level": 2079,
                        "already_joined": False,
                    },
                    {
                        "profound_id": "unjoined-no-tl",
                        "treasure_level": None,
                        "already_joined": False,
                    },
                ],
            }
        )

        self.assertEqual(
            report["stats"],
            {
                "total_raids": 4,
                "raids_with_tl": 2,
                "joined_with_tl": 1,
                "joined_without_tl": 1,
                "unjoined_with_tl": 1,
                "unjoined_without_tl": 1,
            },
        )
        self.assertEqual(
            [
                raid["profound_id"]
                for raid in report["unjoined_with_tl_cases"]
            ],
            ["unjoined-tl"],
        )

    def test_malformed_payload_is_rejected(self) -> None:
        with self.assertRaisesRegex(ValueError, "raids must be a list"):
            PROBE.build_verification_report(
                {"ok": True, "raids": {}}
            )

    def test_runtime_expression_is_read_only(self) -> None:
        expression = PROBE.READ_TREASURE_LEVEL_JS

        self.assertIn("Raid", expression)
        self.assertIn("raid_data", expression)
        self.assertIn("treasure_level", expression)
        self.assertNotIn("socket", expression)
        self.assertNotIn(".emit(", expression)
        for forbidden_event in (
            "raid_code_input",
            "db_raid_delete",
            "db_raid_reward",
            "raid_turn",
            "battle",
            "claim",
        ):
            self.assertNotIn(forbidden_event, expression)


if __name__ == "__main__":
    unittest.main()
