from __future__ import annotations

import asyncio
import time

from raid_bot.infrastructure.cdp_client import (
    CDPClient,
)

from raid_bot.infrastructure.raid_reader import (
    RaidReader,
)

from raid_bot.services.raid_service import (
    RaidService,
)

from raid_bot.services.raid_detector import (
    RaidDetector,
)


CDP_PORT = 59222



async def main():

    print(
        "[+] Starting Raid Detector"
    )


    cdp = CDPClient(
        port=CDP_PORT
    )


    reader = RaidReader(
        cdp
    )


    service = RaidService(
        reader,
        interval_seconds=2,
    )


    detector = RaidDetector()


    while True:

        try:

            await service.update_once()


            snapshot = (
                service.get_snapshot()
            )


            if snapshot:

                new_raids = detector.check_new(
                    snapshot.raids
                )


                if new_raids:

                    print()
                    print(
                        "=" * 60
                    )

                    print(
                        "[NEW RAID DETECTED]"
                    )


                    for raid in new_raids:

                        print(
                            f"""
Boss:
  {raid.boss_name}

ID:
  {raid.profound_id}

星:
  {raid.star}

地圖:
  {raid.map_id}

Stage:
  {raid.stage_id}

HP:
  {raid.hp}/{raid.hp_max}

人數:
  {raid.participant_count}/{raid.member_limit}

參戰:
  {raid.already_joined}

Pass:
  {raid.pass_code}
"""
                        )


                    print(
                        "=" * 60
                    )


        except Exception as exc:

            print(
                "[ERROR]",
                exc
            )


        await asyncio.sleep(2)



if __name__ == "__main__":

    asyncio.run(main())