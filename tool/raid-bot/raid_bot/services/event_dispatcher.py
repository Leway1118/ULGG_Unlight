from __future__ import annotations

import sys
from typing import Iterable

from ..domain.raid_event import RaidEvent, RaidEventType
from ..formatter.raid_text import format_raid_list
from ..notifications.notifier import RaidNotifier


class RaidEventDispatcher:
    def __init__(
        self,
        notifier: RaidNotifier | None = None,
    ) -> None:
        self.notifier = notifier

    def dispatch(
        self,
        events: Iterable[RaidEvent],
    ) -> None:
        new_events = [
            event
            for event in events
            if event.event_type == RaidEventType.NEW and event.is_new
        ]
        if not new_events:
            return

        text = self.format_events(new_events)
        try:
            print(text)
        except UnicodeEncodeError:
            encoding = getattr(sys.stdout, "encoding", None) or "utf-8"
            print(text.encode(encoding, errors="replace").decode(encoding))
        if self.notifier:
            self.notifier.notify_text(text)

    def format_events(
        self,
        events: list[RaidEvent],
    ) -> str:
        return format_raid_list([
            event.raid
            for event in events
        ])
