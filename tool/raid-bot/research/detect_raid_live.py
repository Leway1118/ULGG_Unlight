# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json
from datetime import datetime
from pathlib import Path

from ul_sniffer import ULSniffer


CDP_PORT = 59222
OUTPUT_PATH = Path("raid_live_probe.jsonl")
POLL_SECONDS = 20.0
POLL_INTERVAL = 0.25


READ_RAID_JS = r"""
(() => {
  const raid = globalThis.game?.scene?.keys?.Raid;

  if (!raid) {
    return JSON.stringify({
      ok: false,
      error: "Raid scene missing"
    });
  }

  function simple(value, depth = 0, seen = new WeakSet()) {
    if (
      value === null ||
      typeof value === "string" ||
      typeof value === "number" ||
      typeof value === "boolean"
    ) {
      return value;
    }

    if (typeof value === "function") {
      return `[Function ${value.name || "anonymous"}]`;
    }

    if (
      typeof value !== "object" ||
      value === null
    ) {
      return String(value);
    }

    if (seen.has(value)) {
      return "[Circular]";
    }

    if (depth >= 4) {
      return Array.isArray(value)
        ? `[Array(${value.length})]`
        : `[${value.constructor?.name || "Object"}]`;
    }

    seen.add(value);

    if (Array.isArray(value)) {
      return value.slice(0, 100).map(
        item => simple(item, depth + 1, seen)
      );
    }

    const out = {};

    for (const key of Object.keys(value).slice(0, 120)) {
      try {
        out[key] = simple(
          value[key],
          depth + 1,
          seen
        );
      } catch (error) {
        out[key] = `[Error: ${error?.message || error}]`;
      }
    }

    return out;
  }

  function objectInfo(value) {
    if (value === undefined) {
      return {
        exists: false,
        type: "undefined",
        length: null,
        value: null
      };
    }

    return {
      exists: true,
      type: Array.isArray(value)
        ? "array"
        : typeof value,
      length: Array.isArray(value)
        ? value.length
        : null,
      value: simple(value)
    };
  }

  const display = (
    raid.children?.list ||
    raid.sys?.displayList?.list ||
    []
  ).map((obj, index) => ({
    index,
    type:
      obj?.type ||
      obj?.constructor?.name ||
      null,
    name: obj?.name || null,
    text:
      typeof obj?.text === "string"
        ? obj.text
        : null,
    texture: obj?.texture?.key || null,
    frame:
      obj?.frame?.name ??
      obj?.frame?.index ??
      null,
    x: Number(obj?.x || 0),
    y: Number(obj?.y || 0),
    visible: obj?.visible !== false,
    active: obj?.active !== false,
    interactive: Boolean(obj?.input?.enabled)
  }));

  const interactive = display.filter(
    item => item.interactive
  );

  const visibleTexts = display.filter(
    item =>
      item.visible &&
      typeof item.text === "string" &&
      item.text.trim()
  );

  const directKeys = Object.keys(raid);

  return JSON.stringify({
    ok: true,
    at: new Date().toISOString(),
    active: Boolean(
      raid.scene?.isActive?.()
    ),
    directKeys,
    raid_data: objectInfo(raid.raid_data),
    raid_reward_data:
      objectInfo(raid.raid_reward_data),
    raid_list: objectInfo(raid.raid_list),
    raid_info: objectInfo(raid.raid_info),
    raid_id: objectInfo(raid.raid_id),
    raid_idx: objectInfo(raid.raid_idx),
    vortex: objectInfo(raid.vortex),
    mc_boss: objectInfo(raid.mc_boss),
    mcInfo: objectInfo(raid.mcInfo),
    monsProfile: objectInfo(raid.monsProfile),
    socketHandledKeys:
      Object.keys(raid.socket?.handled || {}),
    visibleTexts,
    interactive
  });
})()
"""


def fingerprint(data: dict) -> str:
    important = {
        "active": data.get("active"),
        "raid_data": data.get("raid_data"),
        "raid_reward_data":
            data.get("raid_reward_data"),
        "raid_list": data.get("raid_list"),
        "raid_id": data.get("raid_id"),
        "raid_idx": data.get("raid_idx"),
        "vortex": data.get("vortex"),
        "visibleTexts":
            data.get("visibleTexts"),
        "interactive":
            data.get("interactive"),
    }

    return json.dumps(
        important,
        ensure_ascii=False,
        sort_keys=True,
    )


async def after_connect(
    sniffer: ULSniffer,
) -> None:
    print("\n[+] 已連接遊戲")
    print(
        "[+] 請先停在 Raid 畫面，"
        "按 Enter 後在 20 秒內切到公開 Raid 清單"
    )
    input("[+] 準備好後按 Enter 開始監看……")

    OUTPUT_PATH.write_text(
        "",
        encoding="utf-8",
    )

    loop = asyncio.get_running_loop()
    deadline = loop.time() + POLL_SECONDS
    previous_fingerprint: str | None = None
    change_count = 0

    while loop.time() < deadline:
        value, error = await sniffer.evaluate(
            READ_RAID_JS,
            await_promise=False,
        )

        if error:
            print(f"[!] 讀取失敗：{error}")
            await asyncio.sleep(POLL_INTERVAL)
            continue

        try:
            data = json.loads(value)
        except json.JSONDecodeError:
            await asyncio.sleep(POLL_INTERVAL)
            continue

        current_fingerprint = fingerprint(data)

        if current_fingerprint != previous_fingerprint:
            change_count += 1
            previous_fingerprint = current_fingerprint

            with OUTPUT_PATH.open(
                "a",
                encoding="utf-8",
            ) as handle:
                handle.write(
                    json.dumps(
                        data,
                        ensure_ascii=False,
                    )
                    + "\n"
                )

            raid_data_len = (
                data.get("raid_data", {})
                .get("length")
            )
            raid_list_len = (
                data.get("raid_list", {})
                .get("length")
            )
            vortex_len = (
                data.get("vortex", {})
                .get("length")
            )
            visible_text_count = len(
                data.get("visibleTexts", [])
            )

            print(
                f"[{change_count:02}] "
                f"active={data.get('active')} "
                f"raid_data={raid_data_len} "
                f"raid_list={raid_list_len} "
                f"vortex={vortex_len} "
                f"texts={visible_text_count}"
            )

        await asyncio.sleep(POLL_INTERVAL)

    print("\n[+] 監看完成")
    print(f"[+] 狀態變化次數：{change_count}")
    print(
        f"[+] 輸出：{OUTPUT_PATH.resolve()}"
    )
    print(
        "[+] 請把 raid_live_probe.jsonl "
        "上傳給我"
    )

    input("\n按 Enter 關閉……")
    await sniffer.ws.close()


def main() -> None:
    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=False,
    )

    sniffer.start(
        after_connect=after_connect,
    )


if __name__ == "__main__":
    main()
