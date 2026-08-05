from ul_sniffer import ULSniffer
import asyncio
import json
import time


CDP_PORT = 59222


CLICK_CHANGE_JS = r"""
(() => {

const move =
 globalThis.game?.scene?.keys?.MovePhaseA;

if(!move || !move.change){
    return JSON.stringify({
        ok:false,
        error:"change button missing"
    });
}


move.change.emit("pointerup");


return JSON.stringify({
    ok:true,
    action:"change_click"
});

})();
"""


READ_CHANGE_JS = r"""
(() => {


const change =
 globalThis.game?.scene?.keys?.Change_A;


if(!change){

 return JSON.stringify({

    exists:false,

    scenes:Object.keys(
      globalThis.game.scene.keys
    )

 });

}


function dump(obj,name){

if(!obj) return null;


return {

name:name,

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

    input:!!x.input

})).slice(0,100)

};


}


return JSON.stringify({

exists:true,

sceneKeys:Object.keys(change),

children:
change.children?.list?.map(x=>({

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

    # 打開換角
    value,error = await sniffer.evaluate(
        CLICK_CHANGE_JS,
        await_promise=False
    )

    print(value)


    await asyncio.sleep(1)


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