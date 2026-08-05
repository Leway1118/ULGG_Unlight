# channel_watcher.py
# pip install websockets
from pathlib import Path
from itertools import count
from abc import ABC, abstractmethod
from typing import Coroutine
import json
import asyncio
import logging
import sys
import time
import ssl  # ← 移到這裡，避免在 class 內 import

from websockets.asyncio.client import connect, ClientConnection
from websockets.exceptions import ConnectionClosed

logging.basicConfig(
    level=logging.WARNING,
    format="%(asctime)s - %(levelname)s - %(message)s",
)
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
    def task_group(self) -> list[Coroutine]: ...

    async def prepare(self):
        print(f"[{self.task_id}] 連線中 → {self.endpoint}")
        ssl_ctx = ssl._create_unverified_context()  # 關閉 SSL 驗證（臨時繞過）
        self.ws = await connect(self.endpoint, ssl=ssl_ctx)
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
                await asyncio.gather(*self.task_group())
            except Exception as e:
                print(f"[{self.task_id}] 連線中斷 {e}，第 {retry} 次嘗試")
                await asyncio.sleep(5)


class ChannelWatcher(BaseWatcher):
    """監聽一般頻道 (channel_in) 事件，如 channel1_room"""

    EXPORT_PERIOD = 10  # seconds

    def __init__(
        self,
        channel_id: int,
        endpoint: str = "wss://www.playunlight.online:11012",
        host_code: str = "TW",
        owner_id: str = "",
    ):
        super().__init__(endpoint)
        self.channel_id = channel_id
        self.host_code = host_code
        self.owner_id = owner_id

        self.data_file = data_dir / f"channel{self.channel_id}_room_{host_code}.json"

        if not self.data_file.exists() or self.data_file.stat().st_size == 0:
            self.data_file.parent.mkdir(parents=True, exist_ok=True)
            self.data_file.write_text("{}", encoding="utf-8")
        try:
            with self.data_file.open("r", encoding="utf-8") as f:
                self.channel_data = json.load(f)
        except json.JSONDecodeError:
            print(f"[{self.task_id}] WARNING: 無法解析 {self.data_file.name}，初始化為空資料")
            self.channel_data = {}

    @property
    def task_id(self) -> str:
        return f"{self.host_code} Ch{self.channel_id}"

    async def prepare(self):
        await super().prepare()
        if self.owner_id:
            await self.emit_event("register", [self.owner_id])
            await asyncio.sleep(1)
        await self.emit_event("channel_in", [self.channel_id])
        print(f"[{self.task_id}] 已送出 channel_in 訂閱")

    def handle_event(self, event, args):
        if event == f"channel{self.channel_id}_room":
            rooms = args[0]
            for room in rooms:
                self.channel_data[room["room_id"]] = room

    async def export(self):
        while True:
            await asyncio.sleep(self.EXPORT_PERIOD)
            if self.channel_data:
                with self.data_file.open("w", encoding="utf-8") as f:
                    json.dump(self.channel_data, f, indent=4, ensure_ascii=False)

    def task_group(self):
        return [self.ping(), self.export(), self.listen()]


class RankingWatcher(BaseWatcher):
    """監聽 ranking 資訊 (BP/QP)，連線到 11007"""

    EXPORT_PERIOD = 30

    def __init__(
        self,
        endpoint: str = "wss://www.playunlight.online:11007/",
        host_code: str = "TW",
    ):
        super().__init__(endpoint)
        self.host_code = host_code
        self.bp_file = data_dir / f"ranking_bp_{host_code}.json"
        self.qp_file = data_dir / f"ranking_qp_{host_code}.json"

        def load_json(file_path: Path) -> dict:
            if not file_path.exists():
                file_path.parent.mkdir(parents=True, exist_ok=True)
                file_path.write_text("{}", encoding="utf-8")
            with file_path.open("r", encoding="utf-8") as f:
                return json.load(f)

        self.bp_data = load_json(self.bp_file)
        self.qp_data = load_json(self.qp_file)

    @property
    def task_id(self) -> str:
        return f"Ranking:{self.host_code}"

    async def prepare(self):
        print(f"[{self.task_id}] 連線中 → {self.endpoint}")
        ssl_ctx = ssl._create_unverified_context()
        self.ws = await connect(self.endpoint, ssl=ssl_ctx)
        print(f"[{self.task_id}] 已連線成功")
        await self.emit_event("ranking_bp", [])
        await self.emit_event("ranking_qp", [])

    def handle_event(self, event, args):
        match event:
            case "ranking_bp":
                self.bp_data = args[0]
            case "ranking_qp":
                self.qp_data = args[0]

    async def export(self):
        while True:
            await asyncio.sleep(self.EXPORT_PERIOD)
            with self.bp_file.open("w", encoding="utf-8") as bf:
                json.dump(self.bp_data, bf, indent=4, ensure_ascii=False)
            with self.qp_file.open("w", encoding="utf-8") as qf:
                json.dump(self.qp_data, qf, indent=4, ensure_ascii=False)

    async def request_loop(self):
        while True:
            await asyncio.sleep(self.EXPORT_PERIOD)
            await self.emit_event("ranking_bp", [])
            await self.emit_event("ranking_qp", [])

    def task_group(self):
        return [self.ping(), self.export(), self.request_loop(), self.listen()]


async def merge_room_data(channel_id: int, output_file: Path, interval: int = 30):
    """定期合併 channel_id 的 TW/JP 資料，寫入 output_file"""
    input_files = list(data_dir.glob(f"channel{channel_id}_room_*.json"))
    if not input_files:
        print(f"[MERGE] 沒有找到 channel{channel_id} 的資料檔案")
        return

    while True:
        merged = {}
        for f in input_files:
            try:
                with f.open("r", encoding="utf-8") as jf:
                    data = json.load(jf)
                    merged.update(data)
            except Exception as e:
                print(f"[MERGE] 無法讀取 {f.name}: {e}")
        with output_file.open("w", encoding="utf-8") as out:
            json.dump(merged, out, indent=4, ensure_ascii=False)
        await asyncio.sleep(interval)


async def main():
    watchers = [
        ChannelWatcher(1, "wss://www.playunlight.online:11012", "TW"),
        ChannelWatcher(3, "wss://www.playunlight-dmm.com:20005", "TW", owner_id="lwe2j1e9DSSd2vpXzAFh252jTIVk3m3X0000"),
        ChannelWatcher(1, "wss://www.playunlight-dmm.com:11012", "JP"),
        RankingWatcher("wss://www.playunlight.online:11007", "TW"),
        RankingWatcher("wss://www.playunlight-dmm.com:11007", "JP"),
    ]

    merge_tasks = [
        merge_room_data(1, data_dir / "channel1_room.json", 30),
        merge_room_data(3, data_dir / "channel3_room.json", 30),
    ]

    # ⚠️ 改為 asyncio.gather，兼容 Python 3.12+
    await asyncio.gather(*(w.start() for w in watchers), *merge_tasks)


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("程式中止 by user")
        sys.exit(0)
