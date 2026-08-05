from ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT = 59222


JS = r"""
(() => {

const main = game.scene.keys.MainA;

let result = [];

for(
 let i=0;
 i<(main.arr1?.length||0);
 i++
){

 let card = main.arr1[i];

 if(!Array.isArray(card))
    continue;


 let sprite = card[0];


 if(!sprite)
    continue;


 result.push({

    index:i,

    keys:Object.keys(sprite).slice(0,50),

    texture:
      sprite.texture?.key || null,


    frame:
      sprite.frame?.name || null,


    children:
      sprite.list ?
      sprite.list.length :
      null,


    data:
      sprite.data ?
      sprite.data :
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