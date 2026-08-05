from ul_sniffer import ULSniffer
import asyncio
import json
import time


CDP_PORT = 59222


READ_CHANGE_JS = r"""
(() => {

const scene =
globalThis.game.scene.keys.Change_A;

if(!scene){
    return JSON.stringify({
        exists:false
    });
}


return JSON.stringify({

exists:true,

sceneKeys:Object.keys(scene),

children:
scene.children?.list?.map(x=>({

name:x.name,
type:x.constructor?.name,

texture:x.texture?.key,
frame:x.frame?.name,

x:x.x,
y:x.y,

visible:x.visible,

input:x.input ? {
 enabled:x.input.enabled
}:null,

children:
x.children?.list?.length || 0

})).slice(0,200)

},null,2);


})();
"""


async def after_connect(sniffer):

    print("[+] connected")

    await asyncio.sleep(2)

    value,error = await sniffer.evaluate(
        READ_CHANGE_JS,
        await_promise=False
    )

    print(value)

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