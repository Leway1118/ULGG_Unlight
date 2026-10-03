#!/usr/bin/python3.11
# channel_watcher.py
# pip install websockets
WATCHER_VERSION = "2026-09-24 protocol-1.4-auth-session"
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

load_dotenv(
    dotenv_path=Path(__file__).resolve().parent.parent / ".env"
)

DISCORD_WEBHOOK = os.getenv("DISCORD_WEBHOOK")

ULGG_LINK_ROOM_URL = os.getenv(
    "ULGG_LINK_ROOM_URL",
    "https://ulgg.online/pages/ruleset/api/link_room.php",
).strip()

ULGG_WATCHER_API_TOKEN = os.getenv(
    "ULGG_WATCHER_API_TOKEN",
    "",
).strip()

ULGG_VALIDATE_DECK_URL = os.getenv(
    "ULGG_VALIDATE_DECK_URL",
    ""
).strip()


# ULGG_RULESET_WATCHER_FLAG_20260930_V1
# 自訂規則自動綁房 / 牌組驗證可獨立停用。
ULGG_RULESET_WATCHER_ENABLED = (
    os.getenv(
        "ULGG_RULESET_WATCHER_ENABLED",
        "0",
    )
    .strip()
    .lower()
    in {"1", "true", "yes", "on"}
)

# ── 2026-09-23 遊戲改版（WS 協定 1.4）──────────────────────────────
# 1. 握手版本：伺服器的 __handshake_s 只接受 major.minor 相同的客戶端（現在是
#    ["1.4"]）。送舊的 1.1.2 不會報錯，只是永遠等不到 __connected。
# 2. **每條連線都要先 register 一個有效的 auth/player session id**，不然伺服器
#    對後續請求不回。TW 可從 9998 auth endpoint 用 getid 動態取得；固定
#    WATCHER_PLAYER_ID_TW 只保留作 fallback，不再當主要長期身份。
# 3. 頻道：房間列表改成 register [player_id, 頻道] → get_channel_room [頻道]，
#    之後伺服器主動推 refresh_room [整份列表]。頻道開在哪個 port 要先問大廳
#    （get_matching_channel，每次問可能給不同的 11012~11015）。
# 4. 排行榜：ranking_bp / ranking_qp 合併成 get_ranking_data。
CLIENT_VERSION = os.getenv("ULR_CLIENT_VERSION", "1.4.1").strip()

WATCHER_PLAYER_ID = {
    "TW": os.getenv("WATCHER_PLAYER_ID_TW", "").strip(),
    "JP": os.getenv("WATCHER_PLAYER_ID_JP", "").strip(),
    # 跨服（CR）以前是 dmm:20004/20005 加 owner_id；改版後那幾個 port 全部連不上，
    # 頻道會從 JP 大廳的 get_matching_channel 找（找不到就每 10 分鐘再試）。
    "CR": os.getenv("WATCHER_PLAYER_ID_CR", "").strip()
          or os.getenv("WATCHER_PLAYER_ID_JP", "").strip(),
}

LOBBY_ENDPOINT = {
    "TW": "wss://www.playunlight.online:11007/",
    "JP": "wss://www.playunlight-dmm.com:11007/",
}

# ── Protocol 1.4 session auth ────────────────────────────────────────
# 已驗證 TW Steam iframe 會帶 platform_id / platform_key=unlight /
# platform_type=steam；auth endpoint 的 getid 會回 register 所需 UUID。
# JP/CR auth endpoint 尚未實測，因此預設不猜；仍走 WATCHER_PLAYER_ID_* fallback。
AUTH_ENDPOINT = {
    "TW": os.getenv(
        "WATCHER_AUTH_ENDPOINT_TW",
        "wss://www.playunlight.online:9998/",
    ).strip(),
    "JP": os.getenv("WATCHER_AUTH_ENDPOINT_JP", "").strip(),
    "CR": os.getenv("WATCHER_AUTH_ENDPOINT_CR", "").strip(),
}

WATCHER_PLATFORM_ID = {
    "TW": os.getenv("WATCHER_PLATFORM_ID_TW", "").strip(),
    "JP": os.getenv("WATCHER_PLATFORM_ID_JP", "").strip(),
    "CR": os.getenv("WATCHER_PLATFORM_ID_CR", "").strip(),
}

WATCHER_PLATFORM_KEY = {
    "TW": os.getenv("WATCHER_PLATFORM_KEY_TW", "unlight").strip(),
    "JP": os.getenv("WATCHER_PLATFORM_KEY_JP", "").strip(),
    "CR": os.getenv("WATCHER_PLATFORM_KEY_CR", "").strip(),
}

WATCHER_PLATFORM_TYPE = {
    "TW": os.getenv("WATCHER_PLATFORM_TYPE_TW", "steam").strip(),
    "JP": os.getenv("WATCHER_PLATFORM_TYPE_JP", "").strip(),
    "CR": os.getenv("WATCHER_PLATFORM_TYPE_CR", "").strip(),
}


class WatcherConfigError(Exception):
    """設定缺了／動態 auth 與 fallback 都不可用 —— 重試也沒用。"""


class RegisterRejected(Exception):
    """register 回非 0；可能是 session UUID 過期，可先刷新 auth 再重試。"""

    def __init__(self, rejected_id: str, response):
        super().__init__(f"register rejected: {response}")
        self.rejected_id = rejected_id
        self.response = response


class ChannelUnavailable(Exception):
    """大廳現在沒開這個頻道 —— 慢慢等，不要每 5 秒敲一次、也不要一直發 Discord。"""



# 找不到比賽的房間仍需稍後重試，避免玩家先進房、後按配對。
_ULGG_LINK_LAST_ATTEMPT: dict[str, float] = {}
_ULGG_LINK_RETRY_SECONDS = 60



# 驗證 API 暫時失敗時稍後重試。
_ULGG_VALIDATE_LAST_ATTEMPT: dict[str, float] = {}
_ULGG_VALIDATE_RETRY_SECONDS = 60

from websockets.asyncio.client import connect, ClientConnection
from websockets.exceptions import ConnectionClosed

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

data_dir = Path(__file__).parent

#新增原子寫入 helper 這樣後面不用重複寫 .tmp → replace
def atomic_write_json(file_path: Path, data) -> None:
    file_path.parent.mkdir(parents=True, exist_ok=True)
    tmp_file = file_path.with_suffix(file_path.suffix + ".tmp")

    with tmp_file.open("w", encoding="utf-8") as f:
        json.dump(data, f, indent=4, ensure_ascii=False)

    tmp_file.replace(file_path)
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
    host_code: str = "?"

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
                # args = [相容版本清單, session id, fetch 逾時秒數, ?]，例如 [["1.4"], "…", 5, true]
                args = data.get("args") or []
                accepted = args[0] if args and isinstance(args[0], list) else []
                mine = ".".join(CLIENT_VERSION.split(".")[:2])
                if accepted and mine not in accepted:
                    raise WatcherConfigError(
                        f"[{self.task_id}] 遊戲又改版了：伺服器只收 {accepted}，"
                        f"我們送 {CLIENT_VERSION}。把 ULR_CLIENT_VERSION（或 CLIENT_VERSION）"
                        f"改成新版本號，並確認事件名稱有沒有跟著換。"
                    )
                await self.emit_event("__handshake_c", [CLIENT_VERSION, None])
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
        # 握手做完才算連上：__handshake_s → __handshake_c → __connected。
        # 在那之前送的東西伺服器不理（1.4 起版本不合就永遠等不到 __connected）。
        await self.recv_until("__connected")
        print(f"[{self.task_id}] 已連線成功")

    async def recv_until(self, want: str, timeout: float = 15):
        """一路收到 want 這個事件為止，回傳它的 args。只在 listen() 開始**之前**用。

        途中收到的心跳／握手照常處理，其他事件照常交給 handle_event —— 不會漏。
        """
        deadline = time.monotonic() + timeout
        while True:
            left = deadline - time.monotonic()
            if left <= 0:
                raise TimeoutError(f"[{self.task_id}] 等 {want} 超過 {timeout} 秒")
            message = await asyncio.wait_for(self.ws.recv(), left)
            data = json.loads(message)
            evt = data.get("event")
            got = await self.check_msg(data)
            if evt == want:
                return data.get("args") or []
            if got:
                self.handle_event(got, data.get("args") or [])

    async def fetch(self, event: str, args: list, timeout: float = 15):
        """跟遊戲客戶端的 WSClient.fetch 一樣：送出去，等同名事件回來。"""
        await self.emit_event(event, args)
        return await self.recv_until(event, timeout)

    async def register(self, player_id: str, *extra):
        """1.4 起每條連線都要先報身分。回 0 = 成功；非 0 交由 auth refresh。"""
        if not player_id:
            raise WatcherConfigError(
                f"[{self.task_id}] 沒有可用的 auth/player session id"
            )
        res = await self.fetch("register", [player_id, *extra])
        if not res or res[0] != 0:
            # 不把 UUID 印到 log/Discord。
            raise RegisterRejected(player_id, res)

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

    async def _close_ws(self):
        ws = getattr(self, "ws", None)
        if ws is not None:
            try:
                await ws.close()
            except Exception:
                pass

    async def _run_task_group_until_disconnect(self):
        """任一背景 task 結束就視為連線生命週期結束，取消其餘 task 後重連。

        舊版 gather() 會在 listen() 已結束時仍被 export() 之類的無限迴圈卡住，
        造成實際斷線後無法回到 start() 的 reconnect loop。
        """
        coroutines = self.task_group()
        if not coroutines:
            return

        tasks = [asyncio.create_task(coro) for coro in coroutines]
        try:
            done, pending = await asyncio.wait(
                tasks,
                return_when=asyncio.FIRST_COMPLETED,
            )
            for task in done:
                exc = task.exception()
                if exc is not None:
                    raise exc
            raise ConnectionError("watcher background task ended")
        finally:
            for task in tasks:
                if not task.done():
                    task.cancel()
            await asyncio.gather(*tasks, return_exceptions=True)
            await self._close_ws()

    async def start(self):
        #raise Exception("TEST - watcher crash")  # ←測試test用
        for retry in count(1):
            try:
                await self.prepare()
                await self._run_task_group_until_disconnect()
            except RegisterRejected as e:
                await self._close_ws()
                provider = getattr(self, "auth_provider", None)
                if provider is None:
                    msg = (
                        f"[{self.task_id}] register 被拒（回 {e.response}），"
                        "但沒有 auth provider 可刷新"
                    )
                    print(msg)
                    await send_discord_alert(msg)
                    return
                try:
                    self.player_id = await provider.refresh_after_rejection(
                        e.rejected_id
                    )
                except WatcherConfigError as refresh_error:
                    msg = f"[{self.task_id}] {refresh_error}"
                    print(msg)
                    await send_discord_alert(msg)
                    return
                print(f"[{self.task_id}] register session 已刷新，重新連線")
                await asyncio.sleep(1)
            except WatcherConfigError as e:
                await self._close_ws()
                # 設定錯了，重試一萬次也一樣 —— 講一次就停，別洗 Discord。
                print(e)
                await send_discord_alert(str(e))
                return
            except ChannelUnavailable as e:
                await self._close_ws()
                print(e)
                await asyncio.sleep(600)
            except Exception as e:
                await self._close_ws()
                msg = f"[{self.task_id}] 連線中斷：{e}（第 {retry} 次重試）"
                print(msg)
                await send_discord_alert(msg)
                await asyncio.sleep(5)


class AuthClient(BaseWatcher):
    """一次性向 auth endpoint 取得 protocol-1.4 register UUID。"""

    def __init__(
        self,
        endpoint: str,
        host_code: str,
        platform_id: str,
        platform_key: str,
        platform_type: str,
    ):
        super().__init__(endpoint)
        self.host_code = host_code
        self.config = {
            "platform_id": platform_id,
            "platform_key": platform_key,
            "platform_type": platform_type,
        }

    @property
    def task_id(self) -> str:
        return f"Auth:{self.host_code}"

    def handle_event(self, event, args):
        pass

    def task_group(self):
        return []

    async def get_id(self) -> str:
        try:
            await self.prepare()
            # 參照實測 prototype：握手完成後稍等再送 getid，避免 auth server
            # 剛建立 session 時的競態；每個 process 通常只做一次，成本可忽略。
            await asyncio.sleep(1)
            args = await self.fetch(
                "getid",
                [self.config, "browser"],
                timeout=15,
            )
            auth_id = args[0] if args and isinstance(args[0], str) else ""
            # register 使用的是 UUID；只驗證基本 UUID 形狀，不記錄實值。
            if len(auth_id) != 36 or auth_id.count("-") != 4:
                raise RuntimeError("getid 未回傳有效 UUID")
            print(f"[{self.task_id}] Authentication successful")
            return auth_id
        finally:
            await self._close_ws()


# SHARED_TW_AUTH_OWNER_20260927_V1
#
# TW dynamic auth must have exactly one owner process.
# New getid invalidates the previously issued session.
# channel_watcher_unlight is the owner; other local services
# consume this root-only runtime file instead of calling getid.
SHARED_TW_AUTH_FILE = Path(
    os.getenv(
        "WATCHER_SHARED_TW_AUTH_FILE",
        "/run/unlight/tw_auth_session",
    )
)


def _publish_shared_auth_session(
    host_code: str,
    auth_id: str,
) -> None:
    if host_code != "TW":
        return

    if not isinstance(auth_id, str):
        return

    auth_id = auth_id.strip()

    if len(auth_id) != 36 or auth_id.count("-") != 4:
        return

    runtime_dir = SHARED_TW_AUTH_FILE.parent
    runtime_dir.mkdir(
        parents=True,
        exist_ok=True,
        mode=0o700,
    )

    try:
        runtime_dir.chmod(0o700)
    except OSError:
        pass

    tmp = SHARED_TW_AUTH_FILE.with_name(
        SHARED_TW_AUTH_FILE.name + ".tmp"
    )

    tmp.write_text(
        auth_id + "\n",
        encoding="utf-8",
    )

    try:
        tmp.chmod(0o600)
    except OSError:
        pass

    tmp.replace(SHARED_TW_AUTH_FILE)

    try:
        SHARED_TW_AUTH_FILE.chmod(0o600)
    except OSError:
        pass

    print(
        "[Auth:TW] shared session published "
        "to runtime file"
    )


class AuthSessionProvider:
    """每個 host 共用一顆 auth UUID；動態 getid 優先，固定 ID 僅作 fallback。"""

    def __init__(self, host_code: str):
        self.host_code = host_code
        self.auth_endpoint = AUTH_ENDPOINT.get(host_code, "")
        self.platform_id = WATCHER_PLATFORM_ID.get(host_code, "")
        self.platform_key = WATCHER_PLATFORM_KEY.get(host_code, "")
        self.platform_type = WATCHER_PLATFORM_TYPE.get(host_code, "")
        self.static_fallback = WATCHER_PLAYER_ID.get(host_code, "")
        self._auth_id = ""
        self._source = ""
        self._lock = asyncio.Lock()

    def dynamic_configured(self) -> bool:
        return bool(
            self.auth_endpoint
            and self.platform_id
            and self.platform_key
            and self.platform_type
        )

    def _validate_platform_config(self) -> None:
        if self.platform_type.lower() == "steam":
            if not (
                self.platform_id.isdigit()
                and len(self.platform_id) == 17
            ):
                raise WatcherConfigError(
                    f"WATCHER_PLATFORM_ID_{self.host_code} 必須是 17 碼 SteamID64"
                )

    async def _fetch_dynamic(self) -> str:
        self._validate_platform_config()
        client = AuthClient(
            self.auth_endpoint,
            self.host_code,
            self.platform_id,
            self.platform_key,
            self.platform_type,
        )
        return await client.get_id()

    async def _resolve_locked(
        self,
        *,
        rejected_id: str = "",
    ) -> str:
        dynamic_error = None

        if self.dynamic_configured():
            try:
                fresh = await self._fetch_dynamic()
                if fresh and fresh != rejected_id:
                    self._auth_id = fresh
                    self._source = "dynamic"

                    # SHARED_TW_AUTH_OWNER_20260927_V1
                    _publish_shared_auth_session(
                        self.host_code,
                        fresh,
                    )

                    print(
                        f"[Auth:{self.host_code}] 使用 getid 動態 session"
                    )
                    return fresh
                dynamic_error = RuntimeError(
                    "getid 回傳與剛被拒絕相同的 UUID"
                )
            except Exception as e:
                dynamic_error = e
                print(
                    f"[Auth:{self.host_code}] 動態 getid 失敗："
                    f"{type(e).__name__}: {e}"
                )

        # 安全 fallback：只在固定 ID 沒有剛被 register 拒絕時使用。
        if self.static_fallback and self.static_fallback != rejected_id:
            self._auth_id = self.static_fallback
            self._source = "static-fallback"
            print(
                f"[Auth:{self.host_code}] 使用 WATCHER_PLAYER_ID_"
                f"{self.host_code} fallback"
            )
            return self._auth_id

        if not self.dynamic_configured() and not self.static_fallback:
            raise WatcherConfigError(
                f"沒有可用身份：請設定 WATCHER_PLATFORM_ID_{self.host_code} "
                f"或 WATCHER_PLAYER_ID_{self.host_code}"
            )

        detail = (
            f"；dynamic={type(dynamic_error).__name__}: {dynamic_error}"
            if dynamic_error is not None
            else ""
        )
        # AUTH_TRANSIENT_RETRY_20260930_V1
        #
        # 真正的設定錯誤仍然 fail-closed；
        # 但 dynamic auth 的握手 timeout / 網路暫時失敗
        # 不應該讓單一 watcher task 永久退出。
        if not self.dynamic_configured():
            raise WatcherConfigError(
                "register session 已失效，且沒有可刷新的 dynamic auth"
            )

        if isinstance(dynamic_error, WatcherConfigError):
            # 例如 SteamID64 格式錯誤等真正配置問題。
            raise dynamic_error

        # BaseWatcher.start() 的一般 Exception 分支會 5 秒後重試。
        raise RuntimeError(
            f"dynamic auth 暫時不可用{detail}"
        )

    async def get(self) -> str:
        async with self._lock:
            if self._auth_id:
                return self._auth_id
            return await self._resolve_locked()

    async def refresh_after_rejection(self, rejected_id: str) -> str:
        """register=非0 時刷新；若另一 task 已先刷新，直接沿用新的共享 UUID。"""
        async with self._lock:
            if self._auth_id and self._auth_id != rejected_id:
                return self._auth_id
            self._auth_id = ""
            self._source = ""
            return await self._resolve_locked(rejected_id=rejected_id)


def _iso_to_ms(value) -> int | None:
    """"2026-09-23T13:40:30.000Z" → epoch 毫秒。認不得回 None。"""
    if not isinstance(value, str) or not value:
        return None
    try:
        dt = datetime.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    return int(dt.timestamp() * 1000)


def normalize_room(room: dict) -> dict:
    """1.4 版的房間補上舊版欄位，下游（合併檔、ULGG 綁房／驗牌）不必跟著改。

    新版：create_at(ISO)、playerA_info{player_name…}、playerA_deck{chara_card_id…}、
          pending（第二個人進房、雙方牌組都在時是 1）
    補上：date(毫秒)、playerA{name…}、deckA、ready／readyA／readyB

    ⚠ ready 是**推的**：新版沒有各自的 ready 旗標，pending==1 而且兩邊牌組都在
      是最接近「雙方鎖定」的狀態（2026-09-23 對著 ch2 八間房看過）。
    ⚠ deckA／deckB 是新版的牌組格式（chara_card_id／weapon_card_id／event_card_id／
      card_effect／cost），ULGG 驗牌那邊若吃舊格式要跟著改。
    """
    out = dict(room)
    if not isinstance(out.get("date"), (int, float)):
        ms = _iso_to_ms(out.get("create_at"))
        if ms is not None:
            out["date"] = ms
    for side in ("A", "B"):
        info = out.get(f"player{side}_info")
        if isinstance(info, dict) and not isinstance(out.get(f"player{side}"), dict):
            out[f"player{side}"] = {"name": info.get("player_name"), **info}
        deck = out.get(f"player{side}_deck")
        if isinstance(deck, dict) and f"deck{side}" not in out:
            out[f"deck{side}"] = deck
    if "pending" in out and "ready" not in out:
        both = isinstance(out.get("deckA"), dict) and isinstance(out.get("deckB"), dict)
        ready = out.get("pending") == 1 and both
        out["ready"] = ready
        out["readyA"] = out["readyB"] = 1 if ready else 0
    return out


class LobbyClient(BaseWatcher):
    """一次性問大廳用的（開頻道前要先問頻道開在哪）。不常駐。"""

    def __init__(self, endpoint: str, host_code: str):
        super().__init__(endpoint)
        self.host_code = host_code

    @property
    def task_id(self) -> str:
        return f"Lobby:{self.host_code}"

    def handle_event(self, event, args):
        pass

    def task_group(self):
        return []

    async def matching_channels(self, player_id: str) -> list[dict]:
        try:
            await self.prepare()
            await self.register(player_id)
            res = await self.fetch("get_matching_channel", [])
            return res[0] if res and isinstance(res[0], list) else []
        finally:
            try:
                await self.ws.close()
            except Exception:
                pass


class ChannelWatcher(BaseWatcher):
    """監聽對戰頻道的房間列表（1.4：get_channel_room + refresh_room 推播）"""

    EXPORT_PERIOD = 3  # seconds

    def __init__(
        self,
        channel_id: int,
        lobby_endpoint: str = LOBBY_ENDPOINT["TW"],
        host_code: str = "TW",
        player_id: str = "",
        auth_provider: AuthSessionProvider | None = None,
    ):
        # 真正的 endpoint 每次連線前才問大廳（get_matching_channel），這裡先放大廳的。
        super().__init__(lobby_endpoint)
        self.lobby_endpoint = lobby_endpoint
        self.channel_id = channel_id
        self.host_code = host_code
        self.auth_provider = auth_provider
        self.player_id = player_id

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
        if not self.player_id:
            if self.auth_provider is not None:
                self.player_id = await self.auth_provider.get()
            else:
                self.player_id = WATCHER_PLAYER_ID.get(self.host_code, "")
        if not self.player_id:
            raise WatcherConfigError(
                f"[{self.task_id}] 沒有可用的 auth/player session id"
            )
        channels = await LobbyClient(self.lobby_endpoint, self.host_code).matching_channels(self.player_id)
        mine = next((c for c in channels if c.get("channel") == self.channel_id), None)
        if not mine or not mine.get("domain"):
            raise ChannelUnavailable(
                f"[{self.task_id}] 大廳現在沒有頻道 {self.channel_id}"
                f"（有的是 {[c.get('channel') for c in channels]}），10 分鐘後再問"
            )
        # "https://www.playunlight.online:11015" → "wss://www.playunlight.online:11015/"
        self.endpoint = mine["domain"].replace("https://", "wss://", 1).rstrip("/") + "/"
        await super().prepare()
        await self.register(self.player_id, self.channel_id)
        res = await self.fetch("get_channel_room", [self.channel_id])
        self.merge_rooms(res[0] if res else [])
        print(f"[{self.task_id}] 已訂閱頻道 {self.channel_id}（{len(res[0]) if res else 0} 間房）")

    def handle_event(self, event, args):
        # 1.4：伺服器每次推的都是**整份**列表；舊版叫 channel{N}_room。
        if event in ("refresh_room", f"channel{self.channel_id}_room"):
            self.merge_rooms(args[0] if args else [])

    def merge_rooms(self, rooms):
        if isinstance(rooms, list):
            for room in rooms:
                if not isinstance(room, dict) or not room.get("room_id"):
                    continue
                room = normalize_room(room)
                room["region"] = self.host_code
                rid = room["room_id"]

                if self.channel_data.get(rid) != room:
                    self.channel_data[rid] = room
                    self._dirty = True

            # CH2_RETENTION_6H_20260930_V1
            #
            # Channel 2 主要作為近期 W/D/L snapshot / 一般房候選來源。
            # importer 約每 30 分鐘處理一次，不需要長期保留 48 小時資料。
            #
            # 僅 Ch2 縮短為 6 小時；
            # Ch1 / Ch3 / Ch4 等其他頻道仍維持原本 48 小時。
            if self.channel_id == 2:
                retention_seconds = 6 * 60 * 60
                retention_label = "6 小時"
            else:
                retention_seconds = 2 * 24 * 60 * 60
                retention_label = "2 天"

            cutoff_ms = int(
                (time.time() - retention_seconds)
                * 1000
            )

            before_count = len(self.channel_data)

            self.channel_data = {
                rid: room
                for rid, room in self.channel_data.items()
                if isinstance(room, dict)
                and isinstance(
                    room.get("date"),
                    (int, float),
                )
                and room.get("date", 0) >= cutoff_ms
            }

            after_count = len(self.channel_data)

            if after_count != before_count:
                self._dirty = True
                print(
                    f"[{self.task_id}] "
                    f"清理逾 {retention_label}房間："
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
    """排行榜 (BP/QP)。1.4 起是大廳的 get_ranking_data，一次回兩份：
    {"ranking_bp": {"data": [{player_name, point}…], "update_at": ms}, "ranking_qp": {…}}"""

    EXPORT_PERIOD = 30

    def __init__(
        self,
        endpoint: str = LOBBY_ENDPOINT["TW"],
        host_code: str = "TW",
        player_id: str = "",
        auth_provider: AuthSessionProvider | None = None,
    ):
        super().__init__(endpoint)
        self.host_code = host_code
        self.auth_provider = auth_provider
        self.player_id = player_id
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
        if not self.player_id:
            if self.auth_provider is not None:
                self.player_id = await self.auth_provider.get()
            else:
                self.player_id = WATCHER_PLAYER_ID.get(self.host_code, "")
        if not self.player_id:
            raise WatcherConfigError(
                f"[{self.task_id}] 沒有可用的 auth/player session id"
            )
        await super().prepare()
        await self.register(self.player_id)
        self.handle_event("get_ranking_data", await self.fetch("get_ranking_data", []))

    def handle_event(self, event, args):
        match event:
            case "get_ranking_data":
                data = args[0] if args and isinstance(args[0], dict) else {}
                # RANKING_IMMEDIATE_FLUSH_20260927_V1
                # Ranking socket may be invalidated before the old 30s export loop.
                # Persist a valid response immediately so importer cannot miss it.
                if data.get("ranking_bp") is not None:
                    self.bp_data = data["ranking_bp"]
                    atomic_write_json(self.bp_file, self.bp_data)

                if data.get("ranking_qp") is not None:
                    self.qp_data = data["ranking_qp"]
                    atomic_write_json(self.qp_file, self.qp_data)

                if (
                    data.get("ranking_bp") is not None
                    or data.get("ranking_qp") is not None
                ):
                    print(
                        f"[{self.task_id}] RANKING IMMEDIATE FLUSH "
                        f"bp={len(self.bp_data.get('data', [])) if isinstance(self.bp_data, dict) else '?'} "
                        f"qp={len(self.qp_data.get('data', [])) if isinstance(self.qp_data, dict) else '?'}"
                    )
            case "ranking_bp":          # 舊協定（1.1.x），留著無害
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
            await self.emit_event("get_ranking_data", [])

    def task_group(self):
        return [self.ping(), self.export(), self.request_loop(), self.listen()]


async def merge_room_data(channel_id: int, output_file: Path, interval: int = 30):
    input_files = list(data_dir.glob(f"channel{channel_id}_room_*.json"))

    # JP_FINAL_RETIRE_20260930_V3
    # channel1_room_JP.json 是歷史封存檔，不再加入現役 Ch1 merge。
    if channel_id == 1:
        input_files = [
            f for f in input_files
            if f.name != "channel1_room_JP.json"
        ]

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

#新增每房間 snapshot 輸出函式
def export_room_snapshots(
    rooms: dict,
    snapshot_dir: Path,
) -> None:
    snapshot_dir.mkdir(parents=True, exist_ok=True)

    active_files: set[str] = set()

    for room_id, room_data in rooms.items():
        if not isinstance(room_id, str) or not room_id:
            continue

        if not isinstance(room_data, dict):
            continue

        snapshot_file = snapshot_dir / f"{room_id}.json"
        atomic_write_json(snapshot_file, room_data)
        active_files.add(snapshot_file.name)

    for existing_file in snapshot_dir.glob("*.json"):
        if existing_file.name not in active_files:
            try:
                existing_file.unlink()
            except OSError as e:
                print(
                    f"[SNAPSHOT] 無法刪除舊 snapshot "
                    f"{existing_file.name}: {e}"
                )
#自動綁房函式
async def link_ulgg_rooms(rooms: dict) -> None:
    """將目前 ROOM2／ROOM4 房間嘗試綁定到等待中的 ULGG Match。"""
    if not ULGG_LINK_ROOM_URL or not ULGG_WATCHER_API_TOKEN:
        return

    now_ts = time.time()

    timeout = aiohttp.ClientTimeout(total=10)
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-ULGG-Watcher-Token": ULGG_WATCHER_API_TOKEN,
    }

    async with aiohttp.ClientSession(timeout=timeout) as session:
        for room_id, room in rooms.items():
            if not isinstance(room, dict):
                continue

            room_id = str(room.get("room_id") or room_id).strip()
            player_a = str(
                (room.get("playerA") or {}).get("name") or ""
            ).strip()
            player_b = str(
                (room.get("playerB") or {}).get("name") or ""
            ).strip()

            if not room_id or not player_a or not player_b:
                continue

            if player_a == player_b:
                continue

            room_date_ms = room.get("date")

            if not isinstance(room_date_ms, (int, float)):
                continue

            room_age_seconds = (
                now_ts - (float(room_date_ms) / 1000)
            )

            # 自訂房可能先建立後等待配對或重新進入，
            # 允許最近 2 小時內的房間參與 ULGG 自動綁定。
            if room_age_seconds < -30 or room_age_seconds > 7200:
                continue

            

            last_attempt = _ULGG_LINK_LAST_ATTEMPT.get(room_id, 0)

            if now_ts - last_attempt < _ULGG_LINK_RETRY_SECONDS:
                continue

            _ULGG_LINK_LAST_ATTEMPT[room_id] = now_ts

            payload = {
                "room_id": room_id,
                "player_a": player_a,
                "player_b": player_b,
                "snapshot_date_ms": int(room_date_ms),
            }

            try:
                async with session.post(
                    ULGG_LINK_ROOM_URL,
                    headers=headers,
                    json=payload,
                ) as response:
                    response_text = await response.text()

                    try:
                        result = json.loads(response_text)
                    except json.JSONDecodeError:
                        print(
                            f"[ULGG LINK] room={room_id} "
                            f"HTTP {response.status} 非 JSON 回應"
                        )
                        continue

                    if response.status == 200 and result.get("ok") is True:
                        match_id = result.get("data", {}).get(
                            "ulgg_match_id"
                        )

                        

                        print(
                            f"[ULGG LINK] 綁定成功："
                            f"Match #{match_id} ← room {room_id} "
                            f"({player_a} vs {player_b})"
                        )
                        continue

                    error_code = (
                        result.get("error", {}).get("code")
                        or "UNKNOWN_ERROR"
                    )

                    # 一般房間不是 ULGG 配對很正常，不需要一直印錯誤。
                    if response.status == 404 and error_code == (
                        "WAITING_MATCH_NOT_FOUND"
                    ):
                        continue

                    print(
                        f"[ULGG LINK] 綁定失敗："
                        f"room={room_id} HTTP={response.status} "
                        f"code={error_code}"
                    )

            except (aiohttp.ClientError, asyncio.TimeoutError) as e:
                print(
                    f"[ULGG LINK] API 連線失敗："
                    f"room={room_id} error={e}"
                )
   
# 自動送出已鎖定牌組進行 ULGG 規則驗證
async def validate_ulgg_decks(rooms: dict) -> None:
    if not ULGG_VALIDATE_DECK_URL or not ULGG_WATCHER_API_TOKEN:
        return

    now_ts = time.time()
    timeout = aiohttp.ClientTimeout(total=10)
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-ULGG-Watcher-Token": ULGG_WATCHER_API_TOKEN,
    }

    async with aiohttp.ClientSession(timeout=timeout) as session:
        for fallback_room_id, room in rooms.items():
            if not isinstance(room, dict):
                continue

            if room.get("ready") is not True:
                continue

            if int(room.get("readyA") or 0) != 1:
                continue

            if int(room.get("readyB") or 0) != 1:
                continue

            room_id = str(
                room.get("room_id") or fallback_room_id
            ).strip()

            player_a = str(
                (room.get("playerA") or {}).get("name") or ""
            ).strip()

            player_b = str(
                (room.get("playerB") or {}).get("name") or ""
            ).strip()

            deck_a = room.get("deckA")
            deck_b = room.get("deckB")
            room_date_ms = room.get("date")

            if not isinstance(room_date_ms, (int, float)):
                continue

            if not room_id or not player_a or not player_b:
                continue

            if player_a == player_b:
                continue

            if not isinstance(deck_a, dict) or not isinstance(deck_b, dict):
                continue

            

            last_attempt = _ULGG_VALIDATE_LAST_ATTEMPT.get(room_id, 0)

            if now_ts - last_attempt < _ULGG_VALIDATE_RETRY_SECONDS:
                continue

            _ULGG_VALIDATE_LAST_ATTEMPT[room_id] = now_ts

            payload = {
                "room_id": room_id,
                "player_a": player_a,
                "player_b": player_b,
                "deck_a": deck_a,
                "deck_b": deck_b,
                "snapshot_date_ms": int(room_date_ms),
            }

            try:
                async with session.post(
                    ULGG_VALIDATE_DECK_URL,
                    headers=headers,
                    json=payload,
                ) as response:
                    response_text = await response.text()

                    try:
                        result = json.loads(response_text)
                    except json.JSONDecodeError:
                        print(
                            f"[ULGG DECK] room={room_id} "
                            f"HTTP {response.status} 非 JSON 回應"
                        )
                        continue

                    if response.status == 200 and result.get("ok") is True:
                        data = result.get("data", {})
                        match_id = data.get("ulgg_match_id")
                        match_status = data.get("match_status")


                        print(
                            f"[ULGG DECK] 驗證完成："
                            f"Match #{match_id} room={room_id} "
                            f"status={match_status}"
                        )
                        continue

                    error_code = (
                        result.get("error", {}).get("code")
                        or "UNKNOWN_ERROR"
                    )

                    # 一般房間沒有對應的 WAITING_DECK Match 很正常。
                    if response.status == 404 and error_code == (
                        "WAITING_DECK_MATCH_NOT_FOUND"
                    ):
                        continue

                    print(
                        f"[ULGG DECK] 驗證失敗："
                        f"room={room_id} HTTP={response.status} "
                        f"code={error_code}"
                    )

            except (aiohttp.ClientError, asyncio.TimeoutError) as e:
                print(
                    f"[ULGG DECK] API 連線失敗："
                    f"room={room_id} error={e}"
                )
  
                        
# ROOM2_INCREMENTAL_MERGE_20260930_V1
def export_room_snapshots_delta(
    changed_rooms: dict,
    removed_room_ids: set[str],
    snapshot_dir: Path,
) -> None:
    """
    只更新本輪有變動的 room snapshot。

    舊版每一輪都把一萬多個 snapshot 全部重寫；
    ROOM2 JSON 成長到 100MB+ 後會造成大量無效 I/O。
    """
    snapshot_dir.mkdir(
        parents=True,
        exist_ok=True,
    )

    written = 0
    removed = 0

    for room_id, room_data in changed_rooms.items():
        if not isinstance(room_id, str) or not room_id:
            continue

        if not isinstance(room_data, dict):
            continue

        snapshot_file = (
            snapshot_dir
            / f"{room_id}.json"
        )

        try:
            atomic_write_json(
                snapshot_file,
                room_data,
            )
            written += 1
        except Exception as e:
            print(
                f"[SNAPSHOT DELTA] "
                f"寫入失敗 room={room_id}: {e}"
            )

    for room_id in removed_room_ids:
        if not isinstance(room_id, str) or not room_id:
            continue

        snapshot_file = (
            snapshot_dir
            / f"{room_id}.json"
        )

        if not snapshot_file.exists():
            continue

        try:
            snapshot_file.unlink()
            removed += 1
        except OSError as e:
            print(
                f"[SNAPSHOT DELTA] "
                f"刪除失敗 room={room_id}: {e}"
            )

    if written or removed:
        print(
            f"[SNAPSHOT DELTA] "
            f"write={written} remove={removed}"
        )


def build_recent_room_pool(
    merged: dict,
    changed_rooms: dict,
    max_age_seconds: int = 7200,
) -> dict:
    """
    ULGG link/deck 必須保留重試能力。

    除本輪 changed rooms 外，再保留最近 2 小時的房間，
    避免「房間沒變，但稍後才建立 ULGG Match」時失去綁定機會。
    """
    now_ms = time.time() * 1000

    result = {}

    for room_id, room in merged.items():
        if not isinstance(room, dict):
            continue

        if room_id in changed_rooms:
            result[room_id] = room
            continue

        room_date_ms = room.get("date")

        if not isinstance(
            room_date_ms,
            (int, float),
        ):
            continue

        age_seconds = (
            now_ms - float(room_date_ms)
        ) / 1000

        if (
            age_seconds >= -30
            and age_seconds <= max_age_seconds
        ):
            result[room_id] = room

    return result


async def merge_room_sources(
    output_file: Path,
    patterns: list[str],
    interval: int = 30,
    snapshot_dir: Path | None = None,
):
    """
    ROOM2/ROOM4 incremental merge.

    V1:
      1. source mtime+size 沒變 → 不重新 json.load
      2. logical room 沒變 → 不重新輸出 merged JSON
      3. snapshot 只寫 changed / removed room
      4. ULGG link/deck 只掃 changed + 最近 2 小時
    """

    source_cache: dict[Path, dict] = {}
    source_signature: dict[Path, tuple[int, int]] = {}

    previous_merged: dict = {}

    # watcher 每 30 分鐘可能被 importer 重啟。
    # 先載入既有 merged 檔當 baseline，
    # 避免每次 process restart 都把 1 萬多個 snapshot 當成全新資料。
    if (
        output_file.exists()
        and output_file.stat().st_size > 0
    ):
        try:
            with output_file.open(
                "r",
                encoding="utf-8",
            ) as jf:
                baseline = json.load(jf)

            if isinstance(baseline, dict):
                previous_merged = baseline

            print(
                f"[MERGE DELTA] baseline="
                f"{len(previous_merged)} rooms "
                f"from {output_file.name}"
            )

        except Exception as e:
            print(
                f"[MERGE DELTA] baseline load failed: {e}"
            )

    while True:
        input_files = []

        for pattern in patterns:
            input_files.extend(
                data_dir.glob(pattern)
            )

        input_files = sorted(
            set(input_files)
        )

        if not input_files:
            print(
                f"[MERGE] 沒有找到來源檔案："
                f"{patterns}"
            )
            await asyncio.sleep(interval)
            continue

        current_files = set(input_files)
        cached_files = set(source_cache)

        source_changed = False

        # 來源檔若消失，也算 logical change。
        for stale_file in (
            cached_files - current_files
        ):
            source_cache.pop(
                stale_file,
                None,
            )
            source_signature.pop(
                stale_file,
                None,
            )
            source_changed = True

            print(
                f"[MERGE DELTA] source removed: "
                f"{stale_file.name}"
            )

        for f in input_files:
            try:
                st = f.stat()

                sig = (
                    st.st_mtime_ns,
                    st.st_size,
                )

            except OSError as e:
                print(
                    f"[MERGE] stat failed "
                    f"{f.name}: {e}"
                )
                continue

            if (
                source_signature.get(f) == sig
                and f in source_cache
            ):
                continue

            try:
                with f.open(
                    "r",
                    encoding="utf-8",
                ) as jf:
                    data = json.load(jf)

                if not isinstance(data, dict):
                    print(
                        f"[MERGE] {f.name} "
                        f"不是 dict，保留舊 cache"
                    )
                    continue

                normalized = {}

                for (
                    room_id,
                    room_data,
                ) in data.items():

                    if not isinstance(
                        room_data,
                        dict,
                    ):
                        continue

                    # copy，避免直接修改 json.load
                    # 回來的 source object。
                    row = dict(room_data)

                    if (
                        "region" not in row
                        or not row["region"]
                    ):
                        row["region"] = None

                    if f.name.startswith(
                        "channel4_room_"
                    ):
                        row["room_source"] = (
                            "ROOM4"
                        )

                    elif f.name.startswith(
                        "channel2_room_"
                    ):
                        row["room_source"] = (
                            "ROOM2"
                        )

                    else:
                        row["room_source"] = (
                            f.stem
                        )

                    normalized[room_id] = row

                source_cache[f] = normalized
                source_signature[f] = sig
                source_changed = True

                print(
                    f"[MERGE DELTA] source reload "
                    f"{f.name} "
                    f"rooms={len(normalized)} "
                    f"bytes={st.st_size}"
                )

            except Exception as e:
                # fail-safe：
                # 本輪讀 source 失敗就保留上一輪 cache，
                # 不因瞬時讀檔問題把 merged 清空。
                print(
                    f"[MERGE] 無法讀取 "
                    f"{f.name}: {e}; "
                    f"keep previous cache"
                )

        # 所有來源 signature 都沒變：
        # 這 3 秒 loop 只做 stat，不碰 100MB JSON。
        if not source_changed:
            await asyncio.sleep(interval)
            continue

        merged = {}

        for f in input_files:
            data = source_cache.get(f)

            if not isinstance(data, dict):
                continue

            merged.update(data)

        changed_rooms = {}

        for room_id, room in merged.items():
            old = previous_merged.get(room_id)

            if old != room:
                changed_rooms[room_id] = room

        removed_room_ids = (
            set(previous_merged)
            - set(merged)
        )

        # source mtime 雖變，但 logical data 完全相同。
        if (
            not changed_rooms
            and not removed_room_ids
        ):
            print(
                f"[MERGE DELTA] "
                f"source changed but logical rooms unchanged "
                f"total={len(merged)}"
            )

            previous_merged = merged

            await asyncio.sleep(interval)
            continue

        started = time.monotonic()

        tmp_file = output_file.with_suffix(
            output_file.suffix + ".tmp"
        )

        try:
            with tmp_file.open(
                "w",
                encoding="utf-8",
            ) as out:
                json.dump(
                    merged,
                    out,
                    indent=4,
                    ensure_ascii=False,
                )

            tmp_file.replace(
                output_file
            )

        except Exception:
            # 不更新 baseline，
            # 下輪還會重新嘗試這批 logical change。
            try:
                if tmp_file.exists():
                    tmp_file.unlink()
            except OSError:
                pass

            raise

        if snapshot_dir is not None:
            try:
                export_room_snapshots_delta(
                    changed_rooms,
                    removed_room_ids,
                    snapshot_dir,
                )
            except Exception as e:
                print(
                    f"[SNAPSHOT DELTA] "
                    f"{output_file.name} failed: {e}"
                )

        if output_file.name == "channel2_room.json":

            if ULGG_RULESET_WATCHER_ENABLED:

                candidate_rooms = build_recent_room_pool(
                    merged,
                    changed_rooms,
                    7200,
                )

                print(
                    f"[MERGE DELTA] "
                    f"changed={len(changed_rooms)} "
                    f"removed={len(removed_room_ids)} "
                    f"total={len(merged)} "
                    f"ulgg_candidates="
                    f"{len(candidate_rooms)}"
                )

                try:
                    await link_ulgg_rooms(
                        candidate_rooms
                    )
                except Exception as e:
                    print(
                        f"[ULGG LINK] "
                        f"本輪自動綁房失敗：{e}"
                    )

                try:
                    await validate_ulgg_decks(
                        candidate_rooms
                    )
                except Exception as e:
                    print(
                        f"[ULGG DECK] "
                        f"本輪牌組驗證失敗：{e}"
                    )

            else:
                print(
                    "[ULGG RULESET] disabled; "
                    "skip room link / deck validation"
                )


        elapsed = (
            time.monotonic()
            - started
        )

        print(
            f"[MERGE DELTA] COMPLETE "
            f"file={output_file.name} "
            f"changed={len(changed_rooms)} "
            f"removed={len(removed_room_ids)} "
            f"total={len(merged)} "
            f"elapsed={elapsed:.3f}s"
        )

        previous_merged = merged

        await asyncio.sleep(interval)



async def main():
    # 每個區域共用一個 AuthSessionProvider：TW 若設定 WATCHER_PLATFORM_ID_TW，
    # 會在每次 watcher process 啟動時向 9998 getid 一次；固定 WATCHER_PLAYER_ID_TW
    # 僅作 fallback。register 若被拒，provider 會刷新一次再讓 watcher 重連。
    auth_provider = {
        "TW": AuthSessionProvider("TW"),
        "JP": AuthSessionProvider("JP"),
        "CR": AuthSessionProvider("CR"),
    }

    # 頻道開在哪個 port 每次連線前問大廳，不再寫死。
    # CR_PAUSE_20260930_V1
    # CR Ch3 / Ch4 暫停現役監控；保留實作供後續 topology probe。
    # JP_FINAL_RETIRE_20260930_V3
    # DMM / JP 現役資料源正式封存；只移除純 JP Ch1/Ch2/Ranking。
    # CR Ch3/Ch4 保留，仍使用 JP lobby endpoint，auth 另案處理。
    watchers = [
        ChannelWatcher(1, LOBBY_ENDPOINT["TW"], "TW", auth_provider=auth_provider["TW"]),
        ChannelWatcher(2, LOBBY_ENDPOINT["TW"], "TW", auth_provider=auth_provider["TW"]),
        # 跨服：以前是 dmm:20005／20004（改版後連不上）。JP 大廳列得出 3／4 才會接上。
        RankingWatcher(LOBBY_ENDPOINT["TW"], "TW", auth_provider=auth_provider["TW"]),
    ]

    merge_tasks = [
        merge_room_data(1, data_dir / "channel1_room.json", 30),

        # ROOM2 + ROOM4 合併成「目前一般對戰候選池」
        # ROOM2_MERGE_INTERVAL_10S_20260930_V1
        # Ruleset 即時綁房已停用，3 秒 refresh 已無必要。
        # 降為 10 秒，減少 30~40MB JSON reload / merge I/O。
        merge_room_sources(
            data_dir / "channel2_room.json",
            [
                # JP_FINAL_RETIRE_20260930_V3
                "channel2_room_TW.json",
                # CR_PAUSE_20260930_V1
                # CR Ch4 paused; historical JSON no longer enters active Room2.
            ],
            10,
            data_dir / "room_snapshots" / "channel2",
        ),

        # CR_PAUSE_20260930_V1
        # merge_room_data(3, ...) paused

        # 可選：保留 ROOM4 獨立總檔，方便 debug
        # CR_PAUSE_20260930_V1
        # merge_room_data(4, ...) paused
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
