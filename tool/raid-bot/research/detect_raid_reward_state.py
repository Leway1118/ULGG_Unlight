# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json
from pathlib import Path

from ul_sniffer import ULSniffer


CDP_PORT = 59222
OUTPUT_PATH = Path("raid_reward_state_probe.jsonl")
POLL_SECONDS = 30.0
POLL_INTERVAL = 0.25


READ_JS = r"""
(() => {
  const raid = globalThis.game?.scene?.keys?.Raid;

  if (!raid) {
    return JSON.stringify({
      ok: false,
      error: "Raid scene missing"
    });
  }

  function safe(value, depth = 0, seen = new WeakSet()) {
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

    if (depth >= 5) {
      return Array.isArray(value)
        ? `[Array(${value.length})]`
        : `[${value.constructor?.name || "Object"}]`;
    }

    seen.add(value);

    if (Array.isArray(value)) {
      return value.slice(0, 200).map(
        item => safe(item, depth + 1, seen)
      );
    }

    const out = {};

    for (const key of Object.keys(value).slice(0, 200)) {
      try {
        out[key] = safe(
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

  function textOf(value) {
    if (typeof value?.text === "string") {
      return value.text;
    }

    return null;
  }

  const raidData = Array.isArray(raid.raid_data)
    ? raid.raid_data.map((row, index) => ({
        index,
        profound_id: row?.profound_id ?? null,
        monster_code: row?.profound_mons ?? null,
        monster_id: row?.core_monster_id ?? null,
        name_tcn: row?.name_tcn ?? null,
        rarity: row?.rarity ?? null,
        level: row?.level ?? null,
        treasure_level: row?.treasure_level ?? null,
        hp: row?.hp ?? null,
        hp_max: row?.hp_max ?? null,
        pass: row?.pass ?? null,
        state: safe(row?.state ?? []),
        reward_fields: Object.fromEntries(
          Object.entries(row || {}).filter(
            ([key]) =>
              /reward|treasure|item|fragment|bonus/i.test(key)
          )
        )
      }))
    : [];

  const itemRaid = Array.isArray(raid.itemInfo?.raid)
    ? raid.itemInfo.raid.map((item, index) => ({
        index,
        ...safe(item)
      }))
    : [];

  const display = (
    raid.children?.list ||
    raid.sys?.displayList?.list ||
    []
  );

  const visibleTexts = display
    .filter(
      obj =>
        obj?.visible !== false &&
        typeof obj?.text === "string" &&
        obj.text.trim()
    )
    .map((obj, index) => ({
      index,
      text: obj.text,
      name: obj?.name || null,
      x: Number(obj?.x || 0),
      y: Number(obj?.y || 0)
    }));

  const rewardTexts = visibleTexts.filter(
    item =>
      /碎片|獎勵|報酬|死亡|靈魂|生命|reward|fragment/i
        .test(item.text)
  );

  const stateTexts = visibleTexts.filter(
    item =>
      /咒|攻|防|移|自壞|狀態|curse|attack|defense|move/i
        .test(item.text)
  );

  return JSON.stringify({
    ok: true,
    at: new Date().toISOString(),
    active: Boolean(raid.scene?.isActive?.()),

    raid_id: raid.raid_id ?? null,
    raid_idx: raid.raid_idx ?? null,

    raid_data: raidData,
    raid_reward_data: safe(
      raid.raid_reward_data ?? null
    ),
    item_info_raid: itemRaid,

    raid_info: safe(raid.raid_info ?? null),
    raid_info_name: textOf(raid.raid_info_name),
    raid_info_hp: textOf(raid.raid_info_hp),
    raid_info_reward: textOf(
      raid.raid_info_reward_text
    ),
    raid_info_limit: textOf(
      raid.raid_info_limit_info
    ),

    visible_reward_texts: rewardTexts,
    visible_state_texts: stateTexts,

    direct_reward_keys: Object.keys(raid).filter(
      key =>
        /reward|treasure|item|fragment|state/i.test(key)
    ),

    socket_handled_keys:
      Object.keys(raid.socket?.handled || {})
  });
})()
"""


def fingerprint(data: dict) -> str:
    watched = {
        "active": data.get("active"),
        "raid_id": data.get("raid_id"),
        "raid_idx": data.get("raid_idx"),
        "raid_data": data.get("raid_data"),
        "raid_reward_data":
            data.get("raid_reward_data"),
        "raid_info":
            data.get("raid_info"),
        "raid_info_name":
            data.get("raid_info_name"),
        "raid_info_reward":
            data.get("raid_info_reward"),
        "visible_reward_texts":
            data.get("visible_reward_texts"),
        "visible_state_texts":
            data.get("visible_state_texts"),
    }

    return json.dumps(
        watched,
        ensure_ascii=False,
        sort_keys=True,
    )


async def after_connect(
    sniffer: ULSniffer,
) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請先停在 Raid 平台")
    print(
        "[+] 按 Enter 後，在30秒內切到公開清單，"
        "並依序點開不同 Raid 詳細頁"
    )
    input("[+] 準備好後按 Enter 開始偵測……")

    OUTPUT_PATH.write_text(
        "",
        encoding="utf-8",
    )

    loop = asyncio.get_running_loop()
    deadline = loop.time() + POLL_SECONDS
    previous: str | None = None
    change_count = 0

    while loop.time() < deadline:
        value, error = await sniffer.evaluate(
            READ_JS,
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

        current = fingerprint(data)

        if current != previous:
            previous = current
            change_count += 1

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

            raid_count = len(
                data.get("raid_data", [])
            )
            reward_data = data.get(
                "raid_reward_data"
            )
            reward_len = (
                len(reward_data)
                if isinstance(reward_data, list)
                else "-"
            )
            reward_texts = len(
                data.get(
                    "visible_reward_texts",
                    [],
                )
            )
            state_texts = len(
                data.get(
                    "visible_state_texts",
                    [],
                )
            )

            print(
                f"[{change_count:02}] "
                f"active={data.get('active')} "
                f"raid_id={data.get('raid_id')} "
                f"raid_idx={data.get('raid_idx')} "
                f"raids={raid_count} "
                f"reward_data={reward_len} "
                f"reward_texts={reward_texts} "
                f"state_texts={state_texts}"
            )

        await asyncio.sleep(POLL_INTERVAL)

    print("\n[+] 偵測完成")
    print(f"[+] 狀態變化次數：{change_count}")
    print(
        f"[+] 輸出：{OUTPUT_PATH.resolve()}"
    )
    print(
        "[+] 請把 raid_reward_state_probe.jsonl "
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
