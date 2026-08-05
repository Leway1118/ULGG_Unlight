from ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT=59222


JS=r"""
(() => {

const main=game.scene.keys.MainA;

let result=[];


for(
 let i=0;
 i<Math.min(main.arr1.length,6);
 i++
){

 let card=main.arr1[i];


 if(!Array.isArray(card))
    continue;


 let num=card[2];


 result.push({

    card:i,

    keys:
      num ?
      Object.keys(num)
      :
      null,


    text:
      num?.text || null,


    value:
      num?.value || null,


    frame:
      num?.frame?.name || null,


    texture:
      num?.texture?.key || null,


    children:
      num?.list?.length || null

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

    value,error=await sniffer.evaluate(
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