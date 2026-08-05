# -*- coding: utf-8 -*-
"""
Inspect UNLIGHT Bonus battle-item inventory through CDP.

Usage:
    python inspect_bonus_items.py

Run this after entering the HIGH / LOW bonus screen.
This script is read-only: it does not press or use any item.
"""

from __future__ import annotations

import asyncio
import json
import sys

from ul_sniffer import ULSniffer

sys.stdout.reconfigure(encoding="utf-8")


INSPECT_JS = r"""
(() => {
  const bonus = window?.game?.scene?.keys?.Bonus;

  if (!bonus) {
    return JSON.stringify({ error: "bonus_scene_missing" });
  }

  const itemList = bonus.item_list;

  if (!itemList) {
    return JSON.stringify({ error: "item_list_missing" });
  }

  function safePrimitive(value) {
    if (
      value === null ||
      typeof value === "string" ||
      typeof value === "number" ||
      typeof value === "boolean"
    ) {
      return value;
    }
    return undefined;
  }

  function summarizeObject(obj, depth = 0, seen = new WeakSet()) {
    if (obj === null) return null;

    const primitive = safePrimitive(obj);
    if (primitive !== undefined) return primitive;

    if (typeof obj !== "object") {
      return `[${typeof obj}]`;
    }

    if (seen.has(obj)) {
      return "[Circular]";
    }
    seen.add(obj);

    if (depth >= 3) {
      return `[${obj.constructor?.name || "Object"}]`;
    }

    if (Array.isArray(obj)) {
      return obj.slice(0, 30).map(
        value => summarizeObject(value, depth + 1, seen)
      );
    }

    const result = {};
    let keys = [];

    try {
      keys = Object.keys(obj).slice(0, 100);
    } catch (error) {
      return `[Unreadable: ${String(error)}]`;
    }

    for (const key of keys) {
      try {
        const value = obj[key];
        const primitiveValue = safePrimitive(value);

        if (primitiveValue !== undefined) {
          result[key] = primitiveValue;
          continue;
        }

        // Prioritize fields likely related to item names, counts and availability.
        if (
          /item|battle|count|num|qty|amount|stock|remain|have|owned|enable|visible|index|list|data/i.test(key)
        ) {
          result[key] = summarizeObject(value, depth + 1, seen);
        }
      } catch (error) {
        result[key] = `[Error: ${String(error)}]`;
      }
    }

    return result;
  }

  function ownPropertyNames(obj) {
    try {
      return Object.getOwnPropertyNames(obj);
    } catch (error) {
      return [];
    }
  }

  const candidates = {
    item_list: summarizeObject(itemList),
    item_list_keys: ownPropertyNames(itemList),
    item_list_proto_keys: ownPropertyNames(
      Object.getPrototypeOf(itemList) || {}
    ),
    item_select: summarizeObject(itemList.item_select),
    item_use: summarizeObject(itemList.item_use),
    bonus_item_fields: {},
  };

  for (const key of ownPropertyNames(bonus)) {
    if (/item|battle|inventory|stock|count|num/i.test(key)) {
      try {
        candidates.bonus_item_fields[key] = summarizeObject(bonus[key]);
      } catch (error) {
        candidates.bonus_item_fields[key] = `[Error: ${String(error)}]`;
      }
    }
  }

  return JSON.stringify(candidates);
})()
"""


async def inspect(sniffer: ULSniffer) -> None:
    try:
        value, error = await sniffer.evaluate(
            INSPECT_JS,
            await_promise=False,
        )

        if error:
            print(f"[!] 探查失敗：{error}")
            return

        data = json.loads(value)

        error_code = data.get("error")
        if error_code == "bonus_scene_missing":
            print("[!] 找不到 Bonus 場景")
            print("    請先完成對戰並進入 HIGH / LOW 獎勵畫面")
            return

        if error_code == "item_list_missing":
            print("[!] Bonus 場景存在，但 item_list 尚未建立")
            print("    請等 HIGH / LOW 畫面與道具按鈕完整顯示後重試")
            return

        print(json.dumps(data, ensure_ascii=False, indent=2))

        output_path = Path("bonus_item_inventory_probe.json")
        output_path.write_text(
            json.dumps(data, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

        print()
        print(f"[+] 已輸出：{output_path.resolve()}")
        print("[+] 請把這個 JSON 的內容貼回來，我再判斷實際數量欄位")
    finally:
        if sniffer.ws is not None:
            await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=59222,
        verbose=False,
    )
    sniffer.start(after_connect=inspect)


if __name__ == "__main__":
    from pathlib import Path
    main()
