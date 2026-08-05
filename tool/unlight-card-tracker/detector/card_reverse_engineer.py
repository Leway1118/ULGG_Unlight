from ul_sniffer import ULSniffer
import asyncio
import json
import copy


CDP_PORT = 59222


READ_CARD_TREE_JS = r"""
(() => {

function primitive(v){
    return (
        typeof v === "string" ||
        typeof v === "number" ||
        typeof v === "boolean"
    );
}


function scanArray(arr){

    if(!Array.isArray(arr))
        return null;


    return arr.map((v,i)=>{

        let item={
            index:i,
            type:
                v?.constructor?.name || typeof v
        };


        try{

            if(v && typeof v==="object"){

                item.keys=
                    Object.keys(v)
                    .slice(0,60);


                let sample={};


                Object.keys(v)
                .slice(0,60)
                .forEach(k=>{

                    try{

                        if(primitive(v[k])){
                            sample[k]=v[k];
                        }

                    }catch(e){}

                });


                item.sample=sample;

            }


        }catch(e){}


        return item;

    }).slice(0,30);

}



let main=game.scene.keys.MainA;


if(!main){
    return JSON.stringify({
        error:"MainA missing"
    });
}



let result={};


[
"button",
"arr1",
"seclet",
"cardempty"
]
.forEach(name=>{

    try{

        result[name]=scanArray(
            main[name]
        );

    }catch(e){

        result[name]=String(e);

    }

});



//
// 搜尋所有可能牌相關欄位
//

result.keyword={};


Object.keys(main)
.forEach(k=>{


    if(
        k.toLowerCase().includes("card")
        ||
        k.toLowerCase().includes("select")
        ||
        k.toLowerCase().includes("hand")
        ||
        k.toLowerCase().includes("button")
    ){

        try{

            let v=main[k];


            result.keyword[k]={
                type:
                    v?.constructor?.name,

                keys:
                    v && typeof v==="object"
                    ?
                    Object.keys(v).slice(0,40)
                    :
                    []
            };


        }catch(e){}


    }


});



return JSON.stringify(
    result,
    null,
    2
);


})();
"""


async def get_snapshot(sniffer):

    value,error = await sniffer.evaluate(
        READ_CARD_TREE_JS,
        await_promise=False
    )

    if value:
        return json.loads(value)

    return {}



def diff(a,b,path=""):

    changes=[]


    if type(a)!=type(b):
        changes.append({
            "path":path,
            "before":a,
            "after":b
        })
        return changes


    if isinstance(a,dict):

        keys=set(a.keys()) | set(b.keys())

        for k in keys:

            changes += diff(
                a.get(k),
                b.get(k),
                path+"."+k
            )


    elif isinstance(a,list):

        length=max(
            len(a),
            len(b)
        )

        for i in range(length):

            av=a[i] if i<len(a) else None
            bv=b[i] if i<len(b) else None

            changes += diff(
                av,
                bv,
                f"{path}[{i}]"
            )


    else:

        if a!=b:

            changes.append({
                "path":path,
                "before":a,
                "after":b
            })


    return changes



async def run(sniffer):

    print("[+] connected")

    print("")
    print("=== STEP 1 ===")
    print("請保持攻擊階段，不要選牌")
    input("完成後按 Enter...")


    before = await get_snapshot(
        sniffer
    )


    print("")
    print("=== STEP 2 ===")
    print("請點一張牌")
    input("點完後按 Enter...")


    after = await get_snapshot(
        sniffer
    )


    print("")
    print("=== DIFF ===")


    changes=diff(
        before,
        after
    )


    for c in changes[:100]:

        print(
            json.dumps(
                c,
                ensure_ascii=False,
                indent=2
            )
        )


    with open(
        "card_reverse_result.json",
        "w",
        encoding="utf-8"
    ) as f:

        json.dump(
            {
                "changes":changes,
                "before":before,
                "after":after
            },
            f,
            ensure_ascii=False,
            indent=2
        )


    print("")
    print("[+] saved card_reverse_result.json")



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