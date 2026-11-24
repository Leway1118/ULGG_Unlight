"""Offline-only account-scoped journal location and OS permission preflight.

This binds a journal to a normalized Steam ID rather than a caller-chosen
filename. It is NOT proof of Steam identity and does not protect against a
privileged operator changing the trusted state root. A future live adapter
must pin the root through root-owned configuration and prove account binding.
"""
from __future__ import annotations

import hashlib
import os
import re
import stat
from pathlib import Path

from raid_bot.safety.cleanup_journal import GateBlocked, PersistentCleanupJournal


_STEAM_ID_PATTERN = re.compile(r"7656119[0-9]{10}\Z")


def account_key(steam_id: str) -> str:
    if not isinstance(steam_id, str) or not _STEAM_ID_PATTERN.fullmatch(steam_id):
        raise GateBlocked("account_steam_id_invalid")
    return hashlib.sha256(
        ("ulgg-raid-safety-account-v1:" + steam_id).encode("utf-8")
    ).hexdigest()


class OfflineAccountGuard:
    """Canonical account journal under a pre-created, trusted local directory.

    This class is only appropriate for offline fixtures. Root changes must be
    impossible through CLI arguments in a future live executable.
    """

    def __init__(self, state_root: Path, *, repository_root: Path):
        raw = Path(state_root)
        repo = Path(repository_root).resolve(strict=False)
        if not raw.is_absolute():
            raise GateBlocked("account_state_root_not_absolute")
        if raw.is_symlink() or any(part.is_symlink() for part in raw.parents):
            raise GateBlocked("account_state_root_symlink_forbidden")
        try:
            root = raw.resolve(strict=True)
        except (OSError, RuntimeError) as exc:
            raise GateBlocked("account_state_root_unavailable") from exc
        if root == repo or repo in root.parents:
            raise GateBlocked("account_state_inside_repository_forbidden")
        info = root.stat()
        if not stat.S_ISDIR(info.st_mode):
            raise GateBlocked("account_state_root_not_directory")
        if os.name != "nt":
            if info.st_uid != os.geteuid() or (info.st_mode & 0o077):
                raise GateBlocked("account_state_root_not_owner_private")
        # Windows lacks a trustworthy owner-only ACL check in this module.
        # It is accepted only for synthetic offline operations.
        self.root = root

    def journal_for(self, steam_id: str) -> PersistentCleanupJournal:
        # Filename cannot be selected by the caller. Alias changes do not
        # change the key; one Steam ID always resolves to the same journal.
        key = account_key(steam_id)
        path = self.root / ("account-" + key + ".journal.json")
        if path.is_symlink():
            raise GateBlocked("account_journal_symlink_forbidden")
        return PersistentCleanupJournal(path)

    def read_account_state(self, steam_id: str) -> dict:
        """Read-only state inspection. Absence is NOT permission to use live."""
        journal = self.journal_for(steam_id)
        if not journal.path.exists():
            return {"state": "UNRESERVED_OFFLINE_ONLY", "journal_present": False}
        return {
            **journal.read_public_state(),
            "journal_present": True,
        }
