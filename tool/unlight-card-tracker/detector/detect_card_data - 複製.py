from ul_sniffer import ULSniffer
import json


CDP_PORT = 59222


READ_CARD_DATA_JS = r"""
(() => {


function dump(obj, depth=0){

    if(depth>3)
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

        return obj.map(
            x=>dump(x,depth+1)
        )
        .slice(0,10);

    }


    if(typeof obj==="object"){

        let out={};


        Object.keys(obj)
        .slice(0,80)
        .forEach(k=>{

            try{

                out[k]=dump(
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


if(!main){

 return JSON.stringify({
    error:"MainA missing"
 });

}



let result={};



[
"button",
"arr1",
"seclet"
]
.forEach(name=>{


    try{


        result[name]=dump(
            main[name]
        );


    }catch(e){

        result[name]=String(e);

    }


});



return JSON.stringify(
    result,
    null,
    2
);


})();
"""


async def after_connect(sniffer):

    print("[+] connected")


    value,error = await sniffer.evaluate(
        READ_CARD_DATA_JS,
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