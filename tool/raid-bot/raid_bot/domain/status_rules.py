from __future__ import annotations

import re
from typing import Any

from .models import RaidStatus


_STATUS_TYPE = re.compile(r"^([A-Za-z]+)(\d+)?$")


def _optional_int(value: Any) -> int | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def parse_raid_status(value: Any) -> RaidStatus | None:
    if not isinstance(value, dict):
        return None
    raw_type = value.get("type")
    if not isinstance(raw_type, str) or not raw_type.strip():
        return None
    raw_type = raw_type.strip()
    match = _STATUS_TYPE.fullmatch(raw_type)
    if match is None:
        base_type = raw_type
        level = None
    else:
        base_type = match.group(1)
        level = _optional_int(match.group(2))

    turn = _optional_int(value.get("turn"))
    if base_type == "curse":
        return RaidStatus(
            raw_type=raw_type,
            base_type=base_type,
            level=level,
            value=turn,
        )
    return RaidStatus(
        raw_type=raw_type,
        base_type=base_type,
        level=level,
        expires_at=turn,
    )


def parse_raid_statuses(value: Any) -> tuple[RaidStatus, ...]:
    if not isinstance(value, list):
        return ()
    statuses = (parse_raid_status(item) for item in value)
    return tuple(status for status in statuses if status is not None)
