from __future__ import annotations


def choose_attack_card(cards):

    candidates=[]

    for card in cards:

        if card.get("type") != "sword":
            continue

        candidates.append(card)


    if not candidates:
        return None


    return max(
        candidates,
        key=lambda x:x.get("value",0)
    )



def choose_defense_card(cards):

    candidates=[]

    for card in cards:

        if card.get("type") != "shield":
            continue

        candidates.append(card)


    if not candidates:
        return None


    return max(
        candidates,
        key=lambda x:x.get("value",0)
    )


def choose_move():
    """
    MOVE MVP:
    永遠往前
    """

    return "front"
