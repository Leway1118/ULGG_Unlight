from __future__ import annotations

from dataclasses import dataclass
from typing import Optional


@dataclass
class StableValue:
    value: Optional[int] = None
    candidate: Optional[int] = None
    candidate_since: float = 0.0


class VisionStateTracker:
    def __init__(
        self,
        stable_seconds: float = 0.6
    ) -> None:
        self.stable_seconds = stable_seconds

        self.deck = StableValue()
        self.phase = StableValue()

        self.last_phase = "unknown"

    def update_deck(
        self,
        value: Optional[int],
        now: float
    ) -> Optional[dict]:

        if value is None:
            return None

        if value != self.deck.candidate:
            self.deck.candidate = value
            self.deck.candidate_since = now
            return None

        if (
            now - self.deck.candidate_since
            < self.stable_seconds
        ):
            return None

        if value == self.deck.value:
            return None

        previous = self.deck.value
        self.deck.value = value

        if previous is None:
            return {
                "type": "deck_initialized",
                "value": value,
            }

        if value > previous:
            increase = value - previous

            if increase >= 10:
                return {
                    "type": "reshuffle_detected",
                    "before": previous,
                    "after": value,
                    "recycled": increase,
                }

            return {
                "type": "deck_increased",
                "before": previous,
                "after": value,
                "amount": increase,
            }

        return {
            "type": "deck_decreased",
            "before": previous,
            "after": value,
            "amount": previous - value,
        }

    def update_phase(
        self,
        phase: str,
        now: float
    ) -> Optional[dict]:

        if phase == "unknown":
            return None

        if phase != self.phase.candidate:
            self.phase.candidate = phase
            self.phase.candidate_since = now
            return None

        if (
            now - self.phase.candidate_since
            < self.stable_seconds
        ):
            return None

        if phase == self.last_phase:
            return None

        previous = self.last_phase
        self.last_phase = phase

        return {
            "type": "phase_changed",
            "before": previous,
            "after": phase,
        }