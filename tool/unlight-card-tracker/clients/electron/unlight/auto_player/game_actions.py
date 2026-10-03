from core.runtime.cdp.ul_sniffer import ULSniffer
import asyncio
import json


class GameActions:

    def __init__(self, sniffer):
        self.sniffer = sniffer

    async def click_ok(self):

        js = r"""
        (() => {

            const main =
                game.scene.keys.MainA;


            const btn =
                main.ok ||
                main.friend_ok_btn ||
                main.btn_ok;


            if(!btn)
            {
                return JSON.stringify({
                    ok:false,
                    error:"OK button not found"
                });
            }


            btn.emit("pointerdown");

            btn.emit("pointerup");


            return JSON.stringify({
                ok:true,
                action:"click ok"
            });


        })()
        """


        value,error = await self.sniffer.evaluate(
            js,
            await_promise=False
        )


        return value

    async def emit_click(self, js_object):

        script = f"""
        (() => {{

            let obj = {js_object};

            if(!obj)
                return JSON.stringify({{
                    ok:false,
                    error:"object missing"
                }});

            try {{

                obj.emit("pointerdown");
                obj.emit("pointerup");

                return JSON.stringify({{
                    ok:true
                }});

            }} catch(e) {{

                return JSON.stringify({{
                    ok:false,
                    error:String(e)
                }});

            }}

        }})();
        """

        value,error = await self.sniffer.evaluate(
            script,
            await_promise=False
        )

        return value



    async def move_forward(self):

        print("[ACTION] MOVE FRONT")


        return await self.emit_click(
            "game.scene.keys.MovePhaseA.front"
        )



    async def click_ok(self):

        print("[ACTION] CLICK OK")


        return await self.emit_click(
            """
            game.scene.keys.MainA.ok ||
            game.scene.keys.MainA.friend_ok_btn ||
            game.scene.keys.MainA.btn_ok
            """
        )
