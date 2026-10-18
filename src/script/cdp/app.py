import asyncio
import sys
import json
import tomllib
import traceback
from pathlib import Path
from functools import wraps

import requests
from websockets.asyncio.client import ClientConnection, connect

if getattr(sys, "frozen", False):
    dir_path = Path(sys.executable).resolve().parent
else:
    # Python 執行
    dir_path = Path(__file__).resolve().parent
config = json.loads((dir_path / "config.json").read_text(encoding="utf-8"))
PORT = config.get("port", 9222)
COST_DATA = tomllib.loads(
    (dir_path / config.get("costData", "cc_asset_cost.toml")).read_text(
        encoding="utf-8"
    )
)


def get_debugger_url():
    res = requests.get(f"http://127.0.0.1:{PORT}/json")
    res.raise_for_status()
    data = res.json()
    return data[0]["webSocketDebuggerUrl"]


class CdpClient:
    ws: ClientConnection

    def __init__(self, debugger_url: str):
        self.debugger_url = debugger_url
        self.index = 1
        self.responses = {}

    async def __aenter__(self):
        self.ws = await connect(self.debugger_url)
        return self

    async def __aexit__(self, exc_type, exc_val, exc_tb):
        await self.ws.close()

    async def send(self, message: dict):
        message["id"] = self.index
        self.index += 1
        await self.ws.send(json.dumps(message))

    async def fetch(self, message: dict) -> dict:
        await self.send(message)
        return await self.listen({"id": message["id"]})

    async def listen(self, message: dict) -> dict:
        async for data in self.ws:
            msg = json.loads(data)
            for key, value in message.items():
                if msg.get(key) == value:
                    return msg
        raise RuntimeError("Expected message not received")

    async def get_game_context_id(self) -> int:
        frame_tree = await self.fetch({"method": "Page.getFrameTree"})
        iframe_id = frame_tree["result"]["frameTree"]["childFrames"][0]
        await self.send({"method": "Runtime.enable"})
        async for data in self.ws:
            msg = json.loads(data)
            if msg.get("method") == "Runtime.executionContextCreated":
                context = msg["params"]["context"]
                if context["auxData"]["frameId"] == iframe_id["frame"]["id"]:
                    return context["id"]
        raise RuntimeError("Game context not found")

    async def inject_script(
        self, script_path: Path, context_id: int, data: dict | None = None
    ) -> dict:
        script_content = script_path.read_text(encoding="utf-8")
        if data:
            script_content = script_content.replace(
                "__JSON_DATA__", json.dumps(data, ensure_ascii=False)
            )
        return await self.fetch(
            {
                "method": "Runtime.evaluate",
                "params": {
                    "expression": script_content,
                    "contextId": context_id,
                },
            }
        )


def pause_on_error(async_func):
    @wraps(async_func)
    async def async_wrapper(*args, **kwargs):
        try:
            return await async_func(*args, **kwargs)
        except Exception:
            traceback.print_exc()
            input("Paused on error. Press Enter to continue...")
            raise

    return async_wrapper


@pause_on_error
async def main():
    debugger_url = get_debugger_url()
    async with CdpClient(debugger_url) as cdp_client:
        context_id = await cdp_client.get_game_context_id()
        for script_path in dir_path.glob("*.js"):
            res = await cdp_client.inject_script(
                script_path,
                context_id,
                COST_DATA,
            )
            print(res)
        await cdp_client.fetch(
            {
                "method": "Runtime.evaluate",
                "params": {"expression": "alert('修補完成！')"},
            }
        )


if __name__ == "__main__":
    asyncio.run(main())
