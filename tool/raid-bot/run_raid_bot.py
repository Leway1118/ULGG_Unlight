from __future__ import annotations

import sys
from pathlib import Path

TOOL_ROOT = Path(__file__).resolve().parent.parent
if str(TOOL_ROOT) not in sys.path:
    sys.path.insert(0, str(TOOL_ROOT))

import asyncio
import logging
import threading
from contextlib import suppress

import uvicorn


from raid_bot.api.server import create_app
from raid_bot.config import (
    API_HOST,
    API_PORT,
    CDP_PORT,
    DISCORD_WEBHOOK_URL,
    RAID_INTERVAL,
    RAID_OWNER_ID,
    RAID_SOURCE,
    RAID_STATE_FILE,
    RAID_WS_ENDPOINT,
)
from raid_bot.infrastructure.websocket_raid_source import WebSocketRaidSource
from raid_bot.notifications.discord import DiscordWebhook
from raid_bot.notifications.notifier import RaidNotifier
from raid_bot.services.raid_service import RaidService


def _validate_config() -> None:
    if RAID_SOURCE not in {"cdp", "websocket"}:
        raise ValueError("RAID_SOURCE must be 'cdp' or 'websocket'")
    if API_HOST not in {"127.0.0.1", "localhost"}:
        raise ValueError("Raid Bot API must bind to localhost")
    if RAID_INTERVAL <= 0:
        raise ValueError("RAID_INTERVAL must be positive")
    if not DISCORD_WEBHOOK_URL:
        raise ValueError("DISCORD_WEBHOOK_URL is required")
    if RAID_SOURCE == "websocket":
        if not RAID_WS_ENDPOINT.startswith("wss://"):
            raise ValueError("RAID_WS_ENDPOINT must use wss://")
        if not RAID_OWNER_ID:
            raise ValueError("RAID_OWNER_ID is required for websocket source")


def _notifier() -> RaidNotifier:
    return RaidNotifier(DiscordWebhook(DISCORD_WEBHOOK_URL))


def _build_websocket_app():
    source = WebSocketRaidSource(
        endpoint=RAID_WS_ENDPOINT,
        owner_id=RAID_OWNER_ID,
        interval_seconds=RAID_INTERVAL,
    )
    service = RaidService(
        source,
        interval_seconds=RAID_INTERVAL,
        notifier=_notifier(),
        state_file=RAID_STATE_FILE,
        source_name="websocket",
    )
    service_task: asyncio.Task[None] | None = None

    async def startup() -> None:
        nonlocal service_task
        service_task = asyncio.create_task(
            service.run_forever(),
            name="raid-websocket-service",
        )

    async def shutdown() -> None:
        service.stop()
        if service_task is not None:
            service_task.cancel()
            with suppress(asyncio.CancelledError):
                await service_task

    return create_app(
        service,
        on_startup=startup,
        on_shutdown=shutdown,
    )


def _build_cdp_app():
    from raid_bot.infrastructure.cdp_client import CDPClient
    from raid_bot.infrastructure.raid_reader import RaidReader

    cdp = CDPClient(port=CDP_PORT)
    reader = RaidReader(cdp)
    service = RaidService(
        reader,
        interval_seconds=RAID_INTERVAL,
        notifier=_notifier(),
        state_file=RAID_STATE_FILE,
        source_name="cdp",
    )

    async def on_connected(client) -> None:
        del client
        print("[+] CDP connected")
        await service.run_forever()

    threading.Thread(
        target=cdp.start,
        kwargs={"on_connected": on_connected},
        name="raid-cdp-source",
        daemon=True,
    ).start()
    return create_app(service)


def main() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    _validate_config()
    print(f"[+] Starting Raid Bot (source={RAID_SOURCE})")
    app = (
        _build_websocket_app()
        if RAID_SOURCE == "websocket"
        else _build_cdp_app()
    )
    print(f"[+] API http://{API_HOST}:{API_PORT}")
    uvicorn.run(app, host=API_HOST, port=API_PORT)


if __name__ == "__main__":
    main()
