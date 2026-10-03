from __future__ import annotations

from typing import Iterable

from ..domain.models import RaidEntry

from ..domain.raid_event import (
    RaidEvent,
    RaidEventType,
)


class RaidDetector:
    """
    偵測 Raid 新增/消失
    第一版只處理新增
    """

    def __init__(self):
        self.previous_raids = {}


    
    
    def detect_events(
        self,
        raids,
    ) -> list[RaidEvent]:

        events = []

        current = {
            raid.profound_id: raid
            for raid in raids
        }


        # NEW / UPDATED

        for raid_id, raid in current.items():

            old = (
                self.previous_raids
                .get(raid_id)
            )


            if old is None:

                events.append(
                    RaidEvent(
                        event_type=RaidEventType.NEW,
                        raid=raid,
                    )
                )


            elif (
                old.hp != raid.hp
                or
                old.participant_count
                != raid.participant_count
            ):

                events.append(
                    RaidEvent(
                        event_type=RaidEventType.UPDATED,
                        raid=raid,
                        previous=old,
                    )
                )


        # REMOVED

        for raid_id, old in self.previous_raids.items():

            if raid_id not in current:

                events.append(
                    RaidEvent(
                        event_type=RaidEventType.REMOVED,
                        raid=old,
                    )
                )


        self.previous_raids = current


        return events