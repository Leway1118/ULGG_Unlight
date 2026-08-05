import os
from pathlib import Path

from dotenv import load_dotenv


load_dotenv()


DISCORD_WEBHOOK_URL = os.getenv("DISCORD_WEBHOOK_URL", "").strip()
RAID_SOURCE = os.getenv("RAID_SOURCE", "cdp").strip().lower()
RAID_WS_ENDPOINT = os.getenv(
    "RAID_WS_ENDPOINT",
    "wss://www.playunlight.online:15003/",
).strip()
RAID_OWNER_ID = os.getenv("RAID_OWNER_ID", "").strip()
RAID_STATE_FILE = Path(
    os.getenv("RAID_STATE_FILE", "./data/raid_state.json")
)

CDP_URL = os.getenv(
    "CDP_URL",
    "http://127.0.0.1:9222",
)
CDP_PORT = int(os.getenv("CDP_PORT", "59222"))

API_HOST = os.getenv("API_HOST", "127.0.0.1")
API_PORT = int(os.getenv("API_PORT", "8766"))
RAID_INTERVAL = float(os.getenv("RAID_INTERVAL", "10"))
