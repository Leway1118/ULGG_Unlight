# -*- coding: utf-8 -*-
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

from ul_sniffer import ULSniffer


CDP_PORT = 59222
OUTPUT_PATH = Path("raid_list_snapshot.json")


READ_RAID_LIST_JS = r"""
(() => {
  const raid = globalThis.game?.scene?.keys?.Raid;

  if (!raid) {
    return JSON.stringify({
      ok: false,
      error: "Raid scene missing"
    });
  }

  const rows = Array.isArray(raid.raid_data)
    ? raid.raid_data
    : [];

  const result = rows.map((row, index) => {
    const points = Array.isArray(row?.points)
      ? row.points
      : [];

    const myName = raid.player?.name || null;
    const myEntry = points.find(
      item => item?.name === myName
    ) || null;

    return {
      index,
      profound_id: row?.profound_id ?? null,
      profound_date: row?.profound_date ?? null,
      founder: row?.profound_founder ?? null,
      founder_id: row?.profound_founder_id ?? null,

      monster_code: row?.profound_mons ?? null,
      monster_id: row?.core_monster_id ?? null,
      name: row?.name_tcn ?? null,
      description: row?.info_tcn ?? null,
      rarity: row?.rarity ?? null,
      level: row?.level ?? null,

      hp: row?.hp ?? null,
      hp_max: row?.hp_max ?? null,
      hp_ratio:
        Number(row?.hp_max || 0) > 0
          ? Number(row?.hp || 0) /
            Number(row.hp_max)
          : null,

      ap_spend: row?.ap_spend ?? null,
      expires_at: row?.limit ?? null,
      map: row?.map ?? null,
      stage: row?.stage ?? null,
      member_limit: row?.member_limit ?? null,
      participant_count: points.length,

      pass: row?.pass ?? null,
      only_friend: Boolean(row?.only_friend),
      treasure_level: row?.treasure_level ?? null,
      is_active: row?.isActive ?? null,
      pos_index: row?.pos_index ?? null,

      status: Array.isArray(row?.state)
        ? row.state
        : [],

      already_joined: Boolean(myEntry),
      my_point: myEntry?.point ?? null,
      my_damage: myEntry?.damage ?? null,

      participants: points.map(item => ({
        name: item?.name ?? null,
        point: item?.point ?? null,
        damage: item?.damage ?? null,
        level: item?.level ?? null,
        lang: item?.lang ?? null
      })),

      defeat: Array.isArray(row?.defeat)
        ? row.defeat
        : [],
      defeat_name: row?.defeat_name ?? null
    };
  });

  return JSON.stringify({
    ok: true,
    at: new Date().toISOString(),
    active: Boolean(
      raid.scene?.isActive?.()
    ),
    player_name: raid.player?.name || null,
    raid_count: result.length,
    raids: result
  });
})()
"""


def format_local_timestamp(value: int | None) -> str:
    if not value:
        return "-"

    try:
        dt = datetime.fromtimestamp(
            value / 1000,
            tz=timezone.utc,
        ).astimezone()

        return dt.strftime("%Y-%m-%d %H:%M:%S")
    except (ValueError, OSError, TypeError):
        return str(value)


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在 Raid 公開清單畫面")
    input("[+] 準備好後按 Enter 讀取……")

    value, error = await sniffer.evaluate(
        READ_RAID_LIST_JS,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 讀取失敗：{error}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    data = json.loads(value)

    if not data.get("ok"):
        print(
            f"\n[!] {data.get('error', 'unknown error')}"
        )
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

    print(
        f"\n[+] 讀到 Raid："
        f"{data.get('raid_count', 0)} 筆"
    )

    for raid in data.get("raids", []):
        hp = raid.get("hp")
        hp_max = raid.get("hp_max")
        ratio = raid.get("hp_ratio")
        ratio_text = (
            f"{ratio * 100:.1f}%"
            if isinstance(ratio, (int, float))
            else "-"
        )

        print(
            f"\n[{raid.get('index')}] "
            f"{raid.get('name')} "
            f"稀有度={raid.get('rarity')} "
            f"等級={raid.get('level')}"
        )
        print(
            f"    HP={hp}/{hp_max} "
            f"({ratio_text})"
        )
        print(
            f"    人數="
            f"{raid.get('participant_count')}/"
            f"{raid.get('member_limit')}"
        )
        print(
            f"    剩餘期限="
            f"{format_local_timestamp(raid.get('expires_at'))}"
        )
        print(
            f"    發現者={raid.get('founder')} "
            f"已參戰={raid.get('already_joined')}"
        )
        print(
            f"    參戰碼={raid.get('pass')} "
            f"AP={raid.get('ap_spend')}"
        )
        print(
            f"    狀態={raid.get('status')}"
        )

    print(
        f"\n[+] 完整快照："
        f"{OUTPUT_PATH.resolve()}"
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
