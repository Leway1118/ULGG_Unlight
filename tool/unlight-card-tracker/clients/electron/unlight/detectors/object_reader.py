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

TARGET_SCENES = [
    "MainA",
    "DrawPhaseA",
    "MovePhaseA",
    "AttackPhaseA",
    "DefensePhaseA",
    "MainCPU",
    "MatchBoot",
]


def build_scene_inspect_js(scene_names: list[str]) -> str:
    names_json = json.dumps(scene_names, ensure_ascii=False)

    return rf"""
(() => {{
  const scenes = globalThis.game?.scene?.keys || {{}};
  const targetNames = {names_json};
  const result = {{}};

  function safePrimitive(value) {{
    if (
      value === null ||
      typeof value === "string" ||
      typeof value === "number" ||
      typeof value === "boolean"
    ) {{
      return value;
    }}

    if (typeof value === "undefined") {{
      return null;
    }}

    try {{
      return String(value).slice(0, 300);
    }} catch (error) {{
      return `[unreadable: ${{String(error)}}]`;
    }}
  }}

  function safeGet(callback, fallback = null) {{
    try {{
      return callback();
    }} catch (error) {{
      return fallback;
    }}
  }}

  function safeKeys(value, limit = 120) {{
    try {{
      return Object.keys(value)
        .slice(0, limit)
        .map(key => String(key));
    }} catch (error) {{
      return [];
    }}
  }}

  function safeEventNames(value) {{
    try {{
      if (typeof value?.eventNames !== "function") {{
        return null;
      }}

      return value.eventNames()
        .slice(0, 100)
        .map(name => safePrimitive(name));
    }} catch (error) {{
      return null;
    }}
  }}

  function summarizeValue(value) {{
    if (value === null) {{
      return {{
        type: "null"
      }};
    }}

    const type = typeof value;

    if (
      type === "string" ||
      type === "number" ||
      type === "boolean" ||
      type === "undefined"
    ) {{
      return {{
        type,
        value: safePrimitive(value)
      }};
    }}

    let constructorName = null;

    try {{
      constructorName =
        typeof value?.constructor?.name === "string"
          ? value.constructor.name
          : null;
    }} catch (error) {{
      constructorName = null;
    }}

    const isArray = Array.isArray(value);

    return {{
      type,
      constructor: constructorName,
      isArray,
      length: isArray ? Number(value.length) : null,

      name: safePrimitive(
        safeGet(() => value.name)
      ),

      text: safePrimitive(
        safeGet(() => value.text)
      ),

      visible: safePrimitive(
        safeGet(() => value.visible)
      ),

      active: safePrimitive(
        safeGet(() => value.active)
      ),

      inputEnabled: safePrimitive(
        safeGet(() => value.input?.enabled)
      ),

      textureKey: safePrimitive(
        safeGet(() => value.texture?.key)
      ),

      eventNames: safeEventNames(value),
      keys: safeKeys(value)
    }};
  }}

  for (const sceneName of targetNames) {{
    const scene = scenes[sceneName];

    if (!scene) {{
      result[sceneName] = {{
        missing: true
      }};
      continue;
    }}

    const sceneResult = {{
      active: Boolean(
        safeGet(() => scene.scene.isActive(), false)
      ),
      visible: Boolean(
        safeGet(() => scene.scene.isVisible(), false)
      ),
      constructor: safePrimitive(
        safeGet(() => scene.constructor?.name)
      ),
      fields: {{}}
    }};

    const sceneKeys = safeKeys(scene, 500).sort();

    for (const key of sceneKeys) {{
      try {{
        sceneResult.fields[key] = summarizeValue(scene[key]);
      }} catch (error) {{
        sceneResult.fields[key] = {{
          error: safePrimitive(error?.message || error)
        }};
      }}
    }}

    result[sceneName] = sceneResult;
  }}

  /*
   * 不直接 stringify Phaser 物件。
   * result 中所有值都已轉成純字串、數字、布林、陣列或普通物件。
   */
  return JSON.stringify(result, null, 2);
}})()
"""


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在可以操作的戰鬥階段")
    input("[+] 準備好後按 Enter 掃描……")

    js = build_scene_inspect_js(TARGET_SCENES)

    value, error = await sniffer.evaluate(
        js,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 掃描失敗：{error}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    print("\n===== Battle Objects =====")
    print(value)

    output_path = Path("battle_objects.json")

    try:
        parsed = json.loads(value)

        output_path.write_text(
            json.dumps(
                parsed,
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )

        print(f"\n[+] 已儲存：{output_path.resolve()}")

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
