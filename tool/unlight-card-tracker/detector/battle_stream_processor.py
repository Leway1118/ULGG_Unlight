"""Stateful orchestration for safe battle observations.

The processor composes the pure observation adapter and BattleState reducer.
It owns only in-memory state and receives its producer generation explicitly
from the caller. It performs no file, browser, API, database, or game
transport operations.
"""

from __future__ import annotations

from copy import deepcopy
from typing import Any, Callable, Mapping, Sequence

from battle_state_reducer import (
    initial_battle_state,
    reduce_battle_event,
    with_producer_session_id,
)
from battle_tracker_adapter import adapt_battle_observation


PROCESSOR_MODES = frozenset({"strict", "diagnostic-bootstrap"})
BATTLE_CONSOLE_LEVELS = frozenset({"quiet", "important", "debug"})

_IMPORTANT_EVENT_TYPES = frozenset(
    {
        "battle.started",
        "hand.cards_dealt",
        "hand.card_drawn",
        "play.selection_changed",
        "play.cards_revealed",
        "battle.phase_ended",
        "battle.turn_ended",
        "battle.finished",
    }
)
_REJECTION_DIAGNOSTICS = frozenset(
    {
        "adapter_error",
        "confirmation_mismatch",
        "duplicated_event",
        "event_before_battle_start",
        "fallback_authority_rejected",
        "invalid_current_state",
        "invalid_domain_event",
        "invalid_payload",
        "late_event_after_finish",
        "reducer_error",
        "restricted_visibility",
        "unknown_side_reveal",
        "unsupported_authority",
        "unsupported_event_type",
        "unsupported_source",
    }
)
_ERROR_DIAGNOSTICS = frozenset(
    {
        "adapter_error",
        "confirmation_mismatch",
        "invalid_current_state",
        "invalid_domain_event",
        "invalid_payload",
        "reducer_error",
        "restricted_visibility",
        "unsupported_authority",
        "unsupported_source",
    }
)
_STATE_METADATA_FIELDS = frozenset(
    {
        "applied_event_ids",
        "diagnostics",
        "last_producer_session_id",
        "last_source_sequence",
    }
)

Adapter = Callable[[Any], list[dict[str, Any]]]
Reducer = Callable[
    [Any, Any],
    tuple[dict[str, Any], list[dict[str, Any]]],
]


def _is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _safe_observation_metadata(
    observation: Any,
) -> tuple[str | None, int | None]:
    if not isinstance(observation, Mapping):
        return None, None
    observation_type = observation.get("type")
    sequence = observation.get("sequence")
    return (
        observation_type if isinstance(observation_type, str) else None,
        sequence if _is_int(sequence) and sequence >= 0 else None,
    )


def _processor_diagnostic(
    observation: Any,
    code: str,
) -> dict[str, Any]:
    observation_type, source_sequence = _safe_observation_metadata(
        observation
    )
    return {
        "code": code,
        "event_type": observation_type,
        "source_sequence": source_sequence,
    }


def _resolve_observation_side(
    observation: Any,
    local_side: str | None,
) -> Any:
    if not isinstance(observation, Mapping):
        return observation
    result = deepcopy(dict(observation))
    if local_side is None:
        return result

    protocol_side = result.get("protocol_side")
    if protocol_side not in {"A", "B"}:
        return result
    resolved_side = "self" if protocol_side == local_side else "opponent"
    result["resolved_side"] = resolved_side

    observation_type = result.get("type")
    if observation_type == "cards_revealed":
        result["visibility"] = (
            "public"
            if resolved_side == "self"
            else "opponent_revealed"
        )
    elif observation_type == "card_selection_changed":
        result["visibility"] = (
            "self_private"
            if resolved_side == "self"
            else "restricted_opponent_hidden"
        )
    return result


def _domain_projection(state: Mapping[str, Any]) -> dict[str, Any]:
    return {
        key: deepcopy(value)
        for key, value in state.items()
        if key not in _STATE_METADATA_FIELDS
    }


def format_processing_result(
    result: Mapping[str, Any],
    level: str = "important",
) -> list[str]:
    """Return safe console lines without printing or changing processing."""
    if level not in BATTLE_CONSOLE_LEVELS:
        raise ValueError("unsupported battle console level")
    domain_events = result.get("domain_events")
    diagnostics = result.get("diagnostics")
    if not isinstance(domain_events, list):
        domain_events = []
    if not isinstance(diagnostics, list):
        diagnostics = []

    lines: list[str] = []
    if level != "quiet":
        for event in domain_events:
            if not isinstance(event, Mapping):
                continue
            event_type = event.get("event_type")
            if (
                level == "debug"
                or event_type in _IMPORTANT_EVENT_TYPES
            ):
                lines.append(
                    "[BATTLE] "
                    f"{event_type} "
                    f"sequence={event.get('source_sequence')}"
                )

    for diagnostic in diagnostics:
        if not isinstance(diagnostic, Mapping):
            continue
        code = diagnostic.get("code")
        if level == "debug" or code in _ERROR_DIAGNOSTICS:
            lines.append(
                "[BATTLE ERROR] "
                f"{code} "
                f"event={diagnostic.get('event_type')} "
                f"sequence={diagnostic.get('source_sequence')}"
            )
    return lines


class BattleStreamProcessor:
    """Compose safe observations into one in-memory BattleState projection."""

    def __init__(
        self,
        producer_session_id: str,
        *,
        mode: str = "strict",
        local_side: str | None = None,
        console_level: str = "important",
        initial_state: Mapping[str, Any] | None = None,
        adapter: Adapter = adapt_battle_observation,
        reducer: Reducer = reduce_battle_event,
    ) -> None:
        if not isinstance(producer_session_id, str) or not producer_session_id:
            raise ValueError("producer_session_id is required")
        if mode not in PROCESSOR_MODES:
            raise ValueError("unsupported processor mode")
        if local_side not in {"A", "B", None}:
            raise ValueError("local_side must be A, B, or None")
        if console_level not in BATTLE_CONSOLE_LEVELS:
            raise ValueError("unsupported battle console level")
        if not callable(adapter) or not callable(reducer):
            raise TypeError("adapter and reducer must be callable")

        self.producer_session_id = producer_session_id
        self.mode = mode
        self.local_side = local_side
        self.console_level = console_level
        self._adapter = adapter
        self._reducer = reducer
        self._state = deepcopy(
            initial_state
            if initial_state is not None
            else initial_battle_state()
        )
        self._mid_battle_reported = False

    def snapshot(self) -> dict[str, Any]:
        """Return a deep copy of the current BattleState."""
        return deepcopy(self._state)

    def format_result(self, result: Mapping[str, Any]) -> list[str]:
        return format_processing_result(result, self.console_level)

    def process(self, observation: Any) -> dict[str, Any]:
        """Process one safe observation and atomically retain its new state."""
        before = self.snapshot()
        observation_type, _sequence = _safe_observation_metadata(observation)
        resolved_observation = _resolve_observation_side(
            observation,
            self.local_side,
        )
        diagnostics: list[dict[str, Any]] = []

        try:
            adapted = self._adapter(resolved_observation)
        except Exception:
            diagnostic = _processor_diagnostic(
                observation,
                "adapter_error",
            )
            diagnostics.append(diagnostic)
            return self._result(
                accepted=False,
                observation_type=observation_type,
                domain_events=[],
                state_changed=False,
                state=before,
                diagnostics=diagnostics,
            )

        if not isinstance(adapted, list):
            diagnostic = _processor_diagnostic(
                observation,
                "adapter_error",
            )
            diagnostics.append(diagnostic)
            return self._result(
                accepted=False,
                observation_type=observation_type,
                domain_events=[],
                state_changed=False,
                state=before,
                diagnostics=diagnostics,
            )

        domain_events: list[dict[str, Any]] = []
        try:
            for event in adapted:
                domain_events.append(
                    with_producer_session_id(
                        event,
                        self.producer_session_id,
                    )
                )
        except (TypeError, ValueError):
            diagnostic = _processor_diagnostic(
                observation,
                "adapter_error",
            )
            diagnostics.append(diagnostic)
            return self._result(
                accepted=False,
                observation_type=observation_type,
                domain_events=[],
                state_changed=False,
                state=before,
                diagnostics=diagnostics,
            )

        working_state = before
        if (
            self.mode == "diagnostic-bootstrap"
            and working_state.get("battle_status") == "idle"
            and observation_type != "battle_started"
            and domain_events
            and not self._mid_battle_reported
        ):
            bootstrap = _processor_diagnostic(
                observation,
                "stream_started_mid_battle",
            )
            working_state = deepcopy(working_state)
            working_state["diagnostics"].append(bootstrap)
            diagnostics.append(bootstrap)

        try:
            for event in domain_events:
                working_state, emitted = self._reducer(
                    working_state,
                    event,
                )
                diagnostics.extend(deepcopy(emitted))
        except Exception:
            diagnostic = _processor_diagnostic(
                observation,
                "reducer_error",
            )
            diagnostics.append(diagnostic)
            return self._result(
                accepted=False,
                observation_type=observation_type,
                domain_events=domain_events,
                state_changed=False,
                state=before,
                diagnostics=diagnostics,
            )

        self._state = deepcopy(working_state)
        if any(
            diagnostic.get("code") == "stream_started_mid_battle"
            for diagnostic in diagnostics
        ):
            self._mid_battle_reported = True

        blocking = any(
            diagnostic.get("code") in _REJECTION_DIAGNOSTICS
            for diagnostic in diagnostics
            if isinstance(diagnostic, Mapping)
        )
        state_changed = (
            _domain_projection(before)
            != _domain_projection(self._state)
        )
        return self._result(
            accepted=bool(domain_events) and not blocking,
            observation_type=observation_type,
            domain_events=domain_events,
            state_changed=state_changed,
            state=self._state,
            diagnostics=diagnostics,
        )

    @staticmethod
    def _result(
        *,
        accepted: bool,
        observation_type: str | None,
        domain_events: Sequence[Mapping[str, Any]],
        state_changed: bool,
        state: Mapping[str, Any],
        diagnostics: Sequence[Mapping[str, Any]],
    ) -> dict[str, Any]:
        return {
            "accepted": accepted,
            "observation_type": observation_type,
            "domain_events": deepcopy(list(domain_events)),
            "state_changed": state_changed,
            "state": deepcopy(dict(state)),
            "diagnostics": deepcopy(list(diagnostics)),
        }
