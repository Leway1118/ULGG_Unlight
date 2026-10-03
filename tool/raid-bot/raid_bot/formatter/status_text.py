from __future__ import annotations

import time
from collections.abc import Iterable

from ..domain.models import RaidStatus


# STATUS_TEXT_RUNTIME_27_V1
STATUS_SHORT_CODES = {
    "poison": "毒",
    "poison2": "猛",
    "mahi": "麻",

    "atkB": "攻+",
    "atkD": "攻-",
    "defB": "防+",
    "defD": "防-",
    "movB": "移+",
    "movD": "移-",

    "bers": "狂",
    "stun": "暈",
    "huin": "封",
    "jikai": "壞",
    "immo": "不",
    "scare": "恐",
    "rege": "再",
    "bind": "咒",
    "chaos": "混",
    "stigma": "聖",
    "dbuff": "低",

    "sticka": "棍攻",
    "stickd": "棍防",

    "curse": "詛",
    "critical": "臨",
    "control": "操",
    "target": "標",
    "dark": "斷",
}

# Keep the order deterministic. The first-release regression order is:
# bers + defD9 + curse(9) -> 狂 防-9 詛9.
STATUS_ORDER = {
    status_type: index
    for index, status_type in enumerate((
        "huin",
        "bers",
        "mahi",
        "stun",

        "defD",
        "atkD",
        "movD",

        "scare",
        "poison",
        "poison2",
        "dbuff",

        "atkB",
        "defB",
        "movB",

        "chaos",
        "bind",
        "stigma",

        "sticka",
        "stickd",

        "curse",
        "jikai",
        "critical",
        "control",
        "dark",
        "immo",
        "rege",
        "target",
    ))
}


def _lookup_type(status: RaidStatus) -> str | None:
    if status.raw_type in STATUS_SHORT_CODES:
        return status.raw_type
    if status.base_type in STATUS_SHORT_CODES:
        return status.base_type
    return None


def _is_active(status: RaidStatus, now_ms: int) -> bool:
    if status.expires_at is None:
        return True

    # Most states use Unix milliseconds.
    # Some states (e.g. target) use a small in-game turn/count value.
    if status.expires_at < 1_000_000_000_000:
        return status.expires_at > 0

    return status.expires_at > now_ms


def _format_one(status: RaidStatus) -> str | None:
    status_type = _lookup_type(status)
    if status_type is None:
        return None
    text = STATUS_SHORT_CODES[status_type]
    if status_type == "poison2":
        return text
    number = status.value if status.value is not None else status.level
    return f"{text}{number}" if number is not None else text


def format_status_labels(
    statuses: Iterable[RaidStatus],
    *,
    now_ms: int | None = None,
    limit: int = 5,
    filter_expired: bool = True,
) -> list[str]:
    if limit <= 0:
        return []
    current_ms = int(time.time() * 1000) if now_ms is None else now_ms
    prepared = []
    for sequence, status in enumerate(statuses):
        status_type = _lookup_type(status)
        if status_type is None:
            continue
        if filter_expired and not _is_active(status, current_ms):
            continue
        text = _format_one(status)
        if text is not None:
            prepared.append((
                STATUS_ORDER.get(
                    status_type,
                    len(STATUS_ORDER),
                ),
                sequence,
                text,
            ))
    prepared.sort(key=lambda item: (item[0], item[1]))
    tokens = [item[2] for item in prepared]
    truncated = len(tokens) > limit
    labels = tokens[:limit]
    if truncated and labels:
        labels[-1] = f"{labels[-1]}..."
    return labels


def format_statuses(
    statuses: Iterable[RaidStatus],
    *,
    now_ms: int | None = None,
    limit: int = 5,
    filter_expired: bool = True,
) -> str:
    return " ".join(format_status_labels(
        statuses,
        now_ms=now_ms,
        limit=limit,
        filter_expired=filter_expired,
    ))
