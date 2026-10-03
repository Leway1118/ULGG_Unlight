# -*- coding: utf-8 -*-
from __future__ import annotations

import json
import sys
from pathlib import Path

if __package__ in {None, ""}:
    sys.path.insert(0, str(Path(__file__).resolve().parents[5]))

from core.runtime.cdp.ul_sniffer import ULSniffer


CDP_PORT = 59222


LIST_CARDS_JS = r"""
(() => {
  const main = globalThis.game?.scene?.keys?.MainA;

  if (!main) {
    return JSON.stringify({
      error: "MainA missing"
    });
  }

  const typeMap = {
    acswd: "sword",
    acbow: "gun",
    acshi: "shield",
    acmov: "move",
    acspe: "special"
  };

  const cards = [];

  for (let index = 0; index < (main.arr1?.length || 0); index++) {
    const card = main.arr1[index];

    if (!Array.isArray(card) || !card[0]) {
      continue;
    }

    const frontUp =
      Math.abs(Number(card[0].rotation || 0)) < 1;

    const icon = frontUp ? card[1] : card[3];
    const value = frontUp ? card[2] : card[4];

    cards.push({
      index,
      x: Number(card[0].x || 0),
      y: Number(card[0].y || 0),
      selectable: Boolean(card[0].input?.enabled),
      frontUp,
      type: typeMap[icon?.texture?.key] || icon?.texture?.key || null,
      value: Number(value?.text || 0)
    });
  }

  return JSON.stringify({
    phase:
      globalThis.game.scene.keys.AttackPhaseA?.scene?.isActive?.()
        ? "attack"
        : globalThis.game.scene.keys.MovePhaseA?.scene?.isActive?.()
          ? "move"
          : globalThis.game.scene.keys.DefensePhaseA?.scene?.isActive?.()
            ? "defense"
            : "unknown",
    arr1Left: main.arr1_left,
    cards
  }, null, 2);
})()
"""


def build_select_js(index: int) -> str:
    return rf"""
(() => {{
  const main = globalThis.game?.scene?.keys?.MainA;
  const card = main?.arr1?.[{index}]?.[0];

  if (!main) {{
    return JSON.stringify({{
      ok: false,
      error: "MainA missing"
    }});
  }}

  if (!card) {{
    return JSON.stringify({{
      ok: false,
      error: "card missing",
      index: {index}
    }});
  }}

  if (!card.input?.enabled) {{
    return JSON.stringify({{
      ok: false,
      error: "card not selectable",
      index: {index}
    }});
  }}

  card.emit("pointerdown");

  return JSON.stringify({{
    ok: true,
    index: {index}
  }});
}})()
"""


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在可選牌、尚未按 OK 的階段")
    input("[+] 準備好後按 Enter 讀取手牌……")

    value, error = await sniffer.evaluate(
        LIST_CARDS_JS,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 讀取失敗：{error}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    data = json.loads(value)

    print(f"\n階段：{data.get('phase')}")
    print(f"剩餘手牌：{data.get('arr1Left')}")
    print("\n可用卡牌：")

    selectable_indexes: set[int] = set()

    for card in data.get("cards", []):
        marker = "可選" if card["selectable"] else "已鎖定"

        print(
            f"  index={card['index']:>2} "
            f"{card['type']} {card['value']} "
            f"x={card['x']:.0f} y={card['y']:.0f} "
            f"[{marker}]"
        )

        if card["selectable"]:
            selectable_indexes.add(card["index"])

    raw = input(
        "\n輸入要測試點擊的 index，"
        "直接按 Enter 則不操作："
    ).strip()

    if not raw:
        print("[+] 未執行選牌")
    else:
        try:
            index = int(raw)
        except ValueError:
            print("[!] index 必須是整數")
        else:
            if index not in selectable_indexes:
                print("[!] 該 index 不在目前可選清單")
            else:
                result, select_error = await sniffer.evaluate(
                    build_select_js(index),
                    await_promise=False,
                )

                if select_error:
                    print(f"[!] 選牌失敗：{select_error}")
                else:
                    print(f"[+] 選牌結果：{result}")

    input("\n請回遊戲確認效果，再按 Enter 關閉……")
    await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=True,
    )
    sniffer.start(after_connect=after_connect)


if __name__ == "__main__":
    main()
