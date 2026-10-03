from __future__ import annotations

import asyncio
import threading
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

from raid_bot.notifications.discord import (
    DiscordWebhook,
)

from raid_bot.notifications.notifier import (
    RaidNotifier,
)
from raid_bot.config import DISCORD_WEBHOOK_URL


CDP_PORT = 59222


def main():

    print(
        "[+] Starting notifier test"
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


    connected = threading.Event()



    async def on_connected(
        client,
    ):

        print(
            "[+] CDP connected"
        )

        connected.set()



    def start_cdp():

        cdp.start(
            on_connected
        )


    threading.Thread(
        target=start_cdp,
        daemon=True,
    ).start()



    print(
        "[+] Waiting CDP..."
    )


    while not connected.is_set():

        time.sleep(0.1)



    print(
        "[+] Reading Raid..."
    )


    asyncio.run(
        service.update_once()
    )


    snapshot = (
        service.get_snapshot()
    )


    if snapshot is None:

        print(
            "[!] No raid data"
        )

        return



    print(
        f"[+] Raid count: {len(snapshot.raids)}"
    )


    discord = DiscordWebhook(
        DISCORD_WEBHOOK_URL
    )


    notifier = RaidNotifier(
        discord
    )


    result = notifier.notify_snapshot(
        snapshot.raids
    )


    print(
        "[+] Discord sent"
        if result
        else
        "[!] Discord failed"
    )



if __name__ == "__main__":

    main()
