from __future__ import annotations

from dataclasses import dataclass
from enum import Enum

from .models import RaidEntry



class RaidEventType(str, Enum):

    NEW = "NEW"
    UPDATED = "UPDATED"
    REMOVED = "REMOVED"



@dataclass(frozen=True)
class RaidEvent:

    event_type: RaidEventType

    raid: RaidEntry

    previous: RaidEntry | None = None
    is_new: bool = False
    first_seen_at: float = 0.0
