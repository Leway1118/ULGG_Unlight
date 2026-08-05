from __future__ import annotations

import time
from collections import deque

from raid_bot.domain.models import RaidEntry, RawRaidSnapshot


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

    def notify_text(self, text: str) -> bool:
        self.messages.append(text)
        return True


def raid(
    raid_id: str,
    *,
    boss_name: str = "靈龜",
    hp: int = 2759,
    hp_max: int = 6000,
    members: int = 50,
    member_limit: int = 100,
) -> RaidEntry:
    return RaidEntry(
        profound_id=raid_id,
        monster_code="mc1008_02",
        monster_id=None,
        boss_name=boss_name,
        founder="Founder",
        rarity=None,
        level=None,
        map_id=None,
        stage_id=None,
        hp=hp,
        hp_max=hp_max,
        member_limit=member_limit,
        participant_count=members,
        pass_code=None,
        expires_at=1785874217741,
        already_joined=False,
        my_damage=None,
        my_point=None,
        source="websocket",
    )


def snapshot(*raids: RaidEntry) -> RawRaidSnapshot:
    return RawRaidSnapshot(
        captured_at=time.time(),
        source="websocket",
        raids=tuple(raids),
        connected=True,
    )
