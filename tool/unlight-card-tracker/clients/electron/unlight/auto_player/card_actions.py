from core.runtime.cdp.ul_sniffer import ULSniffer


CARD_SELECTION_CONFIRMATION_TIMEOUT_MS = 2500


def build_select_card_script(index, confirmation_timeout_ms):

    if isinstance(index, bool) or not isinstance(index, int) or index < 0:
        raise ValueError("index must be a non-negative integer")

    if (
        isinstance(confirmation_timeout_ms, bool)
        or not isinstance(confirmation_timeout_ms, int)
        or confirmation_timeout_ms <= 0
    ):
        raise ValueError("confirmation_timeout_ms must be a positive integer")

    return f"""
    (async () => {{

        const main =
            globalThis.game?.scene?.keys?.MainA;


        if (!main)
        {{
            return JSON.stringify({{
                ok:false,
                error:"MainA missing"
            }});
        }}


        const card =
            main.arr1?.[{index}]?.[0];


        if (!card)
        {{
            return JSON.stringify({{
                ok:false,
                error:"card missing",
                index:{index}
            }});
        }}


        if (!card.input?.enabled)
        {{
            return JSON.stringify({{
                ok:false,
                error:"card not selectable",
                index:{index}
            }});
        }}


        const listenerCount =
            typeof card.listenerCount === "function"
                ? card.listenerCount("pointerdown")
                : typeof card.listeners === "function"
                    ? card.listeners("pointerdown").length
                    : 0;


        if (listenerCount < 1)
        {{
            return JSON.stringify({{
                ok:false,
                error:"card pointerdown listener missing",
                index:{index}
            }});
        }}


        const player = main.PLAYER;
        const socket = main.socket;


        if (player !== "A" && player !== "B")
        {{
            return JSON.stringify({{
                ok:false,
                error:"local player side unavailable",
                index:{index}
            }});
        }}


        if (!socket || typeof socket.on !== "function")
        {{
            return JSON.stringify({{
                ok:false,
                error:"socket listener unavailable",
                index:{index}
            }});
        }}


        const confirmationEvent =
            `cardclicked${{player}}`;


        return await new Promise(resolve => {{

            let settled = false;
            let timer = null;


            const removeConfirmationListener = () => {{

                if (typeof socket.off === "function")
                {{
                    socket.off(
                        confirmationEvent,
                        onConfirmation
                    );
                }}
                else if (typeof socket.removeListener === "function")
                {{
                    socket.removeListener(
                        confirmationEvent,
                        onConfirmation
                    );
                }}

            }};


            const finish = result => {{

                if (settled)
                    return;

                settled = true;

                if (timer !== null)
                    clearTimeout(timer);

                removeConfirmationListener();

                resolve(JSON.stringify(result));

            }};


            const onConfirmation = (
                confirmedIndex,
                clicked,
                used
            ) => {{

                if (Number(confirmedIndex) !== {index})
                    return;

                if (clicked !== true)
                {{
                    finish({{
                        ok:false,
                        error:"card selection rejected",
                        index:{index},
                        confirmation:{{
                            event:confirmationEvent,
                            clicked:Boolean(clicked),
                            used:Boolean(used)
                        }}
                    }});
                    return;
                }}

                finish({{
                    ok:true,
                    index:{index},
                    confirmation:{{
                        event:confirmationEvent,
                        clicked:true,
                        used:Boolean(used)
                    }},
                    inputEnabledAfter:
                        Boolean(card.input?.enabled)
                }});

            }};


            socket.on(
                confirmationEvent,
                onConfirmation
            );


            timer = setTimeout(
                () => finish({{
                    ok:false,
                    error:"card selection confirmation timeout",
                    index:{index},
                    confirmationEvent:confirmationEvent
                }}),
                {confirmation_timeout_ms}
            );


            try
            {{
                const emitted =
                    card.emit("pointerdown");

                if (emitted !== true)
                {{
                    finish({{
                        ok:false,
                        error:"card pointerdown was not handled",
                        index:{index}
                    }});
                }}
            }}
            catch (error)
            {{
                finish({{
                    ok:false,
                    error:String(error),
                    index:{index}
                }});
            }}

        }});

    }})();
    """


class CardActions:


    def __init__(self, sniffer):

        self.sniffer = sniffer



    async def select_card(self, index):


        print(
            f"[ACTION] SELECT CARD {index}"
        )


        script = build_select_card_script(
            index,
            CARD_SELECTION_CONFIRMATION_TIMEOUT_MS
        )


        value,error = await self.sniffer.evaluate(
            script,
            await_promise=True
        )

        return value
