from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from threading import Lock
from typing import Any


class TreasureLevelCache:
    VERSION = 1
    FIELDS = (
        "profound_id",
        "profound_mons",
        "name_tcn",
        "profound_founder",
        "treasure_level",
        "rarity",
        "whirlpoolTier",
        "level",
        "map",
        "stage",
        "state",
    )

    def __init__(self, path: str | Path) -> None:
        self.path = Path(path)
        self._records: dict[str, dict[str, Any]] = {}
        self._lock = Lock()
        self._load()

    def _normalize(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None
        treasure_level = value.get("treasure_level")
        if treasure_level is None or isinstance(treasure_level, bool):
            return None
        try:
            treasure_level = int(treasure_level)
        except (TypeError, ValueError):
            return None
        normalized = {field: value.get(field) for field in self.FIELDS}
        normalized["treasure_level"] = treasure_level
        state = value.get("state")
        if isinstance(state, list):
            normalized["state"] = [
                {
                    "type": item.get("type"),
                    "turn": item.get("turn"),
                }
                for item in state
                if isinstance(item, dict)
                and isinstance(item.get("type"), str)
                and item.get("type").strip()
            ]
        else:
            # None distinguishes an old cache entry from a confirmed empty state.
            normalized["state"] = None
        return normalized

    def _load(self) -> None:
        if not self.path.is_file():
            return
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
            if (
                not isinstance(payload, dict)
                or payload.get("version") != self.VERSION
                or not isinstance(payload.get("records"), dict)
            ):
                raise ValueError
            records = {
                key: normalized
                for key, value in payload["records"].items()
                if isinstance(key, str)
                and key
                and (normalized := self._normalize(value)) is not None
            }
        except (OSError, ValueError, TypeError, json.JSONDecodeError):
            return
        self._records = records

    def get(self, prf_code: str) -> dict[str, Any] | None:
        with self._lock:
            record = self._records.get(prf_code)
            return dict(record) if record is not None else None

    def put(self, prf_code: str, metadata: dict[str, Any]) -> None:
        normalized = self._normalize(metadata)
        if not prf_code or normalized is None:
            return
        with self._lock:
            self._records[prf_code] = normalized
            self._persist()

    def _persist(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary_path: Path | None = None
        try:
            with tempfile.NamedTemporaryFile(
                mode="w",
                encoding="utf-8",
                dir=self.path.parent,
                prefix=f".{self.path.name}.",
                suffix=".tmp",
                delete=False,
            ) as temporary:
                temporary_path = Path(temporary.name)
                json.dump(
                    {
                        "version": self.VERSION,
                        "records": self._records,
                    },
                    temporary,
                    ensure_ascii=False,
                    indent=2,
                )
                temporary.write("\n")
                temporary.flush()
                os.fsync(temporary.fileno())
            os.replace(temporary_path, self.path)
        finally:
            if temporary_path is not None and temporary_path.exists():
                temporary_path.unlink()
