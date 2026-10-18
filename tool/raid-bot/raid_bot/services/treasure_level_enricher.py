from __future__ import annotations

import asyncio
import logging
from dataclasses import dataclass, field, replace
from typing import Any

from ..domain.models import RaidEntry, RaidPlayer
from ..domain.status_rules import parse_raid_statuses
from ..infrastructure.public_raid_enrichment_client import (
    PublicRaidEnrichmentResult,
)
from ..infrastructure.treasure_level_cache import TreasureLevelCache


logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class EnrichedRaid:
    raid: RaidEntry
    status: str
    cleanup_attempted: bool = False
    cleanup_confirmed: bool = False
    cached: bool = False


NEW_PRIORITY = 0
LIVE_VERIFY_PRIORITY = 5
STATE_REFRESH_PRIORITY = 5
BACKFILL_PRIORITY = 10


@dataclass(order=True)
class _QueueItem:
    priority: int
    sequence: int
    prf_code: str = field(compare=False)
    future: asyncio.Future[PublicRaidEnrichmentResult] = field(compare=False)


def _optional_int(value: Any) -> int | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _optional_text(value: Any) -> str | None:
    return value.strip() if isinstance(value, str) and value.strip() else None


def _parse_players(value: Any) -> tuple[RaidPlayer, ...]:
    if not isinstance(value, list):
        return ()

    players: list[RaidPlayer] = []

    for index, item in enumerate(value):
        if not isinstance(item, dict):
            continue

        name = item.get("name")
        if not isinstance(name, str):
            name = ""
        else:
            name = name.strip()

        point = _optional_int(item.get("point"))
        damage = _optional_int(item.get("damage"))

        players.append(
            RaidPlayer(
                rank=index + 1,
                name=name,
                point=point if point is not None else 0,
                damage=damage if damage is not None else 0,
                is_me=False,
            )
        )

    return tuple(players)


# RAID_ENRICHMENT_SCHEMA14_V3
def _parse_reward_items(
    value: Any,
) -> tuple[dict[str, Any], ...]:

    if not isinstance(value, list):
        return ()

    return tuple(
        dict(item)
        for item in value
        if isinstance(item, dict)
    )


def apply_metadata(
    raid: RaidEntry,
    metadata: dict[str, Any],
) -> RaidEntry:

    rarity = _optional_int(
        metadata.get("rarity")
    )

    whirlpool_tier = _optional_text(
        metadata.get("whirlpoolTier")
    )

    level = _optional_int(
        metadata.get("level")
    )

    # Protocol 1.4: map_index
    # Legacy cache: map
    map_id = _optional_int(
        metadata.get("map_index")
    )

    if map_id is None:
        map_id = _optional_int(
            metadata.get("map")
        )

    # No verified 1.4 stage equivalent yet.
    stage_id = _optional_int(
        metadata.get("stage")
    )

    # Keep old TL compatibility only.
    treasure_level = _optional_int(
        metadata.get("treasure_level")
    )

    monster_id = _optional_int(
        metadata.get("monster_id")
    )

    hp = _optional_int(
        metadata.get("hp")
    )

    hp_max = _optional_int(
        metadata.get("hp_max")
    )

    raw_state = metadata.get("state")

    statuses = (
        parse_raid_statuses(raw_state)
        if isinstance(raw_state, list)
        else raid.statuses
    )

    # IMPORTANT:
    #
    # Protocol 1.4 rank[] currently contains:
    #   player_name / point / level
    #
    # It does NOT contain verified damage.
    #
    # Therefore rank[] MUST NOT be converted into
    # RaidEntry.players. Doing so would turn
    # "damage unknown" into false damage=0.
    #
    # Only legacy points[] may populate players.
    raw_points = metadata.get("points")

    players = (
        _parse_players(raw_points)
        if isinstance(raw_points, list)
        else raid.players
    )

    reward_items = (
        _parse_reward_items(
            metadata.get("reward")
        )
        if isinstance(
            metadata.get("reward"),
            list,
        )
        else raid.reward_items
    )

    merged_raw = dict(
        raid.raw
        if isinstance(raid.raw, dict)
        else {}
    )

    # Preserve rank[] and all new schema fields
    # for later protocol research.
    merged_raw["enrichment"] = dict(
        metadata
    )

    return replace(
        raid,

        monster_id=(
            monster_id
            if monster_id is not None
            else raid.monster_id
        ),

        monster_code=(
            _optional_text(
                metadata.get("profound_mons")
            )
            or raid.monster_code
        ),

        boss_name=(
            raid.boss_name
            or _optional_text(
                metadata.get("name")
            )
            or _optional_text(
                metadata.get("name_tcn")
            )
        ),

        founder=(
            raid.founder
            or _optional_text(
                metadata.get("founder")
            )
            or _optional_text(
                metadata.get(
                    "profound_founder"
                )
            )
        ),

        rarity=(
            rarity
            if rarity is not None
            else raid.rarity
        ),

        whirlpool_tier=(
            whirlpool_tier
            if whirlpool_tier is not None
            else raid.whirlpool_tier
        ),

        level=(
            level
            if level is not None
            else raid.level
        ),

        map_id=(
            map_id
            if map_id is not None
            else raid.map_id
        ),

        stage_id=(
            stage_id
            if stage_id is not None
            else raid.stage_id
        ),

        hp=(
            hp
            if hp is not None
            else raid.hp
        ),

        hp_max=(
            hp_max
            if hp_max is not None
            else raid.hp_max
        ),

        treasure_level=(
            treasure_level
            if treasure_level is not None
            else raid.treasure_level
        ),

        reward_items=reward_items,

        statuses=statuses,

        players=players,

        raw=merged_raw,
    )


class TreasureLevelEnricher:
    def __init__(
        self,
        client,
        cache: TreasureLevelCache,
        *,
        timeout_seconds: float = 10.0,
        history_writer=None,
    ) -> None:
        if timeout_seconds <= 0:
            raise ValueError("enrichment timeout must be positive")
        self.client = client
        self.cache = cache
        self.timeout_seconds = timeout_seconds
        self._queue: asyncio.PriorityQueue[_QueueItem] = asyncio.PriorityQueue()
        self._pending: dict[str, asyncio.Future[PublicRaidEnrichmentResult]] = {}
        self._pending_priorities: dict[str, int] = {}
        self._pending_persist: dict[str, bool] = {}
        self._worker_task: asyncio.Task[None] | None = None
        self._closed = False
        self._sequence = 0
        self.history_writer = history_writer

    def _ensure_worker(self) -> None:
        if self._closed:
            raise RuntimeError("TreasureLevelEnricher is closed")
        if self._worker_task is None or self._worker_task.done():
            self._worker_task = asyncio.create_task(
                self._worker(),
                name="raid-treasure-level-enricher",
            )

    async def _worker(self) -> None:
        while True:
            item = await self._queue.get()
            try:
                if (
                    self._pending.get(item.prf_code) is not item.future
                    or item.future.done()
                    or self._pending_priorities.get(item.prf_code)
                    != item.priority
                ):
                    continue
                try:
                    result = await self.client.enrich(item.prf_code)
                except asyncio.CancelledError:
                    if not item.future.done():
                        item.future.cancel()
                    raise
                except Exception as error:
                    result = PublicRaidEnrichmentResult(
                        status="client_error",
                        error=type(error).__name__,
                    )
                if (
                    result.ok
                    and result.metadata is not None
                    and self._pending_persist.get(item.prf_code, True)
                ):
                    try:
                        self.cache.put(item.prf_code, result.metadata)
                    except Exception as error:
                        logger.warning(
                            "Raid enrichment cache write failed (%s); continuing",
                            type(error).__name__,
                        )

                    if self.history_writer is not None:
                        try:
                            self.history_writer.append(
                                public_raid_code=item.prf_code,
                                metadata=result.metadata,
                            )
                        except Exception as error:
                            logger.warning(
                                "Raid TL history write failed prf_code=%s error=%s; continuing",
                                item.prf_code,
                                type(error).__name__,
                            )
                
                if (
                    not result.cleanup_confirmed
                    and (
                        result.cleanup_lookup_attempted
                        or result.cleanup_attempted
                    )
                ):
                    logger.error(
                        "Raid enrichment cleanup was not confirmed; "
                        "status=%s error=%s cleanup_attempted=%s; continuing",
                        result.status,
                        result.error,
                        result.cleanup_attempted,
                    )
                if not item.future.done():
                    item.future.set_result(result)
            finally:
                if self._pending.get(item.prf_code) is item.future:
                    self._pending.pop(item.prf_code, None)
                    self._pending_priorities.pop(item.prf_code, None)
                    self._pending_persist.pop(item.prf_code, None)
                self._queue.task_done()

    async def _enqueue(
        self,
        prf_code: str,
        *,
        priority: int,
        persist: bool = True,
    ) -> tuple[asyncio.Future[PublicRaidEnrichmentResult], bool]:
        self._ensure_worker()
        future = self._pending.get(prf_code)
        current_priority = self._pending_priorities.get(prf_code)
        if future is not None:
            if persist:
                self._pending_persist[prf_code] = True
            if current_priority is not None and priority < current_priority:
                self._pending_priorities[prf_code] = priority
                self._sequence += 1
                await self._queue.put(
                    _QueueItem(priority, self._sequence, prf_code, future)
                )
            return future, False

        future = asyncio.get_running_loop().create_future()
        self._pending[prf_code] = future
        self._pending_priorities[prf_code] = priority
        self._pending_persist[prf_code] = persist
        self._sequence += 1
        await self._queue.put(
            _QueueItem(priority, self._sequence, prf_code, future)
        )
        return future, True

    async def refresh_live_state(
        self,
        raid: RaidEntry,
    ) -> EnrichedRaid:
        # Force a real protocol query. Do not short-circuit through TL cache.
        future, _ = await self._enqueue(
            raid.profound_id,
            priority=STATE_REFRESH_PRIORITY,
            persist=False,
        )
        return await self._await_result(raid, future)

    def hydrate_cached(self, raid: RaidEntry) -> EnrichedRaid:
        if raid.treasure_level is not None:
            return EnrichedRaid(raid=raid, status="already_enriched")
        cached = self.cache.get(raid.profound_id)
        if cached is None:
            return EnrichedRaid(raid=raid, status="cache_miss")
        return EnrichedRaid(
            raid=apply_metadata(raid, cached),
            status="cached",
            cached=True,
        )

    def hydrate_cached_many(self, raids: list[RaidEntry]) -> list[EnrichedRaid]:
        return [self.hydrate_cached(raid) for raid in raids]

    async def enqueue_backfill(self, raid: RaidEntry) -> bool:
        if self.hydrate_cached(raid).status != "cache_miss":
            return False
        _, queued = await self._enqueue(
            raid.profound_id,
            priority=BACKFILL_PRIORITY,
        )
        return queued

    async def backfill_many(self, raids: list[RaidEntry]) -> int:
        queued = 0
        for raid in raids:
            if await self.enqueue_backfill(raid):
                queued += 1
        return queued

    async def probe_live(
        self,
        prf_code: str,
    ) -> PublicRaidEnrichmentResult:
        # RAID_ACTIVE_DEATH_VERIFY_V3
        #
        # Do NOT use TreasureLevelCache here.
        # Death verification needs current server state.
        #
        # Existing worker path:
        #
        # raid_code_input
        # -> db_raid
        # -> BEFORE/AFTER profound_id diff
        # -> cleanup
        #
        code = (
            prf_code.strip()
            if isinstance(prf_code, str)
            else ""
        )

        if not code:
            return PublicRaidEnrichmentResult(
                status="invalid_prf_code"
            )

        future, _ = await self._enqueue(
            code,
            priority=LIVE_VERIFY_PRIORITY,
        )

        try:
            return await asyncio.wait_for(
                asyncio.shield(future),
                timeout=self.timeout_seconds,
            )

        except asyncio.TimeoutError:
            return PublicRaidEnrichmentResult(
                status="timeout"
            )


    async def enrich(self, raid: RaidEntry) -> EnrichedRaid:
        hydrated = self.hydrate_cached(raid)
        if hydrated.status != "cache_miss":
            return hydrated

        future, _ = await self._enqueue(
            raid.profound_id,
            priority=NEW_PRIORITY,
        )

        return await self._await_result(raid, future)

    async def _await_result(
        self,
        raid: RaidEntry,
        future: asyncio.Future[PublicRaidEnrichmentResult],
    ) -> EnrichedRaid:

        try:
            result = await asyncio.wait_for(
                asyncio.shield(future),
                timeout=self.timeout_seconds,
            )
        except asyncio.TimeoutError:
            return EnrichedRaid(raid=raid, status="timeout")

        if not result.ok or result.metadata is None:
            return EnrichedRaid(
                raid=raid,
                status=result.status,
                cleanup_attempted=result.cleanup_attempted,
                cleanup_confirmed=result.cleanup_confirmed,
            )
        return EnrichedRaid(
            raid=apply_metadata(raid, result.metadata),
            status="success",
            cleanup_attempted=result.cleanup_attempted,
            cleanup_confirmed=result.cleanup_confirmed,
        )

    async def enrich_many(self, raids: list[RaidEntry]) -> list[EnrichedRaid]:
        prepared: list[
            EnrichedRaid
            | tuple[RaidEntry, asyncio.Future[PublicRaidEnrichmentResult]]
        ] = []
        for raid in raids:
            hydrated = self.hydrate_cached(raid)
            if hydrated.status != "cache_miss":
                prepared.append(hydrated)
                continue
            future, _ = await self._enqueue(
                raid.profound_id,
                priority=NEW_PRIORITY,
            )
            prepared.append((raid, future))

        results: list[EnrichedRaid] = []
        for item in prepared:
            if isinstance(item, EnrichedRaid):
                results.append(item)
            else:
                results.append(await self._await_result(*item))
        return results

    async def close(self) -> None:
        self._closed = True
        if self._worker_task is not None:
            self._worker_task.cancel()
            try:
                await self._worker_task
            except asyncio.CancelledError:
                pass
        for future in self._pending.values():
            if not future.done():
                future.cancel()
        self._pending.clear()
        self._pending_priorities.clear()
        self._pending_persist.clear()
