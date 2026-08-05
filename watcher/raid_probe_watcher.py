#!/usr/bin/python3.11
# raid_probe_watcher.py
# pip install websockets aiohttp python-dotenv

from pathlib import Path
from itertools import count
import asyncio
import datetime
import json
import logging
import os
import re
import ssl
import time

from dotenv import load_dotenv
from websockets.asyncio.client import connect
from websockets.exceptions import ConnectionClosed

os.environ["TZ"] = "Asia/Taipei"
load_dotenv()

WATCHER_VERSION = "2026-07-03 raid-code-probe"
print(f"=== Unlight Raid Code Probe Watcher {WATCHER_VERSION} ===")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

data_dir = Path(__file__).parent
out_dir = data_dir / "raid_probe"
out_dir.mkdir(parents=True, exist_ok=True)

class RaidProbeWatcher:
    EXPORT_PERIOD = 15

    def __init__(
        self,
        endpoint: str = "wss://www.playunlight.online:15010/",
        host_code: str = "TW",
        origin: str = "https://www.playunlight.online:14012",
    ):
        self.endpoint = endpoint
        self.host_code = host_code
        self.origin = origin
        self.msg_idx = 0
        self.acks: list[str] = []
        self.alive = True
        self.ws = None

        self.event_count: dict[str, int] = {}
        self.last_events: dict[str, dict] = {}
        self.raid_codes: dict[str, dict] = {}

        self.raw_file = out_dir / f"raid_raw_{host_code}.jsonl"
        self.summary_file = out_dir / f"raid_summary_{host_code}.json"
        self.latest_file = out_dir / f"raid_latest_{host_code}.json"
        self.code_file = out_dir / f"raid_codes_{host_code}.json"
        self.raid_codes: dict[str, dict] = {}

    @property
    def task_id(self) -> str:
        return f"Raid:{self.host_code}"

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
            
    async def request_loop(self):
        await asyncio.sleep(2)
        events = [
            ("raid_list", []),
            ("raid_info", []),
            ("raid_update", []),
            ("raid_search", []),
            ("raid_rank", []),
            ("vortex_list", []),
            ("vortex_update", []),
            ("vortex_info", []),
            ("vortex_normal", []),
            ("vortex_event", []),
            ("vortex_another", []),
            ("raid_all", []),
            ("raid_room", []),
            ("raid_code", []),
            ("raid_find", []),
            ("raid_get", []),
        ]

        while True:
            try:
                for event, args in events:
                    await self.emit_event(event, args)
                    await asyncio.sleep(0.3)
            except Exception as e:
                print(f"[{self.task_id}] request_loop error: {e}")
                return

            await asyncio.sleep(20)

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

    def extract_raid_codes(self, data: dict):
        text = json.dumps(data, ensure_ascii=False)
        candidates = re.findall(r"\b[A-Za-z0-9]{8,20}\b", text)

        ignore_words = {
            "raid_port", "ranking_bp", "ranking_qp", "updatedAt",
            "playunlight", "online", "https", "wss", "event",
            "args", "meta", "ACKs", "endpoint", "host_code"
        }

        now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        event = data.get("event")
        args = data.get("args", [])

        for code in candidates:
            if code in ignore_words:
                continue
            if code.isdigit():
                continue
            if code.startswith(("ranking", "raid", "vortex", "https", "online")):
                continue
            if len(code) < 10:
                continue

            self.raid_codes[code] = {
                "code": code,
                "last_seen": now,
                "source_event": event,
                "raw_args": args,
            }

    def write_export(self):
        now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

        summary = {
            "updated_at": now,
            "host_code": self.host_code,
            "endpoint": self.endpoint,
            "event_count": dict(sorted(self.event_count.items(), key=lambda x: x[0])),
            "known_events": sorted(self.event_count.keys()),
            "raid_code_count": len(getattr(self, "raid_codes", {})),
        }

        latest = {
            "updated_at": now,
            "host_code": self.host_code,
            "endpoint": self.endpoint,
            "events": self.last_events,
        }

        codes = {
            "updated_at": now,
            "host_code": self.host_code,
            "endpoint": self.endpoint,
            "codes": list(getattr(self, "raid_codes", {}).values()),
        }

        with self.summary_file.open("w", encoding="utf-8") as f:
            json.dump(summary, f, indent=4, ensure_ascii=False)

        with self.latest_file.open("w", encoding="utf-8") as f:
            json.dump(latest, f, indent=4, ensure_ascii=False)

        with self.code_file.open("w", encoding="utf-8") as f:
            json.dump(codes, f, indent=4, ensure_ascii=False)

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

                    self.extract_raid_codes(data)
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

    async def prepare(self):
        self.alive = True
        print(f"[{self.task_id}] 連線中 → {self.endpoint}")

        ssl_ctx = ssl._create_unverified_context()
        self.ws = await connect(
            self.endpoint,
            ssl=ssl_ctx,
            origin=self.origin,
            ping_interval=None,
        )

        print(f"[{self.task_id}] 已連線成功")

    async def start(self):
        for retry in count(1):
            try:
                await self.prepare()
                self.write_export()
                print(f"[{self.task_id}] 已建立輸出檔案 → {self.code_file}")
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
    watcher = RaidProbeWatcher(
        endpoint="wss://www.playunlight.online:15010/",
        host_code="TW",
    )
    await watcher.start()

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("程式中止 by user")