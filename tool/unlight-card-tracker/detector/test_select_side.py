# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json

from ul_sniffer import ULSniffer


CDP_PORT = 59222


READ_CARDS_JS = r"""
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

  function readSide(card, iconIndex, valueIndex) {
    const icon = card?.[iconIndex];
    const value = card?.[valueIndex];

    return {
      type:
        typeMap[icon?.texture?.key] ||
        icon?.texture?.key ||
        null,
      value: Number(value?.text || 0)
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

    const sideA = readSide(card, 1, 2);
    const sideB = readSide(card, 3, 4);

    const frontUp =
      Math.abs(Number(card[0].rotation || 0)) < 1;

    cards.push({
      index,
      x: Number(card[0].x || 0),
      y: Number(card[0].y || 0),

      selectable: Boolean(card[0].input?.enabled),
      rotatable: Boolean(
        main.button?.[index]?.input?.enabled
      ),

      currentSide: frontUp ? "A" : "B",
      current: frontUp ? sideA : sideB,
      opposite: frontUp ? sideB : sideA,

      sideA,
      sideB
    });
  }

  return JSON.stringify({
    phase:
      globalThis.game.scene.keys.AttackPhaseA
        ?.scene?.isActive?.()
        ? "attack"
        : globalThis.game.scene.keys.MovePhaseA
          ?.scene?.isActive?.()
          ? "move"
          : globalThis.game.scene.keys.DefensePhaseA
            ?.scene?.isActive?.()
            ? "defense"
            : "unknown",

    cards
  });
})()
"""


def build_rotate_js(index: int) -> str:
    return rf"""
(() => {{
  const main = globalThis.game?.scene?.keys?.MainA;
  const button = main?.button?.[{index}];

  if (!button) {{
    return JSON.stringify({{
      ok: false,
      error: "rotate button missing"
    }});
  }}

  if (!button.input?.enabled) {{
    return JSON.stringify({{
      ok: false,
      error: "card not rotatable"
    }});
  }}

  button.emit("pointerup");

  return JSON.stringify({{
    ok: true,
    action: "rotate",
    index: {index}
  }});
}})()
"""


def build_select_js(index: int) -> str:
    return rf"""
(() => {{
  const main = globalThis.game?.scene?.keys?.MainA;
  const card = main?.arr1?.[{index}]?.[0];

  if (!card) {{
    return JSON.stringify({{
      ok: false,
      error: "card missing"
    }});
  }}

  if (!card.input?.enabled) {{
    return JSON.stringify({{
      ok: false,
      error: "card not selectable"
    }});
  }}

  card.emit("pointerdown");

  return JSON.stringify({{
    ok: true,
    action: "select",
    index: {index}
  }});
}})()
"""


async def read_cards(
    sniffer: ULSniffer,
) -> dict:
    value, error = await sniffer.evaluate(
        READ_CARDS_JS,
        await_promise=False,
    )

    if error:
        raise RuntimeError(error)

    return json.loads(value)


def find_card(
    data: dict,
    index: int,
) -> dict | None:
    for card in data.get("cards", []):
        if card.get("index") == index:
            return card

    return None


async def wait_for_target_side(
    sniffer: ULSniffer,
    index: int,
    target_type: str,
    target_value: int,
    timeout: float = 3.0,
) -> bool:
    loop = asyncio.get_running_loop()
    deadline = loop.time() + timeout

    while loop.time() < deadline:
        await asyncio.sleep(0.1)

        data = await read_cards(sniffer)
        card = find_card(data, index)

        if not card:
            continue

        current = card.get("current", {})

        if (
            current.get("type") == target_type
            and current.get("value") == target_value
        ):
            return True

    return False


async def after_connect(
    sniffer: ULSniffer,
) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在可選牌、尚未按 OK 的階段")

    input(
        "[+] 準備好後按 Enter 讀取雙面卡牌……"
    )

    try:
        data = await read_cards(sniffer)
    except RuntimeError as exc:
        print(f"\n[!] 讀取失敗：{exc}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    print(f"\n階段：{data.get('phase')}")
    print("\n目前可操作卡牌：")

    usable_indexes: set[int] = set()

    for card in data.get("cards", []):
        side_a = card["sideA"]
        side_b = card["sideB"]

        if (
            side_a["type"] is None
            or side_b["type"] is None
        ):
            status = "未知卡，不測試"
        elif not card["selectable"]:
            status = "不可選"
        else:
            status = "可操作"
            usable_indexes.add(card["index"])

        print(
            f"  index={card['index']:>2} "
            f"A={side_a['type']} {side_a['value']} / "
            f"B={side_b['type']} {side_b['value']} "
            f"目前={card['current']['type']} "
            f"{card['current']['value']} "
            f"[{status}]"
        )

    raw_index = input(
        "\n輸入卡牌 index："
    ).strip()

    try:
        index = int(raw_index)
    except ValueError:
        print("[!] index 必須是整數")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    if index not in usable_indexes:
        print("[!] 該卡牌目前不可操作")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    card = find_card(data, index)

    if not card:
        print("[!] 找不到卡牌")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    print("\n請選擇要使用的面：")
    print(
        f"  A = {card['sideA']['type']} "
        f"{card['sideA']['value']}"
    )
    print(
        f"  B = {card['sideB']['type']} "
        f"{card['sideB']['value']}"
    )

    target_side = input(
        "\n輸入 A 或 B："
    ).strip().upper()

    if target_side not in {"A", "B"}:
        print("[!] 必須輸入 A 或 B")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    target = (
        card["sideA"]
        if target_side == "A"
        else card["sideB"]
    )

    current = card["current"]

    print(
        f"\n[+] 目標面："
        f"{target['type']} {target['value']}"
    )

    if (
        current["type"] != target["type"]
        or current["value"] != target["value"]
    ):
        print("[+] 目前不是目標面，先旋轉")

        result, error = await sniffer.evaluate(
            build_rotate_js(index),
            await_promise=False,
        )

        if error:
            print(f"[!] 旋轉失敗：{error}")
            input("\n按 Enter 關閉……")
            await sniffer.ws.close()
            return

        print(f"[+] 旋轉結果：{result}")
        print("[+] 等待遊戲完成翻面……")

        confirmed = await wait_for_target_side(
            sniffer,
            index=index,
            target_type=target["type"],
            target_value=target["value"],
        )

        if not confirmed:
            print("[!] 翻面後未確認到目標面")
            print("[!] 本次不執行選牌")
            input("\n按 Enter 關閉……")
            await sniffer.ws.close()
            return

        print("[+] 已確認翻到目標面")
    else:
        print("[+] 目前已經是目標面，不需旋轉")

    print("[+] 準備選擇這張牌")

    result, error = await sniffer.evaluate(
        build_select_js(index),
        await_promise=False,
    )

    if error:
        print(f"[!] 選牌失敗：{error}")
    else:
        print(f"[+] 選牌結果：{result}")
        print("[+] 請回遊戲確認牌已移到出牌區")
        print("[+] 本程式不會自動按 OK")

    input("\n確認完畢後按 Enter 關閉……")
    await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=True,
    )

    sniffer.start(
        after_connect=after_connect
    )


if __name__ == "__main__":
    main()