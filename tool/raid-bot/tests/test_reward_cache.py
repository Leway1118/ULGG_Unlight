from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from raid_bot.infrastructure.reward_cache import RewardCache


def reward_record(level: int = 2161) -> dict:
    return {
        "treasureLevel": level,
        "bossDisplayName": "M10 龍鯰",
        "rarity": 1,
        "mapLevel": 5,
        "whirlpoolTier": "渦II/III",
        "discovery": [],
        "participation": [],
        "ranking": [{"itemName": "死亡碎片", "quantity": 2}],
        "defeat": [],
    }


class RewardCacheTests(unittest.TestCase):
    def test_persists_by_treasure_level_and_survives_restart(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "raid_reward_cache.json"
            cache = RewardCache(path, locale="zh-TW")
            cache.put_many({2161: reward_record()})

            payload = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual(payload["version"], 1)
            self.assertEqual(payload["locale"], "zh-TW")
            self.assertEqual(payload["rewards"]["2161"]["treasureLevel"], 2161)
            self.assertFalse(list(path.parent.glob("*.tmp")))

            restarted = RewardCache(path, locale="zh-TW")
            self.assertEqual(restarted.get_many([2161])[2161]["rarity"], 1)

    def test_corrupt_or_other_locale_cache_rebuilds_safely(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "raid_reward_cache.json"
            path.write_text("not-json", encoding="utf-8")
            self.assertEqual(RewardCache(path).get_many([2161]), {})

            path.write_text(json.dumps({
                "version": 1,
                "locale": "en",
                "rewards": {"2161": reward_record()},
            }), encoding="utf-8")
            self.assertEqual(
                RewardCache(path, locale="zh-TW").get_many([2161]),
                {},
            )


if __name__ == "__main__":
    unittest.main()
