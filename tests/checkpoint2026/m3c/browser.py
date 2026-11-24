"""M3-C synthetic browser/HTTP client. All runtime traffic is Docker-internal."""
import json,sys,pathlib
from playwright.sync_api import sync_playwright,expect
MODE=sys.argv[1]
BASE="http://m3c-app:8080"
API="/api/checkpoint2026/"
PAGE="/pages/checkpoint2026/index.php"
out=pathlib.Path("/evidence")
cases=[]
def ck(test,name):
    if not test: raise AssertionError(name)
    cases.append(name)
def login(ctx,who):
    r=ctx.request.post(BASE+API+"_fixture_login.php",data={"who":who})
    ck(r.status==200 and r.json()["fixture"]==who,"LOGIN_"+who)
def get(ctx,endpoint):
    return ctx.request.get(BASE+API+endpoint)
def validate(r,access,expected_pvp,expected_cup):
    ck(r.status==200,"API_"+access+"_200")
    d=r.json()
    ck(d["access"]==access and d["readiness"]=="STAGING_ONLY","DTO_ACCESS_"+access)
    ck("no-store" in r.headers["cache-control"] and "noindex" in r.headers["x-robots-tag"],"CACHE_ROBOTS_"+access)
    p=d["payload"]
    ck(p["cup"]["verified_games"]==expected_cup and p["cup"]["personal_cup_status"]=="UNKNOWN","CUP_"+access)
    ck(not any(s in r.text() for s in ["7656119","SYNTH_A","SYNTH_OPP","SECRET REPORT","PRIVATE INTERNAL","steam_id","actor_id","opaque_scope","r3_reconciliation"]),"PRIVACY_"+access)
    if access=="PRIVATE":
        ck(p["pvp"]["verified_matches"]==expected_pvp and p["raid"]["status"]=="UNKNOWN","PVP_AND_RAID")
    else:
        ck(set(p)=={"cup"},"PUBLIC_PAYLOAD_ALLOWLIST")
    return d
def browser_check(browser,width,who,pvp,cup):
    ctx=browser.new_context(viewport={"width":width,"height":850},reduced_motion="reduce")
    login(ctx,who)
    page=ctx.new_page()
    errors=[];external=[]
    page.on("pageerror",lambda e:errors.append(str(e)))
    page.on("request",lambda r: external.append(r.url) if not r.url.startswith(BASE+"/") else None)
    page.route("**/*",lambda r:r.continue_() if r.request.url.startswith(BASE+"/") else r.abort())
    page.goto(BASE+PAGE,wait_until="domcontentloaded")
    expect(page.locator("#matches")).to_have_text(str(pvp) if pvp is not None else "UNKNOWN",timeout=10000)
    expect(page.locator("#cup-games")).to_have_text(str(cup) if cup is not None else "UNKNOWN",timeout=10000)
    if pvp is None:
        expect(page.locator("#pvp-quality")).to_have_text("UNKNOWN")
        expect(page.locator("#live-state")).to_contain_text("資料來源尚未驗證")
    ck(page.evaluate("document.documentElement.scrollWidth <= innerWidth"),"NO_OVERFLOW_"+str(width))
    ck(page.locator(".slide").count()==4,"FOUR_CARDS_"+str(width))
    page.locator("#next").click()
    ck(page.locator("#slide-1").is_visible(),"NEXT_"+str(width))
    page.keyboard.press("ArrowRight")
    ck(page.locator("#slide-2").is_visible(),"KEYBOARD_"+str(width))
    page.locator("#skip").click()
    ck(all(page.locator("#slide-"+str(i)).is_visible() for i in range(4)),"SUMMARY_"+str(width))
    ck(page.evaluate("getComputedStyle(document.getElementById('slide-0')).animationName === 'none'"),"REDUCED_MOTION_"+str(width))
    ck(not errors and not external,"NO_JS_ERROR_OR_EXTERNAL_"+str(width))
    page.screenshot(path=str(out/f"{MODE}-{who}-{width}.png"),full_page=True)
    ctx.close()
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,args=["--no-sandbox","--disable-background-networking"])
    try:
        a=browser.new_context();b=browser.new_context()
        if MODE=="disabled":
            for p in [API+"me.php",API+"public.php",PAGE]:
                ck(a.request.get(BASE+p).status==503,"DISABLED_"+p)
        else:
            unknown=MODE in ("missing","forged","history-conflict")
            rev=2 if MODE=="v2" else 1
            cup=None if unknown else rev+1
            pvp=None if unknown else rev+2
            ck(get(a,"me.php").status==401,"ANONYMOUS_401")
            pub=validate(get(a,"public.php"),"PUBLIC",None,cup)
            login(a,"A");login(b,"B")
            da=validate(get(a,"me.php"),"PRIVATE",pvp,cup)
            db=validate(get(b,"me.php"),"PRIVATE",None if unknown else 1,cup)
            ck(da["source_coverage_status"]==("UNKNOWN" if unknown else "VERIFIED"),"COVERAGE")
            if not unknown:
                ck(da["payload_sha256"]!=db["payload_sha256"],"CROSS_ACCOUNT_PAYLOAD_HASH")
                ck(da["snapshot_revision"]=="M3BR3-"+str(rev),"ACTIVE_PRIVATE_REVISION")
                ck(pub["snapshot_revision"]=="M3C-"+str(rev),"ACTIVE_PUBLIC_REVISION")
                prior=out/"v1-http.json"
                if MODE=="rollback":
                    old=json.loads(prior.read_text())
                    ck(old["hashes"]==[da["payload_sha256"],db["payload_sha256"],pub["payload_sha256"]],"ROLLBACK_EXACT_HTTP_PAYLOADS")
            for selector in ("player","username","steam_id","user_id","region","view_as","revision","scope","anything"):
                ck(get(a,"me.php?"+selector+"=B").status==403,"SELECTOR_"+selector)
            ck(get(a,"public.php?revision=2").status==403,"PUBLIC_REVISION_SELECTOR")
            ck(a.request.post(BASE+API+"me.php").status==405,"NO_API_WRITE")
            ck(a.request.post(BASE+API+"_fixture_login.php",data={"who":"A","user_id":13}).status==400,"MOCK_LOGIN_INJECTION")
            ck(a.request.post(BASE+API+"_fixture_login.php",data={"who":"B"},headers={"Origin":"http://evil.invalid"}).status==403,"CROSS_ORIGIN_LOGIN")
            ck(a.request.get(BASE+API+"me.php",headers={"Host":"evil.invalid"}).status==503,"HOST_GUARD")
            ck(a.request.get(BASE+"/tests/checkpoint2026/m3c/bootstrap.php").status==404,"SOURCE_NOT_EXPOSED")
            login(a,"logout");ck(get(a,"me.php").status==401,"LOGOUT")
            ck(get(b,"me.php").status==200,"INDEPENDENT_B_SESSION")
            browser_check(browser,360,"A",pvp,cup)
            browser_check(browser,1280,"B",None if unknown else 1,cup)
        report={"status":"PASS","mode":MODE,"cases":cases,"count":len(cases)}
        if MODE!="disabled":report["hashes"]=[da["payload_sha256"],db["payload_sha256"],pub["payload_sha256"]]
        (out/(MODE+"-http.json")).write_text(json.dumps(report,indent=2))
        print("M3C_BROWSER_"+MODE+"_PASS",len(cases),flush=True)
    finally:
        browser.close()
