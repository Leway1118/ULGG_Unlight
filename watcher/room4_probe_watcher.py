#!/usr/bin/python3.11
# room4_probe_watcher.py
# pip install websockets python-dotenv

from pathlib import Path
from itertools import count
import asyncio
import datetime
import json
import logging
import os
import ssl
import time

from dotenv import load_dotenv
from websockets.asyncio.client import connect
from websockets.exceptions import ConnectionClosed

os.environ["TZ"] = "Asia/Taipei"
load_dotenv()

WATCHER_VERSION = "2026-07-03 room4-probe"
print(f"=== Unlight ROOM4 Probe Watcher {WATCHER_VERSION} ===")

logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s")
logger = logging.getLogger(__name__)

data_dir = Path(__file__).parent
out_dir = data_dir / "room4_probe"
out_dir.mkdir(parents=True, exist_ok=True)

class Room4ProbeWatcher:
    EXPORT_PERIOD = 10
    REQUEST_PERIOD = 20

    def __init__(
        self,
        endpoint: str = "wss://www.playunlight-dmm.com:20004/",
        channel_id: int = 4,
        host_code: str = "DMM_ROOM4",
        owner_id: str = "",
    ):
        self.endpoint = endpoint
        self.channel_id = channel_id
        self.host_code = host_code
        self.owner_id = owner_id
        self.msg_idx = 0
        self.acks: list[str] = []
        self.alive = True
        self.ws = None
        self.event_count: dict[str, int] = {}
        self.last_events: dict[str, dict] = {}
        self.rooms: dict[str, dict] = {}
        self.raw_file = out_dir / f"room4_raw_{host_code}.jsonl"
        self.summary_file = out_dir / f"room4_summary_{host_code}.json"
        self.latest_file = out_dir / f"room4_latest_{host_code}.json"
        self.room_file = out_dir / f"channel4_room_{host_code}.json"

    @property
    def task_id(self) -> str:
        return f"ROOM4:{self.host_code}"

    async def emit_event(self, event: str, args: list):
        self._last_emit = getattr(self, "_last_emit", 0)
        now_ts = time.time()
        if now_ts - self._last_emit < 0.08:
            await asyncio.sleep(0.08)
        self._last_emit = time.time()

        now = int(time.time() * 1000)
        msg_id = f"{event}:{self.msg_idx}:{now}"
        self.msg_idx += 1

        if event in {"__pong_s", "__ping_c"}:
            args = [now]

        payload = {
            "meta": {
                "id": msg_id,
                "ACKs": self.acks,
            },
            "event": event,
            "args": args,
        }

        self.acks = []
        await self.ws.send(json.dumps(payload, ensure_ascii=False))

        if not event.startswith("__"):
            logger.info("[%s] → emit %s %s", self.task_id, event, args)

    async def check_msg(self, data: dict) -> str | None:
        evt = data.get("event", "")
        meta = data.get("meta", {})

        if evt == "__ping_s":
            await self.emit_event("__pong_s", [])
            return None

        if evt == "__pong_c":
            self.alive = True
            return None

        if evt == "__handshake_s":
            await self.emit_event("__handshake_c", ["1.1.2", None])
            return None

        if evt == "__connected":
            return None

        if evt:
            self.event_count[evt] = self.event_count.get(evt, 0) + 1

        if evt and not evt.startswith("__"):
            msg_id = meta.get("id")
            if msg_id:
                self.acks.append(msg_id)

        return evt

    def write_raw(self, data: dict):
        row = {
            "time": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "endpoint": self.endpoint,
            "data": data,
        }
        with self.raw_file.open("a", encoding="utf-8") as f:
            f.write(json.dumps(row, ensure_ascii=False) + "\n")

    def handle_event(self, event: str, args: list):
        if event != f"channel{self.channel_id}_room":
            return

        rooms = args[0] if args and isinstance(args[0], list) else []

        print(f"[{self.task_id}] 收到 {event}：{len(rooms)} rooms")

        for room in rooms:
            if not isinstance(room, dict):
                continue

            rid = room.get("room_id")
            if not rid:
                continue

            room["region"] = self.host_code
            room["source_endpoint"] = self.endpoint
            room["last_seen"] = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

            self.rooms[str(rid)] = room

        print(f"[{self.task_id}] 目前累積 rooms：{len(self.rooms)}")

    def write_export(self):
        now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

        summary = {
            "updated_at": now,
            "host_code": self.host_code,
            "endpoint": self.endpoint,
            "channel_id": self.channel_id,
            "event_count": dict(sorted(self.event_count.items(), key=lambda x: x[0])),
            "known_events": sorted(self.event_count.keys()),
            "room_count": len(self.rooms),
        }

        latest = {
            "updated_at": now,
            "host_code": self.host_code,
            "endpoint": self.endpoint,
            "events": self.last_events,
        }

        with self.summary_file.open("w", encoding="utf-8") as f:
            json.dump(summary, f, indent=4, ensure_ascii=False)

        with self.latest_file.open("w", encoding="utf-8") as f:
            json.dump(latest, f, indent=4, ensure_ascii=False)

        tmp_file = self.room_file.with_suffix(self.room_file.suffix + ".tmp")

        with tmp_file.open("w", encoding="utf-8") as f:
            json.dump(self.rooms, f, indent=4, ensure_ascii=False)

        tmp_file.replace(self.room_file)

    async def ping(self):
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
                try:
                    await self.ws.close()
                except Exception:
                    pass
                break

    async def listen(self):
        async for message in self.ws:
            try:
                data = json.loads(message)
                event = await self.check_msg(data)

                if event:
                    args = data.get("args", [])
                    logger.info("[%s] recv %s", self.task_id, event)

                    self.last_events[event] = {
                        "time": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                        "args": args,
                    }

                    self.handle_event(event, args)
                    self.write_raw(data)
                    self.write_export()

            except ConnectionClosed:
                print(f"[{self.task_id}] 伺服器連線已關閉")
                break
            except Exception as e:
                print(f"[{self.task_id}] 處理訊息錯誤: {e}")
                self.write_raw({
                    "event": "__local_error",
                    "args": [str(e)],
                })
                break

    async def export_loop(self):
        while True:
            await asyncio.sleep(self.EXPORT_PERIOD)
            self.write_export()

    async def request_loop(self):
        await asyncio.sleep(2)

        if self.owner_id:
            await self.emit_event("register", [self.owner_id])
            print(f"[{self.task_id}] 已送出 register")
            await asyncio.sleep(1)

        await self.emit_event("channel_in", [self.channel_id])
        print(f"[{self.task_id}] 已送出 channel_in [{self.channel_id}]")

        while True:
            await asyncio.sleep(self.REQUEST_PERIOD)
            await self.emit_event("channel_in", [self.channel_id])

    async def prepare(self):
        self.alive = True
        print(f"[{self.task_id}] 連線中 → {self.endpoint}")

        ssl_ctx = ssl._create_unverified_context()
        self.ws = await connect(
            self.endpoint,
            ssl=ssl_ctx,
            ping_interval=None,
        )

        print(f"[{self.task_id}] 已連線成功")
        self.write_export()

    async def start(self):
        for retry in count(1):
            try:
                await self.prepare()
                await asyncio.gather(
                    self.ping(),
                    self.listen(),
                    self.export_loop(),
                    self.request_loop(),
                )
            except Exception as e:
                print(f"[{self.task_id}] 連線中斷：{e}（第 {retry} 次重試）")
                await asyncio.sleep(5)

async def main():
    watcher = Room4ProbeWatcher(
        endpoint="wss://www.playunlight-dmm.com:20004/",
        channel_id=4,
        host_code="DMM_ROOM4",
        owner_id="lwe2j1e9DSSd2vpXzAFh252jTIVk3m3X0000",
    )
    await watcher.start()

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("程式中止 by user")