# -*- coding: utf-8 -*-
"""CDP helper for UNLIGHT future-sight tooling.

This version connects to the browser-level CDP endpoint, auto-attaches to
Electron targets, tracks every Runtime execution context (including iframes
and isolated worlds), and evaluates JavaScript in the context that contains
window.game.

Compatible interface:
    ULSniffer(port=..., on_frame=..., verbose=...)
    sniffer.start(after_connect=...)
    await sniffer.evaluate(js, await_promise=True)
    await sniffer.ws.close()
"""

from __future__ import annotations

import asyncio
import json
from typing import Any, Awaitable, Callable, Optional

import requests
import websockets


FrameCallback = Callable[
    [str, dict[str, Any], str, "ULSniffer"],
    Awaitable[None] | None,
]
ConnectCallback = Callable[["ULSniffer"], Awaitable[None] | None]


class ULSniffer:
    def __init__(
        self,
        port: int = 59222,
        on_frame: Optional[FrameCallback] = None,
        verbose: bool = True,
    ) -> None:
        self.port = port
        self.on_frame = on_frame
        self.verbose = verbose

        self.ws: Any = None
        self._next_id = 0
        self._pending: dict[int, asyncio.Future] = {}
        self._reader_task: Optional[asyncio.Task] = None
        self._background_tasks: set[asyncio.Task] = set()

        self._sessions: set[str] = set()
        self._session_info: dict[str, dict[str, Any]] = {}

        # session_id -> context_id -> context description
        self._contexts: dict[str, dict[int, dict[str, Any]]] = {}
        self._game_location: Optional[tuple[str, int]] = None
        self._contexts_changed = asyncio.Event()

    def _get_browser_endpoint(self) -> str:
        response = requests.get(
            f"http://127.0.0.1:{self.port}/json/version",
            timeout=5,
        )
        response.raise_for_status()
        data = response.json()
        endpoint = data.get("webSocketDebuggerUrl")
        if not endpoint:
            raise RuntimeError("CDP /json/version 沒有 webSocketDebuggerUrl")
        return str(endpoint)

    async def _send(
        self,
        method: str,
        params: Optional[dict[str, Any]] = None,
        session_id: Optional[str] = None,
    ) -> dict[str, Any]:
        if self.ws is None:
            raise RuntimeError("CDP 尚未連線")

        self._next_id += 1
        message_id = self._next_id
        future = asyncio.get_running_loop().create_future()
        self._pending[message_id] = future

        message: dict[str, Any] = {
            "id": message_id,
            "method": method,
            "params": params or {},
        }
        if session_id:
            message["sessionId"] = session_id

        await self.ws.send(json.dumps(message))

        try:
            return await future
        finally:
            self._pending.pop(message_id, None)

    async def _evaluate_in_context(
        self,
        session_id: str,
        context_id: int,
        expression: str,
        *,
        await_promise: bool = False,
    ) -> tuple[Any, Optional[str]]:
        try:
            response = await self._send(
                "Runtime.evaluate",
                {
                    "expression": expression,
                    "contextId": context_id,
                    "awaitPromise": bool(await_promise),
                    "returnByValue": True,
                    "userGesture": True,
                },
                session_id=session_id,
            )
        except Exception as exc:
            return None, str(exc)

        if "error" in response:
            return None, str(response["error"])

        result = response.get("result", {})
        exception = result.get("exceptionDetails")
        if exception:
            description = (
                exception.get("exception", {}).get("description")
                or exception.get("text")
                or "Runtime.evaluate 發生例外"
            )
            return None, str(description)

        remote = result.get("result", {})
        if remote.get("subtype") == "error":
            return None, str(remote.get("description") or "JavaScript error")

        return remote.get("value"), None

    async def _find_game_context(
        self,
    ) -> Optional[tuple[str, int]]:
        candidates: list[tuple[str, int]] = []

        if self._game_location is not None:
            session_id, context_id = self._game_location
            if context_id in self._contexts.get(session_id, {}):
                candidates.append(self._game_location)

        for session_id, contexts in self._contexts.items():
            for context_id in contexts:
                item = (session_id, context_id)
                if item not in candidates:
                    candidates.append(item)

        probe = (
            "(() => Boolean("
            "globalThis.game && "
            "globalThis.game.scene && "
            "globalThis.game.scene.keys"
            "))()"
        )

        for session_id, context_id in candidates:
            value, error = await self._evaluate_in_context(
                session_id,
                context_id,
                probe,
            )
            if error is None and value is True:
                self._game_location = (session_id, context_id)
                context = self._contexts.get(session_id, {}).get(
                    context_id, {}
                )
                aux = context.get("auxData") or {}
                if self.verbose:
                    print(
                        "[+] 找到 UNLIGHT game context: "
                        f"context={context_id} "
                        f"name={context.get('name', '')!r} "
                        f"frame={aux.get('frameId', '?')} "
                        f"default={aux.get('isDefault', '?')}"
                    )
                return self._game_location

        return None

    async def _wait_for_game_context(
        self,
        timeout: float = 12.0,
    ) -> tuple[str, int]:
        loop = asyncio.get_running_loop()
        deadline = loop.time() + timeout

        while True:
            location = await self._find_game_context()
            if location is not None:
                return location

            remaining = deadline - loop.time()
            if remaining <= 0:
                summary = []
                for session_id, contexts in self._contexts.items():
                    for context_id, context in contexts.items():
                        aux = context.get("auxData") or {}
                        summary.append(
                            {
                                "context": context_id,
                                "name": context.get("name"),
                                "origin": context.get("origin"),
                                "frame": aux.get("frameId"),
                                "default": aux.get("isDefault"),
                                "type": aux.get("type"),
                            }
                        )
                raise RuntimeError(
                    "找不到包含 window.game 的 execution context。"
                    f" 已發現 contexts={summary}"
                )

            self._contexts_changed.clear()
            try:
                await asyncio.wait_for(
                    self._contexts_changed.wait(),
                    timeout=min(0.5, remaining),
                )
            except asyncio.TimeoutError:
                pass

    async def evaluate(
        self,
        expression: str,
        await_promise: bool = False,
    ) -> tuple[Any, Optional[str]]:
        try:
            session_id, context_id = await self._wait_for_game_context()
        except Exception as exc:
            return None, str(exc)

        value, error = await self._evaluate_in_context(
            session_id,
            context_id,
            expression,
            await_promise=await_promise,
        )

        if error:
            self._game_location = None
            try:
                session_id, context_id = await self._wait_for_game_context(
                    timeout=3.0
                )
            except Exception:
                return value, error
            return await self._evaluate_in_context(
                session_id,
                context_id,
                expression,
                await_promise=await_promise,
            )

        return value, None

    async def _dispatch_frame(
        self,
        method: str,
        params: dict[str, Any],
    ) -> None:
        if self.on_frame is None:
            return

        response = params.get("response", {})
        if response.get("opcode", 1) != 1:
            return

        raw = response.get("payloadData", "")
        try:
            data = json.loads(raw)
        except (TypeError, ValueError, json.JSONDecodeError):
            return

        if not isinstance(data, dict):
            return

        direction = (
            "→送出"
            if method == "Network.webSocketFrameSent"
            else "←收到"
        )

        try:
            result = self.on_frame(direction, data, raw, self)
            if asyncio.iscoroutine(result):
                await result
        except Exception as exc:
            print(f"[!] on_frame 處理失敗: {exc}")

    async def _handle_attached_target(
        self,
        params: dict[str, Any],
    ) -> None:
        try:
            session_id = params.get("sessionId")
            target_info = params.get("targetInfo", {})

            if not session_id:
                return

            self._sessions.add(session_id)
            self._session_info[session_id] = target_info
            self._contexts.setdefault(session_id, {})

            if self.verbose:
                print(
                    "[+] attach: "
                    f"{target_info.get('type', '?')} "
                    f"{str(target_info.get('url', ''))[:160]}"
                )

            await self._send(
                "Runtime.enable",
                {},
                session_id=session_id,
            )

            await self._send(
                "Network.enable",
                {},
                session_id=session_id,
            )

            await self._send(
                "Page.enable",
                {},
                session_id=session_id,
            )

            await self._send(
                "Target.setAutoAttach",
                {
                    "autoAttach": True,
                    "waitForDebuggerOnStart": True,
                    "flatten": True,
                },
                session_id=session_id,
            )

            await self._send(
                "Runtime.runIfWaitingForDebugger",
                {},
                session_id=session_id,
            )

        except websockets.ConnectionClosed:
            # 主程式正常關閉時，背景 attach task 可能仍在送指令。
            return

        except RuntimeError as exc:
            if "CDP WebSocket 已關閉" in str(exc):
                return
            raise

    async def _reader(self) -> None:
        try:
            async for raw_message in self.ws:
                message = json.loads(raw_message)

                message_id = message.get("id")
                if message_id is not None:
                    future = self._pending.get(message_id)
                    if future is not None and not future.done():
                        future.set_result(message)
                    continue

                method = message.get("method")
                params = message.get("params", {})
                session_id = message.get("sessionId")

                if method == "Target.attachedToTarget":
                    task = asyncio.create_task(
                        self._handle_attached_target(params)
                    )

                    self._background_tasks.add(task)

                    task.add_done_callback(
                        self._background_tasks.discard
                    )

                    continue

                if method == "Target.detachedFromTarget":
                    detached = params.get("sessionId")
                    if detached:
                        self._sessions.discard(detached)
                        self._session_info.pop(detached, None)
                        self._contexts.pop(detached, None)
                        if (
                            self._game_location is not None
                            and self._game_location[0] == detached
                        ):
                            self._game_location = None
                    continue

                if method == "Runtime.executionContextCreated" and session_id:
                    context = params.get("context", {})
                    context_id = context.get("id")
                    if isinstance(context_id, int):
                        self._contexts.setdefault(session_id, {})[
                            context_id
                        ] = context
                        self._contexts_changed.set()
                    continue

                if method == "Runtime.executionContextDestroyed" and session_id:
                    context_id = params.get("executionContextId")
                    self._contexts.get(session_id, {}).pop(
                        context_id, None
                    )
                    if self._game_location == (session_id, context_id):
                        self._game_location = None
                    continue

                if method == "Runtime.executionContextsCleared" and session_id:
                    self._contexts[session_id] = {}
                    if (
                        self._game_location is not None
                        and self._game_location[0] == session_id
                    ):
                        self._game_location = None
                    continue

                if method in {
                    "Network.webSocketFrameSent",
                    "Network.webSocketFrameReceived",
                }:
                    await self._dispatch_frame(method, params)
        except websockets.ConnectionClosed:
            pass
        finally:
            for task in list(self._background_tasks):
                if not task.done():
                    task.cancel()

            if self._background_tasks:
                await asyncio.gather(
                    *self._background_tasks,
                    return_exceptions=True,
                )

            self._background_tasks.clear()

            for future in list(self._pending.values()):
                if not future.done():
                    future.cancel()

    async def _run(
        self,
        after_connect: Optional[ConnectCallback],
    ) -> None:
        endpoint = self._get_browser_endpoint()

        if self.verbose:
            print(f"[+] 連上 UNLIGHT browser CDP: {endpoint}")

        async with websockets.connect(
            endpoint,
            max_size=None,
            ping_interval=20,
            ping_timeout=20,
        ) as websocket:
            self.ws = websocket
            self._reader_task = asyncio.create_task(self._reader())

            await self._send(
                "Target.setDiscoverTargets",
                {"discover": True},
            )
            await self._send(
                "Target.setAutoAttach",
                {
                    "autoAttach": True,
                    "waitForDebuggerOnStart": True,
                    "flatten": True,
                },
            )

            if after_connect is not None:
                result = after_connect(self)
                if asyncio.iscoroutine(result):
                    await result

            await self._reader_task

    def start(
        self,
        after_connect: Optional[ConnectCallback] = None,
    ) -> None:
        try:
            asyncio.run(self._run(after_connect))
        except KeyboardInterrupt:
            print("\n[+] 結束")
        except requests.RequestException as exc:
            print(f"[!] 連不上 127.0.0.1:{self.port}: {exc}")
            print(
                f"    請確認遊戲使用 "
                f"--remote-debugging-port={self.port} 啟動"
            )
        except Exception as exc:
            print(f"[!] Sniffer 啟動失敗: {exc}")
