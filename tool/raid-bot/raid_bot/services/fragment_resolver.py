from __future__ import annotations

import logging
from collections.abc import Iterable
from typing import Any

from ..domain.models import RaidEntry
from ..domain.reward_rules import RewardPrediction
from ..infrastructure.reward_cache import RewardCache
from ..infrastructure.treasure_reward_client import TreasureRewardClient


logger = logging.getLogger(__name__)
FRAGMENT_NAMES = {
    "記憶碎片": "記憶碎片（黃）",
    "记忆碎片": "記憶碎片（黃）",
    "時間碎片": "時間碎片（綠）",
    "时间碎片": "時間碎片（綠）",
    "靈魂碎片": "靈魂碎片（藍）",
    "灵魂碎片": "靈魂碎片（藍）",
    "生命碎片": "生命碎片（紅）",
    "死亡碎片": "死亡碎片（紫）",
}


def is_whirlpool_tier_four(value: Any) -> bool:
    """Match the Reward API's formal whirlpoolTier value for tier IV."""
    return (
        isinstance(value, str)
        and value.strip().replace("渦", "涡") == "涡IV"
    )


def is_whirlpool_tier_one(value: Any) -> bool:
    """Match the Reward API's formal whirlpoolTier value for tier I."""
    return (
        isinstance(value, str)
        and value.strip().replace("渦", "涡") == "涡I"
    )


def resolve_ranking_fragment(
    reward_record: dict[str, Any],
) -> RewardPrediction | None:
    ranking = reward_record.get("ranking")
    if not isinstance(ranking, list):
        return None

    matches: set[str] = set()
    for item in ranking:
        if not isinstance(item, dict):
            continue
        item_name = item.get("itemName")
        if not isinstance(item_name, str):
            continue
        normalized_name = "".join(item_name.split()).replace("的", "")
        for fragment_name, canonical_name in FRAGMENT_NAMES.items():
            if fragment_name in normalized_name:
                matches.add(canonical_name)

    if len(matches) != 1:
        return None
    reward_name = next(iter(matches))
    return RewardPrediction(
        reward_name=reward_name,
        quantity=None,
        confidence="api",
        reason="treasureLevel ranking reward",
    )


# RAID_BOOKMARK_REWARD_V1
BOOKMARK_REWARD_GROUPS = (
    "discovery",
    "participation",
    "ranking",
    "defeat",
)


def reward_record_has_bookmark(
    reward_record: dict[str, Any] | None,
) -> bool:
    """
    True when any Raid reward group contains a bookmark reward.

    Expected zh-TW example:
        記憶的書籤(R1)

    Also accept simplified / English / Japanese naming so cached
    locale differences do not silently lose the marker.
    """
    if not isinstance(reward_record, dict):
        return False

    for group in BOOKMARK_REWARD_GROUPS:
        items = reward_record.get(group)

        if not isinstance(items, list):
            continue

        for item in items:
            if not isinstance(item, dict):
                continue

            name = item.get("itemName")

            if not isinstance(name, str):
                continue

            normalized = "".join(
                name.strip().lower().split()
            )

            if (
                "書籤" in normalized
                or "书签" in normalized
                or "bookmark" in normalized
                or "栞" in normalized
            ):
                return True

    return False


class FragmentResolver:
    def __init__(
        self,
        client: TreasureRewardClient,
        cache: RewardCache,
        *,
        locale: str = "zh-TW",
    ) -> None:
        self.client = client
        self.cache = cache
        self.locale = locale

    def get_reward_record(
        self,
        treasure_level: int | None,
    ) -> dict[str, Any] | None:
        if treasure_level is None:
            return None

        records = self.cache.get_many([treasure_level])
        record = records.get(treasure_level)

        if record is not None:
            return record

        fetched = self.client.query(
            [treasure_level],
            locale=self.locale,
        )
        if not fetched:
            return None

        self.cache.put_many(fetched)
        return fetched.get(treasure_level)

    def resolve_many(
        self,
        raids: Iterable[RaidEntry],
    ) -> dict[str, RewardPrediction]:
        raids = list(raids)
        levels = {
            raid.treasure_level
            for raid in raids
            if raid.treasure_level is not None
        }
        if not levels:
            return {}

        try:
            records = self.cache.get_many(levels)
            missing = levels - records.keys()
            if missing:
                fetched = self.client.query(missing, locale=self.locale)
                if fetched:
                    self.cache.put_many(fetched)
                    records.update(fetched)
        except Exception as error:
            logger.warning(
                "Raid reward resolution failed (%s); using legacy fallback",
                type(error).__name__,
            )
            return {}

        resolved: dict[str, RewardPrediction] = {}
        for raid in raids:
            if raid.treasure_level is None:
                continue
            record = records.get(raid.treasure_level)
            if record is None:
                continue
            prediction = resolve_ranking_fragment(record)
            if prediction is not None:
                resolved[raid.profound_id] = prediction
        return resolved

    def has_bookmark(self, raid: RaidEntry) -> bool:
        """
        Check cached Reward API metadata only.

        resolve_many() is called before this during notification flush,
        so this does not trigger a second HTTP request.
        """
        if raid.treasure_level is None:
            return False

        record = self.cache.get_many(
            [raid.treasure_level]
        ).get(
            raid.treasure_level
        )

        return reward_record_has_bookmark(
            record
        )

    def is_raid4(self, raid: RaidEntry) -> bool:
        """Return true only when cached Reward API metadata confirms tier IV."""
        if raid.treasure_level is None:
            return False
        record = self.cache.get_many([raid.treasure_level]).get(
            raid.treasure_level
        )
        return bool(
            record
            and is_whirlpool_tier_four(record.get("whirlpoolTier"))
        )

    def is_raid1(self, raid: RaidEntry) -> bool:
        """Return true only when cached Reward API metadata confirms tier I."""
        if raid.treasure_level is None:
            return False
        record = self.cache.get_many([raid.treasure_level]).get(
            raid.treasure_level
        )
        return bool(
            record
            and is_whirlpool_tier_one(record.get("whirlpoolTier"))
        )
