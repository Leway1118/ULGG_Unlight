from __future__ import annotations

import asyncio
import time
from contextlib import suppress
from pathlib import Path

from ..domain.models import RawRaidSnapshot
from ..domain.raid_event import RaidEvent, RaidEventType
from ..notifications.notifier import RaidNotifier
from .event_dispatcher import RaidEventDispatcher
from .raid_diff import RaidDiff


class RaidService:
    """Normalize source snapshots, persist seen IDs, and notify new Raids."""

    def __init__(
        self,
        reader,
        interval_seconds: float = 10.0,
        notifier: RaidNotifier | None = None,
        state_file: str | Path | None = None,
        source_name: str | None = None,
    ) -> None:
        self.reader = reader
        self.interval_seconds = interval_seconds
        self.notifier = notifier
        self.diff = RaidDiff(state_file)
        self.dispatcher = RaidEventDispatcher(notifier=notifier)
        self.source_name = source_name or getattr(
            reader,
            "source_name",
            "unknown",
        )
        self.latest_snapshot: RawRaidSnapshot | None = None
        self.last_error: str | None = None
        self.last_update: float | None = None
        self.running = False

    async def update_once(self) -> None:
        try:
            snapshot = await self.reader.read_snapshot()
            self.process_snapshot(snapshot)
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

    def process_snapshot(self, snapshot: RawRaidSnapshot) -> None:
        self.latest_snapshot = snapshot
        self.last_error = snapshot.error
        if not snapshot.connected:
            return

        new_flags = self.diff.mark_new(list(snapshot.raids))
        self.last_update = snapshot.captured_at
        new_events = [
            RaidEvent(
                event_type=RaidEventType.NEW,
                raid=raid,
                is_new=True,
            )
            for raid in snapshot.raids
            if new_flags.get(raid.profound_id, False)
        ]
        if new_events:
            self.dispatcher.dispatch(new_events)

    async def run_forever(self) -> None:
        self.running = True
        try:
            while self.running:
                await self.update_once()
                if not getattr(self.reader, "push_driven", False):
                    await asyncio.sleep(self.interval_seconds)
        except asyncio.CancelledError:
            raise
        finally:
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

    def is_connected(self) -> bool:
        if hasattr(self.reader, "connected"):
            return bool(self.reader.connected)
        return bool(self.latest_snapshot and self.latest_snapshot.connected)

    def get_last_error(self) -> str | None:
        source_error = getattr(self.reader, "last_error", None)
        return source_error or self.last_error
