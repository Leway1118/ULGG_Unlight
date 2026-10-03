# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json
import sys
from pathlib import Path

if __package__ in {None, ""}:
    sys.path.insert(0, str(Path(__file__).resolve().parents[4]))

from core.runtime.cdp.ul_sniffer import ULSniffer


CDP_PORT = 59222


SCENE_LIST_JS = r"""
(() => {
  const scenes = globalThis.game?.scene?.keys || {};

  return JSON.stringify(
    Object.entries(scenes).map(([key, scene]) => ({
      key,
      active: Boolean(scene?.scene?.isActive?.()),
      visible: Boolean(scene?.scene?.isVisible?.()),
      constructor: scene?.constructor?.name || null
    })),
    null,
    2
  );
})()
"""


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請先進入渦戰鬥的出牌畫面")
    input("\n進入出牌畫面後，按 Enter 開始掃描場景……")

    value, error = await sniffer.evaluate(
        SCENE_LIST_JS,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 掃描失敗：{error}")
    else:
        print("\n===== Scene List =====")
        print(value)

        try:
            with open(
                "battle_scene_list.json",
                "w",
                encoding="utf-8",
            ) as file:
                parsed = json.loads(value)
                json.dump(
                    parsed,
                    file,
                    ensure_ascii=False,
                    indent=2,
                )

            print(
                "\n[+] 已儲存：battle_scene_list.json"
            )
        except Exception as exc:
            print(f"\n[!] 儲存失敗：{exc}")

    input("\n按 Enter 關閉……")
    await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=True,
    )
    sniffer.start(after_connect=after_connect)


if __name__ == "__main__":
    main()
