# -*- coding: utf-8 -*-
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

from ul_sniffer import ULSniffer


CDP_PORT = 59222
OUTPUT_PATH = Path("raid_prediction_snapshot.json")


MAP_NAMES = {
    0: "雷城",
    1: "誘惑之森",
    2: "垃圾街",
    3: "冰封湖畔",
    4: "人魂墓地",
    5: "盡頭之村",
    6: "風暴",
    7: "峰亥盧",
    8: "魔都",
    9: "狂山",
    10: "魔女山谷",
    11: "隨機",
    12: "烏波斯黑湖",
}


NORMAL_FRAGMENT_BY_MAP = {
    1: "記憶碎片（黃）",
    2: "時間碎片（綠）",
    3: "靈魂碎片（藍）",
    4: "生命碎片（紅）",
    5: "死亡碎片（紫）",
}


SIX_STAR_FRAGMENT_BY_MAP = {
    1: "時間碎片（綠）",
    2: "靈魂碎片（藍）",
    3: "生命碎片（紅）",
    4: "死亡碎片（紫）",
    5: "記憶碎片（黃）",
}


READ_RAID_JS = r"""
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

  return JSON.stringify({
    ok: true,
    at: new Date().toISOString(),
    active: Boolean(raid.scene?.isActive?.()),
    player_name: raid.player?.name || null,
    raids: rows.map((row, index) => {
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
        founder: row?.profound_founder ?? null,
        monster_code: row?.profound_mons ?? null,
        monster_id: row?.core_monster_id ?? null,
        name: row?.name_tcn ?? null,
        description: row?.info_tcn ?? null,

        rarity: row?.rarity ?? null,
        level: row?.level ?? null,

        map: row?.map ?? null,
        stage: row?.stage ?? null,

        hp: row?.hp ?? null,
        hp_max: row?.hp_max ?? null,
        ap_spend: row?.ap_spend ?? null,
        expires_at: row?.limit ?? null,

        participant_count: points.length,
        member_limit: row?.member_limit ?? null,
        pass: row?.pass ?? null,

        state: Array.isArray(row?.state)
          ? row.state
          : [],

        already_joined: Boolean(myEntry),
        my_damage: myEntry?.damage ?? null,
        my_point: myEntry?.point ?? null
      };
    })
  });
})()
"""


def format_time(timestamp_ms: int | None) -> str:
    if not timestamp_ms:
        return "-"

    try:
        dt = datetime.fromtimestamp(
            timestamp_ms / 1000,
            tz=timezone.utc,
        ).astimezone()

        return dt.strftime("%Y-%m-%d %H:%M:%S")
    except (TypeError, ValueError, OSError):
        return str(timestamp_ms)


def predict_fragment(
    *,
    map_id: int | None,
    raid_type: str = "unknown",
    is_fish_boss: bool = False,
) -> dict:
    """
    raid_type:
      normal   = 1星渦2 / 1星渦3 / 一般渦4
      six_star = 6星渦2 / 6星渦3
      unknown  = 尚未確認
    """

    if is_fish_boss:
        return {
            "fragment": "隨機碎片",
            "confidence": "confirmed_rule",
            "reason": "渦4魚 Boss 特例",
        }

    if map_id not in {1, 2, 3, 4, 5}:
        return {
            "fragment": "未知",
            "confidence": "unknown",
            "reason": "目前僅建立 map 1～5 規則",
        }

    if raid_type == "normal":
        return {
            "fragment": NORMAL_FRAGMENT_BY_MAP[map_id],
            "confidence": "confirmed_rule",
            "reason": "套用1星渦2／1星渦3／一般渦4規則",
        }

    if raid_type == "six_star":
        return {
            "fragment": SIX_STAR_FRAGMENT_BY_MAP[map_id],
            "confidence": "confirmed_rule",
            "reason": "套用6星渦2／6星渦3規則",
        }

    return {
        "fragment": "待確認渦型",
        "confidence": "unknown",
        "reason": (
            f"地圖可判斷為{MAP_NAMES.get(map_id, '未知地圖')}，"
            "但尚未能由欄位可靠分辨1星／6星／魚Boss"
        ),
        "normal_candidate":
            NORMAL_FRAGMENT_BY_MAP.get(map_id),
        "six_star_candidate":
            SIX_STAR_FRAGMENT_BY_MAP.get(map_id),
    }


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 請停在 Raid 公開清單畫面")
    input("[+] 準備好後按 Enter 讀取……")

    value, error = await sniffer.evaluate(
        READ_RAID_JS,
        await_promise=False,
    )

    if error:
        print(f"\n[!] 讀取失敗：{error}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    data = json.loads(value)

    if not data.get("ok"):
        print(f"\n[!] {data.get('error')}")
        input("\n按 Enter 關閉……")
        await sniffer.ws.close()
        return

    output_raids = []

    for raid in data.get("raids", []):
        map_id = raid.get("map")
        prediction = predict_fragment(
            map_id=map_id,
            raid_type="unknown",
            is_fish_boss=False,
        )

        hp = raid.get("hp")
        hp_max = raid.get("hp_max")

        hp_ratio = None
        if isinstance(hp, (int, float)) and isinstance(
            hp_max,
            (int, float),
        ) and hp_max > 0:
            hp_ratio = hp / hp_max

        result = {
            **raid,
            "map_name": MAP_NAMES.get(
                map_id,
                f"未知地圖({map_id})",
            ),
            "hp_ratio": hp_ratio,
            "fragment_prediction": prediction,
        }

        output_raids.append(result)

    output = {
        "ok": True,
        "at": data.get("at"),
        "player_name": data.get("player_name"),
        "raid_count": len(output_raids),
        "raids": output_raids,
        "rules_version": "raid-fragment-v0.1",
        "known_limits": [
            "尚未能可靠分辨1星與6星渦",
            "尚未建立渦4魚Boss monster_code清單",
            "碎片數量尚未判定",
        ],
    }

    OUTPUT_PATH.write_text(
        json.dumps(
            output,
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    print(
        f"\n[+] 讀到 Raid："
        f"{len(output_raids)} 筆"
    )

    for raid in output_raids:
        ratio = raid.get("hp_ratio")
        ratio_text = (
            f"{ratio * 100:.1f}%"
            if isinstance(ratio, (int, float))
            else "-"
        )

        prediction = raid[
            "fragment_prediction"
        ]

        print(
            f"\n[{raid.get('index')}] "
            f"{raid.get('name')} "
            f"monster={raid.get('monster_code')}"
        )
        print(
            f"    地圖={raid.get('map')} "
            f"{raid.get('map_name')} "
            f"stage={raid.get('stage')}"
        )
        print(
            f"    rarity={raid.get('rarity')} "
            f"level={raid.get('level')}"
        )
        print(
            f"    HP={raid.get('hp')}/"
            f"{raid.get('hp_max')} "
            f"({ratio_text})"
        )
        print(
            f"    碎片判斷="
            f"{prediction.get('fragment')}"
        )

        if prediction.get("normal_candidate"):
            print(
                f"    若一般渦："
                f"{prediction.get('normal_candidate')}"
            )

        if prediction.get("six_star_candidate"):
            print(
                f"    若6星渦："
                f"{prediction.get('six_star_candidate')}"
            )

        print(
            f"    參戰碼={raid.get('pass')} "
            f"已參戰={raid.get('already_joined')}"
        )
        print(
            f"    截止={format_time(raid.get('expires_at'))}"
        )

    print(
        f"\n[+] 完整快照："
        f"{OUTPUT_PATH.resolve()}"
    )
    print(
        "[+] 目前未知欄位會明確標記，"
        "不會硬猜碎片"
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
