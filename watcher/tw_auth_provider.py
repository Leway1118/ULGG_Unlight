#!/usr/bin/python3.11
# tw_auth_provider.py
#
# V1_3_TW_AUTH_PROVIDER_20261017_V1
#
# 獨立持有 TW auth/session WebSocket (:9998) 並保持存活，讓 protocol-1.4
# register UUID 的壽命遠超過舊 getid 後立即關閉 WS 的 ~15 分鐘。
#
# Ownership（單一 owner）：
#   provider : tw_auth_session（publish/unlink）、tw_access_token.json
#              （claim/consume）、tw_auth_provider.json（state/heartbeat）
#   consumer : tw_watcher_health.json、tw_auth_refresh_request.<i>.<g>.json
#
# provider 不寫 tw_watcher_health.json；consumer 不刪 tw_auth_session。
# 任何情況都不輸出 SteamID / access token / ticket / register UUID。

from __future__ import annotations

import asyncio
import os
import signal
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import channel_watcher_unlight as cw

try:  # POSIX（正式環境）
    import fcntl
except ImportError:  # pragma: no cover - Windows 測試環境
    fcntl = None

HEARTBEAT_INTERVAL = float(
    os.getenv("WATCHER_TW_AUTH_PROVIDER_HEARTBEAT_INTERVAL", "10")
)
REFRESH_POLL_SECONDS = float(
    os.getenv("WATCHER_TW_AUTH_PROVIDER_REFRESH_POLL", "2")
)
IDLE_POLL_SECONDS = float(
    os.getenv("WATCHER_TW_AUTH_PROVIDER_IDLE_POLL", "2")
)
INFLIGHT_PREFIX = "tw_access_token.inflight."


def open_provider_lock(path: Path):
    """取得 provider single-instance lock；成功回傳 fd，失敗回傳 None。

    POSIX 用 ``fcntl.flock(LOCK_EX | LOCK_NB)``（正式環境）；Windows 測試
    環境改用 ``msvcrt.locking`` 提供等價的跨 process 互斥語意。

    取不到 lock 時不刪除、也不建立任何其他 runtime 檔案。lock file 本身
    永不 unlink，由 kernel 在 process 結束時自動釋放 flock。
    """
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd = os.open(path, os.O_CREAT | os.O_RDWR, 0o600)
    try:
        if fcntl is not None:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        else:
            import msvcrt

            if os.fstat(fd).st_size == 0:
                os.write(fd, b"\0")
            os.lseek(fd, 0, os.SEEK_SET)
            msvcrt.locking(fd, msvcrt.LK_NBLCK, 1)
    except OSError:
        try:
            os.close(fd)
        except OSError:
            pass
        return None
    return fd


class ProviderAuthClient(cw.AuthClient):
    """AuthClient + auth socket lifecycle 事件捕捉。

    只覆寫 handle_event（既有 AuthClient.handle_event 是 no-op），
    不新增第二個 websocket reader。
    """

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.stop_event = asyncio.Event()
        self.stop_reason = ""

    def handle_event(self, event, args):
        # 只記錄事件名稱，不記錄 payload。
        if event in ("session_invalidate", "server_disconnect"):
            self.stop_reason = event
            self.stop_event.set()


class TwAuthProvider:
    def __init__(self) -> None:
        self.instance_id = cw.secrets.token_hex(16)
        self.generation = 0
        self.handled_refresh_generation: int | None = None
        self.state = "starting"
        self.session_sha: str | None = None
        self._stop = asyncio.Event()
        # single-instance lock fd；整個 process lifetime 保持開啟（不 unlink）。
        self.lock_fd = None
        # V1_3H：已 claim 的 credential 檔路徑；getid attempt 結束即設回
        # None（不得殘留到 persistent WS session 結束）。
        self.inflight_path: Path | None = None

    # ---------------------------------------------------------------- state
    def _write_state(self, state: str, session_sha: str | None) -> None:
        """atomic 寫 tw_auth_provider.json。

        呼叫端負責只在 socket/listen/ping 健康時傳入 state="active"。
        """
        self.state = state
        cw._write_provider_state(
            {
                "state": state,
                "pid": os.getpid(),
                "instance_id": self.instance_id,
                "generation": self.generation,
                "handled_refresh_generation": self.handled_refresh_generation,
                "session_sha256": session_sha,
                "heartbeat_at": cw.datetime.datetime.now(
                    cw.datetime.timezone.utc
                ).astimezone().isoformat(timespec="seconds"),
            }
        )

    # -------------------------------------------------------------- cleanup
    @staticmethod
    def _unlink(path: Path) -> None:
        try:
            path.unlink()
        except (FileNotFoundError, OSError):
            pass

    async def _startup_cleanup(self) -> None:
        # provider restart ⇒ 舊 9998 socket 已不存在 ⇒ 不信任既有 shared。
        cw._discard_shared_auth_session("TW")
        cw._discard_refresh_requests()
        base = cw.TW_ACCESS_TOKEN_FILE.parent
        if base.is_dir():
            for stale in base.glob(f"{INFLIGHT_PREFIX}*"):
                self._unlink(stale)
        print("[AuthProvider] startup cleanup done (stale shared/request/inflight removed)")

    async def _final_cleanup(self) -> None:
        self.session_sha = None
        self._write_state("stopping", None)
        cw._discard_shared_auth_session("TW")
        cw._discard_refresh_requests()
        print("[AuthProvider] stopped; shared session removed")

    # ------------------------------------------------------------- lifecycle
    def request_stop(self) -> None:
        self._stop.set()

    async def _pause(self, seconds: float) -> None:
        try:
            await asyncio.wait_for(self._stop.wait(), timeout=seconds)
        except asyncio.TimeoutError:
            pass

    # ------------------------------------------------------------ credential
    def _claim_token(self) -> Path | None:
        """atomic rename claim：成功代表本 provider 已取得該一次性 token。"""
        src = cw.TW_ACCESS_TOKEN_FILE
        if not src.is_file():
            return None
        dst = src.with_name(f"{INFLIGHT_PREFIX}{self.instance_id}.json")
        try:
            os.rename(src, dst)
        except OSError:
            return None
        return dst

    def _release_inflight(self, path: Path) -> None:
        """V1_3H：立即釋放 inflight credential 檔（idempotent）。

        TOKEN_CLAIM_PROTOCOL：一次 getid attempt 結束（成功或失敗）就必須
        unlink inflight；不得等到 persistent WS session 結束才第一次釋放。
        """
        self._unlink(path)
        if self.inflight_path == path:
            self.inflight_path = None

    # ------------------------------------------------------------------- run
    async def run(self) -> int:
        # Step 0：在做任何 startup cleanup 之前先取 single-instance lock。
        # 取不到就完全不做 cleanup、不碰 shared/session/state/token。
        self.lock_fd = open_provider_lock(cw.TW_AUTH_PROVIDER_LOCK_FILE)
        if self.lock_fd is None:
            print(
                "[AuthProvider] PROVIDER_ALREADY_RUNNING: lock 已被其他 "
                "process 持有；拒絕啟動（未執行任何 cleanup，未觸碰 "
                "shared/session/state/token）"
            )
            return 3

        await self._startup_cleanup()
        self._write_state("needs_token", None)
        print("[AuthProvider] waiting for a fresh Steam access token")
        try:
            while not self._stop.is_set():
                inflight = self._claim_token()
                if inflight is None:
                    if self.state != "needs_token":
                        self._write_state("needs_token", None)
                    await self._pause(IDLE_POLL_SECONDS)
                    continue

                self.inflight_path = inflight
                try:
                    await self._run_session(inflight)
                except Exception as e:  # noqa: BLE001 - keep the daemon alive
                    print(f"[AuthProvider] session error: {type(e).__name__}: {e}")
                    self._write_state("needs_token", None)
                finally:
                    # idempotent 安全網；正常路徑在 getid attempt 結束時
                    # （_run_session 內）就已釋放。
                    self._release_inflight(inflight)
        finally:
            await self._final_cleanup()
        return 0

    async def _run_session(self, inflight: Path) -> None:
        platform_id, access_token = cw._read_tw_access_token(inflight)

        client = ProviderAuthClient(
            cw.AUTH_ENDPOINT["TW"],
            "TW",
            platform_id,
            cw.WATCHER_PLATFORM_KEY["TW"],
            cw.WATCHER_PLATFORM_TYPE["TW"],
        )
        platform_id = ""

        try:
            auth_id = await client.get_id(access_token, keep_open=True)
        except Exception as e:  # noqa: BLE001
            print(f"[AuthProvider] getid failed: {type(e).__name__}: {e}")
            await client._close_ws()
            self._write_state("needs_token", None)
            return
        finally:
            # V1_3H：credential 檔壽命與 persistent WS session 完全解耦。
            # 一次 getid attempt 結束（成功或失敗）就 unlink inflight 並清空
            # 本地參考，之後才 publish shared / state active / supervise。
            access_token = ""
            self._release_inflight(inflight)
            inflight = None

        # publish ordering：shared UUID first → generation → state active last
        cw._publish_shared_auth_session("TW", auth_id)
        self.generation += 1
        self.session_sha = cw._sha256_text(auth_id)
        auth_id = ""
        self._write_state("active", self.session_sha)
        print(f"[AuthProvider] session published (generation={self.generation})")

        reason = await self._supervise(client)
        print(f"[AuthProvider] leaving active: {reason}")
        await self._teardown(client, reason)

    # -------------------------------------------------------------- supervise
    async def _supervise(self, client: ProviderAuthClient) -> str:
        listen_task = asyncio.create_task(client.listen())
        ping_task = asyncio.create_task(client.ping())
        heartbeat_task = asyncio.create_task(
            self._heartbeat_loop(listen_task, ping_task)
        )
        refresh_task = asyncio.create_task(self._watch_refresh_requests())
        invalidate_task = asyncio.create_task(client.stop_event.wait())
        stop_task = asyncio.create_task(self._stop.wait())

        tasks = [
            ("listen", listen_task),
            ("ping", ping_task),
            ("heartbeat", heartbeat_task),
            ("refresh_request", refresh_task),
            ("invalidate", invalidate_task),
            ("stop", stop_task),
        ]
        try:
            done, _ = await asyncio.wait(
                [task for _, task in tasks],
                return_when=asyncio.FIRST_COMPLETED,
            )
        finally:
            for _, task in tasks:
                if not task.done():
                    task.cancel()
            await asyncio.gather(
                *(task for _, task in tasks), return_exceptions=True
            )

        for name, task in tasks:
            if task in done:
                if name == "invalidate" and client.stop_reason:
                    return client.stop_reason
                return name
        return "unknown"

    async def _heartbeat_loop(self, listen_task, ping_task) -> str:
        while True:
            await asyncio.sleep(HEARTBEAT_INTERVAL)
            if listen_task.done() or ping_task.done():
                return "auth_task_dead"
            # 只有 listen + ping 都健康時才更新 active heartbeat。
            self._write_state("active", self.session_sha)

    async def _watch_refresh_requests(self) -> str:
        while True:
            await asyncio.sleep(REFRESH_POLL_SECONDS)
            for path in cw._iter_refresh_requests():
                info = cw._parse_refresh_request_name(path.name)
                if info is None:
                    self._unlink(path)
                    continue
                instance_id, generation = info
                stale = (
                    instance_id != self.instance_id
                    or generation != self.generation
                    or self.handled_refresh_generation == self.generation
                    or self.state != "active"
                )
                if stale:
                    self._unlink(path)
                    continue
                return "refresh_request"

    async def _teardown(self, client: ProviderAuthClient, reason: str) -> None:
        # 1. state first（trust gate），sha = null
        if reason == "refresh_request":
            self.handled_refresh_generation = self.generation
            new_state = "recovering"
        else:
            new_state = "invalidated"
        self.session_sha = None
        self._write_state(new_state, None)

        # 2. close 9998 socket
        await client._close_ws()

        # 3. unlink shared session
        cw._discard_shared_auth_session("TW")

        # 4. unlink refresh requests（含已受理者）
        cw._discard_refresh_requests()


def main() -> int:
    if cw.WATCHER_TW_AUTH_MODE != "provider":
        print(
            "[AuthProvider] refused: WATCHER_TW_AUTH_MODE="
            f"{cw.WATCHER_TW_AUTH_MODE!r} (expected 'provider'). "
            "不啟動，避免與 inline watcher 同時 getid。"
        )
        return 2

    provider = TwAuthProvider()
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)

    def _handle(signum, _frame):
        print(f"[AuthProvider] signal {signum}; shutting down")
        provider.request_stop()

    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            loop.add_signal_handler(sig, _handle, sig, None)
        except (NotImplementedError, ValueError):
            signal.signal(sig, lambda s, f: provider.request_stop())

    try:
        rc = loop.run_until_complete(provider.run())
    finally:
        loop.close()
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
