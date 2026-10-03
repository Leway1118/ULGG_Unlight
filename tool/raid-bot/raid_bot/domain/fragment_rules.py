from __future__ import annotations


MAP_FRAGMENT_COLOR = {

    # 依遊戲 map id 對應
    # 需要依你 Reader 實際 map id 校正

    1: "🟡",
    2: "🟢",
    3: "🔵",
    4: "🔴",
    5: "🟣",
    10: "🟡",

}


def get_fragment_icon(
    map_id: int | None,
) -> str:

    if map_id is None:
        return "⚪"


    return MAP_FRAGMENT_COLOR.get(
        map_id,
        "⚪",
    )