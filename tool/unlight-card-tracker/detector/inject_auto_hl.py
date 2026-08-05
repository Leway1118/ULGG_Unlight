# -*- coding: utf-8 -*-
"""
Inject a Tampermonkey userscript into the UNLIGHT Steam/Electron game context.

Usage:
    python inject_auto_hl.py
"""

from __future__ import annotations

import asyncio
from pathlib import Path

from ul_sniffer import ULSniffer


SCRIPT_PATH = Path(__file__).with_name("ulr_auto_hl.user.js")


def load_userscript() -> str:
    if not SCRIPT_PATH.exists():
        raise FileNotFoundError(
            f"找不到 {SCRIPT_PATH.name}，請把它放在 inject_auto_hl.py 同一個資料夾"
        )

    source = SCRIPT_PATH.read_text(encoding="utf-8-sig")

    start = source.find("// ==UserScript==")
    end = source.find("// ==/UserScript==")
    if start != -1 and end != -1:
        end += len("// ==/UserScript==")
        source = source[:start] + source[end:]

    return f"""
(() => {{
  if (globalThis.__ULR_AUTO_HL_INJECTED__) {{
    return "already_injected";
  }}

  globalThis.__ULR_AUTO_HL_INJECTED__ = true;

  try {{
    {source}
    return "injected";
  }} catch (error) {{
    globalThis.__ULR_AUTO_HL_INJECTED__ = false;
    throw error;
  }}
}})()
"""


async def inject(sniffer: ULSniffer) -> None:
    try:
        expression = load_userscript()
        value, error = await sniffer.evaluate(
            expression,
            await_promise=False,
        )

        if error:
            print(f"[!] 注入失敗: {error}")
            return

        if value == "already_injected":
            print("[+] ULR auto HL 已存在於目前遊戲 context")
        else:
            print("[+] ULR auto HL 已注入桌面版")
            print("[+] 進入獎勵遊戲並等獎勵列表出現後，按 Space 開始／停止")
            print("[+] 遊戲重新載入後，需要重新執行注入器")

        print("[+] 監看中，按 Ctrl+C 結束")
        await asyncio.Future()
    finally:
        if sniffer.ws is not None:
            await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=59222,
        verbose=True,
    )
    sniffer.start(after_connect=inject)


if __name__ == "__main__":
    main()
