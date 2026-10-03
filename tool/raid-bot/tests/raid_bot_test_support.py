from __future__ import annotations

import time
from collections import deque

from raid_bot.domain.models import RaidEntry, RaidStatus, RawRaidSnapshot


class FakeRaidSource:
    source_name = "websocket"
    push_driven = True

    def __init__(self, snapshots) -> None:
        self.snapshots = deque(snapshots)
        self.connected = True
        self.last_error = None

    async def read_snapshot(self) -> RawRaidSnapshot:
        return self.snapshots.popleft()


class FakeNotifier:
    def __init__(self) -> None:
        self.messages: list[str] = []
        self.raid4_mentions: list[bool] = []

    def notify_text(
        self,
        text: str,
        *,
        mention_raid4: bool = False,
    ) -> bool:
        self.messages.append(text)
        self.raid4_mentions.append(mention_raid4)
        return True


def raid(
    raid_id: str,
    *,
    boss_name: str = "靈龜",
    hp: int = 2759,
    hp_max: int = 6000,
    members: int = 50,
    member_limit: int = 100,
    monster_code: str = "mc1008_02",
    founder: str = "Founder",
    rarity: int | None = None,
    stage_id: int | None = None,
    source: str = "websocket",
    treasure_level: int | None = None,
    statuses: tuple[RaidStatus, ...] = (),
) -> RaidEntry:
    return RaidEntry(
        profound_id=raid_id,
        monster_code=monster_code,
        monster_id=None,
        boss_name=boss_name,
        founder=founder,
        rarity=rarity,
        level=None,
        map_id=None,
        stage_id=stage_id,
        hp=hp,
        hp_max=hp_max,
        member_limit=member_limit,
        participant_count=members,
        pass_code=None,
        expires_at=1785874217741,
        already_joined=False,
        my_damage=None,
        my_point=None,
        treasure_level=treasure_level,
        statuses=statuses,
        source=source,
    )


def snapshot(
    *raids: RaidEntry,
    captured_at: float | None = None,
) -> RawRaidSnapshot:
    return RawRaidSnapshot(
        captured_at=time.time() if captured_at is None else captured_at,
        source="websocket",
        raids=tuple(raids),
        connected=True,
    )
