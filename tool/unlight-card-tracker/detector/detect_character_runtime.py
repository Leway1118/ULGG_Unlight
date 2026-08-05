from ul_sniffer import ULSniffer
import asyncio
import json


CDP_PORT = 59222





READ_ALL_JS = r"""
(() => {

let result={};


[
"MainA",
"Edit",
"Compo",
"Match",
"MatchBoot"
].forEach(name=>{


let s=game.scene.keys[name];

if(!s){
    result[name]=null;
    return;
}


result[name]={};


[
"chara1",
"chara2",
"chara3",
"_chara1",
"_chara2",
"_chara3"
].forEach(k=>{


let obj=s[k];


if(obj){

result[name][k]={

keys:Object.keys(obj),

main:obj.main,

filename:obj.filename,

charaIndex:obj.charaIndex,

input:obj.input

};

}

});


});


return JSON.stringify(result,null,2);


})();
"""


async def after_connect(sniffer):

    print("[+] connected")

    while True:

        value,error = await sniffer.evaluate(
            READ_ALL_JS,
            await_promise=False
        )

        print("SCAN")

        print(value[:500])


        if '"MainA"' in value:

            print("[+] MAIN FOUND")


            with open(
                "change_character_dump.json",
                "w",
                encoding="utf-8"
            ) as f:
                f.write(value)


            break


        await asyncio.sleep(5)


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