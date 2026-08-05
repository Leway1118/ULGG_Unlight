# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json
from pathlib import Path

from ul_sniffer import ULSniffer


CDP_PORT = 59222


INSPECT_CARDS_JS = r"""
(() => {
  const scenes = globalThis.game?.scene?.keys || {};
  const main = scenes.MainA;

  if (!main) {
    return JSON.stringify({
      error: "main_scene_missing"
    });
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

    if (typeof value === "undefined") {
      return null;
    }

    return null;
  }

  function safe(callback, fallback = null) {
    try {
      return callback();
    } catch (error) {
      return fallback;
    }
  }

  function objectFields(value) {
    if (!value || (
      typeof value !== "object" &&
      typeof value !== "function"
    )) {
      return {};
    }

    const result = {};

    for (const key of Object.keys(value).slice(0, 150)) {
      try {
        const child = value[key];
        const childType = typeof child;

        if (
          child === null ||
          childType === "string" ||
          childType === "number" ||
          childType === "boolean"
        ) {
          result[key] = child;
        } else if (childType === "undefined") {
          result[key] = null;
        } else {
          result[key] = {
            type: childType,
            constructor: safe(
              () => child?.constructor?.name
            ),
            keys: safe(
              () => Object.keys(child).slice(0, 60),
              []
            )
          };
        }
      } catch (error) {
        result[key] = {
          error: String(error)
        };
      }
    }

    return result;
  }

  function eventSummary(value) {
    const names = safe(
      () => value.eventNames().map(String),
      []
    );

    const listeners = {};

    for (const name of names) {
      listeners[name] = safe(
        () => value.listeners(name).map(
          listener => ({
            name: listener?.name || "",
            source: String(listener).slice(0, 1000)
          })
        ),
        []
      );
    }

    return {
      names,
      listeners
    };
  }

  function summarize(value, index) {
    if (value == null) {
      return {
        index,
        missing: true
      };
    }

    return {
      index,
      type: typeof value,
      constructor: safe(
        () => value.constructor?.name
      ),
      keys: safe(
        () => Object.keys(value).slice(0, 150),
        []
      ),

      x: primitive(safe(() => value.x)),
      y: primitive(safe(() => value.y)),
      visible: primitive(safe(() => value.visible)),
      active: primitive(safe(() => value.active)),
      inputEnabled: primitive(
        safe(() => value.input?.enabled)
      ),
      textureKey: primitive(
        safe(() => value.texture?.key)
      ),
      frameName: primitive(
        safe(() => value.frame?.name)
      ),
      name: primitive(safe(() => value.name)),
      text: primitive(safe(() => value.text)),

      fields: objectFields(value),
      events: eventSummary(value)
    };
  }

  function summarizeArray(array) {
    if (!Array.isArray(array)) {
      return {
        isArray: false
      };
    }

    return {
      isArray: true,
      length: array.length,
      items: array.map(
        (item, index) => summarize(item, index)
      )
    };
  }

  const activePhase =
    scenes.MovePhaseA?.scene?.isActive?.()
      ? "move"
      : scenes.AttackPhaseA?.scene?.isActive?.()
        ? "attack"
        : scenes.DefensePhaseA?.scene?.isActive?.()
          ? "defense"
          : "unknown";

  return JSON.stringify({
    activePhase,

    main: {
      distance: primitive(main.distance),
      arr1_left: primitive(main.arr1_left),
      currentPage: primitive(main.currentPage),
      fixedValue: primitive(main.fixedValue),
      sendDelay: primitive(main.sendDelay)
    },

    ok: summarize(main.ok, "ok"),
    arr1: summarizeArray(main.arr1),
    button: summarizeArray(main.button),
    card2: summarizeArray(main.card2)
  }, null, 2);
})()
"""


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在可選牌、尚未按 OK 的畫面")
    input("[+] 準備好後按 Enter 掃描手牌……")

    value, error = await sniffer.evaluate(
        INSPECT_CARDS_JS,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 掃描失敗：{error}")
    else:
        data = json.loads(value)
        phase = data.get("activePhase", "unknown")

        output_path = Path(
            f"battle_cards_{phase}.json"
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