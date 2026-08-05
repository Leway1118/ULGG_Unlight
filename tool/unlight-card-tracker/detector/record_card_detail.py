from ul_sniffer import ULSniffer
import asyncio
import json
import time
from pathlib import Path


CDP_PORT = 59222

OUTPUT = Path("card_detail_record.jsonl")


READ_CARD_DETAIL_JS = r"""
(() => {


function deepSafe(obj, depth=0){

    if(depth>2)
        return "[depth]";


    if(obj===null)
        return null;


    if(
        typeof obj==="string" ||
        typeof obj==="number" ||
        typeof obj==="boolean"
    ){
        return obj;
    }


    if(Array.isArray(obj)){

        return obj.slice(0,10)
            .map(x=>deepSafe(x,depth+1));

    }


    if(typeof obj==="object"){

        let out={};

        Object.keys(obj)
        .slice(0,50)
        .forEach(k=>{

            try{

                out[k]=deepSafe(
                    obj[k],
                    depth+1
                );

            }catch(e){}

        });


        return out;
    }


    return typeof obj;

}



let main =
game.scene.keys.MainA;


let result={};


if(!main){

 return JSON.stringify({
    error:"MainA missing"
 });

}



/*
 手牌
*/

result.card2 =
deepSafe(
    main.card2
);



/*
 其他可能牌區
*/

[
"deck",
"deck_enemy",
"cardLeft",
"card2Left",
"currentDeckLeft"
]
.forEach(k=>{


    try{

        result[k]=deepSafe(
            main[k]
        );

    }catch(e){}


});



/*
 OK相關
*/

[
"ok",
"friend_ok_btn",
"btn_ok"
]
.forEach(k=>{


    let v=main[k];


    result[k]=v ?
    {
        type:v.constructor?.name,
        keys:Object.keys(v).slice(0,30)
    }
    :
    null;


});



return JSON.stringify(
    result,
    null,
    2
);


})();
"""


async def record_loop(sniffer):

    print("[+] recording card detail")


    with OUTPUT.open(
        "a",
        encoding="utf-8"
    ) as f:


        while True:


            value,error = await sniffer.evaluate(
                READ_CARD_DETAIL_JS,
                await_promise=False
            )


            if value:

                row={
                    "time":time.time(),
                    "data":json.loads(value)
                }


                f.write(
                    json.dumps(
                        row,
                        ensure_ascii=False
                    )
                    + "\n"
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