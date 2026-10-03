import sys
from pathlib import Path

if __package__ in {None, ""}:
    sys.path.insert(0, str(Path(__file__).resolve().parents[4]))

from core.runtime.cdp.ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT = 59222


READ_PHASE_JS = r"""
(() => {

const scenes = game.scene.keys;


let result = {
    MovePhaseA: !!scenes.MovePhaseA,
    AttackPhaseA: !!scenes.AttackPhaseA,
    DefensePhaseA: !!scenes.DefensePhaseA,
    Result: !!scenes.Result
};


let phase="UNKNOWN";


if(result.MovePhaseA){
    phase="MOVE";
}
else if(result.AttackPhaseA){
    phase="ATTACK";
}
else if(result.DefensePhaseA){
    phase="DEFENSE";
}
else if(result.Result){
    phase="RESULT";
}


return JSON.stringify({
    phase:phase,
    scenes:result
},null,2);


})();
"""


async def after_connect(sniffer):

    print("[+] connected")


    value,error = await sniffer.evaluate(
        READ_PHASE_JS,
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
