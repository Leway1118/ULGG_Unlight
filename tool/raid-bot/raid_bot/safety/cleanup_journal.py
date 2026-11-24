"""Crash-conservative, append-once claim for one-shot Raid safety tests.

The journal is written and fsynced BEFORE any simulated join attempt. An
existing journal, even a verified-complete one, always blocks another run.
Only an operator may archive it after inspecting the account state.
"""
from __future__ import annotations

import hashlib
import json
import os
import secrets
import stat
import time
import uuid
from pathlib import Path
from typing import Any


class GateBlocked(RuntimeError):
    pass


PENDING = "PENDING_CLEANUP"
BLOCKED = "BLOCKED_PENDING_CLEANUP"
VERIFIED = "VERIFIED_COMPLETE"
VERIFIED_NO_RANK = "VERIFIED_CLEANUP_DATA_UNKNOWN"
FINAL_STATES = frozenset({BLOCKED, VERIFIED, VERIFIED_NO_RANK})


def _sync_parent(path: Path) -> None:
    if os.name == "nt":
        return  # Windows replace is atomic; directory fsync unavailable here.
    fd = os.open(str(path.parent), os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _write_all(fd: int, data: bytes) -> None:
    with os.fdopen(fd, "wb") as file:
        file.write(data)
        file.flush()
        os.fsync(file.fileno())


def _private_open_flags() -> int:
    return os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)


class PersistentCleanupJournal:
    def __init__(self, path: Path):
        self.path = Path(path)
        if not self.path.is_absolute():
            raise ValueError("journal_path_must_be_absolute")
        if self.path.is_symlink() or self.path.parent.is_symlink():
            raise GateBlocked("journal_symlink_forbidden")

    def _data(self) -> dict[str, Any]:
        try:
            if self.path.is_symlink():
                raise GateBlocked("journal_symlink_forbidden")
            obj = json.loads(self.path.read_text(encoding="utf-8"))
            if not isinstance(obj, dict) or obj.get("schema_version") != 1:
                raise GateBlocked("journal_invalid")
            if obj.get("state") not in {PENDING, *FINAL_STATES}:
                raise GateBlocked("journal_invalid_state")
            if not isinstance(obj.get("run_id"), str):
                raise GateBlocked("journal_invalid_run_id")
            return obj
        except (OSError, ValueError, TypeError) as exc:
            raise GateBlocked("journal_unreadable_blocks_operation") from exc

    def reserve(self, *, account_alias: str, raid_code: str) -> str:
        if not account_alias or not raid_code:
            raise ValueError("journal_account_and_target_required")
        # This deliberately refuses to run with an old (even completed)
        # journal; it is NOT a resumable or retryable worker.
        if self.path.is_symlink() or self.path.exists():
            raise GateBlocked("existing_journal_blocks_new_join")
        if not self.path.parent.is_dir():
            raise GateBlocked("journal_parent_must_preexist")
        salt = secrets.token_bytes(32)
        record = {
            "schema_version": 1,
            "run_id": uuid.uuid4().hex,
            "account_alias": account_alias,
            "target_fingerprint": hashlib.sha256(
                salt + raid_code.encode("utf-8")
            ).hexdigest(),
            "target_salt": salt.hex(),
            "state": PENDING,
            "timestamp_utc": int(time.time()),
            "join_budget": 1,
            "join_may_have_occurred": True,
            "cleanup_verified": False,
            "rank_complete": None,
        }
        # O_EXCL prevents two parallel invocations from sharing an attempt.
        try:
            fd = os.open(str(self.path), _private_open_flags(), 0o600)
        except FileExistsError as exc:
            raise GateBlocked("existing_journal_blocks_new_join") from exc
        payload = json.dumps(record, sort_keys=True, separators=(",", ":"))
        _write_all(fd, payload.encode("utf-8"))
        _sync_parent(self.path)
        return record["run_id"]

    def transition(
        self,
        run_id: str,
        *,
        state: str,
        cleanup_verified: bool,
        rank_complete: bool | None = None,
        players_count: int | None = None,
        error_class: str | None = None,
    ) -> None:
        if state not in FINAL_STATES:
            raise ValueError("invalid_final_journal_state")
        if state == BLOCKED and cleanup_verified:
            raise ValueError("blocked_cannot_confirm_cleanup")
        if state != BLOCKED and not cleanup_verified:
            raise ValueError("verified_state_requires_cleanup")
        obj = self._data()
        if obj["state"] != PENDING or obj["run_id"] != run_id:
            raise GateBlocked("journal_not_pending_for_this_run")
        obj.update({
            "state": state,
            "cleanup_verified": cleanup_verified,
            "rank_complete": rank_complete,
            "players_count": players_count,
            "error_class": error_class,
            "updated_utc": int(time.time()),
        })
        # Atomic replace; no automatic removal, reset or replay.
        tmp = self.path.with_name(self.path.name + "." + run_id + ".tmp")
        fd = os.open(str(tmp), _private_open_flags(), 0o600)
        try:
            data = json.dumps(obj, sort_keys=True, separators=(",", ":"))
            _write_all(fd, data.encode("utf-8"))
            os.replace(str(tmp), str(self.path))
            _sync_parent(self.path)
        finally:
            if tmp.exists():
                tmp.unlink()

    def read_public_state(self) -> dict[str, Any]:
        obj = self._data()
        return {
            "run_id": obj["run_id"],
            "state": obj["state"],
            "account_alias": obj["account_alias"],
            "cleanup_verified": obj["cleanup_verified"],
            "rank_complete": obj["rank_complete"],
            "players_count": obj.get("players_count"),
        }
