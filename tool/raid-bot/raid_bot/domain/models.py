from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class RaidStatus:
    """
    Raid 狀態效果

    例如：
    poison
    huin
    curse
    """

    raw_type: str

    # 基礎類型
    # poison2 -> poison
    base_type: str

    # 狀態等級
    # poison2 -> 2
    level: int | None = None

    # 特殊數值
    # curse 使用層數
    value: int | None = None

    # 一般狀態使用結束時間
    expires_at: int | None = None


@dataclass(frozen=True)
class RaidPlayer:
    """
    Raid 排名玩家資料
    """

    rank: int

    name: str

    point: int

    damage: int

    is_me: bool = False


@dataclass(frozen=True)
class RaidEntry:
    """
    正規化後 Raid 資料

    不直接暴露遊戲 row
    """

    profound_id: str

    monster_code: str | None

    monster_id: int | None

    boss_name: str | None

    founder: str | None

    rarity: int | None

    level: int | None

    map_id: int | None

    stage_id: int | None

    hp: int | None

    hp_max: int | None

    member_limit: int | None

    participant_count: int

    pass_code: str | None

    expires_at: int | None

    already_joined: bool

    my_damage: int | None

    my_point: int | None

    treasure_level: int | None = None

    # RAID_RESEARCH_MAP_INDEX_FIELDS_V1
    # Keep protocol-1.4 runtime placement separate from legacy map.
    map_index: int | None = None
    pos_index: int | None = None

    whirlpool_tier: str | None = None

    # RAID_ENRICHMENT_SCHEMA14_V3
    # Raw protocol-1.4 reward rows.
    # Interpretation is intentionally deferred.
    reward_items: tuple[
        dict[str, Any],
        ...,
    ] = field(
        default_factory=tuple
    )


    statuses: tuple[
        RaidStatus,
        ...
    ] = field(
        default_factory=tuple
    )


    players: tuple[
        RaidPlayer,
        ...
    ] = field(
        default_factory=tuple
    )


    source: str = "unknown"


    raw: dict[str, Any] = field(
        default_factory=dict,
        compare=False,
        repr=False,
    )


    @property
    def hp_ratio(self) -> float | None:
        """
        HP 百分比
        """

        if (
            self.hp is None
            or self.hp_max is None
            or self.hp_max <= 0
        ):
            return None

        return self.hp / self.hp_max



@dataclass(frozen=True)
class RawRaidSnapshot:
    """
    Reader 輸出的原始快照
    """

    captured_at: float

    source: str

    raids: tuple[
        RaidEntry,
        ...

    ]

    connected: bool = False

    error: str | None = None
