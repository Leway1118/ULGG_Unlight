import pathlib,json,sys,hashlib,urllib.request,concurrent.futures,shutil,re
from playwright.sync_api import sync_playwright,expect
root=pathlib.Path(__file__).resolve().parent
out=pathlib.Path(sys.argv[1]);out.mkdir(parents=True,exist_ok=True)
manifest=json.loads((root/'assets.json').read_text())
html=(root/'preview.html').read_bytes()
origin=manifest['origin']
assert origin=='https://ulgg.online'
assets={}
for card in manifest['cards']:
    for key in ('card','stand'):
        a=card[key]
        if a:
            assert re.fullmatch(r'(?:[A-Za-z0-9_]+/)?[A-Za-z0-9_]+\.png',a['path'])
            assets['/assets/uploads/'+a['path']]=a
assert len(assets)==46
def fetch(item):
    path,a=item
    # Public image catalog only, no authentication or user data; exact bytes required.
    request=urllib.request.Request(origin+path,headers={'User-Agent':'ULGG-Checkpoint-Asset-QA/1.0','Accept':'image/*','Accept-Encoding':'identity'})
    with urllib.request.urlopen(request,timeout=12) as response:
        assert response.geturl()==origin+path,'unexpected asset redirect'
        body=response.read(a['bytes']+1)
    assert len(body)==a['bytes'] and hashlib.sha256(body).hexdigest()==a['sha256'],path+' hash mismatch'
    assert (a['mime']=='image/png' and body.startswith(b'\x89PNG\r\n\x1a\n')) or (a['mime']=='image/jpeg' and body.startswith(b'\xff\xd8\xff')),path+' MIME mismatch'
    return path,body
# Separate public transport verification from offline browser route replay.
failures=[];payloads={}
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    future={pool.submit(fetch,item):item[0] for item in assets.items()}
    for f in concurrent.futures.as_completed(future):
        try:
            path,b=f.result();payloads[path]=b
        except Exception as e:failures.append({'path':future[f],'error':str(e)})
(out/'asset-qa.json').write_text(json.dumps({'status':'PASS' if not failures else 'FAIL','verified':len(payloads),'required':len(assets),'failures':failures},ensure_ascii=False,indent=2))
print('FT04V_PUBLIC_ASSET_BYTES',len(payloads),'/',len(assets),flush=True)
if failures: print(json.dumps(failures[:6],ensure_ascii=False),flush=True)
# Even if CDN blocks a file, test fallback separately. Never report actual image QA PASS in that case.
results=[]
with sync_playwright() as p:
    exe=next((shutil.which(x) for x in ['chromium','google-chrome','google-chrome-stable'] if shutil.which(x)),None)
    assert exe
    b=p.chromium.launch(executable_path=exe,headless=True,args=['--no-sandbox','--disable-background-networking'])
    for w,h in [(360,740),(1280,850)]:
        ctx=b.new_context(viewport={'width':w,'height':h},reduced_motion='reduce',accept_downloads=True,has_touch=w==360,is_mobile=w==360)
        page=ctx.new_page();checks=[];errors=[];unapproved=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        entry=origin+'/preview/ft04v-test-only'
        def route(r):
            u=r.request.url
            if u==entry:r.fulfill(status=200,content_type='text/html; charset=utf-8',body=html);return
            path=u[len(origin):] if u.startswith(origin+'/') else ''
            if path in assets:
                if path in payloads:r.fulfill(status=200,content_type=assets[path]['mime'],body=payloads[path])
                else:r.fulfill(status=404,body='')
                return
            if u.startswith('blob:'):r.abort();return
            unapproved.append(u);r.abort()
        page.route('**/*',route)
        def ck(x,n):
            assert x,n
            checks.append(n)
        def txt(id):return page.locator('#'+id).inner_text()
        def step(id):
            page.locator('#progress button[data-slide="'+str(id)+'"]').click()
            expect(page.locator('#slide-'+str(id))).to_be_visible()
            ck(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no_overflow_'+str(id))
        def scope(i):return page.locator('#slide-'+str(i)).get_attribute('data-scope')
        def scene(name):
            page.locator('#scenario').select_option(name)
            expect(page.locator('#slide-0')).to_be_visible()
        def visible_images(id):
            loc=page.locator('#'+id+' img')
            if not failures:
                ck(loc.count()>0 and loc.evaluate_all('(imgs)=>imgs.every(i=>i.complete&&i.naturalWidth>0)'),'verified_image_decode_'+id)
        def shot(name):
            page.wait_for_timeout(250)
            page.screenshot(path=str(out/f'{name}-{w}.png'),full_page=True)
        page.goto(entry,wait_until='networkidle')
        ck(page.locator('.slide').count()==13,'13_chapters')
        ck(page.locator('#progress button').evaluate_all("(a)=>a.map(x=>+x.dataset.slide)")==[0,1,2,3,11,12,4,5,6,7,9,8,10],'story_order')
        ck('UNLIGHT' in page.locator('h1').inner_text(),'unlight_cover')
        ck(page.locator('#cover-art img').count()==3,'cover_stands')
        visible_images('cover-art')
        ck('未經確認的資料不計為零' not in html.decode() and '依現有 DTO' not in html.decode() and '暗房檔案 · 年度探索' not in html.decode(),'removed_redundant_copy')
        step(1);ck(txt('matches')=='174' and txt('rate')=='56.3%' and txt('battle-months')=='11 個月','legacy_battle')
        ck(page.locator('#battle-bar span').count()==3,'battle_result_visual')
        step(2);ck('古魯瓦爾多 L2' in txt('guess-options') and '角色 #' not in txt('guess-options'),'named_guess')
        visible_images('guess-options')
        page.locator('#guess-options button[data-id="22"]').click();page.locator('#reveal').click()
        ck(txt('guess-feedback')=='猜對了！','guess_reveal')
        ck(page.locator('#hero-cards .cardart').count()==4,'core_top4')
        ck('古魯瓦爾多 L2' in txt('hero-note'),'hero_name')
        page.wait_for_timeout(200);visible_images('hero-cards');shot('hero')
        step(3);ck(page.locator('#team-cards .team-group').count()==2 and page.locator('#team-cards .cardart').count()==6,'team_top2')
        page.wait_for_timeout(200);visible_images('team-cards');shot('teams')
        page.locator('#team-best').click();ck('76.4%' in txt('team-matches'),'best_team')
        page.locator('#next').click();expect(page.locator('#slide-11')).to_be_visible()
        ck(page.locator('#rivals .rival').count()==3 and scope(11)=='PERSONAL','rivals_top3')
        ck('18 場' in txt('rivals') and page.locator('#rivals .result-bar').count()==3,'rivals_counts_bars');shot('rivals')
        page.locator('#next').click();expect(page.locator('#slide-12')).to_be_visible()
        ck(scope(12)=='PERSONAL' and '魔女山谷' in txt('stage-name'),'battlefield')
        ck(float(txt('avg-cost'))>=50 and float(txt('avg-cost'))<=90,'cost_range')
        ck(sum(int(x.split(' ')[0]) for x in page.locator('#stage-list .stage-row strong').all_inner_texts())==174,'stage_counts_reconcile')
        shot('battlefield')
        step(4);ck(page.locator('#peak-options').count()==0,'no_duplicate_month_quiz')
        page.locator('#months button').nth(6).click();ck('24' in txt('month-title'),'month_exploration')
        step(5);ck(txt('concentration')=='43.1%' and txt('hero-rate')=='74.7%','highlights_preserved')
        ck('艾依查庫' in page.locator('#compare-team option').all_inner_texts()[1],'named_team_select')
        step(6)
        for _ in range(3):page.locator('#quiz button').nth(1).click()
        ck(txt('persona-name')=='破陣先鋒','persona')
        step(7);ck(txt('cup-games')=='3' and scope(7)=='PUBLIC','cup')
        step(9);ck(txt('fortune-count')=='33' and txt('fortune-five')=='6' and txt('fortune-streak')=='3','fortune_original_metrics')
        ck(page.locator('#fortune-radar polygon').count()==5 and page.locator('#fortune-values span').count()==6,'six_dimensions')
        page.locator('#fortune-reveal').click();ck(txt('fortune-name')=='古魯瓦爾多','fortune_named_reveal')
        page.wait_for_timeout(200);visible_images('fortune-art')
        ck(txt('fortune-exp')=='1892' and txt('fortune-all-draws')=='112','lifetime_separate');shot('fortune')
        step(8);ck(scope(8)=='PERSONAL' and page.locator('#bond-top button').count()==3,'bond_top3')
        ck(txt('bond-name')=='古魯瓦爾多' and txt('bond-count')=='15 次','same_character_across_levels')
        slots=page.locator('#bond-grid .bond-slot')
        ck(slots.count()==10 and slots.evaluate_all('(a)=>a.map(x=>x.dataset.level)')==['L1','L2','L3','L4','L5','R1','R2','R3','R4','R5'],'all_10_levels')
        counts=slots.evaluate_all('(a)=>a.map(x=>+x.dataset.count)')
        ck(sum(counts)==15 and sum(n>0 for n in counts)>1,'collection_counts_reconcile')
        ck(str(sum(n>0 for n in counts))+' / 10' in txt('bond-completion'),'collection_completion')
        page.wait_for_timeout(200);visible_images('bond-grid');shot('bond')
        page.locator('#bond-top button').nth(1).click();ck(txt('bond-name')!='古魯瓦爾多','switch_bond_role')
        ck(sum(slots.evaluate_all('(a)=>a.map(x=>+x.dataset.count)'))==int(txt('bond-count').split()[0]),'switch_counts_reconcile')
        step(10);ck('個人 可敬對手' in txt('share-data') and '戰場：魔女山谷' in txt('share-data') and '古魯瓦爾多' in txt('share-data'),'share_restored_fields')
        ck(page.locator('#share-art img').count()==3,'share_card_art')
        page.locator('#share-rival').click();ck('可敬對手' not in txt('share-data'),'share_rival_optout')
        page.locator('#share-fortune').click();ck('命運：' not in txt('share-data'),'share_fortune_optout')
        page.locator('#share-rival').click();page.locator('#share-fortune').click()
        page.locator('#square').click()
        with page.expect_download() as dl:page.locator('#download').click()
        f=out/f'share-square-{w}.png';dl.value.save_as(f);raw=f.read_bytes()
        ck(int.from_bytes(raw[16:20],'big')==1080 and int.from_bytes(raw[20:24],'big')==1080,'square_png')
        page.locator('#portrait').click()
        with page.expect_download() as dl:page.locator('#download').click()
        f=out/f'share-portrait-{w}.png';dl.value.save_as(f);raw=f.read_bytes()
        ck(int.from_bytes(raw[16:20],'big')==1080 and int.from_bytes(raw[20:24],'big')==1440,'portrait_png')
        scene('fallback');ck(all(scope(i)=='PUBLIC' for i in [1,2,3,4,5,7,8,9,11,12]),'public_scope')
        step(11);ck('公開樣本' in txt('rivals-title') and page.locator('#rivals .result-bar').count()==0,'public_not_personal_rival')
        step(8);ck('全站公開' in txt('bond-title') and '非個人收藏' in txt('bond-caption'),'public_collection_label')
        step(10);ck('個人 PvP' not in txt('share-data') and '可敬對手' not in txt('share-data') and page.locator('#share-rival').is_hidden(),'public_share_privacy')
        scene('mixed');ck(scope(1)=='PERSONAL' and scope(2)=='PUBLIC' and scope(4)=='PUBLIC' and scope(8)=='PERSONAL','mixed_scope')
        scene('conflict');ck(scope(8)=='PUBLIC' and scope(9)=='PUBLIC','conflict_fortune_fallback')
        scene('fixture');step(3);page.locator('#team-best').click();ck(txt('team')=='資料不足','small_sample_no_rank')
        scene('unknown');ck(all(scope(i)=='SKIP' for i in [1,2,3,4,5,7,8,9,11,12]),'skip_all_missing')
        ck(page.locator('#progress button:visible').count()==3,'skip_progress')
        page.locator('#start').click();expect(page.locator('#slide-6')).to_be_visible()
        page.locator('#next').click();expect(page.locator('#slide-10')).to_be_visible()
        ck(txt('page-count')=='3 / 3','skip_navigation')
        page.locator('#skip').click();ck(page.locator('.slide:visible').count()==3,'skip_summary')
        ck(not errors,'no_js_errors');ck(not unapproved,'only_allowlisted_asset_requests')
        results.append({'viewport':[w,h],'status':'PASS','count':len(checks),'checks':checks});ctx.close()
    b.close()
(out/'browser-qa.json').write_text(json.dumps({'status':'PASS','asset_status':'PASS' if not failures else 'FAIL','results':results},ensure_ascii=False,indent=2))
print('FT04V_BROWSER_PASS',[(r['viewport'],r['count']) for r in results],flush=True)
if failures:sys.exit('ASSET_BYTE_GATE_FAILED')
