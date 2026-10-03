from __future__ import annotations

from dataclasses import dataclass
from enum import Enum

from .models import RaidEntry


class RaidLifecycleStatus(str, Enum):
    ACTIVE = "active"
    DEFEATED = "defeated"
    EXPIRED = "expired"
    ENDED = "ended"


@dataclass(frozen=True)
class LastKnownRaid:
    raid: RaidEntry
    last_seen_at: float


@dataclass(frozen=True)
class EndedRaid:
    raid: RaidEntry
    status: RaidLifecycleStatus
    last_seen_at: float
    ended_at: float


@dataclass(frozen=True)
class RaidLifecycleView:
    updated_at: float | None
    active: tuple[LastKnownRaid, ...]
    recent_ended: tuple[EndedRaid, ...]

