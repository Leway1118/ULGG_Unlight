from __future__ import annotations

from ..domain.models import RaidEntry
from ..domain.monster_rules import get_monster_info


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


def _format_pair(
    current: int | None,
    maximum: int | None,
) -> str:
    if current is None or maximum is None:
        return "unknown"
    return f"{current:,}/{maximum:,}"


def format_raid_line(raid: RaidEntry) -> str:
    boss = raid.boss_name or "未知"
    if raid.source == "websocket":
        hp = _format_pair(raid.hp, raid.hp_max)
        members = _format_pair(
            raid.participant_count,
            raid.member_limit,
        )
        founder = raid.founder or "未知"
        return (
            f"NEW {boss} | HP {hp} | "
            f"Members {members} | Founder {founder}"
        )
    return f"{boss:<8}{format_short_name(raid)}"


def format_raid_list(raids: list[RaidEntry]) -> str:
    if not raids:
        return "目前沒有 Raid"
    lines = ["🌀 Raid List", ""]
    lines.extend(format_raid_line(raid) for raid in raids)
    return "\n".join(lines)
