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

function dump(obj,name){

if(!obj) return null;

return {

name:name,

keys:Object.keys(obj).slice(0,50),

x:obj.x,
y:obj.y,

visible:obj.visible,
active:obj.active,

texture:obj.texture?.key,
frame:obj.frame?.name,

input: obj.input ? {
 enabled:obj.input.enabled
 }:null,

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

})).slice(0,30)

};

}


const scene =
globalThis.game?.scene?.keys?.Change_A;


return JSON.stringify({

chara1:dump(scene.chara1,"chara1"),
chara2:dump(scene.chara2,"chara2"),
chara3:dump(scene.chara3,"chara3"),

_chara1:dump(scene._chara1,"_chara1"),
_chara2:dump(scene._chara2,"_chara2"),
_chara3:dump(scene._chara3,"_chara3")

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