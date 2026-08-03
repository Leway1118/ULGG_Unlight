from __future__ import annotations

from typing import Iterable

from ..domain.raid_event import RaidEvent
from ..notifications.notifier import RaidNotifier
from ..domain.monster_rules import (
    get_monster_icon,
)

from ..domain.fragment_rules import (
    get_fragment_icon,
)

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

        events = list(events)

        if not events:
            return


        text = self.format_events(
            events
        )


        print(text)


        if self.notifier:

            self.notifier.notify_text(
                text
            )


    def format_events(
        self,
        events: list[RaidEvent],
    ) -> str:

        lines = [
            "🌀 Raid List",
            "",
        ]


        for event in events:

            raid = event.raid
            
           
            fragment_icon = get_fragment_icon(
                raid.map_id
            )


            monster_icon = get_monster_icon(
                raid.boss_name
            )


            prefix = (
                "NEW "
                if event.is_new
                else ""
            )


            lines.append(
                f"{prefix}{raid.boss_name:<6}"
                f"{fragment_icon}{monster_icon}"
            )


        return "\n".join(lines)