from ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT = 59222


READ_MOVE_DETAIL_JS = r"""
(() => {

const move =
 globalThis.game?.scene?.keys?.MovePhaseA;

if(!move){
 return JSON.stringify({
   error:"MovePhaseA missing"
 });
}


function scanObject(obj, name){

 if(!obj) return null;

 return {
   name,
   keys:Object.keys(obj).slice(0,80),

   children:
     obj.children?.list?.map(x=>({
        name:x.name,
        type:x.constructor?.name,
        texture:x.texture?.key,
        frame:x.frame?.name,
        x:x.x,
        y:x.y,
        visible:x.visible,
        active:x.active,
        input:
          !!x.input
     })).slice(0,100)
 };


}


return JSON.stringify({

sceneKeys:Object.keys(move),

buttons:
 scanObject(move.button,"button"),

children:
 move.children?.list?.map(x=>({

    name:x.name,
    type:x.constructor?.name,
    texture:x.texture?.key,
    frame:x.frame?.name,
    x:x.x,
    y:x.y,
    visible:x.visible,
    input:!!x.input

 })).slice(0,100)


},null,2);


})();
"""


async def after_connect(sniffer):

    print("[+] connected")

    value,error = await sniffer.evaluate(
        READ_MOVE_DETAIL_JS,
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