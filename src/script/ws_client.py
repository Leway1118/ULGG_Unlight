from pathlib import Path
from itertools import count
from abc import ABC, abstractmethod
from collections.abc import Sequence, Coroutine
import json
import asyncio
import logging
import time

from websockets.asyncio.client import connect, ClientConnection
from websockets.exceptions import ConnectionClosed

logger = logging.getLogger(__name__)

data_dir = Path(__file__).parent


class BaseWatcher(ABC):
    EXPORT_PERIOD: int
    ws: ClientConnection

    def __init__(self, endpoint: str):
        self.endpoint = endpoint
        self.msg_idx = 0
        self.acks: list[str] = []

    async def emit_event(self, event: str, args: list):
        now = int(time.time() * 1000)
        msg_id = f"{event}:{self.msg_idx}:{now}"
        self.msg_idx += 1
        if event in {"__pong_s", "__ping_c"}:
            args = [now]
        msg = json.dumps(
            {
                "meta": {"id": msg_id, "ACKs": self.acks},
                "event": event,
                "args": args,
            }
        )
        self.acks = []
        logger.info(msg)
        await self.ws.send(msg)

    async def check_msg(self, data) -> str | None:
        logger.info(data)
        evt: str = data["event"]
        match evt:
            case "__ping_s":
                await self.emit_event("__pong_s", [])
            case "__pong_c":
                pass
            case "__handshake_s":
                await self.emit_event("__handshake_c", ["1.1.2", None])
            case "__connected":
                pass
            case _:
                if not evt.startswith("__"):
                    msg_id: str = data["meta"]["id"]
                    self.acks.append(msg_id)
                return evt

    async def ping(self):
        while True:
            await asyncio.sleep(30)
            try:
                await self.emit_event("__ping_c", [])
            except Exception as e:
                print(f"[{self.task_id}] Ping error: {e}")
                break

    @property
    @abstractmethod
    def task_id(self) -> str: ...

    @abstractmethod
    def handle_event(self, event, args): ...

    @abstractmethod
    def task_group(self) -> Sequence[Coroutine]: ...

    async def prepare(self):
        print(f"[{self.task_id}] 連線中 → {self.endpoint}")
        self.ws = await connect(self.endpoint)
        print(f"[{self.task_id}] 已連線成功")

    async def listen(self):
        async for message in self.ws:
            try:
                data = json.loads(message)
                event = await self.check_msg(data)
                args: list = data["args"]
                if event:
                    self.handle_event(event, args)
            except ConnectionClosed:
                print(f"[{self.task_id}] 伺服器連線已關閉")
                break
            except Exception as e:
                print(f"[{self.task_id}] 處理訊息錯誤: {e}")
                break

    async def start(self):
        for retry in count(1):
            try:
                await self.prepare()
                async with asyncio.TaskGroup() as tg:
                    for coro in self.task_group():
                        tg.create_task(coro)
            except* Exception as eg:
                for e in eg.exceptions:
                    print(f"[{self.task_id}] 連線中斷 {e}，第 {retry} 次嘗試")
                await asyncio.sleep(5)