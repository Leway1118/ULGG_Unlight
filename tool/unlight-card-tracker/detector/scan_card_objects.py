from ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT = 59222


SCAN_CARD_JS = r"""
(() => {


function summarize(obj,name){

    let result={
        name:name,
        type:
            obj?.constructor?.name || null,
        keys:[],
        sample:{}
    };


    try{

        result.keys =
            Object.keys(obj)
            .slice(0,80);


        result.keys.forEach(k=>{

            try{

                let v=obj[k];


                if(
                    typeof v==="string" ||
                    typeof v==="number" ||
                    typeof v==="boolean"
                ){

                    result.sample[k]=v;

                }

            }catch(e){}

        });


    }catch(e){}


    return result;

}



let output={};



let targets=[

    [
        "MainA",
        game.scene.keys.MainA
    ],

    [
        "AttackPhaseA",
        game.scene.keys.AttackPhaseA
    ],

    [
        "DefensePhaseA",
        game.scene.keys.DefensePhaseA
    ],

    [
        "MovePhaseA",
        game.scene.keys.MovePhaseA
    ]

];



targets.forEach(([name,obj])=>{


    if(!obj){
        return;
    }


    let found={};


    Object.keys(obj)
    .forEach(k=>{


        try{


            let v=obj[k];


            let type =
                v?.constructor?.name;


            /*
             找 Array
             */

            if(
                Array.isArray(v)
                &&
                v.length>0
            ){

                found[k]={
                    type:type,
                    length:v.length,
                    first:
                        summarize(
                            v[0],
                            k+"[0]"
                        )
                };

            }


            /*
             找可能牌名
             */

            else if(
                k.toLowerCase()
                .includes("card")
                ||
                k.toLowerCase()
                .includes("hand")
                ||
                k.toLowerCase()
                .includes("select")
            ){

                found[k]=
                    summarize(v,k);

            }


        }catch(e){}


    });


    output[name]=found;


});



return JSON.stringify(
    output,
    null,
    2
);


})();
"""


async def after_connect(sniffer):

    print("[+] connected")


    value,error = await sniffer.evaluate(
        SCAN_CARD_JS,
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