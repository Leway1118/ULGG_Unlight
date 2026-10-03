from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from raid_bot.domain.status_rules import parse_raid_status, parse_raid_statuses
from raid_bot.formatter.raid_notification_text import (
    format_full_support_summary,
    format_new_raid_batch,
)
from raid_bot.formatter.raid_text import format_raid_list
from raid_bot.formatter.status_text import format_status_labels, format_statuses
from raid_bot.infrastructure.treasure_level_cache import TreasureLevelCache
from raid_bot.services.treasure_level_enricher import apply_metadata
from tests.raid_bot_test_support import raid


NOW_MS = 1_000_000
FUTURE_MS = NOW_MS + 60_000


def status(raw_type: str, turn: int = FUTURE_MS):
    parsed = parse_raid_status({"type": raw_type, "turn": turn})
    if parsed is None:
        raise AssertionError("test status did not parse")
    return parsed


class StatusFormatterTests(unittest.TestCase):
    def test_bers_uses_short_code(self) -> None:
        self.assertEqual(format_statuses([status("bers")], now_ms=NOW_MS), "狂")

    def test_huin_uses_short_code(self) -> None:
        self.assertEqual(format_statuses([status("huin")], now_ms=NOW_MS), "封")

    def test_first_release_short_code_mapping(self) -> None:
        expected = {
            "poison": "毒",
            "poison2": "猛毒",
            "atkB": "攻+",
            "atkD": "攻-",
            "defB": "防+",
            "defD": "防-",
            "movB": "移+",
            "movD": "移-",
            "bind": "咒",
            "dbuff": "弱",
            "stun": "暈",
            "immo": "不",
            "rege": "再",
            "dark": "斷",
            "chaos": "混",
            "critical": "臨",
        }
        for raw_type, short_code in expected.items():
            with self.subTest(raw_type=raw_type):
                self.assertEqual(
                    format_statuses([status(raw_type)], now_ms=NOW_MS),
                    short_code,
                )

    def test_type_suffix_becomes_level(self) -> None:
        parsed = status("defD9")
        self.assertEqual(parsed.base_type, "defD")
        self.assertEqual(parsed.level, 9)
        self.assertEqual(format_statuses([parsed], now_ms=NOW_MS), "防-9")

    def test_curse_turn_becomes_value(self) -> None:
        parsed = status("curse", 9)
        self.assertEqual(parsed.value, 9)
        self.assertIsNone(parsed.expires_at)
        self.assertEqual(format_statuses([parsed], now_ms=NOW_MS), "詛9")

    def test_multiple_statuses_have_stable_compact_order(self) -> None:
        statuses = [status("curse", 9), status("defD9"), status("bers")]
        self.assertEqual(
            format_status_labels(statuses, now_ms=NOW_MS),
            ["狂", "防-9", "詛9"],
        )
        self.assertEqual(
            format_statuses(statuses, now_ms=NOW_MS),
            "狂 防-9 詛9",
        )

    def test_more_than_five_statuses_are_truncated(self) -> None:
        statuses = parse_raid_statuses([
            {"type": "bers", "turn": FUTURE_MS},
            {"type": "defD9", "turn": FUTURE_MS},
            {"type": "curse", "turn": 9},
            {"type": "poison", "turn": FUTURE_MS},
            {"type": "dbuff", "turn": FUTURE_MS},
            {"type": "immo", "turn": FUTURE_MS},
        ])
        self.assertEqual(
            format_statuses(statuses, now_ms=NOW_MS),
            "狂 防-9 詛9 毒 弱...",
        )

    def test_expired_status_is_hidden(self) -> None:
        self.assertEqual(
            format_statuses([status("bers", NOW_MS)], now_ms=NOW_MS),
            "",
        )

    def test_snapshot_mode_preserves_production_like_statuses(self) -> None:
        statuses = parse_raid_statuses([
            {"type": "defD9", "turn": 1_786_370_712_593},
            {"type": "atkD9", "turn": 1_786_370_712_598},
            {"type": "movD9", "turn": 1_786_370_712_608},
            {"type": "poison", "turn": 1_786_370_712_628},
            {"type": "bers", "turn": 1_786_370_712_632},
            {"type": "dbuff", "turn": 1_786_370_669_292},
            {"type": "unknown", "turn": 1_786_370_712_640},
        ])
        after_all_deadlines = 1_786_370_800_000

        self.assertEqual(
            format_statuses(statuses, now_ms=after_all_deadlines),
            "",
        )
        self.assertEqual(
            format_statuses(
                statuses,
                now_ms=after_all_deadlines,
                filter_expired=False,
            ),
            "狂 攻-9 防-9 移-9 毒...",
        )

    def test_snapshot_mode_keeps_curse_value_and_order(self) -> None:
        statuses = parse_raid_statuses([
            {"type": "curse", "turn": 9},
            {"type": "defD9", "turn": NOW_MS},
            {"type": "bers", "turn": NOW_MS},
        ])

        self.assertEqual(
            format_status_labels(
                statuses,
                now_ms=NOW_MS + 1,
                filter_expired=False,
            ),
            ["狂", "防-9", "詛9"],
        )

    def test_notification_snapshot_and_summary_preserve_expired_statuses(self) -> None:
        target = raid(
            "raid-code",
            founder="伊迪歐特",
            hp=2290,
            hp_max=3500,
            statuses=tuple(parse_raid_statuses([
                {"type": "bers", "turn": NOW_MS},
                {"type": "atkD9", "turn": NOW_MS},
            ])),
        )

        self.assertEqual(
            format_new_raid_batch([target]),
            "🆕 伊迪歐特 🐢 靈龜 2290/3500｜狂 攻-9",
        )
        self.assertEqual(
            format_full_support_summary([target]),
            "📡 目前支援\n伊迪歐特 🐢 靈龜 2290/3500｜狂 攻-9",
        )

    def test_notification_without_status_is_unchanged(self) -> None:
        self.assertEqual(
            format_new_raid_batch([
                raid("raid-code", founder="伊迪歐特", hp=2290, hp_max=3500),
            ]),
            "🆕 伊迪歐特 🐢 靈龜 2290/3500",
        )

    def test_old_cache_without_state_is_safe(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "cache.json"
            path.write_text(json.dumps({
                "version": 1,
                "records": {
                    "raid-code": {
                        "treasure_level": 2091,
                        "profound_mons": "mc1008_02",
                    },
                },
            }), encoding="utf-8")
            cached = TreasureLevelCache(path).get("raid-code")

        self.assertIsNotNone(cached)
        enriched = apply_metadata(raid("raid-code"), cached or {})
        self.assertEqual(enriched.statuses, ())

    def test_raw_state_survives_cache_and_hydrates_raid(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "cache.json"
            cache = TreasureLevelCache(path)
            cache.put("raid-code", {
                "treasure_level": 2091,
                "state": [
                    {"type": "bers", "turn": FUTURE_MS},
                    {"type": "curse", "turn": 9},
                ],
            })
            cached = TreasureLevelCache(path).get("raid-code")

        self.assertIsNotNone(cached)
        assert cached is not None
        self.assertEqual(cached["state"][0]["type"], "bers")
        enriched = apply_metadata(raid("raid-code"), cached)
        self.assertEqual(
            format_statuses(enriched.statuses, now_ms=NOW_MS),
            "狂 詛9",
        )

    def test_raid_line_appends_status_after_hp(self) -> None:
        text = format_raid_list([
            raid(
                "raid-code",
                founder="伊迪歐特",
                hp=2290,
                hp_max=3500,
                statuses=(status("bers", 9_999_999_999_999),),
            ),
        ])
        self.assertEqual(
            text,
            "👤 伊迪歐特 🐢 靈龜 ❤️2290/3500｜狂",
        )


if __name__ == "__main__":
    unittest.main()
