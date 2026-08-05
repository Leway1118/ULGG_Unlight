from ul_sniffer import ULSniffer
import asyncio
from ul_sniffer import ULSniffer

from strategy import (
    choose_attack_card,
    choose_defense_card
)

from runtime_card_reader import read_cards

from strategy import (
    choose_attack_card,
    choose_defense_card
)


CDP_PORT = 59222









async def run(sniffer):


    print("[+] READ LIVE CARDS")


    cards = await read_cards(
        sniffer
    )


    print(cards)


    print()


    print("[ATTACK CHOICE]")


    print(
        choose_attack_card(cards)
    )


    print()


    print("[DEFENSE CHOICE]")


    print(
        choose_defense_card(cards)
    )


    print()


    print(
        "[DEFENSE CHOICE]"
    )

    print(
        choose_defense_card(cards)
    )




async def after_connect(sniffer):

    await run(
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