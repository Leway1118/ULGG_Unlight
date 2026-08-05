from ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT = 59222


JS = r"""
(() => {

const main = game.scene.keys.MainA;

let result=[];


for(
 let i=0;
 i<(main.arr1?.length||0);
 i++
){

 let card = main.arr1[i];


 result.push({

    index:i,

    length:
        Array.isArray(card)
        ? card.length
        : "not_array",


    elements:
        Array.isArray(card)
        ?
        card.map((x,idx)=>({

            index:idx,

            type:
              x?.constructor?.name || typeof x,


            texture:
              x?.texture?.key || null,


            frame:
              x?.frame?.name ?? null,


            keys:
              x ?
              Object.keys(x).slice(0,20)
              :
              null

        }))
        :
        null

 });


}


return JSON.stringify(
 result,
 null,
 2
);


})();
"""


async def run(sniffer):

    value,error = await sniffer.evaluate(
        JS,
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