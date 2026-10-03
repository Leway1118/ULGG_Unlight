from __future__ import annotations

import asyncio
import logging
from dataclasses import replace
from typing import Iterable

from ..domain.raid_event import RaidEvent
from ..notifications.notifier import RaidNotifier
from .fragment_resolver import FragmentResolver
from .raid_diff import RaidDiff
from .raid_notification_policy import RaidNotificationPolicy
from .treasure_level_enricher import TreasureLevelEnricher


logger = logging.getLogger(__name__)


class RaidEventDispatcher:
    def __init__(
        self,
        notifier: RaidNotifier | None = None,
        fragment_resolver: FragmentResolver | None = None,
        treasure_enricher: TreasureLevelEnricher | None = None,
        state: RaidDiff | None = None,
        notification_batch_seconds: float = 30.0,
        full_summary_interval_seconds: float = 10_800.0,
    ) -> None:
        self.treasure_enricher = treasure_enricher
        self.notification_policy = RaidNotificationPolicy(
            notifier=notifier,
            fragment_resolver=fragment_resolver,
            state=state or RaidDiff(),
            batch_seconds=notification_batch_seconds,
            full_summary_interval_seconds=full_summary_interval_seconds,
        )

    async def dispatch_async(
        self,
        events: Iterable[RaidEvent],
        *,
        active_raids=None,
    ) -> None:
        events = list(events)
        new_events = [event for event in events if event.is_new]
        if self.treasure_enricher is not None:
            try:
                if new_events:
                    enriched = await self.treasure_enricher.enrich_many(
                        [event.raid for event in new_events]
                    )
                    replacements = {
                        event.raid.profound_id: result.raid
                        for event, result in zip(new_events, enriched)
                    }
                    events = [
                        replace(
                            event,
                            raid=replacements.get(
                                event.raid.profound_id,
                                event.raid,
                            ),
                        )
                        for event in events
                    ]

                hydrated = self.treasure_enricher.hydrate_cached_many(
                    [event.raid for event in events]
                )
                events = [
                    replace(event, raid=result.raid)
                    for event, result in zip(events, hydrated)
                ]
                if new_events:
                    await self.treasure_enricher.backfill_many([
                        result.raid
                        for event, result in zip(events, hydrated)
                        if not event.is_new and result.status == "cache_miss"
                    ])
            except Exception as error:
                # Enrichment is optional; the basic Raid notification must survive.
                logger.warning(
                    "Raid enrichment failed (%s); sending basic notification",
                    type(error).__name__,
                )
        await self.notification_policy.submit(
            events,
            active_raids=(
                [event.raid for event in events]
                if events
                else list(active_raids or [])
            ),
        )

    # RAID_DISCORD_DISPATCH_REFRESH_HP_V1
    def tracked_unmarked_raid_ids(
        self,
    ) -> tuple[str, ...]:
        return (
            self.notification_policy
            .tracked_unmarked_raid_ids()
        )


    # RAID_DISCORD_DELIVERY_LOOKUP_V1
    def has_discord_record(
        self,
        raid_id: str,
    ) -> bool:
        return (
            self.notification_policy
            .has_discord_record(raid_id)
        )


    async def dispatch_defeated_async(
        self,
        raid_id: str,
        *,
        active_raids=(),
    ) -> bool:
        active_raids = tuple(
            active_raids or ()
        )

        return await asyncio.to_thread(
            self.notification_policy.edit_defeated_by_id,
            raid_id,
            active_raids=active_raids,
        )


    async def close(self) -> None:
        await self.notification_policy.close()
        if self.treasure_enricher is not None:
            await self.treasure_enricher.close()

    async def flush_pending(self) -> None:
        await self.notification_policy.flush_pending()
