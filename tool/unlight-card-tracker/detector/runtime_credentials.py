from __future__ import annotations

import json
import os
import secrets
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Mapping

from diagnostics_models import utc_iso, utc_now


RUNTIME_DIRECTORY_ENV = "ULGG_TRACKER_RUNTIME_DIR"
ROOM_TOKEN_FILENAME = "room_observer.token"
CDP_TOKEN_FILENAME = "cdp_adapter.token"
RUNTIME_METADATA_FILENAME = "runtime.json"


def default_runtime_directory() -> Path:
    configured = os.environ.get(RUNTIME_DIRECTORY_ENV)
    if configured:
        return Path(configured)
    local_app_data = os.environ.get("LOCALAPPDATA")
    if local_app_data:
        return Path(local_app_data) / "ULGGTracker" / "runtime"
    return Path.home() / ".ulgg-tracker" / "runtime"


@dataclass(frozen=True)
class ProducerCredential:
    producer_id: str
    token: str
    allowed_actions: frozenset[str]


class ProducerCredentials:
    def __init__(
        self,
        credentials: Mapping[str, ProducerCredential],
    ) -> None:
        self._by_token = {
            credential.token: credential
            for credential in credentials.values()
        }

    @classmethod
    def generate(cls) -> "ProducerCredentials":
        return cls(
            {
                "room-observer": ProducerCredential(
                    producer_id="room-observer",
                    token=secrets.token_urlsafe(48),
                    allowed_actions=frozenset(
                        {"room.write", "room.delete"}
                    ),
                ),
                "cdp-adapter": ProducerCredential(
                    producer_id="cdp-adapter",
                    token=secrets.token_urlsafe(48),
                    allowed_actions=frozenset({"cdp.write"}),
                ),
            }
        )

    @classmethod
    def for_testing(
        cls,
        *,
        room_token: str = "test-room-token",
        cdp_token: str = "test-cdp-token",
    ) -> "ProducerCredentials":
        return cls(
            {
                "room-observer": ProducerCredential(
                    "room-observer",
                    room_token,
                    frozenset({"room.write", "room.delete"}),
                ),
                "cdp-adapter": ProducerCredential(
                    "cdp-adapter",
                    cdp_token,
                    frozenset({"cdp.write"}),
                ),
            }
        )

    def authorize(
        self,
        token: str,
        action: str,
    ) -> ProducerCredential | None:
        credential = self._by_token.get(token)
        if credential is None or action not in credential.allowed_actions:
            return None
        return credential

    def authenticate(self, token: str) -> ProducerCredential | None:
        return self._by_token.get(token)

    def credential_for(self, producer_id: str) -> ProducerCredential:
        for credential in self._by_token.values():
            if credential.producer_id == producer_id:
                return credential
        raise KeyError(producer_id)


def _write_private_text(path: Path, value: str) -> None:
    descriptor = os.open(
        path,
        os.O_WRONLY | os.O_CREAT | os.O_TRUNC,
        0o600,
    )
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            stream.write(value)
            stream.flush()
            os.fsync(stream.fileno())
    except Exception:
        try:
            os.close(descriptor)
        except OSError:
            pass
        raise
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass


def write_runtime_credentials(
    *,
    runtime_directory: Path,
    credentials: ProducerCredentials,
    pid: int,
    port: int,
    api_version: str,
    started_at: datetime | None = None,
) -> None:
    runtime_directory.mkdir(parents=True, exist_ok=True)
    _write_private_text(
        runtime_directory / ROOM_TOKEN_FILENAME,
        credentials.credential_for("room-observer").token,
    )
    _write_private_text(
        runtime_directory / CDP_TOKEN_FILENAME,
        credentials.credential_for("cdp-adapter").token,
    )
    metadata = {
        "pid": pid,
        "port": port,
        "started_at": utc_iso(started_at or utc_now()),
        "api_version": api_version,
    }
    _write_private_text(
        runtime_directory / RUNTIME_METADATA_FILENAME,
        json.dumps(metadata, ensure_ascii=False, indent=2),
    )
