from __future__ import annotations

import asyncio

from raid_bot.infrastructure.cdp_client import CDPClient
from raid_bot.infrastructure.raid_reader import RaidReader
from raid_bot.services.raid_service import RaidService
from raid_bot.formatter.raid_text import (
    format_raid_list,
)


CDP_PORT = 59222


async def main():

    print(
        "[+] Starting formatter test"
    )


    cdp = CDPClient(
        port=CDP_PORT
    )


    reader = RaidReader(
        cdp
    )


    service = RaidService(
        reader,
        interval_seconds=2.0,
    )


    connected = asyncio.Event()


    async def on_connected(
        client,
    ):

        print(
            "[+] CDP connected"
        )

        connected.set()



    cdp.start(
        on_connected
    )


    print(
        "[+] Waiting CDP..."
    )


    await connected.wait()


    print(
        "[+] Reading Raid..."
    )


    await service.update_once()


    snapshot = (
        service.get_snapshot()
    )


    if snapshot is None:

        print(
            "[!] No snapshot"
        )

        return


    print(
        f"[+] Raid count: {len(snapshot.raids)}"
    )


    print()

    print(
        format_raid_list(
            list(snapshot.raids)
        )
    )


if __name__ == "__main__":

    asyncio.run(
        main()
    )