from dataclasses import dataclass


MONSTER_RULES = {
    # 死獸
    "mc1003_02": "🐶",
    "mc1003_03": "🐶",

    # 妖精
    "mc1004_01": "<:A16:1315893421335908433>",
    "mc1004_02": "<:A16:1315893421335908433>",
    "mc1004_03": "<:A16:1315893421335908433>",
    # 蟲
    "mc1006_01": "🐛",
    "mc1006_02": "🐛",
    "mc1006_03": "🐛",

    # 龜
    "mc1008_01": "🐢",
    "mc1008_02": "🐢",
    "mc1008_03": "🐢",

    # 魚
    "mc1012_01": "🐟",
    "mc1012_02": "🐟",
    "mc1012_03": "🐟",

    # 章魚
    "mc1007_01": "🐙",
    "mc1007_02": "🐙",
    "mc1007_03": "🐙",
}


MONSTER_SHORT_NAMES = {
    "mc1003_02": "狗",
    "mc1003_03": "狗",
    "mc1006_01": "蟲",
    "mc1006_02": "蟲",
    "mc1006_03": "蟲",
    "mc1007_01": "海",
    "mc1007_02": "海",
    "mc1007_03": "海",
    "mc1008_01": "龜",
    "mc1008_02": "龜",
    "mc1008_03": "龜",
    "mc1012_01": "魚",
    "mc1012_02": "魚",
    "mc1012_03": "魚",

    # 妖精
    "mc1004_01": "妖",
    "mc1004_02": "妖",
    "mc1004_03": "妖",
}


# RAID_BOSS_ICON_FALLBACK_STAGE_PLACEHOLDER_V2_3
# Public raid_support_list no longer reliably exposes monster_code after the
# 2026-09-23 protocol change.  Preserve the historical compact Discord display
# by falling back to the authoritative boss display name.
BOSS_NAME_ICONS = {
    # 死獸
    "赤死獸": "🐶",
    "黑死獸": "🐶",
    "瘟疫": "🐶",
    # 蟲
    "啃食者": "🐛",
    "屠殺者": "🐛",
    "爬行者": "🐛",
    # 海／章魚
    "深沉之者": "🐙",
    "誘引之者": "🐙",
    "深奧之者": "🐙",
    "深奥之者": "🐙",
    # 龜
    "赑屃": "🐢",
    "贔屭": "🐢",
    "贔屓": "🐢",
    "靈龜": "🐢",
    "玄帝": "🐢",
    # 魚
    "龍魚": "🐟",
    "龙鱼": "🐟",
    "龍鯰": "🐟",
    "龙鲶": "🐟",
    "龍鯇": "🐟",
    "龍鯉": "🐟",
    "龙鲤": "🐟",
}

BOSS_NAME_SHORT_NAMES = {
    "赤死獸": "狗",
    "黑死獸": "狗",
    "瘟疫": "狗",
    "啃食者": "蟲",
    "屠殺者": "蟲",
    "爬行者": "蟲",
    "深沉之者": "海",
    "誘引之者": "海",
    "深奧之者": "海",
    "深奥之者": "海",
    "赑屃": "龜",
    "贔屭": "龜",
    "贔屓": "龜",
    "靈龜": "龜",
    "玄帝": "龜",
    "龍魚": "魚",
    "龙鱼": "魚",
    "龍鯰": "魚",
    "龙鲶": "魚",
    "龍鯇": "魚",
    "龍鯉": "魚",
    "龙鲤": "魚",
}

@dataclass(frozen=True)
class MonsterInfo:

    name: str

    icon: str

    dead: bool = False



def get_monster_icon(
    monster_code: str | None,
    boss_name: str | None = None,
) -> str:
    icon = MONSTER_RULES.get(monster_code)
    if icon is not None:
        return icon
    if isinstance(boss_name, str):
        return BOSS_NAME_ICONS.get(boss_name, "❓")
    return "❓"


def get_monster_short_name(
    monster_code: str | None,
    boss_name: str | None = None,
) -> str | None:
    short_name = MONSTER_SHORT_NAMES.get(monster_code)
    if short_name is not None:
        return short_name
    if isinstance(boss_name, str):
        return BOSS_NAME_SHORT_NAMES.get(boss_name)
    return None



def get_monster_info(
    monster_code: str | None,
) -> MonsterInfo:

    return MonsterInfo(
        name=monster_code or "",
        icon=get_monster_icon(
            monster_code
        ),
        dead=False,
    )
