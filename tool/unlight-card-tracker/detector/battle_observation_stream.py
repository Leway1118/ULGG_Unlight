"""Safe JSONL projection for parsed battle protocol observations."""

import json
import threading
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any, Callable, Mapping

from battle_protocol import (
    SUPPORTED_EVENT_NAMES,
    SideResolutionContext,
    Visibility,
    classify_visibility,
    parse_discovery_record,
    resolve_side,
)


BATTLE_OBSERVATION_SCHEMA_VERSION = 1
ALLOWED_VISIBILITIES = frozenset(
    {
        Visibility.PUBLIC.value,
        Visibility.SELF_PRIVATE.value,
        Visibility.OPPONENT_REVEALED.value,
    }
)

_RESTRICTED_VISIBILITIES = frozenset(
    {Visibility.RESTRICTED_OPPONENT_HIDDEN.value}
)
_DIAGNOSTIC_VISIBILITIES = frozenset({Visibility.DIAGNOSTIC_ONLY.value})
_MALFORMED_DISCOVERY_EVENTS = frozenset(
    {"__malformed_json__", "__non_object_json__"}
)
_STAGE_CONTEXT_EVENTS = frozenset(
    {"db_quest_land_now", "raid_ready", "joinRoom", "gameReady"}
)
_STAGE_CONTEXT_SOURCES = frozenset({"db_quest_land_now", "raid_ready"})
_FORBIDDEN_PAYLOAD_KEYS = frozenset(
    {
        "args",
        "args_summary",
        "auth",
        "raw",
        "room",
        "room_id",
        "roomid",
        "session",
        "session_id",
        "session_target",
        "session_token",
        "steam_id",
        "steamid",
        "token",
        "url",
        "websocket_url",
    }
)


def _normalized_key(value: Any) -> str:
    return str(value).lower().replace("-", "_")


def _contains_forbidden_payload_key(value: Any) -> bool:
    if isinstance(value, Mapping):
        for key, child in value.items():
            if _normalized_key(key) in _FORBIDDEN_PAYLOAD_KEYS:
                return True
            if _contains_forbidden_payload_key(child):
                return True
    elif isinstance(value, (list, tuple)):
        return any(_contains_forbidden_payload_key(child) for child in value)
    return False


def _is_valid_empty_protocol_event(record: Mapping[str, Any]) -> bool:
    event_name = record.get("event")
    args = record.get("args_summary")
    if not isinstance(args, list):
        return False

    if event_name in {"drawPhase", "cardOpen", "cardOpen_A", "cardOpen_B"}:
        return bool(args) and isinstance(args[0], list) and not args[0]
    if event_name in {"chara_A", "chara_B"}:
        return not args or all(
            isinstance(item, Mapping) and not item.get("chara")
            for item in args
        )
    return False


@dataclass(frozen=True)
class _PendingStageContext:
    room_id: str
    stage_code: str
    source_event: str
    source_sequence: int
    confirmed: bool = False
    confirmed_sequence: int | None = None

    def safe_payload(self) -> dict[str, Any]:
        return {
            "stage_code": self.stage_code,
            "stage_source": self.source_event,
            "stage_source_sequence": self.source_sequence,
        }


class BattleObservationStream:
    """Append an allowlisted projection of redacted discovery records."""

    def __init__(
        self,
        output_path: str | Path,
        *,
        local_side: str | None = None,
        battle_mode: str = "unknown",
        persist: bool = True,
        parser: Callable[..., list[dict[str, Any]]] = parse_discovery_record,
        console: Callable[[str], None] = print,
    ):
        self.output_path = Path(output_path)
        self.persist = persist
        self.context = SideResolutionContext(
            local_side=local_side,
            mode=battle_mode,
        )
        self.parser = parser
        self.console = console
        self._lock = threading.RLock()
        self._closed = False
        self._pending_stage_context: _PendingStageContext | None = None
        self._stats = {
            "written": 0,
            "rejected_restricted": 0,
            "rejected_diagnostic": 0,
            "parse_errors": 0,
            "unknown_events": 0,
        }

        self._file = None
        if self.persist:
            self.output_path.parent.mkdir(parents=True, exist_ok=True)
            self._file = self.output_path.open(
                "a",
                encoding="utf-8",
                buffering=1,
            )

    @staticmethod
    def _room_identity(args: Any) -> str | None:
        if not isinstance(args, list) or not args:
            return None
        room_id = args[0]
        return room_id if isinstance(room_id, str) and room_id else None

    @staticmethod
    def _new_stage_context(
        record: Mapping[str, Any],
        args: Any,
    ) -> _PendingStageContext | None:
        if record.get("direction") != "received":
            return None
        source_event = record.get("event")
        sequence = record.get("sequence")
        if (
            source_event not in _STAGE_CONTEXT_SOURCES
            or not isinstance(sequence, int)
            or isinstance(sequence, bool)
            or sequence < 0
            or not isinstance(args, list)
            or not args
            or not isinstance(args[0], Mapping)
        ):
            return None
        room_id = args[0].get("room_id")
        stage_code = args[0].get("room_stage")
        if (
            not isinstance(room_id, str)
            or not room_id
            or not isinstance(stage_code, str)
            or len(stage_code) != 3
            or not stage_code.isascii()
            or not stage_code.isdigit()
        ):
            return None
        return _PendingStageContext(
            room_id=room_id,
            stage_code=stage_code,
            source_event=source_event,
            source_sequence=sequence,
        )

    def observe_transport_context(
        self,
        record: Any,
        private_args: Any,
    ) -> None:
        """Observe exact room identity without persisting transport payloads."""
        with self._lock:
            if self._closed or not isinstance(record, Mapping):
                return
            event_name = record.get("event")
            if event_name in _STAGE_CONTEXT_SOURCES:
                self._pending_stage_context = self._new_stage_context(
                    record,
                    private_args,
                )
                return
            if event_name not in {"joinRoom", "gameReady"}:
                return
            if record.get("direction") != "sent":
                return
            sequence = record.get("sequence")
            room_id = self._room_identity(private_args)
            pending = self._pending_stage_context
            if (
                pending is None
                or room_id is None
                or room_id != pending.room_id
                or not isinstance(sequence, int)
                or isinstance(sequence, bool)
                or sequence <= pending.source_sequence
            ):
                self._pending_stage_context = None
                return
            self._pending_stage_context = replace(
                pending,
                confirmed=True,
                confirmed_sequence=sequence,
            )

    def _parser_record(
        self,
        record: Mapping[str, Any],
    ) -> Mapping[str, Any]:
        pending = self._pending_stage_context
        if (
            record.get("event") != "gameStart"
            or pending is None
            or not pending.confirmed
            or not isinstance(pending.confirmed_sequence, int)
            or not isinstance(record.get("sequence"), int)
            or isinstance(record.get("sequence"), bool)
            or record["sequence"] <= pending.source_sequence
            or record["sequence"] <= pending.confirmed_sequence
        ):
            return record
        return {
            **record,
            "stage_context": pending.safe_payload(),
        }

    def _safe_error(self, record: Any, reason: str) -> None:
        event_name = None
        sequence = None
        if isinstance(record, Mapping):
            event_name = record.get("event")
            sequence = record.get("sequence")
        self.console(
            "[!] Battle observation parse skipped: "
            f"event={event_name!r} sequence={sequence!r} reason={reason}"
        )

    def _record_parse_error(self, record: Any, reason: str) -> None:
        self._stats["parse_errors"] += 1
        self._safe_error(record, reason)

    def _project_observation(
        self,
        observation: Mapping[str, Any],
        observation_index: int,
    ) -> dict[str, Any] | None:
        payload = observation.get("payload")
        if not isinstance(payload, Mapping):
            return None
        if _contains_forbidden_payload_key(payload):
            return None

        protocol_side = observation.get("side")
        if protocol_side not in {"A", "B", "unknown"}:
            protocol_side = "unknown"

        return {
            "schema_version": BATTLE_OBSERVATION_SCHEMA_VERSION,
            "timestamp": observation.get("timestamp")
            if isinstance(observation.get("timestamp"), str)
            else None,
            "sequence": observation.get("sequence")
            if isinstance(observation.get("sequence"), int)
            and not isinstance(observation.get("sequence"), bool)
            else None,
            "observation_index": observation_index,
            "type": observation.get("type"),
            "source_event": observation.get("source_event"),
            "direction": observation.get("direction"),
            "protocol_side": protocol_side,
            "resolved_side": resolve_side(protocol_side, self.context),
            "visibility": observation.get("visibility"),
            "confirmation": observation.get("confirmation"),
            "payload": dict(payload),
        }

    def process(self, record: Any) -> list[dict[str, Any]]:
        """Parse and append one record, isolating all per-frame failures."""
        with self._lock:
            if self._closed:
                return []
            if not isinstance(record, Mapping):
                self._record_parse_error(record, "invalid_discovery_record")
                return []

            event_name = record.get("event")
            if event_name in _MALFORMED_DISCOVERY_EVENTS:
                self._record_parse_error(record, "malformed_frame")
                return []
            if not isinstance(event_name, str):
                self._stats["unknown_events"] += 1
                return []
            if event_name not in SUPPORTED_EVENT_NAMES:
                self._stats["unknown_events"] += 1
                return []
            if event_name in _STAGE_CONTEXT_EVENTS:
                return []
            if not isinstance(record.get("args_summary"), list):
                self._record_parse_error(record, "invalid_args_schema")
                return []

            try:
                observations = self.parser(
                    self._parser_record(record),
                    context=self.context,
                )
            except Exception as exc:
                self._record_parse_error(
                    record,
                    f"parser_exception:{type(exc).__name__}",
                )
                return []
            finally:
                if event_name in {"gameStart", "result"}:
                    self._pending_stage_context = None

            if not isinstance(observations, list):
                self._record_parse_error(record, "invalid_parser_result")
                return []
            if not observations and not _is_valid_empty_protocol_event(record):
                self._record_parse_error(record, "unsupported_event_schema")
                return []

            written = []
            for observation_index, observation in enumerate(observations):
                if not isinstance(observation, Mapping):
                    self._record_parse_error(record, "invalid_observation")
                    continue

                visibility = classify_visibility(observation, self.context)
                if visibility in _RESTRICTED_VISIBILITIES:
                    self._stats["rejected_restricted"] += 1
                    continue
                if visibility in _DIAGNOSTIC_VISIBILITIES:
                    self._stats["rejected_diagnostic"] += 1
                    continue
                if visibility not in ALLOWED_VISIBILITIES:
                    self._record_parse_error(record, "invalid_visibility")
                    continue

                projected = self._project_observation(
                    {**observation, "visibility": visibility},
                    observation_index,
                )
                if projected is None:
                    self._record_parse_error(record, "unsafe_observation_schema")
                    continue

                if self._file is not None:
                    self._file.write(
                        json.dumps(
                            projected,
                            ensure_ascii=False,
                            separators=(",", ":"),
                        )
                        + "\n"
                    )
                    self._file.flush()
                    self._stats["written"] += 1
                written.append(projected)
            return written

    def summary(self) -> dict[str, int]:
        with self._lock:
            return dict(self._stats)

    def flush(self) -> None:
        with self._lock:
            if not self._closed and self._file is not None:
                self._file.flush()

    def close(self) -> dict[str, int]:
        with self._lock:
            if not self._closed:
                self._pending_stage_context = None
                if self._file is not None:
                    self._file.flush()
                    self._file.close()
                self._closed = True
            return dict(self._stats)
