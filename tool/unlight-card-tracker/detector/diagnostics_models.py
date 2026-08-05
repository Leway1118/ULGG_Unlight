from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum
from typing import Any, Callable

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    StringConstraints,
    field_validator,
    model_validator,
)
from typing_extensions import Annotated


CONTENT_HASH_PATTERN = r"^sha256:[0-9a-f]{64}$"
ROOM_STALE_AFTER_SECONDS = 30.0
ROOM_EXPIRE_AFTER_SECONDS = 120.0
CDP_STALE_AFTER_SECONDS = 45.0
CDP_EXPIRE_AFTER_SECONDS = 120.0

ShortText = Annotated[str, StringConstraints(max_length=256)]
Identifier = Annotated[str, StringConstraints(max_length=256)]
ObservedAt = Annotated[str, StringConstraints(max_length=64)]
ContentHash = Annotated[
    str,
    StringConstraints(pattern=CONTENT_HASH_PATTERN),
]


class RoomState(str, Enum):
    UNKNOWN = "UNKNOWN"
    WAITING = "WAITING"
    MATCHED = "MATCHED"
    STARTING = "STARTING"
    ACTIVE = "ACTIVE"
    ENDING = "ENDING"
    ENDED = "ENDED"


class ObservationStatus(str, Enum):
    OK = "OK"
    ERROR = "ERROR"


class ConnectionState(str, Enum):
    DISCONNECTED = "DISCONNECTED"
    CONNECTING = "CONNECTING"
    CONNECTED = "CONNECTED"
    ERROR = "ERROR"


class ContextState(str, Enum):
    NOT_FOUND = "NOT_FOUND"
    SEARCHING = "SEARCHING"
    FOUND = "FOUND"
    LOST = "LOST"
    ERROR = "ERROR"


class InjectionState(str, Enum):
    NOT_STARTED = "NOT_STARTED"
    INJECTING = "INJECTING"
    INJECTED = "INJECTED"
    FAILED = "FAILED"


class RulesetApplyState(str, Enum):
    NOT_STARTED = "NOT_STARTED"
    WAITING_ASSET = "WAITING_ASSET"
    APPLYING = "APPLYING"
    APPLIED = "APPLIED"
    PARTIAL = "PARTIAL"
    FAILED = "FAILED"


class DiagnosticError(BaseModel):
    model_config = ConfigDict(extra="forbid")

    code: Annotated[str, StringConstraints(min_length=1, max_length=64)]
    message: Annotated[str, StringConstraints(min_length=1, max_length=1024)]
    stage: Annotated[str, StringConstraints(max_length=64)] | None = None
    occurred_at: ObservedAt
    details: dict[str, Any] = Field(default_factory=dict)

    @field_validator("details")
    @classmethod
    def reject_sensitive_details(
        cls,
        details: dict[str, Any],
    ) -> dict[str, Any]:
        sensitive_fragments = (
            "token",
            "secret",
            "password",
            "authorization",
            "cookie",
            "api_key",
            "credential",
        )

        def inspect(value: Any) -> None:
            if isinstance(value, dict):
                for key, nested in value.items():
                    normalized = str(key).casefold()
                    if any(
                        part in normalized
                        for part in sensitive_fragments
                    ):
                        raise ValueError(
                            "sensitive error detail keys are forbidden"
                        )
                    inspect(nested)
            elif isinstance(value, list):
                for nested in value:
                    inspect(nested)

        inspect(details)
        if len(str(details)) > 4096:
            raise ValueError("error details are too large")
        return details


class RoomObservationSubmission(BaseModel):
    model_config = ConfigDict(extra="forbid")

    observed_room_state: RoomState
    raw_state: ShortText | None
    game_room_id: Identifier | None
    platform: ShortText | None
    channel: ShortText | None
    room_name: ShortText | None
    game_host_player_id: Identifier | None
    game_opponent_player_id: Identifier | None
    host_display_name: ShortText | None
    opponent_display_name: ShortText | None
    rule_version_id: Identifier | None
    content_hash: ContentHash | None
    tracker_session_id: Identifier | None
    client_observed_at: ObservedAt | None
    observation_status: ObservationStatus = ObservationStatus.OK
    error: DiagnosticError | None = None

    @model_validator(mode="after")
    def error_status_is_consistent(self) -> "RoomObservationSubmission":
        if self.observation_status == ObservationStatus.ERROR:
            if self.error is None:
                raise ValueError("ERROR observation_status requires error")
        elif self.error is not None:
            raise ValueError("error requires ERROR observation_status")
        return self


class CdpDiagnosticPatch(BaseModel):
    model_config = ConfigDict(extra="forbid")

    connection_state: ConnectionState | None = None
    context_state: ContextState | None = None
    target: ShortText | None = None
    context_id: int | None = Field(default=None, ge=0)
    injection_state: InjectionState | None = None
    ruleset_apply_state: RulesetApplyState | None = None
    rule_version_id: Identifier | None = None
    content_hash: ContentHash | None = None
    format_valid: bool | None = None
    registry_match: bool | None = None
    content_hash_match: bool | None = None
    enforcement_result: ShortText | None = None
    expected_count: int | None = Field(default=None, ge=0)
    matched_count: int | None = Field(default=None, ge=0)
    changed_count: int | None = Field(default=None, ge=0)
    already_compliant_count: int | None = Field(default=None, ge=0)
    missing_count: int | None = Field(default=None, ge=0)
    invalid_count: int | None = Field(default=None, ge=0)
    verified_count: int | None = Field(default=None, ge=0)
    client_observed_at: ObservedAt | None = None
    error: DiagnosticError | None = None


CDP_DEFAULTS: dict[str, Any] = {
    "connection_state": ConnectionState.DISCONNECTED.value,
    "context_state": ContextState.NOT_FOUND.value,
    "target": None,
    "context_id": None,
    "injection_state": InjectionState.NOT_STARTED.value,
    "ruleset_apply_state": RulesetApplyState.NOT_STARTED.value,
    "rule_version_id": None,
    "content_hash": None,
    "format_valid": None,
    "registry_match": None,
    "content_hash_match": None,
    "enforcement_result": None,
    "expected_count": None,
    "matched_count": None,
    "changed_count": None,
    "already_compliant_count": None,
    "missing_count": None,
    "invalid_count": None,
    "verified_count": None,
    "client_observed_at": None,
    "error": None,
}


def validate_cdp_current(current: dict[str, Any]) -> None:
    apply_state = current.get("ruleset_apply_state")
    counts = {
        name: current.get(name)
        for name in (
            "expected_count",
            "matched_count",
            "changed_count",
            "already_compliant_count",
            "missing_count",
            "invalid_count",
            "verified_count",
        )
    }
    supplied = [value for value in counts.values() if value is not None]
    if supplied and len(supplied) != len(counts):
        raise ValueError("all COST compliance counters are required together")
    if not supplied:
        if apply_state == RulesetApplyState.APPLIED.value:
            raise ValueError("APPLIED requires COST compliance counters")
        return

    expected = counts["expected_count"]
    matched = counts["matched_count"]
    changed = counts["changed_count"]
    compliant = counts["already_compliant_count"]
    missing = counts["missing_count"]
    invalid = counts["invalid_count"]
    verified = counts["verified_count"]
    assert all(value is not None for value in counts.values())

    if verified != changed + compliant:
        raise ValueError(
            "verified_count must equal changed_count plus "
            "already_compliant_count"
        )
    if matched > expected or verified > expected:
        raise ValueError("COST counters cannot exceed expected_count")
    if apply_state == RulesetApplyState.APPLIED.value and not (
        matched == expected
        and verified == expected
        and missing == 0
        and invalid == 0
    ):
        raise ValueError("APPLIED counters do not prove final compliance")


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def utc_iso(value: datetime) -> str:
    normalized = value.astimezone(timezone.utc)
    return normalized.isoformat(timespec="milliseconds").replace(
        "+00:00",
        "Z",
    )


def parse_utc(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def derive_freshness(
    server_received_at: str | None,
    *,
    stale_after_seconds: float,
    expire_after_seconds: float,
    now_provider: Callable[[], datetime] = utc_now,
) -> tuple[str, float | None]:
    if not server_received_at:
        return "EXPIRED", None
    age_seconds = max(
        0.0,
        (
            now_provider().astimezone(timezone.utc)
            - parse_utc(server_received_at).astimezone(timezone.utc)
        ).total_seconds(),
    )
    if age_seconds >= expire_after_seconds:
        freshness = "EXPIRED"
    elif age_seconds >= stale_after_seconds:
        freshness = "STALE"
    else:
        freshness = "FRESH"
    return freshness, round(age_seconds, 3)
