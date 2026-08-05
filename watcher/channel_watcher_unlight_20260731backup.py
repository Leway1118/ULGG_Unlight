#!/usr/bin/python3.11
# channel_watcher.py
# pip install websockets
WATCHER_VERSION = "2025-12-11 maintenance-aware"
print(f"=== Unlight Watcher {WATCHER_VERSION} ===")
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

import aiohttp
import datetime
import os,time
os.environ['TZ'] = 'Asia/Taipei'
from dotenv import load_dotenv

load_dotenv()
DISCORD_WEBHOOK = os.getenv("DISCORD_WEBHOOK")

from websockets.asyncio.client import connect, ClientConnection
from websockets.exceptions import ConnectionClosed

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

data_dir = Path(__file__).parent
# --- Discord alert throttle ---
_ALERT_LAST_SENT: dict[str, float] = {}
_ALERT_COOLDOWN = 300  # 秒（5 分鐘）

async def send_discord_alert(msg: str):
    """傳送錯誤/崩潰通知到 Discord（含節流）"""
    if not DISCORD_WEBHOOK:
        return

    now_ts = time.time()

    # 用訊息內容當 key（同錯誤只送一次）
    last_ts = _ALERT_LAST_SENT.get(msg, 0)
    if now_ts - last_ts < _ALERT_COOLDOWN:
        return  # ⛔ 節流：不再送

    _ALERT_LAST_SENT[msg] = now_ts

    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    payload = {
        "embeds": [
            {
                "title": "⚠️ Unlight Watcher Alert",
                "description": msg,
                "color": 16733440,
                "footer": {"text": f"Time: {now}"}
            }
        ]
    }

    async with aiohttp.ClientSession() as session:
        try:
            await session.post(DISCORD_WEBHOOK, json=payload)
        except Exception as e:
            print(f"[ALERT] Discord 通知失敗：{e}")



class BaseWatcher(ABC):
    EXPORT_PERIOD: int
    ws: ClientConnection

    def __init__(self, endpoint: str):
        self.endpoint = endpoint
        self.msg_idx = 0
        self.acks: list[str] = []
        self.alive = True

    async def emit_event(self, event: str, args: list):
        self._last_emit = getattr(self, "_last_emit", 0)

        now_ts = time.time()
        if now_ts - self._last_emit < 0.05:
            await asyncio.sleep(0.05)

        self._last_emit = time.time()
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
        # 只印事件名稱，不印 payload
        if event.startswith("__"):
            logger.debug("[%s] → %s", self.task_id, event)
        else:
            logger.info("[%s] → emit %s", self.task_id, event)

        await self.ws.send(msg)

    async def check_msg(self, data) -> str | None:
        
        evt: str = data["event"]
        # ⭐ 不要印 payload，只印事件名稱
        if evt.startswith("__"):
            logger.debug("[%s] recv %s", self.task_id, evt)
        else:
            #logger.info("[%s] recv %s", self.task_id, evt)
            NOISY_EVENTS = {"channel_length"}

            if evt.startswith("__"):
                logger.debug("[%s] recv %s", self.task_id, evt)
            elif evt in NOISY_EVENTS:
                logger.debug("[%s] recv %s", self.task_id, evt)
            else:
                logger.info("[%s] recv %s", self.task_id, evt)
        match evt:
            case "__ping_s":
                await self.emit_event("__pong_s", [])
            case "__pong_c":
                self.alive = True
            case "__handshake_s":
                await self.emit_event("__handshake_c", ["1.1.2", None])
            case "__connected":
                pass
            case _:
                if not evt.startswith("__"):
                    msg_id: str = data["meta"]["id"]
                    self.acks.append(msg_id)
                return evt

    async def ping(self):#BaseWatcher全域設定
        while True:
            await asyncio.sleep(10)
            try:
                if not self.alive:
                    print(f"[{self.task_id}] Pong 超時，斷線重連中...")
                    raise ConnectionError("Pong timeout")
                self.alive = False
                await self.emit_event("__ping_c", [])
            except Exception as e:
                print(f"[{self.task_id}] Ping error: {e}")
                await self.ws.close()
                break


    @property
    @abstractmethod
    def task_id(self) -> str: ...

    @abstractmethod
    def handle_event(self, event, args): ...

    @abstractmethod
    def task_group(self) -> list[Coroutine]: ...

    async def prepare(self):
        self.alive = True   # ⭐ reset heartbeat state
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
                msg = f"[{self.task_id}] 處理訊息錯誤: {e}"
                print(msg)
                await send_discord_alert(msg)
                break

    async def start(self):
        #raise Exception("TEST - watcher crash")  # ←測試test用
        for retry in count(1):
            try:
                await self.prepare()
                await asyncio.gather(*self.task_group())
            except Exception as e:
                msg = f"[{self.task_id}] 連線中斷：{e}（第 {retry} 次重試）"
                print(msg)
                await send_discord_alert(msg)
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
        self._dirty = False   # ⭐ 新增：資料是否有變更

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
                room["region"] = self.host_code
                rid = room["room_id"]

                if self.channel_data.get(rid) != room:
                    self.channel_data[rid] = room
                    self._dirty = True

            # 只保留最近 2 天
            cutoff_ms = int((time.time() - 2 * 24 * 60 * 60) * 1000)

            before_count = len(self.channel_data)

            self.channel_data = {
                rid: room
                for rid, room in self.channel_data.items()
                if isinstance(room, dict)
                and isinstance(room.get("date"), (int, float))
                and room.get("date", 0) >= cutoff_ms
            }

            after_count = len(self.channel_data)

            if after_count != before_count:
                self._dirty = True
                print(
                    f"[{self.task_id}] 清理逾 2 天房間："
                    f"{before_count} → {after_count}"
                )
        


    #避免之後又出現短暫 missing
    async def export(self):
        while True:
            await asyncio.sleep(self.EXPORT_PERIOD)

            if not self._dirty:
                continue

            tmp_file = self.data_file.with_suffix(self.data_file.suffix + ".tmp")

            with tmp_file.open("w", encoding="utf-8") as f:
                json.dump(self.channel_data, f, indent=4, ensure_ascii=False)

            tmp_file.replace(self.data_file)

            self._dirty = False



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
        #self._dirty_bp = False
        #self._dirty_qp = False

        def load_json(file_path: Path) -> dict:
            file_path.parent.mkdir(parents=True, exist_ok=True)

            if not file_path.exists() or file_path.stat().st_size == 0:
                file_path.write_text("{}", encoding="utf-8")
                return {}

            try:
                with file_path.open("r", encoding="utf-8") as f:
                    return json.load(f)
            except json.JSONDecodeError:
                print(f"[WARNING] {file_path.name} 格式錯誤，已重建為空 JSON")
                file_path.write_text("{}", encoding="utf-8")
                return {}

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

                    for room_id, room_data in data.items():
                        if "region" not in room_data or not room_data["region"]:
                            room_data["region"] = None
                        merged[room_id] = room_data


            except Exception as e:
                print(f"[MERGE] 無法讀取 {f.name}: {e}")

        tmp_file = output_file.with_suffix(output_file.suffix + ".tmp")

        with tmp_file.open("w", encoding="utf-8") as out:
            json.dump(merged, out, indent=4, ensure_ascii=False)

        tmp_file.replace(output_file)

        await asyncio.sleep(interval)
        
async def merge_room_sources(output_file: Path, patterns: list[str], interval: int = 30):
    while True:
        input_files = []
        for pattern in patterns:
            input_files.extend(data_dir.glob(pattern))

        # 去重
        input_files = sorted(set(input_files))

        if not input_files:
            print(f"[MERGE] 沒有找到來源檔案：{patterns}")
            await asyncio.sleep(interval)
            continue

        merged = {}

        for f in input_files:
            try:
                with f.open("r", encoding="utf-8") as jf:
                    data = json.load(jf)

                if not isinstance(data, dict):
                    continue

                for room_id, room_data in data.items():
                    if not isinstance(room_data, dict):
                        continue

                    if "region" not in room_data or not room_data["region"]:
                        room_data["region"] = None

                    if f.name.startswith("channel4_room_"):
                        room_data["room_source"] = "ROOM4"
                    elif f.name.startswith("channel2_room_"):
                        room_data["room_source"] = "ROOM2"
                    else:
                        room_data["room_source"] = f.stem

                    merged[room_id] = room_data

            except Exception as e:
                print(f"[MERGE] 無法讀取 {f.name}: {e}")

        tmp_file = output_file.with_suffix(output_file.suffix + ".tmp")

        with tmp_file.open("w", encoding="utf-8") as out:
            json.dump(merged, out, indent=4, ensure_ascii=False)

        for i in range(5):
            try:
                tmp_file.replace(output_file)
                break
            except PermissionError as e:
                print(f"[MERGE] {output_file.name} 被鎖定，{i + 1}/5 次重試：{e}")
                await asyncio.sleep(1)
        else:
            print(f"[MERGE] 放棄本輪寫入 {output_file.name}，下輪再試")

        await asyncio.sleep(interval)



async def main():
    watchers = [
        ChannelWatcher(1, "wss://www.playunlight.online:11012", "TW"),
        ChannelWatcher(2, "wss://www.playunlight.online:11012", "TW"),
        ChannelWatcher(3, "wss://www.playunlight-dmm.com:20005", "CR", owner_id="lwe2j1e9DSSd2vpXzAFh252jTIVk3m3X0000"),
         ChannelWatcher(4, "wss://www.playunlight-dmm.com:20004/", "CR", owner_id="lwe2j1e9DSSd2vpXzAFh252jTIVk3m3X0000"),
        ChannelWatcher(1, "wss://www.playunlight-dmm.com:11012", "JP"),
        ChannelWatcher(2, "wss://www.playunlight-dmm.com:11012", "JP"),
        RankingWatcher("wss://www.playunlight.online:11007", "TW"),
        RankingWatcher("wss://www.playunlight-dmm.com:11007", "JP"),
    ]

    merge_tasks = [
        merge_room_data(1, data_dir / "channel1_room.json", 30),

        # ROOM2 + ROOM4 合併成「目前一般對戰候選池」
        merge_room_sources(
            data_dir / "channel2_room.json",
            [
                "channel2_room_*.json",
                "channel4_room_*.json",
            ],
            30
        ),

        merge_room_data(3, data_dir / "channel3_room.json", 30),

        # 可選：保留 ROOM4 獨立總檔，方便 debug
        merge_room_data(4, data_dir / "channel4_room.json", 30),
    ]

    # ⚠️ 改為 asyncio.gather，兼容 Python 3.12+
    await asyncio.gather(*(w.start() for w in watchers), *merge_tasks)


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("程式中止 by user")
    except Exception as e:
        msg = f"🛑 Watcher 主程式異常終止：{e}"
        print(msg)
        asyncio.run(send_discord_alert(msg))
