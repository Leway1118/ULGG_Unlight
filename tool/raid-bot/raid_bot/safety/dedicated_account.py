"""Dedicated TW test account configuration, separate from watcher-owned auth.

No network connection, getid call or game login occurs in this module.
A Steam ID is an account reference, NOT proof that the session UUID is bound
to it. A later live adapter must verify the binding independently.
"""
from __future__ import annotations

import os
import re
import stat
import uuid
from dataclasses import dataclass
from pathlib import Path


WATCHER_AUTH_LOCATIONS = frozenset({
    Path("/run/unlight/tw_auth_session"),
})
REJECTED_ENV_SOURCES = (
    "RAID_PLAYER_SHARED_TW_AUTH_FILE",
    "RAID_WS_SHARED_TW_AUTH_FILE",
)


def _within(path: Path, folder: Path) -> bool:
    try:
        path.relative_to(folder)
    except ValueError:
        return False
    return True


@dataclass(frozen=True)
class DedicatedAccountConfig:
    alias: str
    steam_id: str
    credential_file: Path

    def validate(self, repository_root: Path) -> None:
        if not re.fullmatch(r"[A-Z][A-Z0-9_-]{2,47}", self.alias):
            raise ValueError("test_account_alias_invalid")
        if not re.fullmatch(r"7656119[0-9]{10}", self.steam_id):
            raise ValueError("test_steam_id_invalid")
        raw_path = Path(self.credential_file)
        if not raw_path.is_absolute():
            raise ValueError("credential_path_must_be_absolute")
        if any(p.is_symlink() for p in (
            raw_path, *raw_path.parents
        )):
            raise ValueError("credential_symlink_forbidden")
        path = raw_path.resolve(strict=False)
        root = Path(repository_root).resolve(strict=False)
        if _within(raw_path, root) or _within(path, root):
            raise ValueError("credential_within_repository_forbidden")
        if path in {p.resolve(strict=False) for p in WATCHER_AUTH_LOCATIONS}:
            raise ValueError("watcher_auth_file_forbidden")
        for key in REJECTED_ENV_SOURCES:
            current = os.getenv(key)
            if current and path == Path(current).resolve(strict=False):
                raise ValueError("shared_auth_source_forbidden")


class DedicatedAuthProvider:
    """Read local dedicated credentials; never fetch/refresh Watcher auth.

    This is only a candidate source for a future authorized live adapter.
    On Windows the ACL check is not implemented and the provider fails closed.
    """

    def __init__(self, config: DedicatedAccountConfig, repository_root: Path):
        config.validate(repository_root)
        self.config = config

    async def get(self) -> str:
        path = self.config.credential_file
        # Windows file mode cannot prove owner-only ACL protection.
        if os.name == "nt":
            raise PermissionError("windows_credential_acl_unverified")
        # Open-then-fstat closes the lstat-to-read symlink swap window.
        fd = os.open(str(path), os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        with os.fdopen(fd, "r", encoding="utf-8") as source:
            info = os.fstat(source.fileno())
            if not stat.S_ISREG(info.st_mode):
                raise PermissionError("credential_must_be_regular_file")
            if info.st_uid != os.geteuid() or (info.st_mode & 0o077):
                raise PermissionError("credential_owner_only_required")
            value = source.read(128).strip()
        try:
            candidate = uuid.UUID(value)
            if str(candidate) != value.lower():
                raise ValueError("noncanonical")
        except (ValueError, AttributeError) as exc:
            raise ValueError("dedicated_credential_invalid_format") from exc
        # This checks only syntax, NOT Steam-to-game-account binding or validity.
        return value
