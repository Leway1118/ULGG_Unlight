# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json
from pathlib import Path

from ul_sniffer import ULSniffer


CDP_PORT = 59222


INSPECT_JS = r"""
(() => {
  const scenes = globalThis.game?.scene?.keys || {};
  const main = scenes.MainA;

  if (!main) {
    return JSON.stringify({
      error: "main_scene_missing"
    });
  }

  function safe(callback, fallback = null) {
    try {
      return callback();
    } catch (error) {
      return fallback;
    }
  }

  function primitive(value) {
    if (
      value === null ||
      typeof value === "string" ||
      typeof value === "number" ||
      typeof value === "boolean"
    ) {
      return value;
    }

    return null;
  }

  function listeners(value) {
    const result = {};

    const names = safe(
      () => value.eventNames().map(String),
      []
    );

    for (const name of names) {
      result[name] = safe(
        () => value.listeners(name).map(
          callback => ({
            name: callback?.name || "",
            source: String(callback).slice(0, 3000)
          })
        ),
        []
      );
    }

    return result;
  }

  function summarizeComponent(value, componentIndex) {
    if (!value) {
      return {
        componentIndex,
        missing: true
      };
    }

    return {
      componentIndex,

      constructor: safe(
        () => value.constructor?.name
      ),

      type: primitive(
        safe(() => value.type)
      ),

      x: primitive(
        safe(() => value.x)
      ),

      y: primitive(
        safe(() => value.y)
      ),

      depth: primitive(
        safe(() => value.depth ?? value._depth)
      ),

      visible: primitive(
        safe(() => value.visible)
      ),

      active: primitive(
        safe(() => value.active)
      ),

      inputEnabled: primitive(
        safe(() => value.input?.enabled)
      ),

      textureKey: primitive(
        safe(() => value.texture?.key)
      ),

      frameName: primitive(
        safe(() => value.frame?.name)
      ),

      text: primitive(
        safe(() => value.text ?? value._text)
      ),

      rotation: primitive(
        safe(() => value.rotation ?? value._rotation)
      ),

      events: listeners(value)
    };
  }

  function summarizeCard(card, cardIndex) {
    if (!Array.isArray(card)) {
      return {
        cardIndex,
        missing: true
      };
    }

    return {
      cardIndex,
      componentCount: card.length,
      components: card.map(
        (component, componentIndex) =>
          summarizeComponent(component, componentIndex)
      )
    };
  }

  function summarizeButton(button, cardIndex) {
    if (!button) {
      return {
        cardIndex,
        missing: true
      };
    }

    return {
      cardIndex,
      x: primitive(safe(() => button.x)),
      y: primitive(safe(() => button.y)),
      visible: primitive(safe(() => button.visible)),
      inputEnabled: primitive(
        safe(() => button.input?.enabled)
      ),
      events: listeners(button)
    };
  }

  const phase =
    scenes.MovePhaseA?.scene?.isActive?.()
      ? "move"
      : scenes.AttackPhaseA?.scene?.isActive?.()
        ? "attack"
        : scenes.DefensePhaseA?.scene?.isActive?.()
          ? "defense"
          : "unknown";

  const cards = [];

  for (
    let index = 0;
    index < Math.max(
      main.arr1?.length || 0,
      main.button?.length || 0
    );
    index++
  ) {
    if (!main.arr1?.[index] && !main.button?.[index]) {
      continue;
    }

    cards.push({
      index,
      card: summarizeCard(
        main.arr1?.[index],
        index
      ),
      rotateButton: summarizeButton(
        main.button?.[index],
        index
      )
    });
  }

  return JSON.stringify({
    phase,

    state: {
      arr1Left: primitive(main.arr1_left),
      distance: primitive(main.distance),
      swdSum: primitive(main.swdSum),
      gunSum: primitive(main.gunSum),
      shiSum: primitive(main.shiSum),
      movSum: primitive(main.movSum),
      speSum: primitive(main.speSum),
      sendDelay: primitive(main.sendDelay)
    },

    cards
  }, null, 2);
})()
"""


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在攻擊階段，可以選牌但尚未按 OK")
    input("[+] 準備好後按 Enter 掃描……")

    value, error = await sniffer.evaluate(
        INSPECT_JS,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 掃描失敗：{error}")
    else:
        data = json.loads(value)
        phase = data.get("phase", "unknown")

        output_path = Path(
            f"card_components_{phase}.json"
        )

        output_path.write_text(
            json.dumps(
                data,
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )

        print(f"\n[+] 階段：{phase}")
        print(f"[+] 已儲存：{output_path.resolve()}")

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