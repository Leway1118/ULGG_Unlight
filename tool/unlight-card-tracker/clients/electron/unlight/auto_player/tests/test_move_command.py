import sys
from pathlib import Path

if __package__ in {None, ""}:
    sys.path.insert(0, str(Path(__file__).resolve().parents[5]))

from core.runtime.cdp.ul_sniffer import ULSniffer
import asyncio


CDP_PORT = 59222


READ_MOVE_COMMAND_JS = r"""
(() => {

const move =
 globalThis.game?.scene?.keys?.MovePhaseA;


if(!move){
 return JSON.stringify({
   error:"MovePhaseA missing"
 });
}


let result={};


[
"back",
"stay",
"change",
"front"
].forEach(k=>{

let obj = move[k];


result[k]={

    exists:!!obj,

    type:
        obj?.constructor?.name || null,

    keys:
        obj ? Object.keys(obj).slice(0,50) : [],

    hasEmit:
        typeof obj?.emit==="function",

    hasOn:
        typeof obj?.on==="function",

    input:
        obj?.input ?
        {
            enabled:obj.input.enabled
        }
        :
        null
};

});


return JSON.stringify(result,null,2);


})();
"""


async def after_connect(sniffer):

    print("[+] connected")


    value,error = await sniffer.evaluate(
        READ_MOVE_COMMAND_JS,
        await_promise=False
    )


    print(value)


    await sniffer.ws.close()



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
