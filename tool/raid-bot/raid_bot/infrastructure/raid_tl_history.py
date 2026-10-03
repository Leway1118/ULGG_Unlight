from __future__ import annotations

import json
from pathlib import Path
from datetime import datetime, timezone
from threading import Lock


class RaidTLHistoryWriter:
    def __init__(self, path: str | Path):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)

        self._lock = Lock()
        self._seen: set[str] = set()

        if self.path.exists():
            self._load_existing_ids()

    def _load_existing_ids(self) -> None:
        try:
            with self.path.open("r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if not line:
                        continue

                    try:
                        row = json.loads(line)
                    except json.JSONDecodeError:
                        continue

                    raid_id = row.get("public_raid_code")
                    if raid_id:
                        self._seen.add(str(raid_id))
        except OSError:
            pass

    def append(
        self,
        *,
        public_raid_code: str,
        metadata: dict,
    ) -> bool:
        if not public_raid_code:
            return False

        treasure_level = metadata.get("treasure_level")
        if treasure_level is None:
            return False

        with self._lock:
            if public_raid_code in self._seen:
                return False

            row = {
                "recorded_at": datetime.now(timezone.utc).isoformat(),
                "public_raid_code": public_raid_code,
                "treasure_level": treasure_level,
                "rarity": metadata.get("rarity"),
                "level": metadata.get("level"),
                "map": metadata.get("map"),
                "stage": metadata.get("stage"),
                "boss": (
                    metadata.get("bossDisplayName")
                    or metadata.get("name_tcn")
                    or metadata.get("boss_name")
                ),
                "monster_code": (
                    metadata.get("monster_code")
                    or metadata.get("profound_mons")
                ),
                "founder": (
                    metadata.get("founder")
                    or metadata.get("profound_founder")
                    or metadata.get("prf_founder")
                ),
            }

            try:
                with self.path.open("a", encoding="utf-8") as f:
                    f.write(
                        json.dumps(
                            row,
                            ensure_ascii=False,
                            separators=(",", ":"),
                        )
                        + "\n"
                    )
            except OSError:
                return False

            self._seen.add(public_raid_code)
            return True