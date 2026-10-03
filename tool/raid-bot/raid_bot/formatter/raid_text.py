from __future__ import annotations

from collections.abc import Mapping, Set

from ..domain.models import RaidEntry
from ..domain.monster_rules import (
    get_monster_icon,
    get_monster_info,
    get_monster_short_name,
)
from ..domain.reward_rules import RewardPrediction, predict_fragment
from .fragment_display import get_fragment_display
from .status_text import format_statuses


STAR_COLOR = {
    1: "🟡",
    2: "🟢",
    3: "🔵",
    4: "🔴",
    5: "🟣",
    6: "⚪",
}


def get_star_color(raid: RaidEntry) -> str:
    return STAR_COLOR.get(raid.rarity, "❔")


def format_short_name(raid: RaidEntry) -> str:
    """Preserve the existing compact CDP Raid representation."""
    color = get_star_color(raid)
    monster = get_monster_info(raid.monster_code)
    dead = "☠️" if monster.dead else ""
    return f"{color}{monster.icon}{dead}"


def format_raid_line(
    raid: RaidEntry,
    *,
    is_new: bool = False,
    reward_prediction: RewardPrediction | None = None,
) -> str:
    prefix = "🆕 " if is_new else ""
    founder = raid.founder or "未知"
    boss = raid.boss_name or "未知"
    icon = get_monster_icon(raid.monster_code)
    prediction = reward_prediction or predict_fragment(raid)
    fragment = get_fragment_display(prediction)
    if fragment is not None:
        short_name = get_monster_short_name(raid.monster_code) or boss
        raid_text = f"{fragment.short_name}{short_name}{fragment.icon}{icon}"
    else:
        # Preserve the metadata-poor raid_support_list representation.
        raid_text = f"{icon} {boss}"
    hp_text = (
        f" ❤️{raid.hp}/{raid.hp_max}"
        if raid.hp is not None and raid.hp_max is not None
        else ""
    )
    status_text = format_statuses(raid.statuses)
    status_suffix = f"｜{status_text}" if status_text else ""

    return f"{prefix}👤 {founder} {raid_text}{hp_text}{status_suffix}"


def format_raid_list(
    raids: list[RaidEntry],
    *,
    new_ids: Set[str] | None = None,
    first_seen_at: Mapping[str, float] | None = None,
    reward_predictions: Mapping[str, RewardPrediction] | None = None,
) -> str:
    if not raids:
        return "目前沒有 Raid"
    new_ids = new_ids or set()
    first_seen_at = first_seen_at or {}
    reward_predictions = reward_predictions or {}

    unique_raids = {
        raid.profound_id: raid
        for raid in raids
    }
    ordered = sorted(
        unique_raids.values(),
        key=lambda raid: (
            raid.profound_id in new_ids,
            first_seen_at.get(raid.profound_id, 0.0),
        ),
    )
    lines = [
        format_raid_line(
            raid,
            is_new=raid.profound_id in new_ids,
            reward_prediction=reward_predictions.get(raid.profound_id),
        )
        for raid in ordered
    ]
    return "\n".join(lines)
