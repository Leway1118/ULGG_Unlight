from __future__ import annotations

import math
import time
from collections.abc import Callable

from ..domain.models import RaidEntry, RawRaidSnapshot
from ..domain.raid_lifecycle import (
    EndedRaid,
    LastKnownRaid,
    RaidLifecycleStatus,
    RaidLifecycleView,
)


DEFAULT_ENDED_TTL_SECONDS = 120.0

# RAID_LIFECYCLE_MISSING_60S_DEBOUNCE_V2
MISSING_SECONDS_TO_END = 60.0


class RaidLifecycleTracker:
    """Keep current and recently-ended public Raids in memory."""

    def __init__(
        self,
        *,
        ended_ttl_seconds: float = DEFAULT_ENDED_TTL_SECONDS,
        expected_source: str = "websocket",
        clock: Callable[[], float] = time.time,
    ) -> None:
        if ended_ttl_seconds <= 0:
            raise ValueError("ended Raid TTL must be positive")
        self.ended_ttl_seconds = float(ended_ttl_seconds)
        self.expected_source = expected_source
        self._clock = clock
        self._active: dict[str, LastKnownRaid] = {}
        self._recent_ended: dict[str, EndedRaid] = {}

        # First valid snapshot time at which an otherwise-live Raid
        # disappeared. It must remain absent for 60 seconds before ENDED.
        self._missing_since: dict[str, float] = {}

        self.updated_at: float | None = None

    def observe_snapshot(self, snapshot: RawRaidSnapshot) -> bool:
        """Apply only a verified, connected raid_support_list snapshot."""
        if not self._is_valid_snapshot(snapshot):
            return False

        observed_at = float(snapshot.captured_at)
        self._prune(observed_at)

        seen: dict[str, LastKnownRaid] = {
            raid.profound_id: LastKnownRaid(
                raid=raid,
                last_seen_at=observed_at,
            )
            for raid in snapshot.raids
        }

        next_active: dict[str, LastKnownRaid] = {}

        # Raids present in this valid snapshot.
        for raid_id, record in seen.items():
            terminal_status = self._status_after_removal(
                record.raid,
                observed_at,
            )

            # Authoritative terminal conditions:
            # HP=0 or actual expiration ends immediately.
            if terminal_status in (
                RaidLifecycleStatus.DEFEATED,
                RaidLifecycleStatus.EXPIRED,
            ):
                existing = self._recent_ended.get(raid_id)

                if (
                    existing is None
                    or existing.status != terminal_status
                ):
                    self._recent_ended[raid_id] = EndedRaid(
                        raid=record.raid,
                        status=terminal_status,
                        last_seen_at=record.last_seen_at,
                        ended_at=observed_at,
                    )

                self._missing_since.pop(
                    raid_id,
                    None,
                )
                continue

            # A live Raid is visible again:
            # cancel transient disappearance / stale ended state.
            self._recent_ended.pop(
                raid_id,
                None,
            )

            self._missing_since.pop(
                raid_id,
                None,
            )

            next_active[raid_id] = record

        # Raids that were active previously but are absent now.
        for raid_id, previous in self._active.items():
            if raid_id in seen:
                continue

            removal_status = self._status_after_removal(
                previous.raid,
                observed_at,
            )

            # Defeated / expired is authoritative and immediate.
            if removal_status in (
                RaidLifecycleStatus.DEFEATED,
                RaidLifecycleStatus.EXPIRED,
            ):
                self._recent_ended[raid_id] = EndedRaid(
                    raid=previous.raid,
                    status=removal_status,
                    last_seen_at=previous.last_seen_at,
                    ended_at=observed_at,
                )

                self._missing_since.pop(
                    raid_id,
                    None,
                )
                continue

            missing_since = self._missing_since.get(
                raid_id
            )

            if missing_since is None:
                # First observed disappearance.
                self._missing_since[raid_id] = observed_at
                next_active[raid_id] = previous
                continue

            missing_seconds = max(
                0.0,
                observed_at - missing_since,
            )

            if missing_seconds < MISSING_SECONDS_TO_END:
                # Still inside the grace period:
                # preserve the Raid as ACTIVE using its last known data.
                next_active[raid_id] = previous
                continue

            # Live HP + not expired, but genuinely absent for >=60 seconds.
            self._recent_ended[raid_id] = EndedRaid(
                raid=previous.raid,
                status=RaidLifecycleStatus.ENDED,
                last_seen_at=previous.last_seen_at,
                ended_at=observed_at,
            )

            self._missing_since.pop(
                raid_id,
                None,
            )

        self._active = next_active
        self.updated_at = observed_at
        return True

    def view(self, *, now: float | None = None) -> RaidLifecycleView:
        self._prune(self._clock() if now is None else now)
        return RaidLifecycleView(
            updated_at=self.updated_at,
            active=tuple(self._active.values()),
            recent_ended=tuple(self._recent_ended.values()),
        )

    @staticmethod
    def active_status(raid: RaidEntry) -> RaidLifecycleStatus:
        return (
            RaidLifecycleStatus.DEFEATED
            if raid.hp == 0
            else RaidLifecycleStatus.ACTIVE
        )

    @staticmethod
    def _status_after_removal(
        raid: RaidEntry,
        ended_at: float,
    ) -> RaidLifecycleStatus:
        if raid.hp == 0:
            return RaidLifecycleStatus.DEFEATED
        if (
            raid.expires_at is not None
            and ended_at * 1000 >= raid.expires_at
        ):
            return RaidLifecycleStatus.EXPIRED
        return RaidLifecycleStatus.ENDED

    def _prune(self, now: float) -> None:
        expired_ids = [
            raid_id
            for raid_id, record in self._recent_ended.items()
            if now - record.ended_at >= self.ended_ttl_seconds
        ]
        for raid_id in expired_ids:
            self._recent_ended.pop(raid_id, None)

    def _is_valid_snapshot(self, snapshot: RawRaidSnapshot) -> bool:
        if not isinstance(snapshot, RawRaidSnapshot):
            return False
        if (
            snapshot.source != self.expected_source
            or not snapshot.connected
            or snapshot.error is not None
            or isinstance(snapshot.captured_at, bool)
            or not isinstance(snapshot.captured_at, (int, float))
            or not math.isfinite(snapshot.captured_at)
            or snapshot.captured_at < 0
            or not isinstance(snapshot.raids, tuple)
        ):
            return False

        raid_ids: set[str] = set()
        for raid in snapshot.raids:
            if (
                not isinstance(raid, RaidEntry)
                or not isinstance(raid.profound_id, str)
                or not raid.profound_id
                or raid.profound_id in raid_ids
            ):
                return False
            raid_ids.add(raid.profound_id)
        return True

