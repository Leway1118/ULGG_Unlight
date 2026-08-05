from strategy import (
    choose_attack_card,
    choose_defense_card,
    choose_move
)



cards = [

    {
        "index":0,
        "type":"sword",
        "value":3,
        "selectable":True
    },

    {
        "index":1,
        "type":"sword",
        "value":7,
        "selectable":True
    },

    {
        "index":2,
        "type":"shield",
        "value":5,
        "selectable":True
    }

]


print(
    "[MOVE]",
    choose_move()
)


print(
    "[ATTACK]",
    choose_attack_card(cards)
)


print(
    "[DEFENSE]",
    choose_defense_card(cards)
)