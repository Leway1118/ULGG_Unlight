from __future__ import annotations

from collections.abc import Mapping

from ..domain.models import RaidEntry
from ..domain.monster_rules import get_monster_icon, get_monster_short_name
from ..domain.reward_rules import RewardPrediction, predict_fragment
from .fragment_display import get_fragment_display
from .status_text import format_statuses


def format_notification_raid_line(
    raid: RaidEntry,
    *,
    reward_prediction: RewardPrediction | None = None,
    has_bookmark: bool = False,
) -> str:
    founder = raid.founder or "未知"
    boss = raid.boss_name or "未知"
    # RAID_BOSS_ICON_FALLBACK_STAGE_PLACEHOLDER_V2_3
    monster_icon = get_monster_icon(raid.monster_code, boss)
    monster_short = get_monster_short_name(raid.monster_code, boss)

    # RAID_NOTIFICATION_DEAD_SKULL_V1
    dead_icon = (
        "☠️"
        if raid.hp is not None and raid.hp <= 0
        else ""
    )

    prediction = reward_prediction or predict_fragment(raid)
    fragment = get_fragment_display(prediction)
    if fragment is not None:
        short_name = monster_short or boss
        raid_text = (
            f"{fragment.short_name}{short_name}"
            f"{fragment.icon}{monster_icon}{dead_icon}"
        )
    else:
        # Keep a dedicated fragment placeholder while still showing
        # the historical Boss short name/icon. Stage Sync later turns
        # ❓海🐙 into e.g. 紅海🔴🐙. Preserve ☠️ when already dead.
        if monster_short is not None and monster_icon != "❓":
            raid_text = f"❓{monster_short}{monster_icon}{dead_icon}"
        else:
            raid_text = f"❓{dead_icon} {boss}"
    hp_text = (
        f" {raid.hp}/{raid.hp_max}"
        if raid.hp is not None and raid.hp_max is not None
        else ""
    )
    # RAID_NOTIFICATION_RARITY_GT1_V1
    # 1★ keeps the normal compact display.
    # Any known rarity above 1 is explicitly marked.
    rarity_suffix = (
        f"｜✨{raid.rarity}★"
        if raid.rarity is not None
        and raid.rarity > 1
        else ""
    )
    # These statuses are the state snapshot captured by enrichment. Preserve
    # that snapshot across the notification batch delay instead of expiring it
    # again at formatting time.
    status_text = format_statuses(raid.statuses, filter_expired=False)
    status_suffix = f"｜{status_text}" if status_text else ""
    return f"{founder} {raid_text}{hp_text}{rarity_suffix}{status_suffix}"


def format_new_raid_batch(
    raids: list[RaidEntry],
    *,
    reward_predictions: Mapping[str, RewardPrediction] | None = None,
    bookmark_flags: Mapping[str, bool] | None = None,
) -> str:
    if not raids:
        return ""
    predictions = reward_predictions or {}
    bookmarks = bookmark_flags or {}

    lines = [
        format_notification_raid_line(
            raid,
            reward_prediction=predictions.get(raid.profound_id),
            has_bookmark=bookmarks.get(raid.profound_id, False),
        )
        for raid in raids
    ]
    if len(lines) == 1:
        return f"🆕 {lines[0]}"
    return "\n".join([
        f"🆕 新增 {len(lines)} 個公開渦",
        *lines,
    ])


def format_full_support_summary(
    raids: list[RaidEntry],
    *,
    reward_predictions: Mapping[str, RewardPrediction] | None = None,
    bookmark_flags: Mapping[str, bool] | None = None,
) -> str:
    predictions = reward_predictions or {}
    bookmarks = bookmark_flags or {}

    lines = [
        format_notification_raid_line(
            raid,
            reward_prediction=predictions.get(raid.profound_id),
            has_bookmark=bookmarks.get(raid.profound_id, False),
        )
        for raid in raids
    ]
    return "\n".join(["📡 目前支援", *lines])
