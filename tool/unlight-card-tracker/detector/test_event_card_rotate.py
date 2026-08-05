# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json

from ul_sniffer import ULSniffer


CDP_PORT = 59222


READ_EVENT_CARDS_JS = r"""
(() => {
  const main = globalThis.game?.scene?.keys?.MainA;

  if (!main) {
    return JSON.stringify({
      ok: false,
      error: "MainA missing"
    });
  }

  const cards = [];

  for (
    let index = 0;
    index < (main.arr1?.length || 0);
    index++
  ) {
    const card = main.arr1[index];
    const sprite = card?.[0];

    if (
      !Array.isArray(card) ||
      card.length !== 1 ||
      sprite?.texture?.key !== "event_asset"
    ) {
      continue;
    }

    const eventId = Number(
      sprite?.frame?.name
    );

    const info =
      main.eventInfo?.frames?.[eventId] ||
      null;

    cards.push({
        index,
        eventId,
        textureKey:
            sprite?.texture?.key || null,
        frameName:
            sprite?.frame?.name ?? null,
        rotation:
            Number(sprite?.rotation || 0),
        angle:
            Number(sprite?.angle || 0),

        currentSide:
            Math.abs(Number(sprite?.rotation || 0)) < 1
            ? "A"
            : "B",

        selectable:
            Boolean(sprite?.input?.enabled),
        rotatable:
            Boolean(
            main.button?.[index]?.input?.enabled
            ),
        x: Number(sprite?.x || 0),
        y: Number(sprite?.y || 0),
        info
    });
  }

  return JSON.stringify({
    ok: true,
    cards
  }, null, 2);
})()
"""


def build_rotate_js(index: int) -> str:
    return rf"""
(() => {{
  const main = globalThis.game?.scene?.keys?.MainA;
  const button = main?.button?.[{index}];

  if (!main) {{
    return JSON.stringify({{
      ok: false,
      error: "MainA missing"
    }});
  }}

  if (!button) {{
    return JSON.stringify({{
      ok: false,
      error: "rotate button missing",
      index: {index}
    }});
  }}

  if (!button.input?.enabled) {{
    return JSON.stringify({{
      ok: false,
      error: "event card not rotatable",
      index: {index}
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


async def read_event_cards(
    sniffer: ULSniffer,
) -> dict:
    value, error = await sniffer.evaluate(
        READ_EVENT_CARDS_JS,
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


async def wait_for_side_change(
    sniffer: ULSniffer,
    index: int,
    before_side: str,
    timeout: float = 3.0,
) -> dict | None:
    loop = asyncio.get_running_loop()
    deadline = loop.time() + timeout

    while loop.time() < deadline:
        await asyncio.sleep(0.1)

        data = await read_event_cards(sniffer)
        card = find_card(data, index)

        if not card:
            continue

        if card.get("currentSide") != before_side:
            return card

    return None

async def after_connect(
    sniffer: ULSniffer,
) -> None:
    print("\n[+] 已連接遊戲")
    print(
        "[+] 請停在手牌中有玩家事件卡、"
        "尚未按 OK 的階段"
    )

    input(
        "[+] 準備好後按 Enter 讀取事件卡……"
    )

    try:
        data = await read_event_cards(sniffer)
    except RuntimeError as exc:
        print(f"\n[!] 讀取失敗：{exc}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    cards = data.get("cards", [])

    if not cards:
        print("[!] 找不到玩家事件卡")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    print("\n玩家事件卡：")

    valid_indexes: set[int] = set()

    for card in cards:
        print(
            f"  index={card['index']} "
            f"frame={card['eventId']} "
            f"rotation={card['rotation']:.3f} "
            f"angle={card['angle']:.0f} "
            f"selectable={card['selectable']} "
            f"rotatable={card['rotatable']}"
        )

        info = card.get("info") or {}

        print(
            f"  index={card['index']} "
            f"id={card['eventId']} "
            f"{info.get('name_tcn', '未知事件卡')} "
            f"目前={card['currentSide']}"
        )

        if card.get("rotatable"):
            valid_indexes.add(card["index"])

    raw = input(
        "\n輸入要旋轉的事件卡 index："
    ).strip()

    try:
        index = int(raw)
    except ValueError:
        print("[!] index 必須是整數")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    if index not in valid_indexes:
        print("[!] 該事件卡目前不可旋轉")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    before = find_card(data, index)

    if not before:
        print("[!] 找不到事件卡")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    before_side = before["currentSide"]
    info = before.get("info") or {}
    name = info.get("name_tcn", "未知事件卡")

    print(
        f"\n旋轉前：{name} {before_side}"
    )

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
    print("[+] 等待事件卡切換 frame……")

    changed = await wait_for_side_change(
        sniffer,
        index=index,
        before_side=before_side,
    )

    if not changed:
        print("[!] 未偵測到卡面切換")
    else:
        changed_info = changed.get("info") or {}

        print(
            f"旋轉後："
            f"{changed_info.get('name_tcn', '未知事件卡')} "
            f"{changed['currentSide']}"
        )

    input(
        "\n請回遊戲確認卡面，再按 Enter 關閉……"
    )

    await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=False,
    )

    sniffer.start(
        after_connect=after_connect
    )


if __name__ == "__main__":
    main()