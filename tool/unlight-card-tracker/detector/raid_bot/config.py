import os

from dotenv import load_dotenv


load_dotenv()


DISCORD_WEBHOOK_URL = os.getenv(
    "DISCORD_WEBHOOK_URL",
    "",
)


CDP_URL = os.getenv(
    "CDP_URL",
    "http://127.0.0.1:9222",
)


API_HOST = os.getenv(
    "API_HOST",
    "127.0.0.1",
)


API_PORT = int(
    os.getenv(
        "API_PORT",
        "8766",
    )
)


RAID_INTERVAL = float(
    os.getenv(
        "RAID_INTERVAL",
        "2",
    )
)
CDP_PORT = int(
    os.getenv(
        "CDP_PORT",
        "59222",
    )
)