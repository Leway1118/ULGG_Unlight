from ul_sniffer import ULSniffer
import asyncio
import json
import time
from pathlib import Path


CDP_PORT = 59222

OUTPUT = Path("battle_state_record.jsonl")


READ_STATE_JS = r"""
(() => {

function safe(obj, keys){

    if(!obj) return null;

    let out={};

    keys.forEach(k=>{

        try{

            let v=obj[k];

            if(
                typeof v==="string" ||
                typeof v==="number" ||
                typeof v==="boolean"
            ){
                out[k]=v;
            }
            else if(v && typeof v==="object"){
                out[k]={
                    type:v.constructor?.name || "object",
                    keys:Object.keys(v).slice(0,20)
                };
            }

        }catch(e){}

    });

    return out;
}



let result={};



/*
 MainA
*/

let main =
game.scene.keys.MainA;


result.MainA = safe(
    main,
    [
        "state",
        "_state",
        "state_info",
        "phase",
        "turn"
    ]
);



/*
 Move
*/

let move =
game.scene.keys.MovePhaseA;


result.MovePhaseA={

    exists:!!move,

    active:
        move ?
        move.scene.isActive()
        :
        false,


    move_option:
        move?.move_option ?
        Object.keys(move.move_option)
        :
        null
};



/*
 Attack
*/

let attack =
game.scene.keys.AttackPhaseA;


result.AttackPhaseA={

    exists:!!attack,

    active:
        attack ?
        attack.scene.isActive()
        :
        false
};



/*
 Defense
*/

let defense =
game.scene.keys.DefensePhaseA;


result.DefensePhaseA={

    exists:!!defense,

    active:
        defense ?
        defense.scene.isActive()
        :
        false
};



/*
 Characters
*/

[
"chara1",
"chara2",
"chara3"
].forEach(name=>{


let c=main?.[name];


result[name]=safe(
    c,
    [
        "chara",
        "hp",
        "hp_max",
        "atk",
        "def",
        "state"
    ]
);


});



return JSON.stringify(result);


})();
"""


async def record_loop(sniffer):

    print("[+] recording")

    with OUTPUT.open(
        "a",
        encoding="utf-8"
    ) as f:


        while True:


            value,error = await sniffer.evaluate(
                READ_STATE_JS,
                await_promise=False
            )


            if value:

                row={

                    "time":
                        time.time(),

                    "data":
                        json.loads(value)

                }


                f.write(
                    json.dumps(
                        row,
                        ensure_ascii=False
                    )
                    + "\n"
                )

                f.flush()


            await asyncio.sleep(0.5)



async def after_connect(sniffer):

    print("[+] connected")

    await record_loop(sniffer)



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