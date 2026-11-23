"""POSIX-only, manager-owned account lock and audit ledger for OFFLINE fixtures.

The fixture worker must not possess write access to this state root.
Only a separate administrator using the manager identity may approve a
release. Every release requires independently reviewed evidence, and no
worker RPC is able to release. This is NOT a live-game authorization service.
"""
from __future__ import annotations

from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time

from raid_bot.safety.account_guard import account_key
from raid_bot.safety.cleanup_journal import (
    BLOCKED, PENDING, VERIFIED, VERIFIED_NO_RANK,
    GateBlocked, PersistentCleanupJournal,
    _sync_parent,
)

try:
    import fcntl
except ImportError:
    fcntl = None


def _canonical(obj):
    return json.dumps(obj, separators=(",", ":"), sort_keys=True).encode("utf-8")


class GatekeeperLedger:
    """A manager-owned ledger with an immutable-by-worker audit path.

    A release does NOT authorize real game access. The next permitted use of
    this ledger still goes through an independent approval and fixture-only
    runner. State-root ownership and process UID are enforced on POSIX.
    """

    def __init__(self, state_root: Path, *, steam_id: str, fixture_target: str):
        if os.name != "posix" or fcntl is None:
            raise GateBlocked("linux_manager_required")
        if not fixture_target.startswith("FIXTURE-ONLY-"):
            raise GateBlocked("only_synthetic_target_supported")
        raw = Path(state_root)
        if not raw.is_absolute():
            raise GateBlocked("manager_root_must_be_absolute")
        if raw.is_symlink() or any(p.is_symlink() for p in raw.parents):
            raise GateBlocked("manager_root_symlink_forbidden")
        try:
            self.root = raw.resolve(strict=True)
            mode = self.root.stat()
        except OSError as exc:
            raise GateBlocked("manager_state_unavailable") from exc
        if not stat.S_ISDIR(mode.st_mode):
            raise GateBlocked("manager_root_not_directory")
        if mode.st_uid != os.geteuid() or (mode.st_mode & 0o077):
            raise GateBlocked("manager_root_not_owner_private")
        key = account_key(steam_id)
        self.steam_id = steam_id
        self.fixture_target = fixture_target
        self.lock_path = self.root / (key + ".lock")
        self.journal = PersistentCleanupJournal(
            self.root / ("account-" + key + ".journal.json")
        )
        self.audit_path = self.root / (key + ".audit.jsonl")
        self.archive_dir = self.root / "archive"

    def _check_private_file(self, path: Path):
        if path.is_symlink():
            raise GateBlocked("ledger_symlink_forbidden")
        if not path.exists():
            return
        mode = path.stat()
        if (
            not stat.S_ISREG(mode.st_mode)
            or mode.st_uid != os.geteuid()
            or (mode.st_mode & 0o077)
        ):
            raise GateBlocked("manager_file_ownership_invalid")

    @contextmanager
    def _locked(self):
        self._check_private_file(self.lock_path)
        flags = os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0)
        fd = os.open(str(self.lock_path), flags, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX)
            self._check_private_file(self.journal.path)
            self._check_private_file(self.audit_path)
            yield
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    def _events(self):
        if not self.audit_path.exists():
            return []
        try:
            data = self.audit_path.read_bytes()
            if data and not data.endswith(b"\n"):
                raise GateBlocked("audit_partial_line")
            events = []
            previous = "GENESIS"
            for line in data.splitlines():
                row = json.loads(line)
                digest = row.pop("digest", None)
                if (
                    not isinstance(row, dict)
                    or row.get("prev") != previous
                    or not isinstance(digest, str)
                    or hashlib.sha256(_canonical(row)).hexdigest() != digest
                ):
                    raise GateBlocked("audit_chain_invalid")
                previous = digest
                events.append({**row, "digest": digest})
            return events
        except (OSError, ValueError, TypeError, AttributeError) as exc:
            raise GateBlocked("audit_corrupt_blocks_all") from exc

    def _append(self, event: str, **fields):
        events = self._events()
        record = {
            "event": event,
            "time_unix": int(time.time()),
            "prev": events[-1]["digest"] if events else "GENESIS",
            **fields,
        }
        record["digest"] = hashlib.sha256(_canonical(record)).hexdigest()
        data = _canonical(record) + b"\n"
        flags = os.O_WRONLY | os.O_APPEND | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0)
        fd = os.open(str(self.audit_path), flags, 0o600)
        with os.fdopen(fd, "ab") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        _sync_parent(self.audit_path)

    def _can_reserve(self, events):
        if self.journal.path.exists():
            raise GateBlocked("account_already_reserved")
        if not events:
            return
        # A crash after RELEASE_INTENT but before RELEASE_DONE leaves a
        # missing journal: this may NOT be treated as a fresh account.
        if events[-1]["event"] != "RELEASE_DONE":
            raise GateBlocked("unresolved_account_history")
        completed = events[-1].get("run_id")
        if not completed or not any(
            e["event"] == "RELEASE_INTENT" and e.get("run_id") == completed
            for e in events
        ):
            raise GateBlocked("release_audit_missing_intent")

    def reserve(self, *, account_alias: str, raid_code: str) -> str:
        if raid_code != self.fixture_target:
            raise GateBlocked("target_not_allowlisted")
        if not re.fullmatch(r"[A-Z][A-Z0-9_-]{2,47}", account_alias):
            raise GateBlocked("account_alias_invalid")
        with self._locked():
            events = self._events()
            self._can_reserve(events)
            # Intent FIRST: a crash here leaves an audit marker that blocks
            # any subsequent reservation even when no Journal exists.
            self._append("RESERVE_INTENT", account_alias=account_alias)
            run_id = self.journal.reserve(
                account_alias=account_alias, raid_code=raid_code
            )
            self._append("RESERVE_DONE", run_id=run_id)
            return run_id

    def complete(
        self, run_id: str, *, cleanup_verified: bool,
        rank_complete: bool | None = None, players_count: int | None = None,
    ) -> dict:
        with self._locked():
            # Worker cannot call release, even if cleanup_verified=True.
            new_state = (
                VERIFIED if rank_complete is True else VERIFIED_NO_RANK
            ) if cleanup_verified else BLOCKED
            self.journal.transition(
                run_id, state=new_state,
                cleanup_verified=cleanup_verified,
                rank_complete=rank_complete if cleanup_verified else None,
                players_count=players_count if cleanup_verified else None,
            )
            self._append("COMPLETE", run_id=run_id, state=new_state)
            return self.journal.read_public_state()

    def status(self) -> dict:
        with self._locked():
            events = self._events()
            if self.journal.path.exists():
                return self.journal.read_public_state()
            return {
                "state": "UNRESERVED" if not events else "HISTORICAL_RELEASED",
                "journal_present": False,
                "new_attempt_blocked": bool(
                    events and events[-1]["event"] != "RELEASE_DONE"
                ),
            }

    def operator_release(
        self, *, operator: str, run_id: str, evidence_ref: str,
        independent_cleanup_confirmed: bool, approval_phrase: str,
    ) -> str:
        """Operator-only local call. NEVER expose through worker socket."""
        if (
            not re.fullmatch(r"[A-Za-z][A-Za-z0-9_.-]{2,55}", operator)
            or not re.fullmatch(r"[A-Z][A-Z0-9._/-]{7,95}", evidence_ref)
            or approval_phrase != "I REVIEWED CLEANUP AND AUTHORIZE THIS TEST RESET"
            or independent_cleanup_confirmed is not True
        ):
            raise GateBlocked("human_review_evidence_required")
        with self._locked():
            if not self.journal.path.exists():
                raise GateBlocked("no_existing_account_journal")
            state = self.journal._data()
            if state.get("run_id") != run_id:
                raise GateBlocked("operator_run_id_mismatch")
            # A pending/blocked record may only be released when independent
            # post-failure cleanup evidence has been reviewed out-of-band.
            self._append(
                "RELEASE_INTENT", run_id=run_id,
                operator=operator, evidence_ref=evidence_ref,
                independent_cleanup_confirmed=True,
                previous_state=state["state"],
            )
            if self.archive_dir.is_symlink():
                raise GateBlocked("archive_symlink_forbidden")
            self.archive_dir.mkdir(mode=0o700, exist_ok=True)
            mode = self.archive_dir.stat()
            if mode.st_uid != os.geteuid() or (mode.st_mode & 0o077):
                raise GateBlocked("archive_ownership_invalid")
            dest = self.archive_dir / (run_id + ".json")
            if dest.exists() or dest.is_symlink():
                raise GateBlocked("archive_record_already_exists")
            os.replace(self.journal.path, dest)
            _sync_parent(dest)
            _sync_parent(self.journal.path)
            self._append(
                "RELEASE_DONE", run_id=run_id,
                operator=operator, evidence_ref=evidence_ref,
            )
            return "MANUALLY_RELEASED_OFFLINE_ONLY"
