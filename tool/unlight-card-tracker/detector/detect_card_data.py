from ul_sniffer import ULSniffer
import json


CDP_PORT = 59222


READ_CARD_DATA_JS = r"""
(() => {

let main = game.scene.keys.MainA;

let result=[];


main.card2.forEach((card,i)=>{

    let obj={
        index:i,
        type:typeof card,
        keys:[],
        sample:{}
    };


    try{

        obj.keys=Object.keys(card);


        obj.sample={

            texture:
                card.texture?.key,

            frame:
                card.frame?.name,

            x:
                card.x,

            y:
                card.y,

            visible:
                card.visible,

            input:
                !!card.input

        };


    }catch(e){}


    result.push(obj);

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