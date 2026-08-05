from ul_sniffer import ULSniffer
import asyncio


CDP_PORT = 59222


CLICK_FRONT_JS = r"""
(() => {

const move =
 globalThis.game?.scene?.keys?.MovePhaseA;


if(!move || !move.front){

 return JSON.stringify({
   ok:false,
   error:"front missing"
 });

}


move.front.emit("pointerdown");

setTimeout(()=>{

 move.front.emit("pointerup");

},100);


return JSON.stringify({
 ok:true,
 action:"front_click"
});


})();
"""


async def after_connect(sniffer):

    print("[+] connected")


    value,error = await sniffer.evaluate(
        CLICK_FRONT_JS,
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