# -*- coding: utf-8 -*-
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from ul_sniffer import ULSniffer


CDP_PORT = 59222
OUTPUT_PATH = Path("player_event_card_probe.json")


PROBE_JS = r"""
(() => {
  const main = globalThis.game?.scene?.keys?.MainA;

  if (!main) {
    return JSON.stringify({
      ok: false,
      error: "MainA missing"
    });
  }

  const publicTextureKeys = new Set([
    "acswd",
    "acbow",
    "acshi",
    "acmov",
    "acspe"
  ]);

  function safeNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function constructorName(value) {
    try {
      return value?.constructor?.name || null;
    } catch {
      return null;
    }
  }

  function ownKeys(value) {
    try {
      return Object.keys(value || {}).slice(0, 120);
    } catch {
      return [];
    }
  }

  function listenerSummary(object) {
    const result = {};

    try {
      const events = object?._events;

      if (!events || typeof events !== "object") {
        return result;
      }

      for (const eventName of Object.keys(events)) {
        const event = events[eventName];
        const listeners = Array.isArray(event)
          ? event
          : [event];

        result[eventName] = listeners.map((listener) => {
          const fn =
            listener?.fn ||
            listener?.listener ||
            listener;

          let source = null;

          try {
            source =
              typeof fn === "function"
                ? String(fn).slice(0, 1000)
                : null;
          } catch {
            source = null;
          }

          return {
            fnType: typeof fn,
            contextType: constructorName(
              listener?.context
            ),
            once: Boolean(listener?.once),
            source
          };
        });
      }
    } catch {
      return {};
    }

    return result;
  }

  function summarizeDisplayObject(object, slot) {
    if (object == null) {
      return {
        slot,
        exists: false
      };
    }

    const textureKey =
      object?.texture?.key ||
      object?.frame?.texture?.key ||
      null;

    const frameName =
      object?.frame?.name ??
      object?.frame?.index ??
      null;

    let text = null;

    if (typeof object?.text === "string") {
      text = object.text;
    }

    let dataEntries = null;

    try {
      const dataValues = object?.data?.values;

      if (
        dataValues &&
        typeof dataValues === "object"
      ) {
        dataEntries = {};

        for (
          const [key, value]
          of Object.entries(dataValues)
        ) {
          if (
            value == null ||
            typeof value === "string" ||
            typeof value === "number" ||
            typeof value === "boolean"
          ) {
            dataEntries[key] = value;
          } else {
            dataEntries[key] =
              `[${constructorName(value) || typeof value}]`;
          }
        }
      }
    } catch {
      dataEntries = null;
    }

    return {
      slot,
      exists: true,
      constructor: constructorName(object),
      type: object?.type || null,
      name: object?.name || null,

      textureKey,
      frameName,
      text,

      x: safeNumber(object?.x),
      y: safeNumber(object?.y),
      depth: safeNumber(object?.depth),
      alpha: safeNumber(object?.alpha),
      visible: Boolean(object?.visible),

      rotation: safeNumber(object?.rotation),
      angle: safeNumber(object?.angle),

      scaleX: safeNumber(object?.scaleX),
      scaleY: safeNumber(object?.scaleY),

      width: safeNumber(
        object?.displayWidth ?? object?.width
      ),
      height: safeNumber(
        object?.displayHeight ?? object?.height
      ),

      interactive: Boolean(object?.input),
      inputEnabled: Boolean(
        object?.input?.enabled
      ),
      cursor:
        object?.input?.cursor ||
        object?.input?.customCursor ||
        null,

      dataEntries,
      ownKeys: ownKeys(object),
      listeners: listenerSummary(object)
    };
  }

  function isPublicActionCard(card) {
    const textureA =
      card?.[1]?.texture?.key ||
      card?.[1]?.frame?.texture?.key ||
      null;

    const textureB =
      card?.[3]?.texture?.key ||
      card?.[3]?.frame?.texture?.key ||
      null;

    return (
      publicTextureKeys.has(textureA) ||
      publicTextureKeys.has(textureB)
    );
  }

  const cards = [];

  for (
    let index = 0;
    index < (main.arr1?.length || 0);
    index++
  ) {
    const card = main.arr1[index];

    if (!Array.isArray(card)) {
      continue;
    }

    const objects = card.map(
      (object, slot) =>
        summarizeDisplayObject(object, slot)
    );

    const publicActionCard =
      isPublicActionCard(card);

    cards.push({
      index,
      arrayLength: card.length,
      publicActionCard,
      suspectedPlayerEventCard:
        !publicActionCard,
      frameSelectable: Boolean(
        card?.[0]?.input?.enabled
      ),
      frameRotation: safeNumber(
        card?.[0]?.rotation
      ),
      rotateButton: summarizeDisplayObject(
        main.button?.[index],
        "rotateButton"
      ),
      objects
    });
  }

  const suspected = cards.filter(
    (card) => card.suspectedPlayerEventCard
  );

  return JSON.stringify({
    ok: true,

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

    arr1Length: main.arr1?.length || 0,
    arr1Left: main.arr1_left ?? null,

    suspectedCount: suspected.length,
    suspected,
    allCards: cards
  }, null, 2);
})()
"""


def print_object_summary(
    object_data: dict[str, Any],
) -> None:
    slot = object_data.get("slot")
    exists = object_data.get("exists")

    if not exists:
        print(f"    slot={slot}: <missing>")
        return

    print(
        f"    slot={slot} "
        f"class={object_data.get('constructor')} "
        f"type={object_data.get('type')} "
        f"texture={object_data.get('textureKey')} "
        f"frame={object_data.get('frameName')} "
        f"text={object_data.get('text')!r} "
        f"x={object_data.get('x')} "
        f"y={object_data.get('y')} "
        f"input={object_data.get('inputEnabled')}"
    )

    listeners = object_data.get("listeners") or {}

    if listeners:
        print(
            "      listeners="
            + ", ".join(listeners.keys())
        )


async def after_connect(
    sniffer: ULSniffer,
) -> None:
    print("\n[+] 已連接遊戲")
    print(
        "[+] 請停在手牌中包含玩家自帶事件卡的階段"
    )

    input(
        "[+] 準備好後按 Enter 執行唯讀探測……"
    )

    value, error = await sniffer.evaluate(
        PROBE_JS,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 探測失敗：{error}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    try:
        data = json.loads(value)
    except json.JSONDecodeError as exc:
        print(f"\n[!] JSON 解析失敗：{exc}")
        print(value)
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    OUTPUT_PATH.write_text(
        json.dumps(
            data,
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    print(f"\n階段：{data.get('phase')}")
    print(
        f"疑似玩家事件卡數量："
        f"{data.get('suspectedCount')}"
    )

    for card in data.get("suspected", []):
        print(
            f"\n  index={card.get('index')} "
            f"arrayLength={card.get('arrayLength')} "
            f"selectable={card.get('frameSelectable')}"
        )

        for object_data in card.get("objects", []):
            print_object_summary(object_data)

        rotate_button = card.get("rotateButton")

        if rotate_button:
            print("    rotateButton:")
            print_object_summary(rotate_button)

    print(
        f"\n[+] 完整結果已輸出："
        f"{OUTPUT_PATH.resolve()}"
    )

    input(
        "\n請把 player_event_card_probe.json "
        "上傳給我，再按 Enter 關閉……"
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