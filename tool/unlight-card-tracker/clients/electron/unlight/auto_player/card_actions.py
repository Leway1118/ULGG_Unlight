import json

from core.runtime.cdp.ul_sniffer import ULSniffer


CARD_SELECTION_CONFIRMATION_TIMEOUT_MS = 2500
CARD_ROTATION_CONFIRMATION_TIMEOUT_MS = 2500


def build_card_runtime_probe_script(index):

    if isinstance(index, bool) or not isinstance(index, int) or index < 0:
        raise ValueError("index must be a non-negative integer")

    return f"""
    (() => {{
        const scenes = globalThis.game?.scene?.keys || {{}};
        const main = scenes.MainA || null;
        const row = main?.arr1?.[{index}] || null;
        const card = Array.isArray(row) ? row[0] : null;
        const active = name => {{
            try {{ return Boolean(scenes[name]?.scene?.isActive()); }}
            catch (_) {{ return false; }}
        }};
        const angle = value => {{
            const numeric = Number(value);
            return Number.isFinite(numeric)
                ? ((numeric % 360) + 360) % 360
                : null;
        }};
        const face = (textureIndex, valueIndex) => {{
            const texture = row?.[textureIndex] || null;
            const value = row?.[valueIndex] || null;
            return {{
                texture: texture?.texture?.key || null,
                value: Number.isFinite(Number(value?.text))
                    ? Number(value.text)
                    : null,
                angle: angle(texture?.angle),
                visible: Boolean(texture?.visible),
                active: Boolean(texture?.active)
            }};
        }};
        const listeners = (() => {{
            const event = card?._events?.pointerdown;
            const values = Array.isArray(event) ? event : event ? [event] : [];
            if (values.length > 0) {{
                return values.map(value => {{
                    const fn = value?.fn || value;
                    const context = value?.context;
                    return {{
                        function_name: typeof fn === "function" ? fn.name || null : null,
                        function_length: typeof fn === "function" ? fn.length : null,
                        function_source: typeof fn === "function" ? String(fn) : null,
                        context: context === card ? "card"
                            : context === main ? "MainA"
                            : context?.constructor?.name || null
                    }};
                }});
            }}
            return typeof card?.listeners === "function"
                ? card.listeners("pointerdown").map(fn => ({{
                    function_name: fn.name || null,
                    function_length: fn.length,
                    function_source: String(fn),
                    context: null
                }}))
                : [];
        }})();
        const front = face(1, 2);
        const reverse = face(3, 4);
        const uprightFace = front.angle === 0 ? "front"
            : reverse.angle === 0 ? "reverse" : null;
        return JSON.stringify({{
            index: {index},
            scenes: {{ MainA: active("MainA"), MovePhaseA: active("MovePhaseA") }},
            row_exists: Array.isArray(row),
            row_length: Array.isArray(row) ? row.length : 0,
            interaction: {{
                exists: Boolean(card),
                active: Boolean(card?.active),
                visible: Boolean(card?.visible),
                input_enabled: Boolean(card?.input?.enabled),
                angle: angle(card?.angle),
                pointerdown_listener_count: listeners.length
            }},
            faces: {{ front, reverse, upright: uprightFace }},
            listeners,
            dependencies: {{
                scene_time_add_event: typeof main?.time?.addEvent === "function",
                info_text: Boolean(main?.info_text),
                info_bg: Boolean(main?.info_bg),
                info_text_timeout_defined: main?.info_text_timeout !== undefined,
                info_text_timeout_type: main?.info_text_timeout === null
                    ? "null" : typeof main?.info_text_timeout,
                button: Boolean(main?.button?.[{index}])
            }}
        }});
    }})();
    """


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


        const scenes = globalThis.game?.scene?.keys || {{}};
        const active = name => {{
            try {{ return Boolean(scenes[name]?.scene?.isActive()); }}
            catch (_) {{ return false; }}
        }};


        const interactionPhase = active("MovePhaseA") ? "move"
            : active("AttackPhaseA") ? "attack"
            : active("DefensePhaseA") ? "defense"
            : null;


        if (active("Result") || active("ResultA") ||
            !active("MainA") || interactionPhase === null)
        {{
            return JSON.stringify({{
                ok:false,
                error:"card interaction scene not ready",
                index:{index},
                readiness:{{
                    MainA:active("MainA"),
                    phase:interactionPhase,
                    Result:active("Result") || active("ResultA")
                }}
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


        if (card.active !== true || card.visible !== true || !card.input?.enabled)
        {{
            return JSON.stringify({{
                ok:false,
                error:"card not selectable",
                index:{index}
            }});
        }}


        const missingDependencies = [];
        if (typeof main.time?.addEvent !== "function")
            missingDependencies.push("MainA.time.addEvent");
        if (!main.info_text)
            missingDependencies.push("MainA.info_text");
        if (!main.info_bg)
            missingDependencies.push("MainA.info_bg");
        if (!main.button?.[{index}])
            missingDependencies.push("MainA.button[{index}]");


        if (missingDependencies.length > 0)
        {{
            return JSON.stringify({{
                ok:false,
                error:"card interaction dependency missing",
                index:{index},
                missingDependencies
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

                if (typeof socket.off === "function")
                    socket.off("result", onBattleEnd);
                else if (typeof socket.removeListener === "function")
                    socket.removeListener("result", onBattleEnd);

                main.events?.off?.("postupdate", onPostUpdate);

            }};


            const battleEnded = () =>
                active("Result") || active("ResultA") ||
                (!active("MainA") &&
                    !active("MovePhaseA") &&
                    !active("AttackPhaseA") &&
                    !active("DefensePhaseA"));


            const onBattleEnd = () => finish({{
                ok:false,
                error:"battle ended during card selection",
                index:{index},
                ack:"result"
            }});


            const onPostUpdate = () => {{
                if (battleEnded())
                    onBattleEnd();
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
            socket.on("result", onBattleEnd);
            main.events?.on?.("postupdate", onPostUpdate);


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
                const hovered =
                    card.emit("pointerover");

                if (hovered !== true ||
                    !main.info_text_timeout ||
                    typeof main.info_text_timeout.remove !== "function")
                {{
                    finish({{
                        ok:false,
                        error:"card hover dependency not ready",
                        index:{index},
                        readiness:{{
                            pointeroverHandled:hovered === true,
                            infoTextTimeoutType:
                                main.info_text_timeout === null
                                    ? "null"
                                    : typeof main.info_text_timeout,
                            infoTextTimeoutRemove:
                                typeof main.info_text_timeout?.remove === "function"
                        }}
                    }});
                    return;
                }}

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
                    index:{index},
                    jsError:{{
                        name:error?.name || null,
                        message:error?.message || String(error),
                        stack:error?.stack || null
                    }}
                }});
            }}

        }});

    }})();
    """


def build_set_card_orientation_script(index, target_face, confirmation_timeout_ms):

    if isinstance(index, bool) or not isinstance(index, int) or index < 0:
        raise ValueError("index must be a non-negative integer")
    if target_face not in {"front", "reverse"}:
        raise ValueError("target_face must be front or reverse")
    if (
        isinstance(confirmation_timeout_ms, bool)
        or not isinstance(confirmation_timeout_ms, int)
        or confirmation_timeout_ms <= 0
    ):
        raise ValueError("confirmation_timeout_ms must be a positive integer")

    return f"""
    (async () => {{
        const scenes = globalThis.game?.scene?.keys || {{}};
        const main = scenes.MainA;
        const row = main?.arr1?.[{index}];
        const card = Array.isArray(row) ? row[0] : null;
        const button = main?.button?.[{index}];
        const active = name => {{
            try {{ return Boolean(scenes[name]?.scene?.isActive()); }}
            catch (_) {{ return false; }}
        }};
        const phase = active("MovePhaseA") ? "move"
            : active("AttackPhaseA") ? "attack"
            : active("DefensePhaseA") ? "defense" : null;
        if (!main || !card || !phase || active("Result") || active("ResultA"))
            return JSON.stringify({{ok:false,error:"card rotation scene not ready",index:{index}}});
        const normalized = value => {{
            const numeric = Number(value);
            return Number.isFinite(numeric) ? ((numeric % 360) + 360) % 360 : null;
        }};
        const upright = object => {{
            const angle = normalized(object?.angle);
            return angle !== null && Math.min(angle, 360-angle) < 0.001;
        }};
        const eventCard = row?.length === 1 && card?.texture?.key === "event_asset";
        const currentFace = () => eventCard
            ? (upright(card) ? "front" : normalized(card?.angle) === 180 ? "reverse" : null)
            : upright(row?.[1]) ? "front" : upright(row?.[3]) ? "reverse" : null;
        if (currentFace() === {json.dumps(target_face)})
            return JSON.stringify({{ok:true,action:"orientation_unchanged",index:{index},face:{json.dumps(target_face)},ack:"not_required"}});
        if (!button?.input?.enabled || button.listenerCount?.("pointerup") < 1)
            return JSON.stringify({{ok:false,error:"card not rotatable",index:{index}}});
        const player = main.PLAYER;
        const socket = main.socket;
        if (!['A','B'].includes(player) || typeof socket?.on !== "function")
            return JSON.stringify({{ok:false,error:"rotation ACK unavailable",index:{index}}});
        const ackEvent = `cardrotate${{player}}`;
        return await new Promise(resolve => {{
            let done=false, ackSeen=false, timer=null;
            const cleanup=()=>{{
                clearTimeout(timer);
                socket.off?.(ackEvent,onAck);
                socket.off?.("result",onResult);
                main.events?.off?.("postupdate",onPostUpdate);
            }};
            const finish=result=>{{if(done)return;done=true;cleanup();resolve(JSON.stringify(result));}};
            const check=()=>{{
                if (ackSeen && currentFace() === {json.dumps(target_face)})
                    finish({{ok:true,action:"set_card_orientation",index:{index},face:{json.dumps(target_face)},ack:ackEvent}});
            }};
            const onAck=(...args)=>{{
                const ackIndex=Number(args[0]);
                if (Number.isFinite(ackIndex) && ackIndex !== {index}) return;
                ackSeen=true;
                check();
            }};
            const onResult=()=>finish({{ok:false,error:"battle ended during card rotation",index:{index},ack:"result"}});
            const onPostUpdate=()=>{{
                if (active("Result") || active("ResultA")) onResult();
                else check();
            }};
            socket.on(ackEvent,onAck);
            socket.on("result",onResult);
            main.events?.on?.("postupdate",onPostUpdate);
            timer=setTimeout(()=>finish({{
                ok:false,error:"card rotation confirmation timeout",index:{index},
                targetFace:{json.dumps(target_face)},currentFace:currentFace(),ackEvent,ackSeen
            }}),{confirmation_timeout_ms});
            try {{
                button.emit("pointerover");
                button.emit("pointerdown");
                const emitted=button.emit("pointerup");
                if (emitted !== true)
                    finish({{ok:false,error:"card rotate pointerup was not handled",index:{index}}});
            }} catch(error) {{ finish({{ok:false,error:String(error),index:{index}}}); }}
        }});
    }})()
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

        if error:
            return json.dumps({
                "ok": False,
                "error": "CDP Runtime.evaluate failed",
                "index": index,
                "cdpError": str(error),
            })

        return value


    async def read_card_runtime(self, index):

        script = build_card_runtime_probe_script(index)
        value,error = await self.sniffer.evaluate(
            script,
            await_promise=False
        )
        if error:
            raise RuntimeError(str(error))
        return json.loads(value)


    async def set_orientation(self, index, target_face):

        print(f"[ACTION] ROTATE CARD {index} -> {target_face}")
        script = build_set_card_orientation_script(
            index,
            target_face,
            CARD_ROTATION_CONFIRMATION_TIMEOUT_MS,
        )
        value,error = await self.sniffer.evaluate(script, await_promise=True)
        if error:
            return json.dumps({
                "ok": False,
                "error": "CDP Runtime.evaluate failed",
                "index": index,
                "cdpError": str(error),
            })
        return value
