import sys
from pathlib import Path

if __package__ in {None, ""}:
    sys.path.insert(0, str(Path(__file__).resolve().parents[5]))

from core.runtime.cdp.ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT = 59222


TEST_OK_JS = r"""
(() => {

let main = game.scene.keys.MainA;

let result = {};

[
"ok",
"friend_ok_btn",
"btn_ok"
].forEach(k=>{

    let obj = main[k];

    result[k] = {
        exists: !!obj,
        type: obj?.constructor?.name || null,
        hasEmit: !!obj?.emit,
        hasInput: !!obj?.input
    };

});


return JSON.stringify(result,null,2);

})();
"""


CLICK_OK_JS = r"""
(() => {

let main = game.scene.keys.MainA;


let target =
    main.ok ||
    main.friend_ok_btn ||
    main.btn_ok;


if(!target){
    return JSON.stringify({
        ok:false,
        error:"no ok button"
    });
}


try{

    target.emit("pointerdown");
    target.emit("pointerup");


    return JSON.stringify({
        ok:true,
        action:"emit click"
    });


}catch(e){

    return JSON.stringify({
        ok:false,
        error:String(e)
    });

}


})();
"""


async def run(sniffer):

    print("[+] connected")

    value,error = await sniffer.evaluate(
        TEST_OK_JS,
        await_promise=False
    )

    print(value)


    input("按 Enter 測試 OK...")


    value,error = await sniffer.evaluate(
        CLICK_OK_JS,
        await_promise=False
    )

    print(value)



async def after_connect(sniffer):
    await run(sniffer)


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
