from ul_sniffer import ULSniffer
import asyncio
import json
import time
from pathlib import Path


CDP_PORT = 59222

OUTPUT = Path("card_action_record.jsonl")


READ_CARD_STATE_JS = r"""
(() => {


function scanObject(obj, name){

    if(!obj){
        return {
            exists:false
        };
    }


    let result={

        exists:true,

        type:
            obj.constructor?.name || null,

        keys:
            Object.keys(obj).slice(0,80)

    };


    return result;

}



let main =
game.scene.keys.MainA;


let result={};



/*
 MainA 基本狀態
*/

result.MainA = scanObject(
    main,
    "MainA"
);



if(main){


    result.main_keys =
        Object.keys(main).slice(0,150);


    result.state_info =
        main.state_info || null;


}



/*
 phase
*/

[
"MovePhaseA",
"AttackPhaseA",
"DefensePhaseA"
].forEach(name=>{


    let s =
        game.scene.keys[name];


    result[name]={

        exists:!!s,

        active:
            s ?
            s.scene.isActive()
            :
            false,


        keys:
            s ?
            Object.keys(s).slice(0,100)
            :
            []

    };


});



/*
 搜尋可能牌相關物件
*/

let found={};


if(main){


Object.keys(main).forEach(k=>{


    try{


        let v=main[k];


        if(
            k.toLowerCase().includes("card") ||
            k.toLowerCase().includes("hand") ||
            k.toLowerCase().includes("select") ||
            k.toLowerCase().includes("deck") ||
            k.toLowerCase().includes("ok")
        ){

            found[k]={
                type:
                    v?.constructor?.name || null,

                keys:
                    v && typeof v==="object"
                    ?
                    Object.keys(v).slice(0,30)
                    :
                    []
            };

        }


    }catch(e){}



});


}



result.keyword_objects=found;



return JSON.stringify(
    result,
    null,
    2
);


})();
"""


async def record_loop(sniffer):

    print("[+] recording card state")


    with OUTPUT.open(
        "a",
        encoding="utf-8"
    ) as f:


        while True:


            value,error = await sniffer.evaluate(
                READ_CARD_STATE_JS,
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
                    +
                    "\n"
                )

                f.flush()


            await asyncio.sleep(0.3)



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