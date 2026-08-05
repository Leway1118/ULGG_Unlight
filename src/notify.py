import asyncio
import os
import argparse

from script.ws_client import BaseWatcher


class NotifyWatcher(BaseWatcher):
    def __init__(self, endpoint: str, user_id: str):
        super().__init__(endpoint)
        self.user_id = user_id

    @property
    def task_id(self) -> str:
        return "notify_watcher"

    async def check_maintenance(self, user_id: str, interval: int = 10):
        while True:
            await self.emit_event("playercheck", [user_id])
            await asyncio.sleep(interval)

    def task_group(self):
        return [self.listen(), self.ping(), self.check_maintenance(self.user_id)]

    def handle_event(self, event, args):
        match event:
            case "playercheck":
                res = args[0]
                is_maintenance = res["maintenance"]
                if not is_maintenance:
                    os.system(
                        "terminal-notifier -message '維修結束！' -title 'Unlight Notification'"
                    )
                    os._exit(0)


def main():
    config = {
        "dmm": {
            "endpoint": "wss://www.playunlight-j53sabwb8b89tn3j.com:12007/",
            "user_id": "lyQbFIyEMlNMIIq0DQ7a9YhfsvJCEGZC",
        },
        "steam": {
            "endpoint": "wss://www.playunlight-yiylzpm2ugvd227f.site:13006/",
            "user_id": "z8GtMzWpSPHimjnBK5ED8Lm4f8NvotY5S046",
        },
    }
    parser = argparse.ArgumentParser()
    parser.add_argument("--server", choices=["steam", "dmm"], default="steam")
    args = parser.parse_args()
    endpoint = config[args.server]["endpoint"]
    user_id = config[args.server]["user_id"]
    watcher = NotifyWatcher(endpoint, user_id)
    asyncio.run(watcher.start())


if __name__ == "__main__":
    main()
