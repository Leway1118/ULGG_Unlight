from __future__ import annotations

from .models import RaidEntry


# =========================
# 地圖
# =========================

MAP_NAMES: dict[int, str] = {
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


def map_name(
    map_id: int | None,
) -> str:
    """
    地圖 ID 轉中文
    """

    if map_id is None:
        return "未知地圖"

    return MAP_NAMES.get(
        map_id,
        f"未知地圖({map_id})",
    )


# =========================
# 星數
# =========================

def star_text(
    rarity: int | None,
) -> str:
    """
    rarity → UI 顯示

    目前依遊戲欄位命名：
    rarity 對應黃色星數
    """

    if rarity is None:
        return "?"

    return "★" * rarity


# =========================
# 探測機等級
# =========================

def detector_tier(
    member_limit: int | None,
) -> str:
    """
    依目前已知人數上限推測探測機
    """

    return {
        80: "I",
        100: "II/III",
        120: "IV",
    }.get(
        member_limit,
        "未知",
    )


# =========================
# Boss 分類
# =========================

BOSS_FAMILIES: dict[str, str] = {

    # 死獸
    "黑死獸": "死獸",
    "瘟疫": "死獸",

    # 蟲
    "屠殺者": "蟲",
    "爬行者": "蟲",

    # 海鮮
    "誘引之者": "海鮮",
    "深奧之者": "海鮮",

    # 烏龜
    "靈龜": "烏龜",
    "玄帝": "烏龜",

    # 魚
    "龍鯰": "魚",
    "龍鯉": "魚",
}


def boss_family(
    raid: RaidEntry,
) -> str:
    """
    Boss 家族分類

    未知不猜
    """

    name = raid.boss_name or ""

    for keyword, family in BOSS_FAMILIES.items():

        if keyword in name:
            return family

    return "未分類"


# =========================
# 顯示輔助
# =========================

def raid_title(
    raid: RaidEntry,
) -> str:
    """
    UI 標題
    """

    boss = raid.boss_name or "未知Boss"

    star = star_text(
        raid.rarity
    )

    return f"{star} {boss}"