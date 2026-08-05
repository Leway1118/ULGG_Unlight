import json
import asyncio
import logging
import os
import sys

from ws_client import BaseWatcher


logging.basicConfig(
    level=logging.WARNING,
    format="%(asctime)s - %(levelname)s - %(message)s",
    stream=sys.stdout,
)
logger = logging.getLogger(__name__)


class RaidWatcher(BaseWatcher):
    def __init__(self, endpoint: str, owner_id: str = ""):
        super().__init__(endpoint)
        self.owner_id = owner_id

    async def get_raid_support_list(self, period: int = 10):
        await self.emit_event("register", [self.owner_id])
        await asyncio.sleep(1)
        while True:
            print(f"[{self.task_id}] Fetching raid support list...")
            try:
                await self.emit_event("raid_get_support", [self.owner_id])
            except Exception as e:
                print(f"[{self.task_id}] Error fetching raid support list: {e}")
                raise
            await asyncio.sleep(period)

    @property
    def task_id(self) -> str:
        return "raid"

    def handle_event(self, event, args):
        logger.info(f"Received event: {event} with args: {args}")
        if event == "raid_support_list":
            print(json.dumps(args, indent=4, ensure_ascii=False))

    def task_group(self):
        return [self.ping(), self.listen(), self.get_raid_support_list()]


async def main():
    user_id = os.environ["RAID_OWNER_ID"]
    endpoint = os.getenv(
        "RAID_WS_ENDPOINT",
        "wss://www.playunlight.online:15003/",
    )
    watcher = RaidWatcher(endpoint, user_id)
    await watcher.start()


if __name__ == "__main__":
    asyncio.run(main())
