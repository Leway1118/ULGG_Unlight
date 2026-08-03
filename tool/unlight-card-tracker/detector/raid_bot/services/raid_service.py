from __future__ import annotations

import asyncio
import time

from ..domain.models import RawRaidSnapshot
from ..infrastructure.raid_reader import RaidReader
from .raid_detector import RaidDetector
from .event_dispatcher import RaidEventDispatcher
from ..notifications.notifier import RaidNotifier
from .raid_diff import RaidDiff
from dataclasses import replace

class RaidService:
    """
    Raid 背景監控服務

    職責：

    Reader
       ↓
    定時更新
       ↓
    latest_snapshot
    """

    def __init__(
        self,
        reader: RaidReader,
        interval_seconds: float = 2.0,
        notifier: RaidNotifier | None = None,
    ) -> None:

        self.reader = reader
        self.notifier = notifier
        self.detector = RaidDetector()
        self.diff = RaidDiff()
        
        self.dispatcher = RaidEventDispatcher(
            notifier=self.notifier
        )

        self.interval_seconds = (
            interval_seconds
        )

        self.latest_snapshot: (
            RawRaidSnapshot | None
        ) = None

        self.last_error: (
            str | None
        ) = None

        self.running = False


    async def update_once(
        self,
    ) -> None:

        try:

            snapshot = (
                await self.reader.read_snapshot()
            )

            self.latest_snapshot = snapshot

            self.last_error = None


        except Exception as exc:

            self.last_error = str(exc)


            self.latest_snapshot = (
                RawRaidSnapshot(
                    captured_at=time.time(),

                    source="error",

                    raids=(),

                    connected=False,

                    error=str(exc),
                )
            )


    async def run_forever(
        self,
    ) -> None:

        self.running = True


        while self.running:

            await self.update_once()
            snapshot = self.get_snapshot()

            if (
                snapshot
                and snapshot.connected
            ):

                events = (
                    self.detector.detect_events(
                        snapshot.raids
                    )
                )
                
                new_flags = self.diff.mark_new(
                    list(snapshot.raids)
                )
                events = [
                    replace(
                        event,
                        is_new=new_flags.get(
                            event.raid.profound_id,
                            False
                        )
                    )
                    for event in events
                ]
                
                if any(
                    event.is_new
                    for event in events
                ):

                    self.dispatcher.dispatch(
                        events
                    )


                


            await asyncio.sleep(
                self.interval_seconds
            )


    def stop(
        self,
    ) -> None:

        self.running = False


    def get_snapshot(
        self,
    ) -> RawRaidSnapshot | None:

        return self.latest_snapshot