from __future__ import annotations

from dataclasses import replace

import asyncio
import logging
import time
from contextlib import suppress
from pathlib import Path

from ..infrastructure.raid_history_repository import RaidHistoryRepository
from ..domain.models import RaidEntry, RawRaidSnapshot
from ..domain.raid_event import RaidEvent, RaidEventType
from ..notifications.notifier import RaidNotifier
from .event_dispatcher import RaidEventDispatcher
from .fragment_resolver import FragmentResolver
from .raid_diff import RaidDiff
from .raid_lifecycle_tracker import RaidLifecycleTracker
from .treasure_level_enricher import TreasureLevelEnricher
from .active_raid_state_cache import ActiveRaidStateCache


logger = logging.getLogger(__name__)

STATE_REFRESH_SECONDS = 30.0
STATE_REFRESH_SPACING_SECONDS = 3.0

# BOSS_INFERRED_DEFEAT_V3_SERVICE
DEFEAT_MISSING_CONFIRM_SECONDS = 180.0
DEFEAT_EXPIRY_GUARD_SECONDS = 300.0


class RaidService:
    """Normalize source snapshots, persist seen IDs, and notify new Raids."""

    # RAID_HISTORY_FRAGMENT_BRIDGE_V1
    def _remember_stage_metadata_and_history(
        self,
        *args,
        **kwargs,
    ):
        """Persist normal stage metadata, then mirror fragment to MySQL."""

        result = (
            self.active_state_cache
            .remember_stage_metadata(
                *args,
                **kwargs,
            )
        )

        raid_id = (
            args[0]
            if args
            else kwargs.get("raid_id")
        )

        fragment = kwargs.get(
            "fragment"
        )

        source = kwargs.get(
            "source"
        )

        repository = getattr(
            self,
            "raid_history_repository",
            None,
        )

        if (
            repository is not None
            and isinstance(raid_id, str)
            and raid_id.strip()
            and isinstance(fragment, str)
            and fragment.strip()
            and isinstance(source, str)
            and source.strip()
        ):
            try:
                # RAID_STAGE_HISTORY_REPLAY_V2
                # RAID_STAGE_SOURCE_PROVENANCE_V1
                repository.update_stage_observation(
                    raid_id=raid_id,
                    stage_id=kwargs.get("stage_id"),
                    rarity=kwargs.get("rarity"),
                    source=source,
                )

                outcome = (
                    repository
                    .update_fragment_observation(
                        raid_id=raid_id,
                        fragment=fragment,
                        source=source,
                    )
                )

                # RAID_IMMEDIATE_COORD_RECONCILE_V1
                repository.reconcile_protocol14_derived_metadata(
                    raid_id=raid_id,
                )

                logger.info(
                    "Raid fragment history sync "
                    "raid_id=%s fragment=%s "
                    "source=%s decision=%s conflict=%s",
                    raid_id,
                    fragment,
                    source,
                    outcome.get("decision"),
                    outcome.get("conflict"),
                )

            except Exception as error:
                # Fragment research persistence is non-critical.
                logger.warning(
                    "Raid fragment history sync failed "
                    "raid_id=%s error=%s",
                    raid_id,
                    type(error).__name__,
                )

        return result


    def __init__(
        self,
        reader,
        interval_seconds: float = 10.0,
        notifier: RaidNotifier | None = None,
        state_file: str | Path | None = None,
        source_name: str | None = None,
        fragment_resolver: FragmentResolver | None = None,
        treasure_enricher: TreasureLevelEnricher | None = None,
        notification_batch_seconds: float = 30.0,
        full_summary_interval_seconds: float = 10_800.0,
    ) -> None:
        self.reader = reader
        self.interval_seconds = interval_seconds
        self.notifier = notifier
        self.treasure_enricher = treasure_enricher
        self.fragment_resolver = fragment_resolver
        self.active_state_cache = ActiveRaidStateCache()

        # Raid history is auxiliary persistence.
        # DB problems must never stop Raid/Discord processing.
        try:
            self.raid_history_repository = (
                RaidHistoryRepository.from_environment()
            )
            logger.info(
                "Raid history database writer enabled"
            )
        except Exception as error:
            self.raid_history_repository = None
            logger.warning(
                "Raid history database writer disabled: %s",
                error,
            )
        self._state_refresh_task: asyncio.Task[None] | None = None
        self._state_refresh_last_attempt: dict[str, float] = {}
        self._state_refresh_cursor = 0
        self.diff = RaidDiff(state_file)
        self.lifecycle = RaidLifecycleTracker()
        # BOSS_AVG_DEFEAT_TIME_SERVICE_V1
        self._persisted_lifecycle_end_ids: set[str] = set()

        # V3 disappearance inference state.
        self._defeat_previous_visible_ids: set[str] = set()

        self._defeat_last_visible: dict[
            str,
            RaidEntry,
        ] = {}

        # raid_id -> (first_missing_at, expires_at_ms)
        self._defeat_pending: dict[
            str,
            tuple[float, int | None],
        ] = {}
        self.dispatcher = RaidEventDispatcher(
            notifier=notifier,
            fragment_resolver=fragment_resolver,
            treasure_enricher=treasure_enricher,
            state=self.diff,
            notification_batch_seconds=notification_batch_seconds,
            full_summary_interval_seconds=full_summary_interval_seconds,
        )

        # RAID_STAGE_METADATA_PERSIST_BRIDGE_V1
        # Keep EventDispatcher's public constructor unchanged. The policy gets
        # a narrow callback bridge to the service-owned metadata cache so a
        # successful stage-sync report survives later db_raid_support snapshots
        # and service restarts.
        notification_policy = getattr(
            self.dispatcher,
            "notification_policy",
            None,
        )
        set_stage_metadata_bridge = getattr(
            notification_policy,
            "set_stage_metadata_bridge",
            None,
        )
        if callable(set_stage_metadata_bridge):
            set_stage_metadata_bridge(
                writer=self._remember_stage_metadata_and_history,
                overlay=self.active_state_cache.overlay,
            )

        self.source_name = source_name or getattr(
            reader,
            "source_name",
            "unknown",
        )
        self.latest_snapshot: RawRaidSnapshot | None = None
        self.last_error: str | None = None
        self.last_update: float | None = None
        self.running = False

        # RAID_DISCORD_DELIVERY_RECOVERY_V1
        self._delivery_recovery_attempted_ids: set[str] = set()

    async def update_once(self) -> None:
        try:
            snapshot = await self.reader.read_snapshot()
            await self.process_snapshot_async(snapshot)
        except asyncio.CancelledError:
            raise
        except Exception as error:
            self.last_error = type(error).__name__
            self.latest_snapshot = RawRaidSnapshot(
                captured_at=time.time(),
                source=self.source_name,
                raids=(),
                connected=False,
                error=self.last_error,
            )

    def _events_from_snapshot(
        self,
        snapshot: RawRaidSnapshot,
    ) -> list[RaidEvent]:
        self.lifecycle.observe_snapshot(snapshot)
        self.latest_snapshot = snapshot
        self.last_error = snapshot.error
        if not snapshot.connected:
            return []

        new_flags = self.diff.mark_new(
            list(snapshot.raids),
            observed_at=snapshot.captured_at,
        )

        # RAID_DISCORD_DELIVERY_RECOVERY_V1
        recovered_ids: list[str] = []

        for raid in snapshot.raids:
            raid_id = str(
                raid.profound_id or ""
            ).strip()

            if not raid_id:
                continue

            if new_flags.get(raid_id, False):
                self._delivery_recovery_attempted_ids.add(
                    raid_id
                )
                continue

            if (
                raid_id
                in self._delivery_recovery_attempted_ids
            ):
                continue

            if self.dispatcher.has_discord_record(
                raid_id
            ):
                continue

            new_flags[raid_id] = True

            self._delivery_recovery_attempted_ids.add(
                raid_id
            )

            recovered_ids.append(raid_id)

        if recovered_ids:
            logger.warning(
                "Discord delivery recovery replay "
                "count=%s raid_ids=%s",
                len(recovered_ids),
                ",".join(recovered_ids),
            )

        self.last_update = snapshot.captured_at
        return [
            RaidEvent(
                event_type=(
                    RaidEventType.NEW
                    if new_flags.get(raid.profound_id, False)
                    else RaidEventType.UPDATED
                ),
                raid=raid,
                is_new=new_flags.get(raid.profound_id, False),
                first_seen_at=self.diff.first_seen_at(raid.profound_id),
            )
            for raid in snapshot.raids
        ]

    def process_snapshot(self, snapshot: RawRaidSnapshot) -> None:
        # Synchronous callers update health/state only. Notifications require
        # the async policy so its fixed batch window can be owned and cancelled.
        self._events_from_snapshot(snapshot)




    async def _persist_lifecycle_history(
        self,
        snapshot: RawRaidSnapshot,
    ) -> None:
        """
        BOSS_AVG_DEFEAT_TIME_SERVICE_V1
        BOSS_DEFEAT_ONLY_PERSISTENCE_V2
        BOSS_INFERRED_DEFEAT_V3_SERVICE

        Defeat sources:

        1. Explicit snapshot HP=0
           -> persist immediately.

        2. Raid disappears from otherwise valid public snapshots
           -> wait 180 seconds continuously.
           -> cancel if it reappears.
           -> require first disappearance to be >5 minutes
              before natural expires_at.
           -> ended_at = first disappearance time.

        Temporary disappearance is therefore NOT immediately
        considered a defeat.
        """

        repository = self.raid_history_repository

        if (
            repository is None
            or not snapshot.connected
            or snapshot.error is not None
        ):
            return

        now = float(snapshot.captured_at)

        current_raids = {
            str(raid.profound_id).strip(): raid
            for raid in snapshot.raids
            if str(raid.profound_id).strip()
        }

        current_ids = set(
            current_raids.keys()
        )

        # ----------------------------------------------------
        # Helper: persist one defeat
        # ----------------------------------------------------

        # RAID_ENDED_PERSISTENCE_V1
        async def persist_ended(
            raid_id: str,
            ended_at: float,
        ) -> bool:
            try:
                updated = await asyncio.to_thread(
                    repository.mark_lifecycle_ended,
                    raid_id=raid_id,
                    ended_at=ended_at,
                )

                if updated:
                    logger.info(
                        "Raid ended persisted "
                        "prf_code=%s "
                        "reason=public_disappearance_unverified",
                        raid_id,
                    )

                return bool(updated)

            except Exception as error:
                logger.warning(
                    "Raid ended persistence failed "
                    "prf_code=%s error=%s",
                    raid_id,
                    error,
                )

                return False


        async def persist_defeat(
            raid_id: str,
            ended_at: float,
            method: str,
        ) -> bool:

            # RAID_AUTHORITATIVE_HP_ZERO_ONLY_GUARD_V1
            if method != "hp_zero":

                logger.warning(
                    "Raid defeat rejected by "
                    "HP_ZERO_ONLY policy "
                    "prf_code=%s method=%s",
                    raid_id,
                    method,
                )

                return False

            try:
                updated = await asyncio.to_thread(
                    repository.mark_lifecycle_end,
                    raid_id=raid_id,
                    status="defeated",
                    ended_at=ended_at,
                    method=method,
                )

                if updated:
                    self._persisted_lifecycle_end_ids.add(
                        raid_id
                    )

                    logger.info(
                        "Raid defeat persisted "
                        "prf_code=%s method=%s",
                        raid_id,
                        method,
                    )

                    # RAID_AUTHORITATIVE_DEFEAT_DISCORD_BRIDGE_V2
                    # Only authoritative defeated persistence reaches
                    # Discord. Expired / transient disappearance does not.
                    try:
                        edited = (
                            await self.dispatcher
                            .dispatch_defeated_async(
                                raid_id,
                                active_raids=tuple(
                                    current_raids.values()
                                ),
                            )
                        )

                        logger.info(
                            "Raid defeat Discord bridge "
                            "prf_code=%s method=%s edited=%s",
                            raid_id,
                            method,
                            edited,
                        )

                    except Exception as discord_error:
                        # Discord must never break lifecycle persistence.
                        logger.warning(
                            "Raid defeat Discord bridge failed "
                            "prf_code=%s method=%s error=%s",
                            raid_id,
                            method,
                            type(discord_error).__name__,
                        )

                return bool(updated)

            except Exception as error:
                logger.warning(
                    "Raid defeat persistence failed "
                    "prf_code=%s method=%s error=%s",
                    raid_id,
                    method,
                    error,
                )

                return False

        # RAID_AUTHORITATIVE_HP_ZERO_ONLY_V1
        #
        # Safety policy:
        # ONLY an explicit observed HP == 0 may create
        # lifecycle_status=defeated and Discord ☠️.
        #
        # Missing visibility, timeout, expiry,
        # raid_code_error, damage inference, and
        # active probing are NOT defeat evidence.
        #
        for raid_id, raid in current_raids.items():

            if raid.hp != 0:
                continue

            if (
                raid_id
                in self._persisted_lifecycle_end_ids
            ):
                continue

            await persist_defeat(
                raid_id,
                now,
                "hp_zero",
            )

        # Old disappearance / V3 / V4 state must never
        # survive into an authoritative death decision.
        self._defeat_pending.clear()

        self._defeat_last_visible = dict(
            current_raids
        )

        self._defeat_previous_visible_ids = set(
            current_ids
        )

        return

        # ----------------------------------------------------
        # Any Raid that reappears cancels pending disappearance.
        # Also remember its latest full RaidEntry for expires_at.
        # ----------------------------------------------------

        for raid_id, raid in current_raids.items():

            self._defeat_last_visible[
                raid_id
            ] = raid

            if raid_id in self._defeat_pending:

                first_missing_at, _ = (
                    self._defeat_pending.pop(
                        raid_id
                    )
                )

                logger.info(
                    "Raid disappearance candidate cancelled "
                    "prf_code=%s missing_seconds=%.1f",
                    raid_id,
                    max(
                        0.0,
                        now - first_missing_at,
                    ),
                )

        # ----------------------------------------------------
        # Direct HP=0 remains highest-confidence evidence.
        # ----------------------------------------------------

        for raid_id, raid in current_raids.items():

            if raid.hp != 0:
                continue

            if (
                raid_id
                in self._persisted_lifecycle_end_ids
            ):
                continue

            updated = await persist_defeat(
                raid_id,
                now,
                "hp_zero",
            )

            if updated:
                self._defeat_pending.pop(
                    raid_id,
                    None,
                )

        # ----------------------------------------------------
        # Detect newly missing Raids.
        #
        # Safety:
        # If >=80% of a list with at least five Raids vanishes
        # in one snapshot, treat it as a suspicious source/list
        # dropout and DO NOT create defeat candidates.
        # ----------------------------------------------------

        previous_ids = (
            self._defeat_previous_visible_ids
        )

        missing_ids = (
            previous_ids - current_ids
        )

        previous_count = len(
            previous_ids
        )

        missing_count = len(
            missing_ids
        )

        mass_dropout = (
            previous_count >= 5
            and missing_count >= 4
            and (
                missing_count
                / previous_count
            ) >= 0.80
        )

        if mass_dropout:

            logger.warning(
                "Raid defeat inference skipped "
                "suspicious mass dropout "
                "previous=%s current=%s missing=%s",
                previous_count,
                len(current_ids),
                missing_count,
            )

        else:

            for raid_id in missing_ids:

                if (
                    raid_id
                    in self._persisted_lifecycle_end_ids
                ):
                    continue

                if raid_id in self._defeat_pending:
                    continue

                previous_raid = (
                    self._defeat_last_visible.get(
                        raid_id
                    )
                )

                if previous_raid is None:
                    continue

                expires_at_ms = (
                    int(previous_raid.expires_at)
                    if previous_raid.expires_at
                    is not None
                    else None
                )

                self._defeat_pending[
                    raid_id
                ] = (
                    now,
                    expires_at_ms,
                )

                logger.info(
                    "Raid disappearance candidate pending "
                    "prf_code=%s confirm_after=%ss",
                    raid_id,
                    int(
                        DEFEAT_MISSING_CONFIRM_SECONDS
                    ),
                )

        # ----------------------------------------------------
        # Confirm pending candidates.
        # ----------------------------------------------------

        for raid_id, pending in list(
            self._defeat_pending.items()
        ):

            if raid_id in current_ids:
                continue

            (
                first_missing_at,
                expires_at_ms,
            ) = pending

            missing_seconds = (
                now - first_missing_at
            )

            if (
                missing_seconds
                < DEFEAT_MISSING_CONFIRM_SECONDS
            ):
                continue

            # We require an expiry timestamp for inferred
            # defeat. Without it we cannot safely distinguish
            # disappearance from natural expiry.
            if expires_at_ms is None:

                logger.warning(
                    "Raid disappearance inference rejected "
                    "prf_code=%s reason=no_expires_at",
                    raid_id,
                )

                self._defeat_pending.pop(
                    raid_id,
                    None,
                )

                continue

            seconds_before_expiry = (
                expires_at_ms / 1000.0
                - first_missing_at
            )

            if (
                seconds_before_expiry
                <= DEFEAT_EXPIRY_GUARD_SECONDS
            ):

                logger.info(
                    "Raid disappearance inference rejected "
                    "prf_code=%s "
                    "reason=near_expiry "
                    "seconds_before_expiry=%.1f",
                    raid_id,
                    seconds_before_expiry,
                )

                self._defeat_pending.pop(
                    raid_id,
                    None,
                )

                self._defeat_last_visible.pop(
                    raid_id,
                    None,
                )

                continue

            # RAID_ENDED_PERSISTENCE_V1
            #
            # 180s disappearance confirms only that public
            # observation ended. It does NOT confirm defeat.
            #
            # Keep the existing live verification below:
            # authoritative HP=0 may still upgrade
            # ended -> defeated.
            await persist_ended(
                raid_id,
                first_missing_at,
            )

            # RAID_DISAPPEARANCE_IS_NOT_DEFEAT_V1
            #
            # A Raid disappearing from raid_support_list is NOT
            # RAID_ACTIVE_DEATH_VERIFY_V3
            #
            # Continuous disappearance is NOT enough
            # to declare defeat.
            #
            # Actively verify this exact public Raid code.
            #
            verifier = self.treasure_enricher

            if (
                verifier is None
                or not hasattr(
                    verifier,
                    "probe_live",
                )
            ):

                logger.warning(
                    "Raid defeat verification deferred "
                    "prf_code=%s "
                    "reason=no_live_verifier",
                    raid_id,
                )

                self._defeat_pending[
                    raid_id
                ] = (
                    now,
                    expires_at_ms,
                )

                continue

            try:

                verification = (
                    await verifier.probe_live(
                        raid_id
                    )
                )

            except Exception as verify_error:

                logger.warning(
                    "Raid defeat verification failed "
                    "prf_code=%s "
                    "error=%s",
                    raid_id,
                    type(
                        verify_error
                    ).__name__,
                )

                self._defeat_pending[
                    raid_id
                ] = (
                    now,
                    expires_at_ms,
                )

                continue

            metadata = (
                verification.metadata
                if isinstance(
                    verification.metadata,
                    dict,
                )
                else None
            )

            verified_hp = None
            verified_hp_max = None

            if metadata is not None:

                try:

                    raw_hp = metadata.get(
                        "hp"
                    )

                    if (
                        raw_hp is not None
                        and not isinstance(
                            raw_hp,
                            bool,
                        )
                    ):
                        verified_hp = int(
                            raw_hp
                        )

                except (
                    TypeError,
                    ValueError,
                ):
                    verified_hp = None

                try:

                    raw_hp_max = metadata.get(
                        "hp_max"
                    )

                    if (
                        raw_hp_max is not None
                        and not isinstance(
                            raw_hp_max,
                            bool,
                        )
                    ):
                        verified_hp_max = int(
                            raw_hp_max
                        )

                except (
                    TypeError,
                    ValueError,
                ):
                    verified_hp_max = None

            logger.info(
                "Raid defeat active verification "
                "prf_code=%s "
                "status=%s "
                "error=%s "
                "hp=%s "
                "hp_max=%s "
                "cleanup_confirmed=%s",
                raid_id,
                verification.status,
                verification.error,
                verified_hp,
                verified_hp_max,
                verification.cleanup_confirmed,
            )

            # --------------------------------------------
            # 明確 HP > 0
            # Raid 還活著。
            #
            # 這就是之前 25/3500 那種案例。
            # 絕對不能畫骷髏。
            # --------------------------------------------

            if (
                verified_hp is not None
                and verified_hp > 0
            ):

                logger.info(
                    "Raid defeat candidate kept alive "
                    "prf_code=%s "
                    "verified_hp=%s",
                    raid_id,
                    verified_hp,
                )

                # 重新開始 180 秒觀察。
                self._defeat_pending[
                    raid_id
                ] = (
                    now,
                    expires_at_ms,
                )

                continue

            # --------------------------------------------
            # HP 無法確認。
            #
            # 例如：
            # join_rejected
            # raid_code_error
            # timeout
            # metadata_missing
            #
            # 目前一律不能猜成死亡。
            # --------------------------------------------

            if verified_hp != 0:

                # RAID_ACTIVE_DEATH_VERIFY_V4
                #
                # probe_live may return join_rejected after
                # the Raid has already disappeared from the
                # server's joinable set.
                #
                # join_rejected alone is NOT death evidence.
                #
                # Secondary authoritative evidence:
                #
                # SUM(raid_player_history.damage) >= hp_max
                #
                damage_evidence = {}

                history_repository = (
                    self.raid_history_repository
                )

                if (
                    history_repository is not None
                    and hasattr(
                        history_repository,
                        "fetch_raid_damage_evidence",
                    )
                ):

                    try:

                        damage_evidence = (
                            await asyncio.to_thread(
                                history_repository
                                .fetch_raid_damage_evidence,
                                raid_id,
                            )
                        )

                    except Exception as evidence_error:

                        logger.warning(
                            "Raid defeat damage evidence "
                            "lookup failed "
                            "prf_code=%s "
                            "error=%s",
                            raid_id,
                            type(
                                evidence_error
                            ).__name__,
                        )

                        damage_evidence = {}

                evidence_hp_max = None
                evidence_damage = None

                try:
                    evidence_hp_max = int(
                        damage_evidence.get(
                            "hp_max"
                        )
                    )
                except (
                    TypeError,
                    ValueError,
                ):
                    evidence_hp_max = None

                try:
                    evidence_damage = int(
                        damage_evidence.get(
                            "total_damage"
                        )
                    )
                except (
                    TypeError,
                    ValueError,
                ):
                    evidence_damage = None

                damage_confirms_defeat = bool(
                    evidence_hp_max is not None
                    and evidence_hp_max > 0
                    and evidence_damage is not None
                    and evidence_damage
                        >= evidence_hp_max
                )

                logger.info(
                    "Raid defeat damage evidence "
                    "prf_code=%s "
                    "status=%s "
                    "damage=%s "
                    "hp_max=%s "
                    "confirmed=%s",
                    raid_id,
                    verification.status,
                    evidence_damage,
                    evidence_hp_max,
                    damage_confirms_defeat,
                )

                if damage_confirms_defeat:

                    updated = await persist_defeat(
                        raid_id,
                        now,
                        "hp_zero",
                    )

                    if updated:

                        logger.info(
                            "Raid verified defeated "
                            "prf_code=%s "
                            "method=damage_sum_ge_hpmax "
                            "damage=%s "
                            "hp_max=%s",
                            raid_id,
                            evidence_damage,
                            evidence_hp_max,
                        )

                        self._defeat_pending.pop(
                            raid_id,
                            None,
                        )

                        self._defeat_last_visible.pop(
                            raid_id,
                            None,
                        )

                    else:

                        logger.warning(
                            "Raid damage evidence "
                            "confirmed defeat but "
                            "persistence did not update "
                            "prf_code=%s",
                            raid_id,
                        )

                        self._defeat_pending[
                            raid_id
                        ] = (
                            now,
                            expires_at_ms,
                        )

                    continue

                logger.info(
                    "Raid defeat verification inconclusive "
                    "prf_code=%s "
                    "status=%s "
                    "error=%s "
                    "damage=%s "
                    "hp_max=%s",
                    raid_id,
                    verification.status,
                    verification.error,
                    evidence_damage,
                    evidence_hp_max,
                )

                # Still no authoritative defeat evidence.
                # Retry after another 180 seconds.
                self._defeat_pending[
                    raid_id
                ] = (
                    now,
                    expires_at_ms,
                )

                continue

            # --------------------------------------------
            # db_raid 明確證明 HP == 0
            #
            # 才真正進 authoritative defeat path：
            #
            # persist defeated
            # -> Discord death bridge
            # -> ☠️
            # --------------------------------------------

            updated = await persist_defeat(
                raid_id,
                now,
                "hp_zero",
            )

            if updated:

                logger.info(
                    "Raid verified defeated "
                    "prf_code=%s "
                    "method=active_probe_hp_zero "
                    "missing_seconds=%.1f",
                    raid_id,
                    missing_seconds,
                )

                self._defeat_pending.pop(
                    raid_id,
                    None,
                )

                self._defeat_last_visible.pop(
                    raid_id,
                    None,
                )

            else:

                logger.warning(
                    "Raid verified HP zero but "
                    "persistence did not update "
                    "prf_code=%s",
                    raid_id,
                )

                self._defeat_pending[
                    raid_id
                ] = (
                    now,
                    expires_at_ms,
                )

        # ----------------------------------------------------
        # Only advance normal visibility baseline when this
        # snapshot does not look like a catastrophic list drop.
        # ----------------------------------------------------

        if not mass_dropout:
            self._defeat_previous_visible_ids = (
                current_ids
            )
        else:
            self._defeat_previous_visible_ids |= (
                current_ids
            )



    # RAID_DISCORD_STARTUP_RECONCILE_V1
    async def _reconcile_discord_deaths_once(
        self,
        snapshot: RawRaidSnapshot,
    ) -> None:
        if getattr(
            self,
            "_discord_death_reconciled",
            False,
        ):
            return

        if (
            not snapshot.connected
            or snapshot.error is not None
        ):
            return

        repository = self.raid_history_repository

        if repository is None:
            return

        tracked = (
            self.dispatcher
            .tracked_unmarked_raid_ids()
        )

        if not tracked:
            self._discord_death_reconciled = True

            logger.info(
                "Discord death startup reconciliation "
                "tracked=0 defeated=0 edited=0"
            )

            return

        try:
            statuses = await asyncio.to_thread(
                repository.fetch_lifecycle_statuses,
                tracked,
            )

        except Exception as error:
            logger.warning(
                "Discord death startup reconciliation "
                "DB query failed error=%s",
                type(error).__name__,
            )

            # Fail open for the Raid Bot itself.
            # Retry on the next valid snapshot.
            return

        defeated_ids = [
            raid_id
            for raid_id in tracked
            if statuses.get(raid_id) == "defeated"
        ]

        edited = 0

        for raid_id in defeated_ids:
            try:
                ok = (
                    await self.dispatcher
                    .dispatch_defeated_async(
                        raid_id,
                        active_raids=snapshot.raids,
                    )
                )

                if ok:
                    edited += 1

            except Exception as error:
                logger.warning(
                    "Discord startup death edit failed "
                    "prf_code=%s error=%s",
                    raid_id,
                    type(error).__name__,
                )

        self._persisted_lifecycle_end_ids.update(
            defeated_ids
        )

        self._discord_death_reconciled = True

        logger.info(
            "Discord death startup reconciliation "
            "tracked=%d defeated=%d edited=%d",
            len(tracked),
            len(defeated_ids),
            edited,
        )


    # RAID_HISTORY_PUBLIC_SNAPSHOT_V2
    async def _persist_public_history_snapshot(
        self,
        snapshot: RawRaidSnapshot,
    ) -> None:
        repository = self.raid_history_repository

        if (
            repository is None
            or not snapshot.connected
            or snapshot.error is not None
            or not snapshot.raids
        ):
            return

        now = time.time()

        last_write = getattr(
            self,
            "_public_history_last_write_at",
            0.0,
        )

        # db_raid_support arrives every ~2 seconds.
        # History only needs a periodic visibility snapshot.
        if now - last_write < 30.0:
            return

        try:
            count = await asyncio.to_thread(
                repository.upsert_public_snapshots,
                tuple(snapshot.raids),
            )

            # RAID_STAGE_HISTORY_REPLAY_V2
            #
            # A stage report can arrive before the first public
            # raid_history row exists. Replay the persisted active
            # stage cache after the row has been upserted.
            stage_replayed = 0
            fragment_replayed = 0
            derived_reconciled = 0

            for raid in snapshot.raids:
                record = self.active_state_cache.get(
                    raid.profound_id
                )

                if record is None:
                    continue

                if (
                    record.stage_id is not None
                    or record.rarity is not None
                ):
                    changed = await asyncio.to_thread(
                        # RAID_REPLAY_STAGE_SOURCE_PROVENANCE_V1
                        repository.update_stage_observation,
                        raid_id=raid.profound_id,
                        stage_id=record.stage_id,
                        rarity=record.rarity,
                        source=(
                            record.stage_source
                            or "existing_pre_provenance"
                        ),
                    )

                    if changed:
                        stage_replayed += 1

                if (
                    isinstance(record.fragment, str)
                    and record.fragment
                    and isinstance(record.stage_source, str)
                    and record.stage_source
                ):
                    outcome = await asyncio.to_thread(
                        repository.update_fragment_observation,
                        raid_id=raid.profound_id,
                        fragment=record.fragment,
                        source=record.stage_source,
                    )

                    if outcome.get("updated"):
                        fragment_replayed += 1

            # RAID_PUBLIC_COORD_RECONCILE_V1
            for public_raid in snapshot.raids:

                reconcile = await asyncio.to_thread(
                    repository.reconcile_protocol14_derived_metadata,
                    raid_id=public_raid.profound_id,
                )

                if reconcile.get("updated"):
                    derived_reconciled += 1

        except Exception as error:
            logger.exception(
                "Raid public history batch failed "
                "error=%s",
                type(error).__name__,
            )
            return

        # Advance throttle only after a successful DB write.
        self._public_history_last_write_at = now

        logger.info(
            "Raid public history batch persisted=%d "
            "stage_replayed=%d fragment_replayed=%d "
            "derived_reconciled=%d",
            count,
            stage_replayed,
            fragment_replayed,
            derived_reconciled,
        )

        # RAID_HISTORY_FRAGMENT_FORMULA_V1
        #
        # Use the existing production fragment mapping.
        # Never duplicate the stage/rarity formula here.
        notification_policy = getattr(
            self.dispatcher,
            "notification_policy",
            None,
        )

        fragment_mapper = getattr(
            notification_policy,
            "_stage_fragment_icon",
            None,
        )

        if callable(fragment_mapper):
            for public_raid in snapshot.raids:
                try:
                    raid = (
                        self.active_state_cache
                        .overlay(public_raid)
                    )

                    if (
                        raid.stage_id is None
                        or raid.rarity is None
                    ):
                        continue

                    fragment = fragment_mapper(
                        raid.stage_id,
                        raid.rarity,
                    )

                    if not fragment:
                        continue

                    await asyncio.to_thread(
                        repository.update_fragment_observation,
                        raid_id=raid.profound_id,
                        fragment=fragment,
                        source="formula",
                    )

                except Exception as error:
                    logger.warning(
                        "Raid fragment formula history failed "
                        "raid_id=%s error=%s",
                        getattr(
                            public_raid,
                            "profound_id",
                            None,
                        ),
                        type(error).__name__,
                    )


    # RAID_CANONICAL_FRAGMENT_CACHE_BRIDGE_V1
    _CANONICAL_FRAGMENT_ICONS = {
        "黃": "🟡",
        "綠": "🟢",
        "藍": "🔵",
        "紅": "🔴",
        "紫": "🟣",
    }

    async def _sync_canonical_fragment_cache(
        self,
        snapshot: RawRaidSnapshot,
    ) -> None:
        """Bridge canonical DB fragment metadata into live Raid state.

        This is read-only against the game server.  The collector has
        already obtained db_raid using raid_code_input and reconciled
        monster_id + map_index into canonical history.
        """

        repository = self.raid_history_repository

        if (
            repository is None
            or not snapshot.connected
            or snapshot.error is not None
            or not snapshot.raids
        ):
            return

        raid_ids = tuple(
            raid.profound_id
            for raid in snapshot.raids
            if raid.profound_id
        )

        if not raid_ids:
            return

        try:
            rows = await asyncio.to_thread(
                repository.fetch_canonical_fragment_metadata,
                raid_ids,
            )

        except Exception as error:
            logger.warning(
                "Canonical Raid fragment cache sync failed "
                "error=%s",
                type(error).__name__,
            )
            return

        for raid_id, row in rows.items():

            color = row.get(
                "fragment_color"
            )

            fragment = (
                self._CANONICAL_FRAGMENT_ICONS
                .get(color)
            )

            if fragment is None:
                continue

            source = row.get(
                "fragment_source"
            )

            if (
                not isinstance(source, str)
                or not source.strip()
            ):
                source = (
                    "existing_pre_provenance"
                )

            self.active_state_cache.remember_stage_metadata(
                raid_id,
                stage_id=row.get(
                    "stage_id"
                ),
                rarity=row.get(
                    "rarity"
                ),
                fragment=fragment,
                source=source,
            )


    async def process_snapshot_async(self, snapshot: RawRaidSnapshot) -> None:
        events = self._events_from_snapshot(snapshot)

        await self._persist_public_history_snapshot(
            snapshot
        )

        await self._persist_lifecycle_history(
            snapshot
        )

        # RAID_CANONICAL_FRAGMENT_CACHE_BRIDGE_V1
        # Collector already reconciles db_raid map_index -> stage
        # -> fragment in MySQL.  Pull that canonical result back
        # into the live cache before website/Discord consumers run.
        await self._sync_canonical_fragment_cache(
            snapshot
        )

        if snapshot.connected:
            await self._reconcile_discord_deaths_once(
                snapshot
            )

            await self.dispatcher.dispatch_async(
                events,
                active_raids=snapshot.raids,
            )

    async def _state_refresh_loop(self) -> None:
        """Round-robin live-state refresh for all active public Raids."""
        await asyncio.sleep(2.0)

        while self.running:
            snapshot = self.latest_snapshot

            if (
                self.treasure_enricher is None
                or snapshot is None
                or not snapshot.connected
                or not snapshot.raids
            ):
                await asyncio.sleep(5.0)
                continue

            active_raids = list(snapshot.raids)
            active_ids = {
                raid.profound_id
                for raid in active_raids
            }

            # Drop scheduler timestamps for Raids no longer active.
            # Do NOT clear ActiveRaidStateCache here, so recent-ended cards
            # can retain their last successful state snapshot.
            self._state_refresh_last_attempt = {
                raid_id: attempted_at
                for raid_id, attempted_at
                in self._state_refresh_last_attempt.items()
                if raid_id in active_ids
            }

            if self._state_refresh_cursor >= len(active_raids):
                self._state_refresh_cursor = 0

            now = time.time()
            selected = None
            selected_index = None

            for offset in range(len(active_raids)):
                index = (
                    self._state_refresh_cursor + offset
                ) % len(active_raids)

                raid = active_raids[index]

                last_attempt = self._state_refresh_last_attempt.get(
                    raid.profound_id
                )

                if (
                    last_attempt is None
                    or now - last_attempt >= STATE_REFRESH_SECONDS
                ):
                    selected = raid
                    selected_index = index
                    break

            if selected is None:
                await asyncio.sleep(STATE_REFRESH_SPACING_SECONDS)
                continue

            self._state_refresh_cursor = (
                (selected_index + 1) % len(active_raids)
            )

            prf_code = selected.profound_id

            self._state_refresh_last_attempt[prf_code] = time.time()
            self.active_state_cache.mark_attempt(prf_code)

            try:
                enriched = await self.treasure_enricher.refresh_live_state(
                    selected
                )

            except asyncio.CancelledError:
                raise

            except Exception as error:
                error_name = type(error).__name__

                self.active_state_cache.mark_failure(
                    prf_code,
                    error_name,
                )

                logger.warning(
                    "Raid state refresh failed "
                    "prf_code=%s error=%s",
                    prf_code,
                    error_name,
                )

            else:
                if enriched.status == "success":
                    # Capture previous successful live-state before replacing it.
                    previous_state = self.active_state_cache.get(prf_code)

                    self.active_state_cache.mark_success(
                        prf_code,
                        enriched.raid.statuses,
                        enriched.raid.players,
                    )

                    if self.raid_history_repository is not None:
                        try:
                            history_raid = enriched.raid

                            if (
                                self.fragment_resolver is not None
                                and history_raid.treasure_level is not None
                            ):
                                reward_record = await asyncio.to_thread(
                                    self.fragment_resolver.get_reward_record,
                                    history_raid.treasure_level,
                                )

                                if reward_record is not None:
                                    reward_rarity = reward_record.get("rarity")
                                    try:
                                        reward_rarity = int(reward_rarity)
                                    except (TypeError, ValueError):
                                        reward_rarity = None

                                    whirlpool_tier = reward_record.get(
                                        "whirlpoolTier"
                                    )
                                    if isinstance(whirlpool_tier, str):
                                        whirlpool_tier = whirlpool_tier.strip()
                                        if not whirlpool_tier:
                                            whirlpool_tier = None
                                    else:
                                        whirlpool_tier = None

                                    history_raid = replace(
                                        history_raid,
                                        rarity=(
                                            reward_rarity
                                            if reward_rarity is not None
                                            else history_raid.rarity
                                        ),
                                        whirlpool_tier=(
                                            whirlpool_tier
                                            if whirlpool_tier is not None
                                            else history_raid.whirlpool_tier
                                        ),
                                    )

                            await asyncio.to_thread(
                                self.raid_history_repository.upsert_snapshot,
                                history_raid,
                            )

                            # Baseline-only on the first successful observation.
                            # Attribution runs after raid_history exists because
                            # raid_support_events has a foreign-key dependency.
                            if (
                                previous_state is not None
                                and previous_state.last_success_at is not None
                            ):
                                try:
                                    support_event_count = await asyncio.to_thread(
                                        self.raid_history_repository.record_support_events,
                                        raid=history_raid,
                                        previous_statuses=previous_state.statuses,
                                        previous_players=previous_state.players,
                                    )
                                    if support_event_count:
                                        logger.info(
                                            "Raid support attribution "
                                            "prf_code=%s events=%d",
                                            prf_code,
                                            support_event_count,
                                        )
                                except Exception as support_error:
                                    logger.warning(
                                        "Raid support attribution failed "
                                        "prf_code=%s error=%s",
                                        prf_code,
                                        support_error,
                                    )
                        except Exception as error:
                            # History persistence is non-critical.
                            # Never interrupt state refresh / Discord.
                            logger.warning(
                                "Raid history write failed "
                                "prf_code=%s error=%s",
                                prf_code,
                                error,
                            )

                    logger.info(
                        "Raid state refreshed "
                        "prf_code=%s statuses=%d",
                        prf_code,
                        len(enriched.raid.statuses),
                    )

                else:
                    self.active_state_cache.mark_failure(
                        prf_code,
                        enriched.status,
                    )

                    logger.warning(
                        "Raid state refresh failed "
                        "prf_code=%s status=%s",
                        prf_code,
                        enriched.status,
                    )

            await asyncio.sleep(STATE_REFRESH_SPACING_SECONDS)


    async def run_forever(self) -> None:
        self.running = True

        if self.treasure_enricher is not None:
            self._state_refresh_task = asyncio.create_task(
                self._state_refresh_loop(),
                name="raid-state-refresh",
            )

        try:
            while self.running:
                await self.update_once()
                if not getattr(self.reader, "push_driven", False):
                    await asyncio.sleep(self.interval_seconds)
        except asyncio.CancelledError:
            raise
        finally:
            if self._state_refresh_task is not None:
                self._state_refresh_task.cancel()
                with suppress(asyncio.CancelledError):
                    await self._state_refresh_task
                self._state_refresh_task = None

            with suppress(asyncio.CancelledError):
                await self.dispatcher.close()
            close = getattr(self.reader, "close", None)
            if close is not None:
                with suppress(asyncio.CancelledError):
                    result = close()
                    if asyncio.iscoroutine(result):
                        await result

    def stop(self) -> None:
        self.running = False

    def get_snapshot(self) -> RawRaidSnapshot | None:
        return self.latest_snapshot

    def hydrate_cached_metadata(self, raid: RaidEntry) -> RaidEntry:
        """Return static metadata plus current in-memory Raid state."""
        hydrated = raid

        if self.treasure_enricher is not None:
            hydrated = self.treasure_enricher.hydrate_cached(raid).raid

        return self.active_state_cache.overlay(hydrated)

    def is_connected(self) -> bool:
        if hasattr(self.reader, "connected"):
            return bool(self.reader.connected)
        return bool(self.latest_snapshot and self.latest_snapshot.connected)

    def get_last_error(self) -> str | None:
        source_error = getattr(self.reader, "last_error", None)
        return source_error or self.last_error
