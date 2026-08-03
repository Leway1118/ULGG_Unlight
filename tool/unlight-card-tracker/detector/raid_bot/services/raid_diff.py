from __future__ import annotations

from ..domain.models import RaidEntry


class RaidDiff:

    def __init__(self) -> None:

        self.previous_ids: set[str] = set()


    def mark_new(
        self,
        raids: list[RaidEntry],
    ) -> dict[str, bool]:

        result = {}


        current_ids = set()


        for raid in raids:

            raid_id = raid.profound_id

            current_ids.add(
                raid_id
            )


            result[raid_id] = (
                raid_id not in self.previous_ids
            )


        self.previous_ids = current_ids


        return result