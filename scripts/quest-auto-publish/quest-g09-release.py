#!/usr/bin/env python3
"""UL.GG Quest G09/G10: private render, operator-approved activation and rollback.
Requires Linux, Python 3, PHP, curl, existing shared writer lock. No sudo or timer changes.
"""
import argparse, contextlib, ctypes, datetime as dt, fcntl, hashlib, json, os, pathlib as pl
import re, shutil, socket, stat, subprocess as sp, tempfile, time, urllib.request, urllib.error, uuid, zipfile
SITE=pl.Path('/var/www/html/unlight/pages/quest_public/v1.4.1')
PRIVATE=pl.Path('/var/lib/ulgg/quest-g2')
BACKUPS=pl.Path('/var/www/.ulgg-quest-g09')
LOCK=pl.Path('/run/lock/ulgg-git-sync.lock')
MODEL_SHA='90593c6e7de3d9fc2e8753f8a22e4a9a68e5d1535fe14c06fa8e09572b09216f'
PHP_FILES=('data.php','g2_route_valh.php','release_trust.php','dynamic_metrics.php')
PAGES=('index.php?window=all','quest.php?window=all&region=1%3A2','quest.php?window=all&region=1%3A2&sort=qph&dir=desc','stars.php?window=all','teams.php')
REQUIRED={'overview.json','quality.json','quest_catalog.json','teams_all.json'}|{f'windows/{w}/{k}.json' for w in ('today','7d','30d','all') for k in ('quests','regions','quests_duration','star_matrix')}
HEX=re.compile(r'^[0-9a-f]{64}$')
def need(x,msg):
    if not x: raise RuntimeError(msg)
def sha(p):return hashlib.sha256(pl.Path(p).read_bytes()).hexdigest()
def load(p):return json.loads(pl.Path(p).read_bytes())
def dump(x):return (json.dumps(x,sort_keys=True,indent=2,ensure_ascii=True,allow_nan=False)+'\n').encode()
def write(p,x,mode=0o600):
    p=pl.Path(p); tmp=p.with_name(p.name+'.'+uuid.uuid4().hex+'.tmp')
    fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,mode)
    with os.fdopen(fd,'wb') as f:f.write(dump(x));f.flush();os.fsync(f.fileno())
    os.replace(tmp,p)
def cmd(*a,env=None,cwd=None):return sp.run(a,check=True,env=env,cwd=cwd,text=True,stdout=sp.PIPE,stderr=sp.STDOUT).stdout
def safe_hash(x):need(isinstance(x,str) and HEX.fullmatch(x),'invalid SHA256');return x
@contextlib.contextmanager
def writer_lock(path):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    try:
        need(stat.S_ISREG(os.fstat(fd).st_mode),'lock is not regular file')
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        need(os.fstat(fd).st_ino==os.stat(path).st_ino,'lock replaced')
        print('WRITER_LOCK_ACQUIRED',flush=True);yield
    finally:os.close(fd);print('WRITER_LOCK_RELEASED',flush=True)
def exchange(a,b):
    # Linux atomic exchange, no non-atomic fallback.
    libc=ctypes.CDLL(None,use_errno=True);f=libc.renameat2
    f.argtypes=[ctypes.c_int,ctypes.c_char_p,ctypes.c_int,ctypes.c_char_p,ctypes.c_uint]
    f.restype=ctypes.c_int
    if f(-100,os.fsencode(a),-100,os.fsencode(b),2):raise OSError(ctypes.get_errno(),os.strerror(ctypes.get_errno()))
def tree_digest(root):
    need(root.is_dir() and not root.is_symlink(),'site directory invalid')
    h=hashlib.sha256()
    for p in sorted(root.rglob('*'),key=lambda p:p.relative_to(root).as_posix()):
        s=p.lstat();rel=p.relative_to(root).as_posix(); kind=stat.S_IFMT(s.st_mode)
        payload=os.readlink(p) if p.is_symlink() else sha(p) if p.is_file() else ''
        need(kind in (stat.S_IFDIR,stat.S_IFREG,stat.S_IFLNK),'special file '+rel)
        h.update(dump([rel,s.st_mode,s.st_uid,s.st_gid,payload]))
    return h.hexdigest()
def extract(zpath,pin,out):
    need(sha(zpath)==safe_hash(pin),'ZIP hash mismatch')
    with zipfile.ZipFile(zpath) as z:
        infos=z.infolist(); names=[i.filename for i in infos]
        need(len(names)==len(set(names)) and len(names)<=300 and sum(i.file_size for i in infos)<=100*1024*1024,'ZIP limits')
        for i in infos:
            n=i.filename;need(not i.is_dir() and not stat.S_ISLNK(i.external_attr>>16) and all(x not in ('','.','..') for x in n.split('/')) and not any(x in n for x in ('\\',':','\x00')),'unsafe archive path')
        m=json.loads(z.read('RELEASE_MANIFEST.json'));fs={x['path']:x for x in m['files']}
        need(len(fs)==len(m['files']) and set(names)==set(fs)|{'RELEASE_MANIFEST.json'},'archive inventory')
        for n in names:
            b=z.read(n)
            if n in fs:need(len(b)==fs[n]['bytes'] and hashlib.sha256(b).hexdigest()==fs[n]['sha256'],'archive integrity '+n)
            p=out/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
    report=load(out/'GATE_REPORT.json');need(report['schema_missing_count']==0,'schema gate')
    need(load(out/'G09_SEMANTIC_REPORT.json')['status']=='PASS_SELECTED_RECONCILIATION','semantic gate')
    need(sha(out/'model/approved.json')==MODEL_SHA,'model pin')
    data=out/'full_candidate'; m=load(data/'manifest.json'); files={x['path']:x for x in m['files']}
    need(set(files)==REQUIRED and len(files)==len(m['files']),'data inventory')
    for n,x in files.items():
        p=data/n;need(sha(p)==x['sha256'] and p.stat().st_size==x['bytes'] and load(p)['as_of_utc']==m['as_of_utc'],'data integrity '+n)
    digest=sha(data/'manifest.json');need(load(out/'G10_DAILY_REPORT.json')['manifest_sha256']==digest,'daily history anchor')
    snap=out/'daily_history'/f'{digest}.json';need(load(snap)['manifest_sha256']==digest,'history snapshot')
    need(set(p.name for p in (out/'candidate_php').glob('*.php'))==set(PHP_FILES),'PHP scope')
    for n in PHP_FILES:need((out/'candidate_php'/n).is_file(),'PHP missing '+n)
    print('PASS archive, schema, semantic report and history anchors',flush=True)
    return digest

def current_data(site):
    for p in (site/'data',site.parent/'out_live'):
        if (p/'manifest.json').is_file():return p.resolve()
    raise RuntimeError('live data directory not found')
def live_state(site):
    d=current_data(site)
    return {'site_sha256':tree_digest(site),'data_path':str(d),'data_files':{n:sha(d/n) for n in sorted(REQUIRED|{'manifest.json'})}}
def classify(site,pkg):
    inc=site/'inc';need(inc.is_dir() and not inc.is_symlink(),'live inc invalid')
    for n in PHP_FILES:
        p=inc/n;need(not p.is_symlink(),'live PHP symlink '+n)
    if all((inc/n).is_file() and sha(inc/n)==sha(pkg/'candidate_php'/n) for n in PHP_FILES):return 'daily'
    for n in ('data.php','g2_route_valh.php'):
        need((inc/n).is_file() and sha(inc/n)==sha(pkg/'contract_reference/inc'/n),'LIVE_PHP_DRIFT '+n)
    for n in ('release_trust.php','dynamic_metrics.php'):
        need(not (inc/n).exists(),'new PHP filename collision '+n)
    return 'initial'
def copy_public_data(source,dest):
    dest.mkdir(mode=0o755)
    for n in sorted(REQUIRED|{'manifest.json'}):
        p=dest/n;p.parent.mkdir(parents=True,exist_ok=True,mode=0o755);shutil.copyfile(source/n,p);p.chmod(0o644)
    for p in dest.rglob('*'):
        if p.is_dir():p.chmod(0o755)
def remove_stage_data(p):
    if p.is_symlink():p.unlink()
    elif p.exists():need(p.is_dir(),'stage data not a directory');shutil.rmtree(p)
def render(site,pkg,digest,run_tests=True):
    with tempfile.TemporaryDirectory(prefix='quest-render-') as tmp:
        # Preserve production relative include depth: inc/layout.php resolves
        # dirname(__DIR__,4) to the UL.GG document root, not the Quest root.
        source_root=site.parents[2]
        root=pl.Path(tmp)/'unlight'
        web=root/site.relative_to(source_root)
        web.parent.mkdir(parents=True)
        shutil.copytree(site,web,symlinks=True)
        public_layout=root/'layout';public_layout.mkdir()
        for name in ('header_public.php','sidebar_public.php','footer_public.php'):
            source=source_root/'layout'/name
            need(source.is_file() and not source.is_symlink(),'public layout dependency missing '+name)
            shutil.copyfile(source,public_layout/name)
        # Do not copy config.php, DB credentials, private data or session layout.
        # Retain optional public CSS mtime used by the actual layout.
        css=source_root/'assets/css/footer_public.css'
        if css.is_file() and not css.is_symlink():
            target=root/'assets/css/footer_public.css'
            target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(css,target)
        need((web/'inc').parents[3]==root,'staging relative layout root')
        remove_stage_data(web/'data');copy_public_data(pkg/'full_candidate',web/'data')
        for n in PHP_FILES:shutil.copyfile(pkg/'candidate_php'/n,web/'inc'/n)
        env=os.environ.copy();env.update(ULGG_PUBLIC_DATA_DIR=str(web/'data'),ULGG_PUBLIC_BASE_PATH='/pages/quest_public/v1.4.1',QA_TEST_MODEL=str(pkg/'model/approved.json'),QA_TEST_MANIFEST_SHA=digest)
        for n in PHP_FILES:cmd('php','-l',str(web/'inc'/n))
        if run_tests:
            for n in ('test_dynamic.php','test_g2_full.php','test_g09_daily.php'):
                print(cmd('php',str(pkg/n)),end='')
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        log=pl.Path(tmp)/'php.log'
        with log.open('wb') as lf:
            proc=sp.Popen(['php','-d','display_errors=1','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=lf,stderr=lf)
            try:
                for _ in range(50):
                    if proc.poll() is not None:raise RuntimeError('PHP staging server exited')
                    try:
                        with socket.create_connection(('127.0.0.1',port),.2):break
                    except OSError:time.sleep(.1)
                results=[]
                body='';route='startup'
                for route in PAGES:
                    body=''
                    with urllib.request.urlopen(f'http://127.0.0.1:{port}/pages/quest_public/v1.4.1/{route}',timeout=15) as r:
                        body=r.read().decode('utf-8','replace');need(r.status==200,'HTTP '+route);need(r.headers.get('X-ULGG-Quest-Release')==digest,'HTTP manifest header '+route)
                    validate_html(body,route);results.append({'route':route,'bytes':len(body.encode()),'g2_marker':bool(re.search(r'<span[^>]+class=["\'][^"\']*\bg2-estimate\b',body,re.I))})
                    print('PASS private HTTP '+route,flush=True)
                need(any(x['g2_marker'] for x in results if x['route'].startswith('quest.php')),'G2 marker not found in rendered Quest pages')
                return results
            except Exception as exc:
                evidence=pl.Path(tempfile.mkdtemp(prefix='quest-render-failure-',dir='/var/tmp'))
                evidence.chmod(0o700)
                if isinstance(exc,urllib.error.HTTPError):
                    body=exc.read().decode('utf-8','replace')
                (evidence/'response.html').write_text(locals().get('body',''),encoding='utf-8')
                lf.flush();shutil.copyfile(log,evidence/'php.log')
                print('RENDER_FAILURE_EVIDENCE='+str(evidence),flush=True)
                print('FAILED_ROUTE='+locals().get('route','startup'),flush=True)
                print('RESPONSE_BYTES='+str(len(locals().get('body','').encode())),flush=True)
                raise
            finally:proc.terminate();proc.wait(timeout=10)
def validate_html(body,route):
    need(len(body)>500 and '</html>' in body.lower(),'incomplete HTML '+route)
    need(not re.search(r'(Fatal error|Parse error|Uncaught (?:Error|Exception)|Warning:|Deprecated:)',body,re.I),'PHP error '+route)
def private_root(p,mode=0o755):
    p.mkdir(mode=mode,exist_ok=True);s=p.lstat()
    need(stat.S_ISDIR(s.st_mode) and s.st_uid==0 and not s.st_mode&0o022,'untrusted directory '+str(p))
def selinux_copy(ref,p):
    if shutil.which('getenforce') and cmd('getenforce').strip()=='Enforcing':cmd('chcon','--reference='+str(ref),str(p))
def immutable_release(pkg,digest):
    private_root(PRIVATE);private_root(PRIVATE/'releases');private_root(PRIVATE/'approved');private_root(PRIVATE/'history',0o700)
    need(sha(PRIVATE/'route_valh.json')==MODEL_SHA,'live approved model mismatch')
    dest=PRIVATE/'releases'/digest
    if not dest.exists():
        temp=PRIVATE/'releases'/('.stage-'+uuid.uuid4().hex)
        copy_public_data(pkg/'full_candidate',temp)
        for p in [temp,*temp.rglob('*')]:selinux_copy(PRIVATE/'route_valh.json',p)
        os.rename(temp,dest)
    need(dest.is_dir() and not dest.is_symlink(),'release path invalid')
    for n in REQUIRED|{'manifest.json'}:
        p=dest/n;need(p.is_file() and not p.is_symlink() and sha(p)==sha(pkg/'full_candidate'/n),'immutable release conflict '+n)
    for p in [dest,*dest.rglob('*')]:need(p.lstat().st_uid==0 and not p.lstat().st_mode&0o022 and not p.is_symlink(),'release writable or symlink '+str(p))
    ap=PRIVATE/'approved'/f'{digest}.json'
    approved={'manifest_sha256':digest}
    if ap.exists():need(not ap.is_symlink() and ap.lstat().st_uid==0 and not ap.lstat().st_mode&0o022 and load(ap)==approved,'approval conflict')
    else:write(ap,approved,0o644);selinux_copy(PRIVATE/'route_valh.json',ap)
    for p in (PRIVATE/'releases',PRIVATE/'approved'):selinux_copy(PRIVATE,p)
    return dest

def install_php(src,dest,ref):
    # Preserve ownership/mode/context from exact existing code; only stage files modified.
    s=ref.stat();shutil.copyfile(src,dest);os.chown(dest,s.st_uid,s.st_gid);os.chmod(dest,stat.S_IMODE(s.st_mode));selinux_copy(ref,dest)
def production_http(digest):
    # TLS verification remains mandatory. Only the known legacy Stars redirect
    # is allowed, and its fixed destination receives a fresh independent request.
    base='https://ulgg.online/pages/quest_public/v1.4.1/'
    stars_location='/pages/quest_public/v1.4.1/index.php#stars'
    results=[]
    for route in PAGES:
        last='not attempted'
        for attempt in range(1,6):
            def fetch(target):
                url=base+target+('&' if '?' in target else '?')+'qa_release='+digest[:16]+'&qa_nonce='+str(time.time_ns())
                with tempfile.TemporaryDirectory(prefix='qa-http-') as td:
                    hp=pl.Path(td)/'headers';bp=pl.Path(td)/'body'
                    status=cmd('curl','--fail','--silent','--show-error','--max-time','20','--header','Cache-Control: no-cache','--dump-header',str(hp),'--output',str(bp),'--write-out','%{http_code}',url).strip()
                    headers=hp.read_text(encoding='iso-8859-1')
                    final=re.split(r'\n\s*\n',headers.strip())[-1]
                    body=bp.read_bytes().decode('utf-8','replace')
                    return status,final,body
            try:
                status,final,body=fetch(route)
                redirected=False
                if status=='302' and route.startswith('stars.php?'):
                    locations=re.findall(r'^Location:[ \t]*([^\r\n]+)$',final,re.I|re.M)
                    need(locations==[stars_location],'unexpected Stars redirect')
                    status,final,body=fetch('index.php?window=all')
                    redirected=True
                need(status=='200','HTTP status '+status)
                matches=re.findall(r'^X-ULGG-Quest-Release:[ \t]*([a-f0-9]{64})[ \t]*$',final,re.I|re.M)
                need(matches==[digest],'public manifest header missing or stale')
                validate_html(body,route)
                if route.startswith('stars.php?'):
                    need(re.search(r'<section\b[^>]*\bid=["\']stars["\']',body,re.I) is not None,'Stars section missing')
                    need(re.search(r'\bqpub-heatmap\b',body,re.I) is not None,'Stars heatmap missing')
            except sp.CalledProcessError as exc:
                last='curl exit '+str(exc.returncode)+' '+str(exc.stdout or '').strip()[:500]
                print('HTTP_WAIT',route,attempt,last,flush=True)
                if exc.returncode==60:raise RuntimeError(last) from exc
            except RuntimeError as exc:
                last=str(exc)
                print('HTTP_WAIT',route,attempt,last,flush=True)
            else:
                results.append({'route':route,'bytes':len(body.encode()),'g2_marker':bool(re.search(r'<span[^>]+class=["\'][^"\']*\bg2-estimate\b',body,re.I)),'redirected':redirected})
                print('PASS public HTTPS '+route+(' -> index.php#stars' if redirected else ''),flush=True)
                break
            if attempt<5:time.sleep(3)
        else:raise RuntimeError('public HTTP gate '+route+': '+last)
    need(any(x['g2_marker'] for x in results if x['route'].startswith('quest.php')),'public G2 marker missing')
    return results

def verify_live_cli(digest):
    # CLI production path; no test environment, same production reader and model approval.
    env={k:v for k,v in os.environ.items() if k not in ('ULGG_PUBLIC_DATA_DIR','QA_TEST_MODEL','QA_TEST_MANIFEST_SHA','ULGG_G2_STAGE_PATH')}
    code="require $argv[1].'/inc/bootstrap.php'; require $argv[1].'/inc/g2_route_valh.php'; if(hash_file('sha256',qpub_data_dir().'/manifest.json')!==$argv[2] || qpub_contract_warnings() || !g2_route_load()) exit(1); echo 'PASS live data/model';"
    print(cmd('php','-r',code,str(SITE),digest,env=env))

def compare_history(prior,now):
    if prior is None:return {'baseline':None,'new_observed':None,'new_confirmed':None,'error':None}
    new={};confirmed={};errors={}
    for key,mm in now['metrics'].items():
        for name,v in mm.items():
            p=prior['metrics'].get(key,{}).get(name,{})
            was=p.get('source') in ('observed','low_sample');is_obs=v['source'] in ('observed','low_sample')
            if is_obs and not was:new[name]=new.get(name,0)+1
            if v['source']=='observed' and p.get('source')!='observed':confirmed[name]=confirmed.get(name,0)+1
            if v['source']=='observed' and p.get('source')=='estimated' and isinstance(p.get('value'),(int,float)):
                errors.setdefault(name,[]).append(v['value']-p['value'])
    return {'baseline':prior['manifest_sha256'],'new_observed':new,'new_confirmed':confirmed,'error':error_summary(errors)}
def error_summary(errors):
    return {k:{'n':len(v),'mae':sum(map(abs,v))/len(v),'bias':sum(v)/len(v)} for k,v in errors.items() if v}
def published_snapshot(pkg,digest):
    now=load(pkg/'daily_history'/f'{digest}.json');now['status']='PUBLISHED'
    rows=[load(p) for p in (PRIVATE/'history').glob('*.json') if p.name!=f'{digest}.json']
    rows=[r for r in rows if r.get('status')=='PUBLISHED' and r['as_of_utc']<now['as_of_utc']]
    latest=max(rows,key=lambda r:r['as_of_utc'],default=None)
    day=max((r for r in rows if r['day']<now['day']),key=lambda r:r['as_of_utc'],default=None)
    now['incremental']=compare_history(latest,now);now['daily']=compare_history(day,now)
    ledger=latest.get('first_estimates',{}) if latest else {};errors={}
    for k,mm in now['metrics'].items():
        for n,v in mm.items():
            if v['source']=='estimated' and v.get('basis','model')=='model':
                ledger.setdefault(k,{}).setdefault(n,{'value':v['value'],'as_of_utc':now['as_of_utc']})
            p=ledger.get(k,{}).get(n)
            if p and v['source']=='observed' and p['as_of_utc']<now['as_of_utc']:
                errors.setdefault(n,[]).append(v['value']-p['value'])
    now['first_estimates']=ledger;now['historical_error']=error_summary(errors)
    return now

def check_release(zip_path,pin,daily_only=False):
    with tempfile.TemporaryDirectory(prefix='ulgg-quest-g09-check-',dir='/var/tmp') as td:
        pkg=pl.Path(td)/'package';pkg.mkdir();digest=extract(zip_path,pin,pkg)
        before=live_state(SITE);kind=classify(SITE,pkg)
        if daily_only:need(kind=='daily','automatic publisher refuses PHP changes')
        results=render(SITE,pkg,digest,run_tests=not daily_only)
        need(live_state(SITE)==before,'live drift during check')
        plan={'status':'READY_FOR_OPERATOR','zip_sha256':pin,'manifest_sha256':digest,'kind':kind,'baseline':before,'render':results,'site':str(SITE),'created_utc':dt.datetime.now(dt.timezone.utc).isoformat()}
        fd,name=tempfile.mkstemp(prefix='ulgg-quest-g09-plan-',suffix='.json',dir='/var/tmp');os.close(fd);p=pl.Path(name);write(p,plan)
        print('READY_FOR_OPERATOR');print('PLAN='+str(p));print('PLAN_SHA256='+sha(p));return p

def apply_release(zip_path,pin,plan_path,plan_pin,daily_only=False):
    need(os.geteuid()==0,'root/operator required; no privilege elevation performed')
    need(sha(plan_path)==safe_hash(plan_pin),'plan hash mismatch');plan=load(plan_path)
    need(plan['status']=='READY_FOR_OPERATOR' and plan['zip_sha256']==pin and plan['site']==str(SITE),'plan mismatch')
    with tempfile.TemporaryDirectory(prefix='ulgg-quest-g09-apply-',dir='/var/tmp') as td:
        pkg=pl.Path(td)/'package';pkg.mkdir();digest=extract(zip_path,pin,pkg)
        need(digest==plan['manifest_sha256'],'plan data digest');kind=plan['kind']
        if daily_only:need(kind=='daily','initial PHP release not installed')
        with writer_lock(LOCK):
            need(live_state(SITE)==plan['baseline'],'LIVE_DRIFT: regenerate check plan')
            need(classify(SITE,pkg)==kind,'live source classification changed')
            # Monotonic releases; same digest is a no-op.
            old=current_data(SITE);old_anchor=load(old/'manifest.json')['as_of_utc'];new_anchor=load(pkg/'full_candidate/manifest.json')['as_of_utc']
            need(new_anchor>=old_anchor,'older release rejected')
            if kind=='daily' and sha(old/'manifest.json')==digest:print('SKIP_ALREADY_PUBLISHED');return
            dest=immutable_release(pkg,digest)
            private_root(BACKUPS,0o700);need(BACKUPS.stat().st_dev==SITE.stat().st_dev,'backup must share filesystem')
            tx=BACKUPS/(dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+uuid.uuid4().hex[:8]);tx.mkdir(mode=0o700)
            state={'status':'PREPARED','kind':kind,'manifest_sha256':digest,'baseline':plan['baseline']};write(tx/'transaction.json',state)
            switched=False;history_written=False
            try:
                if kind=='initial':
                    stage=tx/'site'
                    # GNU cp -a preserves file ownership and SELinux xattrs.
                    cmd('cp','-a','--',str(SITE),str(stage))
                    for n in PHP_FILES:install_php(pkg/'candidate_php'/n,stage/'inc'/n,SITE/'inc'/('data.php' if n=='data.php' else 'g2_route_valh.php'))
                    remove_stage_data(stage/'data');(stage/'data').symlink_to(dest,target_is_directory=True)
                    need(live_state(SITE)==plan['baseline'],'live drift before switch')
                    exchange(SITE,stage);switched=True
                else:
                    pointer=SITE/'data';need(pointer.is_symlink(),'daily requires installed immutable data pointer')
                    state['previous_target']=os.readlink(pointer);write(tx/'transaction.json',state)
                    tmp=SITE/('.qa-data-'+uuid.uuid4().hex);tmp.symlink_to(dest,target_is_directory=True);os.replace(tmp,pointer);switched=True
                verify_live_cli(digest);http=production_http(digest)
                state.update(status='PUBLISHED',after=live_state(SITE),http=http)
                # Keep true published snapshot separately from the local candidate history.
                hist=PRIVATE/'history'/f'{digest}.json';snap=published_snapshot(pkg,digest)
                if hist.exists():need(load(hist)==snap,'published history conflict')
                else:write(hist,snap);history_written=True
                write(tx/'transaction.json',state);print('PUBLISHED='+digest);print('TRANSACTION='+tx.name)
            except BaseException:
                if history_written:os.rename(hist,tx/'uncommitted_history.json')
                if switched:
                    if kind=='initial':exchange(SITE,tx/'site')
                    else:
                        tmp=SITE/('.qa-rollback-'+uuid.uuid4().hex);tmp.symlink_to(state['previous_target'],target_is_directory=True);os.replace(tmp,SITE/'data')
                    need(live_state(SITE)==plan['baseline'],'rollback verification failed')
                    state['status']='ROLLED_BACK';write(tx/'transaction.json',state);print('ROLLBACK_PASS')
                raise

def rollback(txid):
    need(os.geteuid()==0,'root/operator required');need(re.fullmatch(r'\d{8}T\d{6}Z-[0-9a-f]{8}',txid),'transaction id')
    tx=BACKUPS/txid;state=load(tx/'transaction.json');need(state['status']=='PUBLISHED','transaction not published')
    with writer_lock(LOCK):
        need(live_state(SITE)==state['after'],'LIVE_DRIFT: later release or site changes; no overwrite')
        if state['kind']=='initial':exchange(SITE,tx/'site')
        else:
            tmp=SITE/('.qa-rollback-'+uuid.uuid4().hex);tmp.symlink_to(state['previous_target'],target_is_directory=True);os.replace(tmp,SITE/'data')
        need(live_state(SITE)==state['baseline'],'rollback verification failed')
        hist=PRIVATE/'history'/f"{state['manifest_sha256']}.json"
        if hist.exists():os.rename(hist,tx/'rolled_back_history.json')
        state['status']='ROLLED_BACK';write(tx/'transaction.json',state);print('ROLLBACK_PASS '+txid)

def selftest():
    with tempfile.TemporaryDirectory(prefix='qa-transaction-test-') as t:
        p=pl.Path(t);a=p/'live';b=p/'backup';a.mkdir();b.mkdir();(a/'a').write_text('old');(b/'a').write_text('new');old=tree_digest(a);new=tree_digest(b)
        lock=p/'writer.lock';lock.touch()
        with writer_lock(lock):
            try:
                with writer_lock(lock):raise AssertionError('double writer accepted')
            except BlockingIOError:print('PASS writer contention')
            exchange(a,b);need(tree_digest(a)==new and tree_digest(b)==old,'exchange');print('PASS atomic site exchange')
            exchange(a,b);need(tree_digest(a)==old and tree_digest(b)==new,'rollback');print('PASS atomic site rollback')
            ptr=p/'data';ptr.symlink_to(a);tmp=p/'next';tmp.symlink_to(b);os.replace(tmp,ptr);need(ptr.resolve()==b,'pointer');print('PASS atomic daily pointer')
            tmp.symlink_to(a);os.replace(tmp,ptr);need(ptr.resolve()==a,'pointer rollback');print('PASS daily pointer rollback')
            (a/'a').write_text('drift');need(tree_digest(a)!=old,'drift check');print('PASS baseline drift detection')
        p1={'manifest_sha256':'a'*64,'metrics':{'q':{'qp':{'source':'estimated','value':20}}}}
        p2={'manifest_sha256':'b'*64,'metrics':{'q':{'qp':{'source':'observed','value':12}}}}
        r=compare_history(p1,p2);need(r['new_observed']=={'qp':1} and r['error']['qp']=={'n':1,'mae':8.0,'bias':-8.0},'published history transition');print('PASS published history transition')
        need(compare_history(None,p2)['baseline'] is None and compare_history(None,p2)['new_observed'] is None,'no synthetic prior');print('PASS first publication baseline null')
        print('TOTAL 8 PASS (synthetic filesystem/history only)')
def main():
    ap=argparse.ArgumentParser(description=__doc__);sub=ap.add_subparsers(dest='mode',required=True)
    for mode in ('check','apply','daily','daily-auto'):
        x=sub.add_parser(mode);x.add_argument('zip',type=pl.Path);x.add_argument('sha256')
        if mode in ('apply','daily'):x.add_argument('plan',type=pl.Path);x.add_argument('plan_sha256')
    x=sub.add_parser('rollback');x.add_argument('transaction');sub.add_parser('selftest');a=ap.parse_args()
    if a.mode=='selftest':selftest()
    elif a.mode=='check':check_release(a.zip,a.sha256)
    elif a.mode=='rollback':rollback(a.transaction)
    elif a.mode=='daily-auto':
        need(os.geteuid()==0,'authorized root-owned publisher required')
        script=pl.Path(__file__).resolve();need(script.stat().st_uid==0 and not script.stat().st_mode&0o022,'publisher script must be root-owned and protected')
        # The trusted job supplies the independently authorized ZIP digest; never auto-compute it from an incoming ZIP.
        plan=check_release(a.zip,a.sha256,daily_only=True)
        apply_release(a.zip,a.sha256,plan,sha(plan),True)
    else:apply_release(a.zip,a.sha256,a.plan,a.plan_sha256,a.mode=='daily')
if __name__=='__main__':main()
