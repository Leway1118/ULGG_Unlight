from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path

from ..domain.models import RaidEntry


class RaidDiff:
    """Track every observed Raid ID and persist it across restarts."""

    STATE_VERSION = 1

    def __init__(
        self,
        state_file: str | Path | None = None,
    ) -> None:
        self.state_file = Path(state_file) if state_file else None
        self.seen_ids: set[str] = set()
        self.initialized = False
        self._load()

    def _load(self) -> None:
        if self.state_file is None or not self.state_file.is_file():
            return

        try:
            data = json.loads(self.state_file.read_text(encoding="utf-8"))
            values = data["seen_raid_ids"]
            if data.get("version") != self.STATE_VERSION:
                raise ValueError("unsupported state version")
            if not isinstance(values, list) or not all(
                isinstance(value, str) and value for value in values
            ):
                raise ValueError("invalid seen_raid_ids")
        except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError):
            # Missing and corrupt state both rebuild from the next valid snapshot.
            return

        self.seen_ids = set(values)
        self.initialized = True

    def _persist(self, seen_ids: set[str]) -> None:
        if self.state_file is None:
            return

        self.state_file.parent.mkdir(parents=True, exist_ok=True)
        payload = {
            "version": self.STATE_VERSION,
            "seen_raid_ids": sorted(seen_ids),
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
    ) -> dict[str, bool]:
        current_ids = {
            raid.profound_id
            for raid in raids
            if raid.profound_id
        }

        if not self.initialized:
            self._persist(current_ids)
            self.seen_ids = current_ids
            self.initialized = True
            return {raid_id: False for raid_id in current_ids}

        new_ids = current_ids - self.seen_ids
        if new_ids:
            updated_seen_ids = self.seen_ids | new_ids
            self._persist(updated_seen_ids)
            self.seen_ids = updated_seen_ids

        return {
            raid_id: raid_id in new_ids
            for raid_id in current_ids
        }
