import os
from pathlib import Path

from dotenv import load_dotenv


load_dotenv()


DISCORD_WEBHOOK_URL = os.getenv("DISCORD_WEBHOOK_URL", "").strip()
DISCORD_RAID4_ROLE_ID = os.getenv("DISCORD_RAID4_ROLE_ID", "").strip()
RAID_SOURCE = os.getenv("RAID_SOURCE", "cdp").strip().lower()
RAID_WS_ENDPOINT = os.getenv(
    "RAID_WS_ENDPOINT",
    "wss://www.playunlight.online:15003/",
).strip()
RAID_DB_WS_ENDPOINT = os.getenv(
    "RAID_DB_WS_ENDPOINT",
    "wss://www.playunlight.online:15005/",
).strip()
RAID_OWNER_ID = os.getenv("RAID_OWNER_ID", "").strip()
RAID_STATE_FILE = Path(
    os.getenv("RAID_STATE_FILE", "./data/raid_state.json")
)
RAID_REWARD_API_URL = os.getenv(
    "RAID_REWARD_API_URL",
    "https://www.ulrmap.wiki/api/raid-rewards/query",
).strip()
RAID_REWARD_LOCALE = os.getenv("RAID_REWARD_LOCALE", "zh-TW").strip()
RAID_REWARD_TIMEOUT = float(os.getenv("RAID_REWARD_TIMEOUT", "10"))
RAID_REWARD_CACHE_FILE = Path(
    os.getenv("RAID_REWARD_CACHE_FILE", "./data/raid_reward_cache.json")
)
RAID_ENRICHMENT_ENABLED = os.getenv(
    "RAID_ENRICHMENT_ENABLED",
    "false",
).strip().lower() in {"1", "true", "yes", "on"}
RAID_ENRICHMENT_WORKER_ID = os.getenv(
    "RAID_ENRICHMENT_WORKER_ID",
    "",
).strip()
RAID_ENRICHMENT_PROTOCOL_TIMEOUT = float(
    os.getenv("RAID_ENRICHMENT_PROTOCOL_TIMEOUT", "10")
)
RAID_ENRICHMENT_TIMEOUT = float(
    os.getenv("RAID_ENRICHMENT_TIMEOUT", "55")
)
RAID_ENRICHMENT_CACHE_FILE = Path(
    os.getenv(
        "RAID_ENRICHMENT_CACHE_FILE",
        "./data/raid_enrichment_cache.json",
    )
)
CDP_URL = os.getenv(
    "CDP_URL",
    "http://127.0.0.1:9222",
)
CDP_PORT = int(os.getenv("CDP_PORT", "59222"))

API_HOST = os.getenv("API_HOST", "127.0.0.1")
API_PORT = int(os.getenv("API_PORT", "8766"))
RAID_INTERVAL = float(os.getenv("RAID_INTERVAL", "10"))
RAID_NOTIFICATION_BATCH_SECONDS = float(
    os.getenv("RAID_NOTIFICATION_BATCH_SECONDS", "30")
)
RAID_FULL_SUMMARY_INTERVAL_SECONDS = float(
    os.getenv("RAID_FULL_SUMMARY_INTERVAL_SECONDS", "10800")
)

# RAID_STAGE_SYNC_V1
RAID_STAGE_MATCH_SECRET = os.getenv(
    "RAID_STAGE_MATCH_SECRET",
    "",
).strip()
RAID_STAGE_API_SECRET = os.getenv(
    "RAID_STAGE_API_SECRET",
    "",
).strip()
RAID_STAGE_AUTH_WINDOW_SECONDS = float(
    os.getenv("RAID_STAGE_AUTH_WINDOW_SECONDS", "300")
)
