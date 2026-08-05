# -*- coding: utf-8 -*-
from __future__ import annotations

import json

from ul_sniffer import ULSniffer


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

  function sideData(card, iconIndex, valueIndex) {
    const icon = card?.[iconIndex];
    const value = card?.[valueIndex];

    return {
      type:
        typeMap[icon?.texture?.key] ||
        icon?.texture?.key ||
        null,
      value: Number(value?.text || 0),
      rotation: Number(icon?.rotation || 0)
    };
  }

  const cards = [];

  for (
    let index = 0;
    index < (main.arr1?.length || 0);
    index++
  ) {
    const card = main.arr1[index];

    if (!Array.isArray(card) || !card[0]) {
      continue;
    }

    const sideA = sideData(card, 1, 2);
    const sideB = sideData(card, 3, 4);

    const frontUp =
      Math.abs(Number(card[0].rotation || 0)) < 1;

    cards.push({
      index,
      x: Number(card[0].x || 0),
      y: Number(card[0].y || 0),
      selectable: Boolean(card[0].input?.enabled),
      rotatable: Boolean(main.button?.[index]?.input?.enabled),

      currentSide: frontUp ? "A" : "B",
      current: frontUp ? sideA : sideB,
      opposite: frontUp ? sideB : sideA,

      sideA,
      sideB
    });
  }

  return JSON.stringify({
    cards
  }, null, 2);
})()
"""


def build_rotate_js(index: int) -> str:
    return rf"""
(() => {{
  const main = globalThis.game?.scene?.keys?.MainA;
  const rotateButton = main?.button?.[{index}];

  if (!main) {{
    return JSON.stringify({{
      ok: false,
      error: "MainA missing"
    }});
  }}

  if (!rotateButton) {{
    return JSON.stringify({{
      ok: false,
      error: "rotate button missing",
      index: {index}
    }});
  }}

  if (!rotateButton.input?.enabled) {{
    return JSON.stringify({{
      ok: false,
      error: "card not rotatable",
      index: {index}
    }});
  }}

  rotateButton.emit("pointerup");

  return JSON.stringify({{
    ok: true,
    index: {index}
  }});
}})()
"""


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在可選牌、尚未按 OK 的階段")
    input("[+] 準備好後按 Enter 讀取雙面卡牌……")

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

    rotatable_indexes: set[int] = set()

    print("\n卡牌雙面：")

    for card in data.get("cards", []):
        current = card["current"]
        opposite = card["opposite"]

        status = (
            "可旋轉"
            if card["rotatable"]
            else "不可旋轉"
        )

        print(
            f"  index={card['index']:>2} "
            f"目前={current['type']} {current['value']} "
            f"另一面={opposite['type']} {opposite['value']} "
            f"x={card['x']:.0f} y={card['y']:.0f} "
            f"[{status}]"
        )

        if card["rotatable"]:
            rotatable_indexes.add(card["index"])

    raw = input(
        "\n輸入要旋轉的 index，"
        "直接按 Enter 則不操作："
    ).strip()

    if not raw:
        print("[+] 未執行旋轉")
    else:
        try:
            index = int(raw)
        except ValueError:
            print("[!] index 必須是整數")
        else:
            if index not in rotatable_indexes:
                print("[!] 該 index 目前不可旋轉")
            else:
                result, rotate_error = await sniffer.evaluate(
                    build_rotate_js(index),
                    await_promise=False,
                )

                if rotate_error:
                    print(f"[!] 旋轉失敗：{rotate_error}")
                else:
                    print(f"[+] 旋轉結果：{result}")

    input("\n請回遊戲確認翻面，再按 Enter 關閉……")
    await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=True,
    )
    sniffer.start(after_connect=after_connect)


if __name__ == "__main__":
    main()