from __future__ import annotations

import json
import os
import tempfile
import time
from pathlib import Path

from ..domain.models import RaidEntry


class RaidDiff:
    """Track every observed Raid ID and persist it across restarts."""

    STATE_VERSION = 3

    def __init__(
        self,
        state_file: str | Path | None = None,
    ) -> None:
        self.state_file = Path(state_file) if state_file else None
        self.seen_ids: set[str] = set()
        self.first_seen: dict[str, float] = {}
        self.last_full_summary_at: float | None = None
        self.initialized = False
        self._load()

    def _load(self) -> None:
        if self.state_file is None or not self.state_file.is_file():
            return

        try:
            data = json.loads(self.state_file.read_text(encoding="utf-8"))
            version = data.get("version")
            values = data["seen_raid_ids"]
            if version not in {1, 2, self.STATE_VERSION}:
                raise ValueError("unsupported state version")
            if not isinstance(values, list) or not all(
                isinstance(value, str) and value for value in values
            ):
                raise ValueError("invalid seen_raid_ids")
            if version == 1:
                first_seen = {raid_id: 0.0 for raid_id in values}
            else:
                raw_first_seen = data["first_seen_at"]
                if not isinstance(raw_first_seen, dict):
                    raise ValueError("invalid first_seen_at")
                first_seen = {
                    raid_id: float(raw_first_seen[raid_id])
                    for raid_id in values
                }
            raw_summary_at = (
                data.get("last_full_summary_at")
                if version == self.STATE_VERSION
                else None
            )
            if raw_summary_at is not None and isinstance(raw_summary_at, bool):
                raise ValueError("invalid last_full_summary_at")
            last_full_summary_at = (
                float(raw_summary_at)
                if raw_summary_at is not None
                else None
            )
        except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError):
            # Missing and corrupt state both rebuild from the next valid snapshot.
            return

        self.seen_ids = set(values)
        self.first_seen = first_seen
        self.last_full_summary_at = last_full_summary_at
        self.initialized = True

    def _persist(
        self,
        seen_ids: set[str],
        first_seen: dict[str, float],
        last_full_summary_at: float | None,
    ) -> None:
        if self.state_file is None:
            return

        self.state_file.parent.mkdir(parents=True, exist_ok=True)
        payload = {
            "version": self.STATE_VERSION,
            "seen_raid_ids": sorted(seen_ids),
            "first_seen_at": {
                raid_id: first_seen[raid_id]
                for raid_id in sorted(seen_ids)
            },
            "last_full_summary_at": last_full_summary_at,
        }
        temporary_path: Path | None = None
        try:
            with tempfile.NamedTemporaryFile(
                mode="w",
                encoding="utf-8",
                dir=self.state_file.parent,
                prefix=f".{self.state_file.name}.",
                suffix=".tmp",
                delete=False,
            ) as temporary:
                temporary_path = Path(temporary.name)
                json.dump(payload, temporary, ensure_ascii=False, indent=2)
                temporary.write("\n")
                temporary.flush()
                os.fsync(temporary.fileno())
            os.replace(temporary_path, self.state_file)
        finally:
            if temporary_path is not None and temporary_path.exists():
                temporary_path.unlink()

    def mark_new(
        self,
        raids: list[RaidEntry],
        *,
        observed_at: float | None = None,
    ) -> dict[str, bool]:
        observed_at = time.time() if observed_at is None else observed_at
        current_ids = {
            raid.profound_id
            for raid in raids
            if raid.profound_id
        }

        if not self.initialized:
            first_seen = {
                raid_id: observed_at
                for raid_id in current_ids
            }
            self._persist(
                current_ids,
                first_seen,
                self.last_full_summary_at,
            )
            self.seen_ids = current_ids
            self.first_seen = first_seen
            self.initialized = True
            return {raid_id: False for raid_id in current_ids}

        new_ids = current_ids - self.seen_ids
        if new_ids:
            updated_seen_ids = self.seen_ids | new_ids
            updated_first_seen = dict(self.first_seen)
            updated_first_seen.update({
                raid_id: observed_at
                for raid_id in new_ids
            })
            self._persist(
                updated_seen_ids,
                updated_first_seen,
                self.last_full_summary_at,
            )
            self.seen_ids = updated_seen_ids
            self.first_seen = updated_first_seen

        return {
            raid_id: raid_id in new_ids
            for raid_id in current_ids
        }

    def first_seen_at(self, raid_id: str) -> float:
        return self.first_seen.get(raid_id, 0.0)

    def mark_full_summary(self, sent_at: float) -> None:
        self._persist(self.seen_ids, self.first_seen, sent_at)
        self.last_full_summary_at = sent_at
