import pathlib,sys,json,shutil
from playwright.sync_api import sync_playwright,expect
out=pathlib.Path(sys.argv[1])
results=[]
with sync_playwright() as p:
    exe=next((shutil.which(x) for x in ['chromium','google-chrome','google-chrome-stable'] if shutil.which(x)),None)
    assert exe,'system Chromium required'
    b=p.chromium.launch(executable_path=exe,headless=True,args=['--no-sandbox','--disable-background-networking'])
    for w,h in [(360,740),(1280,850)]:
        c=b.new_context(viewport={'width':w,'height':h},reduced_motion='reduce',has_touch=w==360,is_mobile=w==360)
        page=c.new_page();errors=[];net=[];checks=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        page.on('request',lambda r:net.append(r.url) if r.url.startswith(('http:','https:')) else None)
        page.route('http**/*',lambda r:r.abort())
        def ck(v,n):
            assert v,n
            checks.append(n)
        page.set_content((out/'profile-admin.html').read_text(),wait_until='load')
        link=page.locator('#checkpoint2026-admin-entry')
        expect(link).to_be_visible();ck(link.count()==1,'single_admin_link')
        ck(link.get_attribute('href')=='/pages/admin/checkpoint2026_preview.php','clean_relative_href')
        ck(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no_overflow')
        ck(link.bounding_box()['y']<page.locator('#fixture-fortune').bounding_box()['y'],'above_fortune')
        ck(link.bounding_box()['height']>=44,'touch_target')
        link.focus();ck(page.evaluate('document.activeElement.id')=='checkpoint2026-admin-entry','keyboard_focus')
        page.screenshot(path=str(out/f'profile-admin-{w}.png'),full_page=True)
        page.set_content((out/'profile-member.html').read_text(),wait_until='load')
        ck(page.locator('#checkpoint2026-admin-entry').count()==0,'member_hidden')
        page.set_content((out/'authorized.html').read_text(),wait_until='load')
        ck(page.locator('.slide').count()==11,'exact_preview_story_count')
        page.locator('#start').click();expect(page.locator('#slide-1')).to_be_visible()
        ck(page.locator('#matches').inner_text()=='174','legacy_story')
        ck(not errors,'no_js_errors');ck(not net,'zero_external_http')
        results.append({'viewport':[w,h],'count':len(checks),'cases':checks,'status':'PASS'});c.close()
    b.close()
(out/'profile-browser-qa.json').write_text(json.dumps({'status':'PASS','scope':'rendered isolated Profile fragment, not complete live Profile','results':results},ensure_ascii=False,indent=2))
print('FT03BR2_PROFILE_BROWSER_PASS',[(r['viewport'],r['count']) for r in results])
