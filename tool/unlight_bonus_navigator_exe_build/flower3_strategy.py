# flower3_strategy.py


FLOWER3_NAME = "白色石楠3"


def calculate_flower3_ev(rows, current_rank):
    """
    計算目前位置後，花3的期望數量
    """

    future = rows[current_rank:]

    if not future:
        return 0

    flower3_count = sum(
        1
        for r in future
        if r["name"] == FLOWER3_NAME
    )

    return flower3_count / len(future)



def choose_flower3_target(rows, current_rank):

    future = rows[current_rank:]

    flower3_ev = calculate_flower3_ev(
        rows,
        current_rank
    )


    # 花3還值得等
    if flower3_ev < 1:

        for r in future:
            if r["name"] == FLOWER3_NAME:
                return r


    # 花3期望 >= 1
    # 改拿古代妙藥

    for r in future:
        if "古代妙藥" in r["name"]:
            return r


    # 最後 GEM

    for r in future:
        if r["name"] in ["寶石", "GEM"]:
            return r


    return None