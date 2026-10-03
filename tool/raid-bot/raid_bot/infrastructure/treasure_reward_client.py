from __future__ import annotations

import logging
from collections.abc import Callable, Iterable
from typing import Any

import requests


logger = logging.getLogger(__name__)
DEFAULT_REWARD_API_URL = "https://www.ulrmap.wiki/api/raid-rewards/query"
REWARD_GROUPS = ("discovery", "participation", "ranking", "defeat")
REWARD_ITEM_FIELDS = (
    "itemName",
    "quantity",
    "textColor",
    "textBold",
    "rankMin",
    "rankMax",
)


def _normalize_reward_items(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        return []
    return [
        {
            key: item[key]
            for key in REWARD_ITEM_FIELDS
            if key in item
        }
        for item in value
        if isinstance(item, dict)
    ]


def normalize_reward_record(value: Any) -> dict[str, Any] | None:
    """Keep only the documented reward API fields."""
    if not isinstance(value, dict):
        return None
    level = value.get("treasureLevel")
    if isinstance(level, bool):
        return None
    try:
        level = int(level)
    except (TypeError, ValueError):
        return None

    return {
        "treasureLevel": level,
        "bossDisplayName": value.get("bossDisplayName"),
        "rarity": value.get("rarity"),
        "mapLevel": value.get("mapLevel"),
        "whirlpoolTier": value.get("whirlpoolTier"),
        **{
            group: _normalize_reward_items(value.get(group))
            for group in REWARD_GROUPS
        },
    }


class TreasureRewardClient:
    def __init__(
        self,
        endpoint: str = DEFAULT_REWARD_API_URL,
        *,
        timeout_seconds: float = 10.0,
        post: Callable[..., Any] = requests.post,
    ) -> None:
        self.endpoint = endpoint
        self.timeout_seconds = timeout_seconds
        self._post = post

    def query(
        self,
        levels: Iterable[int],
        *,
        locale: str = "zh-TW",
    ) -> dict[int, dict[str, Any]]:
        requested: set[int] = set()
        for level in levels:
            if isinstance(level, bool):
                continue
            try:
                normalized_level = int(level)
            except (TypeError, ValueError):
                continue
            if normalized_level >= 0:
                requested.add(normalized_level)
        if not requested:
            return {}

        try:
            response = self._post(
                self.endpoint,
                json={
                    "levels": sorted(requested),
                    "locale": locale,
                },
                timeout=self.timeout_seconds,
                headers={"User-Agent": "ULGG-Raid-Bot/0.2"},
            )
            if response.status_code != 200:
                logger.warning(
                    "Treasure Reward API returned HTTP %s",
                    response.status_code,
                )
                return {}
            payload = response.json()
            if not isinstance(payload, list):
                raise ValueError("reward response must be a list")
        except (requests.RequestException, TypeError, ValueError) as error:
            logger.warning(
                "Treasure Reward API unavailable (%s)",
                type(error).__name__,
            )
            return {}

        result: dict[int, dict[str, Any]] = {}
        for value in payload:
            record = normalize_reward_record(value)
            if record is None:
                continue
            level = record["treasureLevel"]
            if level in requested:
                result[level] = record
        return result
