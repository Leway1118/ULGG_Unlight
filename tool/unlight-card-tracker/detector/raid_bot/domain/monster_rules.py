from dataclasses import dataclass


MONSTER_RULES = {

    # 龜
    "靈龜": "🐢",
    "赑屃": "🐢",
    "贔屭": "🐢",
    "玄帝": "🐢",

    # 蟲
    "啃食者": "🐛",
    "屠殺者": "🐛",
    "爬行者": "🐛",

    # 死獸
    "黑死獸": "🐶",
    "瘟疫": "🐶",

    # 魚
    "龍魚": "🐟",
    "龍鯇": "🐟",
    "龍鯉": "🐟",
}


@dataclass(frozen=True)
class MonsterInfo:

    name: str

    icon: str

    dead: bool = False



def get_monster_icon(
    boss_name: str,
) -> str:

    return MONSTER_RULES.get(
        boss_name,
        "❓",
    )



def get_monster_info(
    boss_name: str,
) -> MonsterInfo:

    return MonsterInfo(
        name=boss_name,
        icon=get_monster_icon(
            boss_name
        ),
        dead=False,
    )