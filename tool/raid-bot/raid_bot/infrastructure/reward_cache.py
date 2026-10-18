from __future__ import annotations

import json
import logging
import os
import tempfile
from collections.abc import Iterable, Mapping
from pathlib import Path
from threading import Lock
from typing import Any

from .treasure_reward_client import normalize_reward_record


logger = logging.getLogger(__name__)


class RewardCache:
    VERSION = 1

    def __init__(
        self,
        path: str | Path,
        *,
        locale: str = "zh-TW",
    ) -> None:
        self.path = Path(path)
        self.locale = locale
        self._records: dict[int, dict[str, Any]] = {}
        self._lock = Lock()
        self._load()

    def _load(self) -> None:
        if not self.path.is_file():
            return
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
            if (
                not isinstance(payload, dict)
                or payload.get("version") != self.VERSION
                or payload.get("locale") != self.locale
                or not isinstance(payload.get("rewards"), dict)
            ):
                raise ValueError("invalid reward cache")
            records: dict[int, dict[str, Any]] = {}
            for key, value in payload["rewards"].items():
                record = normalize_reward_record(value)
                if record is None or str(record["treasureLevel"]) != str(key):
                    continue
                records[record["treasureLevel"]] = record
            self._records = records
        except (OSError, TypeError, ValueError, json.JSONDecodeError):
            logger.warning("Ignored missing or corrupt Raid reward cache")

    def get_many(
        self,
        levels: Iterable[int],
    ) -> dict[int, dict[str, Any]]:
        with self._lock:
            return {
                level: dict(self._records[level])
                for level in set(levels)
                if level in self._records
            }

    def put_many(
        self,
        records: Mapping[int, dict[str, Any]],
    ) -> None:
        normalized: dict[int, dict[str, Any]] = {}
        for value in records.values():
            record = normalize_reward_record(value)
            if record is not None:
                normalized[record["treasureLevel"]] = record
        if not normalized:
            return

        with self._lock:
            self._records.update(normalized)
            try:
                self._persist()
            except OSError as error:
                logger.warning(
                    "Raid reward cache write failed (%s); continuing",
                    type(error).__name__,
                )

    def _persist(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        payload = {
            "version": self.VERSION,
            "locale": self.locale,
            "rewards": {
                str(level): self._records[level]
                for level in sorted(self._records)
            },
        }
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
                json.dump(payload, temporary, ensure_ascii=False, indent=2)
                temporary.write("\n")
                temporary.flush()
                os.fsync(temporary.fileno())
            os.replace(temporary_path, self.path)

            # Reward metadata is public data and is also read by raid_observer.php.
            # NamedTemporaryFile creates mode 0600, so restore web-readable mode
            # after every atomic cache replacement.
            os.chmod(self.path, 0o644)
        finally:
            if temporary_path is not None and temporary_path.exists():
                temporary_path.unlink()
