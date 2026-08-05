from ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT = 59222


CLICK_CHANGE_JS = r"""
(() => {

const move =
globalThis.game.scene.keys.MovePhaseA;

if(move && move.change){

    move.change.emit("pointerup");

    return JSON.stringify({
        ok:true
    });

}

return JSON.stringify({
    ok:false,
    error:"change not found"
});

})();
"""


READ_ALL_JS = r"""
(() => {


function dump(obj){

    if(!obj) return null;


    return {

        name:obj.name || null,

        type:obj.constructor?.name,

        texture:obj.texture?.key || null,

        frame:obj.frame?.name || null,

        x:obj.x,
        y:obj.y,

        visible:obj.visible,

        active:obj.active,


        input:
            obj.input ?
            {
                enabled:obj.input.enabled,
                draggable:obj.input.draggable
            }
            :
            null,


        children:
            obj.children?.list ?
            obj.children.list.length
            :
            0

    };

}



let result=[];


for(const scene of globalThis.game.scene.scenes){

    if(!scene.scene.isActive()){
        continue;
    }


    result.push({

        scene:scene.scene.key,


        children:

        scene.children?.list?.map(
            x=>dump(x)
        )
        ||
        []

    });

}


return JSON.stringify(result,null,2);


})();
"""


async def after_connect(sniffer):

    print("[+] connected")


    value,error = await sniffer.evaluate(
        CLICK_CHANGE_JS,
        await_promise=False
    )

    print(value)


    await asyncio.sleep(2)


    value,error = await sniffer.evaluate(
        READ_ALL_JS,
        await_promise=False
    )


    print(value)


    with open(
        "change_character_dump.json",
        "w",
        encoding="utf-8"
    ) as f:

        f.write(value)


    print(
        "[+] saved change_character_dump.json"
    )


    await sniffer.ws.close()



def main():

    sniffer=ULSniffer(
        port=CDP_PORT,
        verbose=False
    )

    sniffer.start(
        after_connect=after_connect
    )


if __name__=="__main__":
    main()