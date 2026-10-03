from __future__ import annotations

import json
from typing import Any, Awaitable, Callable

from ul_sniffer import ULSniffer


class CDPClient:
    """
    CDP 包裝層

    封裝現有 ULSniffer：
    - 連線 Chrome DevTools
    - 執行 JavaScript
    - 管理連線狀態
    """


    def __init__(
        self,
        port: int = 59222,
    ) -> None:

        self.port = port

        self.sniffer = ULSniffer(
            port=port,
            verbose=False,
        )

        self.connected = False


    def start(
        self,
        on_connected: Callable[
            ["CDPClient"],
            Awaitable[None],
        ],
    ) -> None:
        """
        啟動 CDP 連線

        ULSniffer 使用 callback 模式
        """

        async def after_connect(
            sniffer: ULSniffer,
        ) -> None:

            self.connected = True

            await on_connected(
                self
            )


        self.sniffer.start(
            after_connect=after_connect
        )


    async def evaluate(
        self,
        script: str,
        *,
        await_promise: bool = False,
    ) -> Any:
        """
        執行瀏覽器 Javascript
        """

        if not self.connected:

            raise RuntimeError(
                "CDP 尚未連線"
            )


        value, error = await (
            self.sniffer.evaluate(
                script,
                await_promise=await_promise,
            )
        )


        if error:

            raise RuntimeError(
                error
            )


        if value is None:

            return None


        if isinstance(
            value,
            str,
        ):

            try:

                return json.loads(
                    value
                )

            except json.JSONDecodeError:

                return value


        return value


    async def close(
        self,
    ) -> None:

        if self.sniffer.ws:

            await self.sniffer.ws.close()


        self.connected = False