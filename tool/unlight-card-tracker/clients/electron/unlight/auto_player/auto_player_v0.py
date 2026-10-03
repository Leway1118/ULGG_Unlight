import sys
from pathlib import Path

if __package__ in {None, ""}:
    sys.path.insert(0, str(Path(__file__).resolve().parents[4]))

from core.runtime.cdp.ul_sniffer import ULSniffer
import asyncio
import json

from clients.electron.unlight.auto_player.game_actions import GameActions

from clients.electron.unlight.auto_player.runtime_card_reader import read_cards

from clients.electron.unlight.auto_player.strategy import (
    choose_attack_card,
    choose_defense_card
)

from clients.electron.unlight.auto_player.card_actions import CardActions


CDP_PORT = 59222



PHASE_JS = r"""
(() => {

let result={};


[
"MovePhaseA",
"AttackPhaseA",
"DefensePhaseA"
]
.forEach(name=>{

    let s =
        game.scene.keys[name];


    result[name] =
        s ?
        s.scene.isActive()
        :
        false;

});


return JSON.stringify(result);

})();
"""



async def detect_phase(sniffer):

    value,error = await sniffer.evaluate(
        PHASE_JS,
        await_promise=False
    )


    if not value:
        return None


    data=json.loads(value)


    if data.get("MovePhaseA"):
        return "MOVE"


    if data.get("AttackPhaseA"):
        return "ATTACK"


    if data.get("DefensePhaseA"):
        return "DEFENSE"


    return None






class AutoPlayerState:


    def __init__(self):

        self.phase=None

        self.action_done=False

        self.card_selected=False

        self.ok_done=False



    def set_phase(self, phase):

        if phase != self.phase:


            print(
                f"[STATE] {self.phase} -> {phase}"
            )


            self.phase = phase

            self.action_done=False

            self.card_selected=False

            self.ok_done=False







async def wait_phase_change(
    sniffer,
    old_phase
):


    print(
        "[WAIT]",
        old_phase,
        "END"
    )


    last_phase=None


    while True:


        phase = await detect_phase(
            sniffer
        )


        if phase != last_phase:

            print(
                "[WAIT PHASE]",
                phase
            )

            last_phase=phase



        if phase and phase != old_phase:


            print(
                "[NEXT PHASE]",
                phase
            )


            return phase



        await asyncio.sleep(
            0.2
        )








async def execute_move(
    action,
    sniffer,
    state
):


    if state.action_done:

        return



    print(
        "[MOVE] CHOOSE FRONT"
    )


    result = await action.move_forward()


    print(result)


    state.action_done=True


    state.card_selected=False



    await wait_phase_change(
        sniffer,
        "MOVE"
    )








async def execute_attack(
    card_action,
    sniffer,
    state
):


    if state.action_done:

        return



    print(
        "[ATTACK] READ CARDS"
    )


    cards = await read_cards(
        sniffer
    )


    choice = choose_attack_card(
        cards
    )


    if not choice:


        print(
            "[ATTACK] NO SWORD"
        )

        return



    print(
        "[ATTACK] SELECT",
        choice
    )


    result = await card_action.select_card(
        choice["index"]
    )


    print(result)


    state.action_done=True

    state.card_selected=True








async def execute_defense(
    card_action,
    sniffer,
    state
):


    if state.action_done:

        return



    print(
        "[DEFENSE] READ CARDS"
    )


    cards = await read_cards(
        sniffer
    )


    choice = choose_defense_card(
        cards
    )


    if not choice:


        print(
            "[DEFENSE] NO SHIELD"
        )

        return



    print(
        "[DEFENSE] SELECT",
        choice
    )


    result = await card_action.select_card(
        choice["index"]
    )


    print(result)


    state.action_done=True

    state.card_selected=True








async def execute_ok(
    action,
    state
):


    if state.ok_done:

        return



    print(
        "[ACTION] CLICK OK"
    )


    result = await action.click_ok()


    print(result)


    state.ok_done=True








async def bot_loop(sniffer):


    action = GameActions(
        sniffer
    )


    card_action = CardActions(
        sniffer
    )


    state = AutoPlayerState()



    print(
        "[+] AUTO PLAYER v0.5 START"
    )



    while True:


        phase = await detect_phase(
            sniffer
        )



        if phase:

            state.set_phase(
                phase
            )



        if phase=="MOVE":


            await execute_move(
                action,
                sniffer,
                state
            )



        elif phase=="ATTACK":


            await execute_attack(
                card_action,
                sniffer,
                state
            )



        elif phase=="DEFENSE":


            await execute_defense(
                card_action,
                sniffer,
                state
            )



        if state.card_selected:


            await execute_ok(
                action,
                state
            )



        await asyncio.sleep(
            0.2
        )







async def after_connect(sniffer):

    await bot_loop(
        sniffer
    )







def main():


    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=False
    )


    sniffer.start(
        after_connect=after_connect
    )






if __name__=="__main__":

    main()
