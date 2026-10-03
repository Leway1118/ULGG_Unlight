from __future__ import annotations

import time

from ..domain.models import (
    RaidEntry,
    RaidPlayer,
    RawRaidSnapshot,
)
from ..domain.status_rules import parse_raid_statuses

from .cdp_client import CDPClient


READ_RAID_JS = r"""
(() => {

  const raid =
    globalThis.game?.scene?.keys?.Raid;


  if (!raid) {

    return JSON.stringify({
      ok:false,
      error:"Raid scene missing"
    });

  }


  const rows =
    Array.isArray(raid.raid_data)
      ? raid.raid_data
      : [];


  const playerName =
    raid.player?.name || null;


  return JSON.stringify({

    ok:true,

    player_name:playerName,

    active:
      Boolean(
        raid.scene?.isActive?.()
      ),


    raids:

      rows.map((row,index)=>{


        const points =
          Array.isArray(row?.points)
            ? row.points
            : [];


        return {

          index,


          profound_id:
            row?.profound_id ?? null,


          founder:
            row?.profound_founder ?? null,


          monster_code:
            row?.profound_mons ?? null,


          monster_id:
            row?.core_monster_id ?? null,


          name:
            row?.name_tcn ?? null,


          rarity:
            row?.rarity ?? null,


          level:
            row?.level ?? null,


          map:
            row?.map ?? null,


          stage:
            row?.stage ?? null,


          treasure_level:
            row?.treasure_level ?? null,


          hp:
            row?.hp ?? null,


          hp_max:
            row?.hp_max ?? null,


          member_limit:
            row?.member_limit ?? null,


          pass:
            row?.pass ?? null,


          expires_at:
            row?.limit ?? null,


          state:
            Array.isArray(row?.state)
              ? row.state
              : [],


          points,

        };

      })

  });


})()
"""


class RaidReader:
    """
    Raid 資料讀取器

    職責：
    JS資料
        ↓
    Python Model
    """

    source_name = "cdp"
    push_driven = False


    def __init__(
        self,
        cdp: CDPClient,
    ) -> None:

        self.cdp = cdp

    @property
    def connected(self) -> bool:
        return self.cdp.connected

    @property
    def last_error(self) -> None:
        return None



    async def read_snapshot(
        self,
    ) -> RawRaidSnapshot:


        data = await self.cdp.evaluate(
            READ_RAID_JS
        )


        if not data.get("ok"):

            return RawRaidSnapshot(
                captured_at=time.time(),
                source="scene",
                raids=(),
                connected=True,
                error=data.get(
                    "error"
                ),
            )


        player_name = (
            data.get(
                "player_name"
            )
        )


        raids = []


        for row in data.get(
            "raids",
            [],
        ):


            players = []

            my_entry = None


            for index, item in enumerate(
                row.get(
                    "points",
                    []
                )
            ):

                if (
                    item.get("name")
                    == player_name
                ):
                    my_entry = item


                players.append(
                    RaidPlayer(
                        rank=index + 1,

                        name=item.get(
                            "name",
                            "",
                        ),

                        point=item.get(
                            "point",
                            0,
                        ),

                        damage=item.get(
                            "damage",
                            0,
                        ),

                        is_me=(
                            item.get("name")
                            == player_name
                        ),
                    )
                )


            statuses = parse_raid_statuses(row.get("state"))


            raid_id = (
                row.get(
                    "profound_id"
                )
            )


            if not raid_id:
                continue


            raids.append(
                RaidEntry(

                    profound_id=raid_id,


                    monster_code=row.get(
                        "monster_code"
                    ),


                    monster_id=row.get(
                        "monster_id"
                    ),


                    boss_name=row.get(
                        "name"
                    ),


                    founder=row.get(
                        "founder"
                    ),


                    rarity=row.get(
                        "rarity"
                    ),


                    level=row.get(
                        "level"
                    ),


                    map_id=row.get(
                        "map"
                    ),


                    stage_id=row.get(
                        "stage"
                    ),


                    treasure_level=row.get(
                        "treasure_level"
                    ),


                    hp=row.get(
                        "hp"
                    ),


                    hp_max=row.get(
                        "hp_max"
                    ),


                    member_limit=row.get(
                        "member_limit"
                    ),


                    participant_count=len(
                        players
                    ),


                    pass_code=row.get(
                        "pass"
                    ),


                    expires_at=row.get(
                        "expires_at"
                    ),


                    already_joined=(
                        my_entry is not None
                    ),


                    my_damage=(
                        my_entry.get(
                            "damage"
                        )
                        if my_entry
                        else None
                    ),


                    my_point=(
                        my_entry.get(
                            "point"
                        )
                        if my_entry
                        else None
                    ),


                    statuses=statuses,


                    players=tuple(
                        players
                    ),


                    source="scene",


                    raw=row,

                )
            )


        return RawRaidSnapshot(

            captured_at=time.time(),

            source="scene",

            raids=tuple(
                raids
            ),

            connected=True,

        )
