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
    DISCORD_RAID4_ROLE_ID,
    DISCORD_WEBHOOK_URL,
    RAID_INTERVAL,
    RAID_NOTIFICATION_BATCH_SECONDS,
    RAID_FULL_SUMMARY_INTERVAL_SECONDS,
    RAID_DB_WS_ENDPOINT,
    RAID_ENRICHMENT_CACHE_FILE,
    RAID_ENRICHMENT_ENABLED,
    RAID_ENRICHMENT_PROTOCOL_TIMEOUT,
    RAID_ENRICHMENT_TIMEOUT,
    RAID_ENRICHMENT_WORKER_ID,
    RAID_OWNER_ID,
    RAID_REWARD_API_URL,
    RAID_REWARD_CACHE_FILE,
    RAID_REWARD_LOCALE,
    RAID_REWARD_TIMEOUT,
    RAID_SOURCE,
    RAID_STATE_FILE,
    RAID_WS_ENDPOINT,
)
from raid_bot.infrastructure.websocket_raid_source import WebSocketRaidSource
from raid_bot.infrastructure.db_raid_source import DbRaidSource
from raid_bot.infrastructure.reward_cache import RewardCache
from raid_bot.infrastructure.treasure_reward_client import TreasureRewardClient
from raid_bot.infrastructure.public_raid_enrichment_client import (
    PublicRaidEnrichmentClient,
)
from raid_bot.infrastructure.treasure_level_cache import TreasureLevelCache
from raid_bot.infrastructure.raid_tl_history import RaidTLHistoryWriter
from raid_bot.notifications.discord import DiscordWebhook
from raid_bot.notifications.notifier import RaidNotifier
from raid_bot.services.raid_service import RaidService
from raid_bot.services.fragment_resolver import FragmentResolver
from raid_bot.services.treasure_level_enricher import TreasureLevelEnricher

def _validate_config() -> None:
    if RAID_SOURCE not in {"cdp", "websocket", "db_raid"}:
        raise ValueError("RAID_SOURCE must be 'cdp', 'websocket', or 'db_raid'")
    if API_HOST not in {"127.0.0.1", "localhost"}:
        raise ValueError("Raid Bot API must bind to localhost")
    if RAID_INTERVAL <= 0:
        raise ValueError("RAID_INTERVAL must be positive")
    if RAID_REWARD_TIMEOUT <= 0:
        raise ValueError("RAID_REWARD_TIMEOUT must be positive")
    _validate_notification_intervals(
        RAID_NOTIFICATION_BATCH_SECONDS,
        RAID_FULL_SUMMARY_INTERVAL_SECONDS,
    )
    _validate_enrichment_timeouts(
        RAID_ENRICHMENT_PROTOCOL_TIMEOUT,
        RAID_ENRICHMENT_TIMEOUT,
        enabled=RAID_ENRICHMENT_ENABLED,
    )
    if not RAID_REWARD_API_URL.startswith("https://"):
        raise ValueError("RAID_REWARD_API_URL must use https://")
    if not DISCORD_WEBHOOK_URL:
        raise ValueError("DISCORD_WEBHOOK_URL is required")
    if DISCORD_RAID4_ROLE_ID and not DISCORD_RAID4_ROLE_ID.isdigit():
        raise ValueError("DISCORD_RAID4_ROLE_ID must contain digits only")
    if RAID_SOURCE == "websocket":
        if not RAID_WS_ENDPOINT.startswith("wss://"):
            raise ValueError("RAID_WS_ENDPOINT must use wss://")
        if not RAID_OWNER_ID:
            raise ValueError("RAID_OWNER_ID is required for websocket source")
        if RAID_ENRICHMENT_ENABLED and not RAID_ENRICHMENT_WORKER_ID:
            raise ValueError(
                "RAID_ENRICHMENT_WORKER_ID is required when enrichment is enabled"
            )
    if RAID_SOURCE == "db_raid":
        if not RAID_DB_WS_ENDPOINT.startswith("wss://"):
            raise ValueError("RAID_DB_WS_ENDPOINT must use wss://")
        if not RAID_OWNER_ID:
            raise ValueError("RAID_OWNER_ID is required for db_raid source")


def _notifier() -> RaidNotifier:
    return RaidNotifier(
        DiscordWebhook(DISCORD_WEBHOOK_URL),
        raid4_role_id=DISCORD_RAID4_ROLE_ID,
    )


def _validate_enrichment_timeouts(
    protocol_timeout: float,
    overall_timeout: float,
    *,
    enabled: bool,
) -> None:
    if protocol_timeout <= 0:
        raise ValueError("RAID_ENRICHMENT_PROTOCOL_TIMEOUT must be positive")
    if overall_timeout <= 0:
        raise ValueError("RAID_ENRICHMENT_TIMEOUT must be positive")
    if enabled and overall_timeout <= protocol_timeout:
        raise ValueError(
            "RAID_ENRICHMENT_TIMEOUT must be greater than "
            "RAID_ENRICHMENT_PROTOCOL_TIMEOUT"
        )


def _validate_notification_intervals(
    batch_seconds: float,
    full_summary_interval_seconds: float,
) -> None:
    if batch_seconds <= 0:
        raise ValueError("RAID_NOTIFICATION_BATCH_SECONDS must be positive")
    if full_summary_interval_seconds <= 0:
        raise ValueError(
            "RAID_FULL_SUMMARY_INTERVAL_SECONDS must be positive"
        )


def _fragment_resolver() -> FragmentResolver:
    return FragmentResolver(
        TreasureRewardClient(
            endpoint=RAID_REWARD_API_URL,
            timeout_seconds=RAID_REWARD_TIMEOUT,
        ),
        RewardCache(
            RAID_REWARD_CACHE_FILE,
            locale=RAID_REWARD_LOCALE,
        ),
        locale=RAID_REWARD_LOCALE,
    )


def _treasure_enricher() -> TreasureLevelEnricher | None:
    if not RAID_ENRICHMENT_ENABLED:
        return None

    return TreasureLevelEnricher(
        PublicRaidEnrichmentClient(
            endpoint=RAID_WS_ENDPOINT,
            worker_id=RAID_ENRICHMENT_WORKER_ID,
            timeout_seconds=RAID_ENRICHMENT_PROTOCOL_TIMEOUT,
        ),
        TreasureLevelCache(RAID_ENRICHMENT_CACHE_FILE),
        timeout_seconds=RAID_ENRICHMENT_TIMEOUT,
        history_writer=RaidTLHistoryWriter(
            "data/raid_tl_history.jsonl"
        ),
    )


def _build_websocket_app():
    fragment_resolver = _fragment_resolver()

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
        fragment_resolver=fragment_resolver,
        treasure_enricher=_treasure_enricher(),
        notification_batch_seconds=RAID_NOTIFICATION_BATCH_SECONDS,
        full_summary_interval_seconds=RAID_FULL_SUMMARY_INTERVAL_SECONDS,
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


def _build_db_raid_app():
    source = DbRaidSource(
        endpoint=RAID_DB_WS_ENDPOINT,
        owner_id=RAID_OWNER_ID,
        interval_seconds=RAID_INTERVAL,
    )
    service = RaidService(
        source,
        interval_seconds=RAID_INTERVAL,
        notifier=_notifier(),
        state_file=RAID_STATE_FILE,
        source_name="db_raid",
        fragment_resolver=_fragment_resolver(),
        notification_batch_seconds=RAID_NOTIFICATION_BATCH_SECONDS,
        full_summary_interval_seconds=RAID_FULL_SUMMARY_INTERVAL_SECONDS,
    )
    service_task: asyncio.Task[None] | None = None

    async def startup() -> None:
        nonlocal service_task
        service_task = asyncio.create_task(
            service.run_forever(),
            name="raid-db-websocket-service",
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
        fragment_resolver=_fragment_resolver(),
        notification_batch_seconds=RAID_NOTIFICATION_BATCH_SECONDS,
        full_summary_interval_seconds=RAID_FULL_SUMMARY_INTERVAL_SECONDS,
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
    if RAID_SOURCE == "websocket":
        app = _build_websocket_app()
    elif RAID_SOURCE == "db_raid":
        app = _build_db_raid_app()
    else:
        app = _build_cdp_app()
    print(f"[+] API http://{API_HOST}:{API_PORT}")
    uvicorn.run(app, host=API_HOST, port=API_PORT)


if __name__ == "__main__":
    main()
