from __future__ import annotations

import asyncio
import threading

import uvicorn

from raid_bot.infrastructure.cdp_client import CDPClient
from raid_bot.infrastructure.raid_reader import RaidReader
from raid_bot.services.raid_service import RaidService
from raid_bot.api.server import create_app
from raid_bot.notifications.discord import DiscordWebhook
from raid_bot.notifications.notifier import RaidNotifier
from raid_bot.config import DISCORD_WEBHOOK_URL
from raid_bot.config import (
    API_HOST,
    API_PORT,
    CDP_PORT,
    RAID_INTERVAL,
    DISCORD_WEBHOOK_URL,
)





def main():

    print("[+] Starting Raid Bot")
    
    webhook = DiscordWebhook(
        DISCORD_WEBHOOK_URL
    )


    notifier = RaidNotifier(
        webhook
    )


    cdp = CDPClient(
        port=CDP_PORT
    )


    reader = RaidReader(
        cdp
    )

    
    service = RaidService(
        reader,
        interval_seconds=RAID_INTERVAL,
        notifier=notifier,
    )


    app = create_app(
        service
    )


    async def on_connected(
        client
    ):

        print(
            "[+] CDP connected"
        )

        await service.run_forever()


    def start_cdp():

        cdp.start(
            on_connected
        )


    threading.Thread(
        target=start_cdp,
        daemon=True,
    ).start()


    print(
        f"[+] API http://{API_HOST}:{API_PORT}"
    )


    uvicorn.run(
        app,
        host=API_HOST,
        port=API_PORT,
    )


if __name__ == "__main__":
    main()