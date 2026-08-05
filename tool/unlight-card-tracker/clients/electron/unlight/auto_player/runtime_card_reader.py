import json


TYPE_MAP = {

    "acswd": "sword",

    "acshi": "shield",

    "acbow": "gun",

    "acmov": "move",

    "acspe": "special"

}



READ_CARDS_JS = r"""
(() => {

const main = game.scene.keys.MainA;

let cards=[];


for(
 let i=0;
 i<(main.arr1?.length||0);
 i++
){

 let card = main.arr1[i];


 if(!Array.isArray(card))
    continue;


 let frame = card[0];

 let icon = card[1];

 let value = card[2];


 if(!frame)
    continue;



 cards.push({

    index:i,


    selectable:
        Boolean(
            card[0]?.input?.enabled ||
            card[1]?.input?.enabled ||
            card[2]?.input?.enabled
        ),


    texture:
        icon?.texture?.key || null,


    value:
        Number(value?.text || 0)

 });


}


return JSON.stringify(cards);


})();
"""



async def read_cards(sniffer):


    result,error = await sniffer.evaluate(
        READ_CARDS_JS,
        await_promise=False
    )


    if not result:
        return []


    raw=json.loads(result)


    cards=[]


    for c in raw:


        cards.append({

            "index":
                c["index"],


            "type":
                TYPE_MAP.get(
                    c["texture"]
                ),


            "value":
                c["value"],


            "selectable":
                c["selectable"]

        })


    return cards