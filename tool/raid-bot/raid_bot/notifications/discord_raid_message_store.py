from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import Any


# RAID_DISCORD_DEATH_EDIT_STORE_V1
class DiscordRaidMessageStore:
    """
    Persist:
      raid_id -> Discord message_id / line indexes / dead line
      message_id -> latest full message content

    This allows death edits to survive Raid Bot restarts.
    """

    def __init__(
        self,
        path: str | Path,
        *,
        ttl_seconds: float = 7 * 24 * 60 * 60,
        stage_report_ttl_seconds: float = 24 * 60 * 60,
    ) -> None:
        self.path = Path(path)
        self.ttl_seconds = float(ttl_seconds)
        self.stage_report_ttl_seconds = float(stage_report_ttl_seconds)
        self.data = self._load()
        self.prune()

    @staticmethod
    def _empty() -> dict[str, Any]:
        return {
            "version": 2,
            "messages": {},
            "raids": {},
            "stage_reports": {},
        }

    def _load(self) -> dict[str, Any]:
        try:
            raw = json.loads(
                self.path.read_text(encoding="utf-8")
            )
        except (OSError, ValueError, TypeError):
            return self._empty()

        if not isinstance(raw, dict):
            return self._empty()

        messages = raw.get("messages")
        raids = raw.get("raids")
        stage_reports = raw.get("stage_reports")

        if not isinstance(messages, dict):
            messages = {}

        if not isinstance(raids, dict):
            raids = {}

        if not isinstance(stage_reports, dict):
            stage_reports = {}

        return {
            "version": 2,
            "messages": messages,
            "raids": raids,
            "stage_reports": stage_reports,
        }

    def _save(self) -> None:
        self.path.parent.mkdir(
            parents=True,
            exist_ok=True,
        )

        tmp = self.path.with_name(
            self.path.name + ".tmp"
        )

        tmp.write_text(
            json.dumps(
                self.data,
                ensure_ascii=False,
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )

        os.replace(
            tmp,
            self.path,
        )

    def prune(
        self,
        *,
        now: float | None = None,
    ) -> None:
        if now is None:
            now = time.time()

        cutoff = now - self.ttl_seconds
        stage_cutoff = now - self.stage_report_ttl_seconds
        raids = self.data["raids"]
        messages = self.data["messages"]
        stage_reports = self.data["stage_reports"]

        changed = False

        for raid_id in list(raids):
            row = raids.get(raid_id)

            if not isinstance(row, dict):
                raids.pop(raid_id, None)
                changed = True
                continue

            created_at = row.get("created_at")

            if (
                isinstance(created_at, (int, float))
                and created_at < cutoff
            ):
                raids.pop(raid_id, None)
                changed = True

        referenced = {
            str(row.get("message_id"))
            for row in raids.values()
            if isinstance(row, dict)
            and row.get("message_id")
        }

        for message_id in list(messages):
            if message_id not in referenced:
                messages.pop(message_id, None)
                changed = True

        for token in list(stage_reports):
            row = stage_reports.get(token)

            if not isinstance(row, dict):
                stage_reports.pop(token, None)
                changed = True
                continue

            created_at = row.get("created_at")

            if (
                not isinstance(created_at, (int, float))
                or created_at < stage_cutoff
            ):
                stage_reports.pop(token, None)
                changed = True

        if changed:
            self._save()

    def remember_message(
        self,
        *,
        message_id: str,
        content: str,
        raid_records: dict[str, dict[str, Any]],
    ) -> None:
        now = time.time()

        self.data["messages"][message_id] = {
            "content": content,
            "created_at": now,
        }

        for raid_id, record in raid_records.items():
            self.data["raids"][raid_id] = {
                "message_id": message_id,
                "line_indexes": list(
                    record.get("line_indexes", [])
                ),
                "dead_line": str(
                    record.get("dead_line", "")
                ),
                "dead": bool(
                    record.get("dead", False)
                ),
                "created_at": now,
            }

        self._save()

    # RAID_STAGE_SYNC_IMMEDIATE_DEFERRED_V2
    def remember_stage_report(
        self,
        *,
        token: str,
        stage: str,
        rarity: int,
        fragment: str,
    ) -> None:
        now = time.time()
        self.prune(now=now)

        self.data["stage_reports"][token] = {
            "stage": stage,
            "rarity": rarity,
            "fragment": fragment,
            "created_at": now,
            "updated_at": now,
        }
        self._save()

    def stage_report(
        self,
        token: str,
    ) -> dict[str, Any] | None:
        self.prune()
        row = self.data["stage_reports"].get(token)

        if not isinstance(row, dict):
            return None

        return dict(row)

    def forget_stage_report(
        self,
        token: str,
    ) -> None:
        reports = self.data.get("stage_reports")

        if not isinstance(reports, dict):
            return

        if token not in reports:
            return

        reports.pop(token, None)
        self._save()

    def death_target(
        self,
        raid_id: str,
    ) -> dict[str, Any] | None:
        row = self.data["raids"].get(raid_id)

        if not isinstance(row, dict):
            return None

        if row.get("dead") is True:
            return None

        message_id = row.get("message_id")

        if not isinstance(message_id, str):
            return None

        message = self.data["messages"].get(
            message_id
        )

        if not isinstance(message, dict):
            return None

        content = message.get("content")
        dead_line = row.get("dead_line")
        indexes = row.get("line_indexes")

        if not isinstance(content, str):
            return None

        if not isinstance(dead_line, str) or not dead_line:
            return None

        if not isinstance(indexes, list):
            return None

        return {
            "message_id": message_id,
            "content": content,
            "line_indexes": [
                int(value)
                for value in indexes
                if isinstance(value, int)
            ],
            "dead_line": dead_line,
        }

    # RAID_STAGE_SYNC_V1
    def fragment_target(
        self,
        raid_id: str,
    ) -> dict[str, Any] | None:
        row = self.data["raids"].get(raid_id)

        if not isinstance(row, dict):
            return None

        message_id = row.get("message_id")

        if not isinstance(message_id, str) or not message_id:
            return None

        message = self.data["messages"].get(message_id)

        if not isinstance(message, dict):
            return None

        content = message.get("content")
        indexes = row.get("line_indexes")
        dead_line = row.get("dead_line")

        if not isinstance(content, str):
            return None

        if not isinstance(indexes, list):
            return None

        if not isinstance(dead_line, str):
            dead_line = ""

        return {
            "message_id": message_id,
            "content": content,
            "line_indexes": [
                int(value)
                for value in indexes
                if isinstance(value, int)
            ],
            "dead_line": dead_line,
            "dead": bool(row.get("dead", False)),
            "fragment": row.get("fragment"),
        }

    def mark_fragment(
        self,
        raid_id: str,
        *,
        content: str,
        dead_line: str,
        fragment: str,
    ) -> None:
        row = self.data["raids"].get(raid_id)

        if not isinstance(row, dict):
            return

        message_id = row.get("message_id")

        if isinstance(message_id, str):
            message = self.data["messages"].get(message_id)

            if isinstance(message, dict):
                message["content"] = content

        row["dead_line"] = dead_line
        row["fragment"] = fragment
        row["updated_at"] = time.time()

        self._save()

    def mark_dead(
        self,
        raid_id: str,
        *,
        content: str,
    ) -> None:
        row = self.data["raids"].get(raid_id)

        if not isinstance(row, dict):
            return

        message_id = row.get("message_id")

        if isinstance(message_id, str):
            message = self.data["messages"].get(
                message_id
            )

            if isinstance(message, dict):
                message["content"] = content

        row["dead"] = True
        row["updated_at"] = time.time()

        self._save()
