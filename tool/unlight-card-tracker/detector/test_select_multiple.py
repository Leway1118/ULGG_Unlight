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

    arr1Left: Number(main.arr1_left || 0),
    cards
  });
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
      error: "card not rotatable",
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
    target_side: str,
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

        if card.get("currentSide") == target_side:
            return True

    return False


async def wait_until_selected(
    sniffer: ULSniffer,
    index: int,
    before_y: float,
    before_arr1_left: int,
    timeout: float = 3.0,
) -> bool:
    """
    確認卡牌已由手牌區移入出牌區。

    選中的牌可能仍維持 interactive，
    因為玩家可以把牌點回手牌，所以不能用
    selectable=False 當作成功條件。
    """
    loop = asyncio.get_running_loop()
    deadline = loop.time() + timeout

    while loop.time() < deadline:
        await asyncio.sleep(0.1)

        data = await read_cards(sniffer)
        card = find_card(data, index)

        current_arr1_left = int(
            data.get("arr1Left", before_arr1_left)
        )

        # 條件一：手牌數減少。
        if current_arr1_left < before_arr1_left:
            return True

        if not card:
            # 卡牌暫時離開陣列，也視為操作已被接受。
            return True

        current_y = float(card.get("y", before_y))

        # 一般手牌區約 y=510，出牌區約 y=380。
        if current_y < before_y - 50:
            return True

    return False

async def wait_until_card_ready(
    sniffer: ULSniffer,
    index: int,
    timeout: float = 5.0,
) -> bool:
    """
    等待指定卡牌在前一張操作完成後重新恢復可選。
    遊戲選牌期間可能短暫關閉其他手牌的 interactive。
    """
    loop = asyncio.get_running_loop()
    deadline = loop.time() + timeout

    while loop.time() < deadline:
        await asyncio.sleep(0.1)

        data = await read_cards(sniffer)
        card = find_card(data, index)

        if not card:
            continue

        if card.get("selectable", False):
            return True

    return False

def parse_plan(
    raw: str,
) -> list[tuple[int, str]]:
    plan: list[tuple[int, str]] = []

    parts = raw.split(",")

    for part in parts:
        item = part.strip()

        if not item:
            continue

        chunks = item.split()

        if len(chunks) != 2:
            raise ValueError(
                f"格式錯誤：{item}"
            )

        index_text, side_text = chunks

        try:
            index = int(index_text)
        except ValueError as exc:
            raise ValueError(
                f"index 不是整數：{index_text}"
            ) from exc

        side = side_text.upper()

        if side not in {"A", "B"}:
            raise ValueError(
                f"面必須是 A 或 B：{side_text}"
            )

        plan.append((index, side))

    if not plan:
        raise ValueError(
            "至少輸入一張牌"
        )

    indexes = [
        index
        for index, _ in plan
    ]

    if len(indexes) != len(set(indexes)):
        raise ValueError(
            "同一個 index 不可重複"
        )

    return plan


async def execute_card(
    sniffer: ULSniffer,
    index: int,
    target_side: str,
) -> bool:
    data = await read_cards(sniffer)
    card = find_card(data, index)

    if not card:
        print(
            f"[!] index={index} 找不到卡牌"
        )
        return False

    if not card.get("selectable"):
        print(
            f"[+] index={index} 尚未恢復可選，"
            "等待遊戲處理前一張牌……"
        )

        ready = await wait_until_card_ready(
            sniffer,
            index=index,
        )

        if not ready:
            print(
                f"[!] index={index} "
                "等待 5 秒後仍不可選"
            )
            return False

        print(
            f"[+] index={index} 已恢復可選"
        )

        data = await read_cards(sniffer)
        card = find_card(data, index)

        if not card:
            print(
                f"[!] index={index} "
                "恢復後找不到卡牌"
            )
            return False

    before_y = float(
        card.get("y", 0)
    )

    before_arr1_left = int(
        data.get("arr1Left", 0)
    )

    side_a = card.get("sideA", {})
    side_b = card.get("sideB", {})

    if (
        side_a.get("type") is None
        or side_b.get("type") is None
    ):
        print(
            f"[!] index={index} 是未知卡，停止操作"
        )
        return False

    target = (
        side_a
        if target_side == "A"
        else side_b
    )

    current_side = card.get("currentSide")

    print(
        f"\n[+] 處理 index={index}"
    )
    print(
        f"[+] 目標：{target_side} = "
        f"{target.get('type')} "
        f"{target.get('value')}"
    )

    if current_side != target_side:
        print(
            f"[+] 目前是 {current_side}，先旋轉"
        )

        result, error = await sniffer.evaluate(
            build_rotate_js(index),
            await_promise=False,
        )

        if error:
            print(
                f"[!] index={index} "
                f"旋轉失敗：{error}"
            )
            return False

        print(
            f"[+] 旋轉結果：{result}"
        )

        confirmed = await wait_for_target_side(
            sniffer,
            index=index,
            target_side=target_side,
        )

        if not confirmed:
            print(
                f"[!] index={index} "
                "未確認翻到目標面"
            )
            return False

        print(
            f"[+] index={index} "
            "已確認目標面"
        )
    else:
        print(
            f"[+] index={index} "
            "目前已是目標面"
        )

    result, error = await sniffer.evaluate(
        build_select_js(index),
        await_promise=False,
    )

    if error:
        print(
            f"[!] index={index} "
            f"選牌失敗：{error}"
        )
        return False

    print(
        f"[+] 選牌結果：{result}"
    )

    selected = await wait_until_selected(
        sniffer,
        index=index,
        before_y=before_y,
        before_arr1_left=before_arr1_left,
    )

    if not selected:
        print(
            f"[!] index={index} "
            "未確認選牌完成"
        )
        return False

    print(
        f"[+] index={index} "
        "已確認移入出牌區"
    )

    # 等遊戲完成動畫並重新啟用其他手牌。
    await asyncio.sleep(0.25)

    return True

async def after_connect(
    sniffer: ULSniffer,
) -> None:
    print("\n[+] 已連接遊戲")
    print(
        "[+] 請停在可選牌、尚未按 OK 的階段"
    )

    input(
        "[+] 準備好後按 Enter 讀取手牌……"
    )

    try:
        data = await read_cards(sniffer)
    except RuntimeError as exc:
        print(
            f"\n[!] 讀取失敗：{exc}"
        )
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    print(
        f"\n階段：{data.get('phase')}"
    )
    print(
        f"剩餘手牌：{data.get('arr1Left')}"
    )
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
            usable_indexes.add(
                card["index"]
            )

        print(
            f"  index={card['index']:>2} "
            f"A={side_a['type']} "
            f"{side_a['value']} / "
            f"B={side_b['type']} "
            f"{side_b['value']} "
            f"目前={card['currentSide']} "
            f"[{status}]"
        )

    print(
        "\n輸入格式："
    )
    print(
        "  index 面, index 面"
    )
    print(
        "例如："
    )
    print(
        "  1 B, 4 A, 5 B"
    )

    raw = input(
        "\n輸入本次出牌計畫："
    ).strip()

    try:
        plan = parse_plan(raw)
    except ValueError as exc:
        print(
            f"[!] 輸入錯誤：{exc}"
        )
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    for index, _ in plan:
        if index not in usable_indexes:
            print(
                f"[!] index={index} "
                "不在可操作清單"
            )
            input("\n按 Enter 關閉……")
            await sniffer.ws.close()
            return

    print("\n本次計畫：")

    for index, side in plan:
        card = find_card(
            data,
            index,
        )

        target = (
            card["sideA"]
            if side == "A"
            else card["sideB"]
        )

        print(
            f"  index={index} "
            f"{side}="
            f"{target['type']} "
            f"{target['value']}"
        )

    confirm = input(
        "\n輸入 YES 開始執行："
    ).strip().upper()

    if confirm != "YES":
        print(
            "[+] 已取消，不執行"
        )
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    completed: list[int] = []

    for index, side in plan:
        try:
            success = await execute_card(
                sniffer,
                index=index,
                target_side=side,
            )
        except RuntimeError as exc:
            print(
                f"[!] index={index} "
                f"讀取失敗：{exc}"
            )
            success = False

        if not success:
            print(
                "\n[!] 本次流程停止"
            )
            print(
                "[!] 已完成："
                f"{completed}"
            )
            print(
                "[!] 尚未自動按 OK"
            )

            input(
                "\n請回遊戲檢查，"
                "再按 Enter 關閉……"
            )

            await sniffer.ws.close()
            return

        completed.append(index)

    print(
        "\n[+] 全部卡牌處理完成"
    )
    print(
        f"[+] 已完成 index：{completed}"
    )
    print(
        "[+] 本程式不會自動按 OK"
    )
    print(
        "[+] 請回遊戲確認出牌區，"
        "再手動按 OK"
    )

    input(
        "\n確認完畢後按 Enter 關閉……"
    )

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