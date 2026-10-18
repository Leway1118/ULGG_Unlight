from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from raid_bot.formatter.raid_text import format_raid_list
from raid_bot.infrastructure.reward_cache import RewardCache
from raid_bot.services.fragment_resolver import (
    FragmentResolver,
    resolve_ranking_fragment,
)
from tests.raid_bot_test_support import raid


def reward_record(level: int, item_name: str) -> dict:
    return {
        "treasureLevel": level,
        "bossDisplayName": "M10 海",
        "rarity": 1,
        "mapLevel": 5,
        "whirlpoolTier": "渦II/III",
        "discovery": [],
        "participation": [],
        "ranking": [
            {"itemName": item_name, "quantity": 2, "rankMin": 1, "rankMax": 10},
            {"itemName": item_name, "quantity": 1, "rankMin": 11, "rankMax": 30},
        ],
        "defeat": [],
    }


class FakeClient:
    def __init__(self, records: dict[int, dict]) -> None:
        self.records = records
        self.calls: list[tuple[set[int], str]] = []

    def query(self, levels, *, locale: str):
        levels = set(levels)
        self.calls.append((levels, locale))
        return {
            level: self.records[level]
            for level in levels
            if level in self.records
        }


class FragmentResolverTests(unittest.TestCase):
    def test_ranking_fragment_supports_traditional_and_api_sample_names(self) -> None:
        self.assertEqual(
            resolve_ranking_fragment(
                reward_record(2161, "死亡的碎片")
            ).reward_name,
            "死亡碎片（紫）",
        )
        self.assertEqual(
            resolve_ranking_fragment(
                reward_record(2162, "時間碎片")
            ).reward_name,
            "時間碎片（綠）",
        )

    def test_cache_misses_are_queried_once_as_a_batch(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            cache = RewardCache(Path(directory) / "cache.json")
            client = FakeClient({
                2161: reward_record(2161, "死亡碎片"),
                2162: reward_record(2162, "時間碎片"),
            })
            resolver = FragmentResolver(client, cache)
            raids = [
                raid("a", treasure_level=2161),
                raid("b", treasure_level=2162),
                raid("c", treasure_level=2161),
            ]

            result = resolver.resolve_many(raids)

            self.assertEqual(client.calls, [({2161, 2162}, "zh-TW")])
            self.assertEqual(result["a"].reward_name, "死亡碎片（紫）")
            self.assertEqual(result["b"].reward_name, "時間碎片（綠）")

            client.calls.clear()
            resolver.resolve_many(raids)
            self.assertEqual(client.calls, [])

    def test_api_reward_overrides_legacy_and_missing_api_uses_fallback(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            cache = RewardCache(Path(directory) / "cache.json")
            client = FakeClient({2161: reward_record(2161, "死亡碎片")})
            resolver = FragmentResolver(client, cache)
            target = raid(
                "raid-id",
                boss_name="黃海",
                monster_code="mc1007_02",
                rarity=1,
                stage_id=2,
                treasure_level=2161,
            )

            resolved = resolver.resolve_many([target])
            self.assertEqual(
                format_raid_list([target], reward_predictions=resolved),
                "👤 Founder 紫海🟣🐙 ❤️2759/6000",
            )

            missing = raid(
                "missing",
                boss_name="黃海",
                monster_code="mc1007_02",
                rarity=1,
                stage_id=2,
                treasure_level=9999,
            )
            unresolved = resolver.resolve_many([missing])
            self.assertEqual(
                format_raid_list([missing], reward_predictions=unresolved),
                "👤 Founder 綠海🟢🐙 ❤️2759/6000",
            )


if __name__ == "__main__":
    unittest.main()
