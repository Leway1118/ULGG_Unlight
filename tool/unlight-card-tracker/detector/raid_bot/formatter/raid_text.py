from __future__ import annotations

from ..domain.models import RaidEntry
from ..domain.monster_rules import (
    get_monster_info,
)


# 星色

STAR_COLOR = {
    1: "🟡",
    2: "🟢",
    3: "🔵",
    4: "🔴",
    5: "🟣",
    6: "⚪",
}



def get_star_color(
    raid: RaidEntry,
) -> str:

    return STAR_COLOR.get(
        raid.rarity,
        "❔",
    )



def format_short_name(
    raid: RaidEntry,
) -> str:
    """
    產生：

    🟡🐛☠️
    🟡🐢
    """

    color = get_star_color(
        raid
    )


    monster = get_monster_info(
        raid.monster_code
    )


    dead = (
        "☠️"
        if monster.dead
        else ""
    )


    return (
        f"{color}"
        f"{monster.icon}"
        f"{dead}"
    )



def format_raid_line(
    raid: RaidEntry,
) -> str:
    """
    單行：

    Boss名  顏色圖示
    """

    boss = (
        raid.boss_name
        or "未知"
    )


    return (
        f"{boss:<8}"
        f"{format_short_name(raid)}"
    )



def format_raid_list(
    raids: list[RaidEntry],
) -> str:

    if not raids:

        return "目前沒有 Raid"


    lines = [
        "🌀 Raid List",
        "",
    ]


    for raid in raids:

        lines.append(
            format_raid_line(
                raid
            )
        )


    return "\n".join(
        lines
    )