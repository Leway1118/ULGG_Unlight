#!/usr/bin/env python3
"""Owner-operated first install: audit, explicit release, guarded recovery."""
import argparse, grp, html, json, os, re, shutil, stat, subprocess, sys, tempfile
from pathlib import Path
from urllib.parse import urlencode
from sync import SITE,STATE,PRIVATE,writer_lock,audit,read,write,atomic,digest,cmd,recover_latest,php_json,now

HERE=Path(__file__).resolve().parent
OPT=Path('/opt/ulgg-card-assets')
UNIT='ulgg-card-assets-refresh'
DASH=SITE/'pages/admin_dashboard.php'
COST=SITE/'scripts/ops/ulgg-cost-audit.php'
EXPECTED={DASH:'aa093704dfb4f4199c721e641c6ba5b25172660672c4da37bd9ffc9d5f197760',
          COST:'3ba6d13fda51edf4b430b64aded76d314817983b63d52f4e424084244a5d7edc'}
RUNTIME=['refresh.py','sync.py','build_import_plan.py','apply_plan.php','run_import.php','preflight.php','smoke_targets.php','release.py']
NEW={SITE/'includes/card_assets_status.php':'card_assets_status.php',
     SITE/'includes/card_assets_panel.php':'card_assets_panel.php',
     SITE/'pages/admin/card_assets_download.php':'card_assets_download.php',
     Path('/etc/systemd/system')/(UNIT+'.service'):UNIT+'.service',
     Path('/etc/systemd/system')/(UNIT+'.timer'):UNIT+'.timer'}
def need(x,m):
    if not x:raise RuntimeError(m)
def sha(p):return digest(p.read_bytes())
def systemctl(*a):subprocess.run(['systemctl',*a],check=True)
def is_active(name):return subprocess.run(['systemctl','is-active','--quiet',name]).returncode==0
def file_install(dst,data,mode,uid,gid):
    need(not dst.is_symlink(),'symlink destination: '+str(dst))
    atomic(dst,data,mode);os.chown(dst,uid,gid);os.chmod(dst,mode)
def replace_once(b,a,x):
    need(b.count(a)==1,'ambiguous patch anchor: '+repr(a));return b.replace(a,x,1)
def current_files():
    for p,h in EXPECTED.items():need(p.is_file() and not p.is_symlink() and sha(p)==h,'live baseline changed: '+str(p))
    for p in NEW:need(not p.exists() and not p.is_symlink(),'new path already exists: '+str(p))
    return rollback_residue()

def rollback_residue():
    paths=(OPT,STATE,PRIVATE)
    for p in paths:need(not p.is_symlink(),'symlink installation path: '+str(p))
    exists=[p.exists() for p in paths]
    if not any(exists):return False
    need(all(exists),'partial old installation; review required')
    for p in paths:
        s=p.lstat();need(stat.S_ISDIR(s.st_mode) and s.st_uid==0 and not s.st_mode&0o022,'unsafe old installation path')
    need(not PRIVATE.stat().st_mode&0o077,'old private directory not private')
    need(not is_active(UNIT+'.service') and not is_active(UNIT+'.timer'),'old service or timer active')
    need(read(PRIVATE/'release-state.json').get('status')=='ROLLED_BACK','old release not rolled back')
    need(not (PRIVATE/'latest-run.json').exists() and not (PRIVATE/'first-complete.json').exists(),'previous DB run requires separate review')
    need({p.name for p in STATE.iterdir()} <= {'attempt.json','db-audit.json'},'unexpected old public snapshots')
    m=read(PRIVATE/'release-manifest.json')
    files={r['path']:r for r in m['files']}
    need(set(files)=={str(p) for p in (*EXPECTED,*NEW)},'old manifest scope mismatch')
    for p,h in EXPECTED.items():
        r=files[str(p)];name=r['backup']
        need(r['old_hash']==h and isinstance(name,str) and re.fullmatch(r'[0-9]+\.original',name),'old manifest original mismatch')
        b=PRIVATE/'release-originals'/name
        need(b.is_file() and not b.is_symlink() and sha(b)==h,'old backup mismatch')
    for p in NEW:need(files[str(p)]['old_hash'] is None,'old manifest unexpected file')
    return True

def archive_rollback():
    # Caller holds the same existing writer lock; never delete original backups.
    if not rollback_residue():return []
    moved=[];archives=[]
    try:
        for p in (OPT,STATE,PRIVATE):
            archive=Path(tempfile.mkdtemp(prefix='ulgg-card-assets-rolledback-',dir=p.parent))
            os.chmod(archive,0o700);archives.append(archive)
            dest=archive/p.name
            p.rename(dest);moved.append((p,dest))
    except BaseException:
        for p,dest in reversed(moved):dest.rename(p)
        for a in archives:a.rmdir()
        raise
    print('ROLLBACK_RESIDUE_ARCHIVED',json.dumps([str(d) for _,d in moved]),flush=True)
    return moved

def service_audit():
    # Run the whole transaction rehearsal in a transient service with the installed
    # service's security properties. No persistent unit/timer or production file change.
    from configparser import ConfigParser
    conf=ConfigParser(interpolation=None,strict=False);conf.optionxform=str
    conf.read(HERE/(UNIT+'.service'),encoding='utf8')
    svc=conf['Service']
    keys=('Type','User','Group','UMask','NoNewPrivileges','ProtectSystem','ProtectHome','PrivateTmp','TimeoutStartSec')
    need(all(k in svc for k in keys),'service runtime contract incomplete')
    with tempfile.TemporaryDirectory(prefix='ulgg-assets-probe-',dir='/opt') as d, tempfile.TemporaryDirectory(prefix='ulgg-assets-audit-',dir='/var/lib') as w:
        runtime=Path(d);work=Path(w)/'approved'
        os.chmod(runtime,0o700);os.chmod(w,0o700)
        for n in RUNTIME:
            shutil.copyfile(HERE/n,runtime/n);os.chmod(runtime/n,0o600)
        args=['systemd-run','--quiet','--wait','--pipe','--collect','--unit=ulgg-card-assets-probe-'+str(os.getpid())]
        for k in keys:args+=['--property='+k+'='+svc[k]]
        args+=['--property=WorkingDirectory='+str(runtime),'--property=ReadWritePaths=-'+str(STATE)+' -'+str(PRIVATE)+' -/var/lib/php/session '+str(w)]
        args+=['/usr/bin/python3','-B',str(runtime/'sync.py'),'audit','--work',str(work)]
        r=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if r.returncode:
            err=r.stderr.decode('utf8','replace')
            msg=re.findall(r'(?:RuntimeError|ValueError): ([A-Za-z0-9_ .+=:/-]{1,250})',err)
            print('SYSTEMD_RUNTIME_GATE_FAIL',r.returncode,msg[-1] if msg else 'inspect transient unit journal',flush=True)
            raise RuntimeError('systemd sandbox rehearsal failed before release')
        result=json.loads(r.stdout)
        need(result.get('rehearsal',{}).get('status')=='ROLLED_BACK_REHEARSAL' and result.get('rehearsal',{}).get('verification',{}).get('status')=='PASS','sandbox rehearsal receipt invalid')
        need(read(work/'approval.json')==result,'sandbox approval receipt mismatch')
        shutil.copytree(work,HERE/'approved')
        print('SYSTEMD_RUNTIME_GATE_PASS',result['summary'],flush=True)
        return result
def patch_files():
    b=DASH.read_bytes()
    b=replace_once(b,b'if (!$alerts) {',b"""require_once __DIR__ . '/../includes/card_assets_status.php';
$officialAssetWarning=ulAssetsWarning();
if($officialAssetWarning!==null)$alerts[]=['level'=>'warning','title'=>'Official card assets','text'=>$officialAssetWarning];

if (!$alerts) {""")
    anchor=b'<div class="admin-layout-equal">'
    b=replace_once(b,anchor,b"<?php include __DIR__ . '/../includes/card_assets_panel.php'; ?>\n      "+anchor)
    c=COST.read_bytes()
    c=replace_once(c,b'$byCard=[];', (HERE/'cost-reference.inc').read_bytes()+b'\n$byCard=[];')
    atomic(HERE/'dashboard.patched.php',b,0o600);atomic(HERE/'cost-audit.patched.php',c,0o600)
    for n in ('dashboard.patched.php','cost-audit.patched.php','card_assets_panel.php','card_assets_status.php','card_assets_download.php','apply_plan.php','run_import.php','preflight.php','smoke_targets.php'):
        cmd('/usr/bin/php','-l',HERE/n)
def check_schedules():
    s=cmd('systemctl','list-unit-files','--type=timer','--no-pager').decode(errors='replace')
    need(not re.search(r'card.asset|chara.cards|feat.refresh',s,re.I),'existing candidate timer found')
    task=SITE/'scripts/run_unlight_tasks_manual.sh'
    need(task.is_file() and sha(task)=='e620e6f1cb857cce16865428036918d5814f94c05dc77614869582432cf3576f','existing task script changed since final gate')
    markers=re.compile(rb'cc_asset\.json|CharaCards\.json|Feats\.json|(?:UPDATE|INSERT\s+INTO|REPLACE\s+INTO)\s+\x60?(?:unlight_skill|cost_unlight|unlight)\b',re.I)
    todo=[task];seen=set()
    while todo:
        p=todo.pop()
        if p in seen:continue
        need(len(seen)<100,'task static inspection limit')
        seen.add(p)
        if not p.is_file() or p.stat().st_size>2*1024*1024:continue
        b=p.read_bytes();need(not markers.search(b),'existing task references target assets/writes: '+str(p))
        for x in re.findall(rb'[\w./-]+\.(?:sh|py|php)',b):
            q=Path(x.decode(errors='ignore'))
            for t in ([q] if q.is_absolute() else [p.parent/q,SITE/q]):
                if t.is_file() and str(t.resolve()).startswith(str(SITE)+'/'):todo.append(t.resolve())
    for base in (Path('/etc/cron.d'),Path('/var/spool/cron')):
        if base.is_dir():
            for p in base.iterdir():
                if p.is_file() and p.stat().st_size<1024*1024:need(not markers.search(p.read_bytes()),'possible duplicate cron: '+str(p))
    p=Path('/etc/crontab')
    if p.is_file():need(not markers.search(p.read_bytes()),'possible duplicate crontab')
    print('SCHEDULE_STATIC_CHECK_PASS',len(seen),'scripts; dynamic references not exhaustively proven',flush=True)
def http(path,expect=(200,)):
    # Use the established public HTTPS route (Cloudflare), not a guessed localhost TLS listener.
    import time
    fd,p=tempfile.mkstemp(prefix='ulgg-http-',dir=HERE);os.close(fd)
    try:
        for attempt in range(1,4):
            url='https://ulgg.online'+path+('&' if '?' in path else '?')+'_ulgg_check='+str(time.time_ns())
            r=subprocess.run(['curl','--silent','--show-error','--connect-timeout','10','--max-time','25',
                '-H','Cache-Control: no-cache','-H','Pragma: no-cache',
                '-o',p,'-w','%{http_code}',url],capture_output=True,text=True)
            code=r.stdout.strip()
            print('HTTP_CHECK',path,'attempt='+str(attempt),'curl='+str(r.returncode),'status='+code,flush=True)
            if r.returncode==0 and code.isdigit() and int(code) in expect:
                b=Path(p).read_text(encoding='utf8',errors='replace')
                need(not re.search(r'(?:Fatal error:|Uncaught (?:PDOException|RuntimeException)|Parse error:|SQLSTATE\[)',b,re.I),'PHP error '+path)
                need(not re.search(r'<title>\s*(?:Just a moment|Attention Required!)',b,re.I),'Cloudflare challenge '+path)
                return b
            if r.returncode:
                print('CURL_ERROR',r.stderr.strip()[:500],flush=True)
            elif code not in ('429','500','502','503','504'):
                raise RuntimeError('unexpected HTTP '+code+' '+path)
            if attempt<3:time.sleep(3)
        raise RuntimeError('HTTP verification failed: '+path+' curl='+str(r.returncode)+' status='+code)
    finally:Path(p).unlink(missing_ok=True)
def smoke(approved):
    p=read(approved/'import-plan.json');cards={x['id']:x for x in read(approved/'CharaCards.json')};feats={x['id']:x for x in read(approved/'Feats.json')}
    probes=[i for i in p['scope']['released_ids'] if i in (420,470,691,696)] or p['scope']['released_ids'][:2]
    for i in probes:
        b=http('/pages/analysis_character_card.php?'+urlencode({'char_id':i,'days':14,'tab':'skill'}))
        need('tab-skill' in b or 'data-tab="skill"' in b,'card Skill tab missing')
        b=html.unescape(http('/pages/admin/ajax/tab_skill.php?'+urlencode({'char_id':i,'days':14})))
        for sid in cards[i]['skill']:
            need(feats[sid]['name_tcn'] in b,'official skill name missing for card '+str(i))
        print('HTTP_SKILL_PASS',i,flush=True)
    n=p['new_skills'][0] if p['new_skills'] else None
    if n:
        b=html.unescape(http('/pages/ajax/skill_search.php?'+urlencode({'q':n['skill_code']})))
        need(n['fields']['name_tcn'] in b,'skill search did not show new skill')
    else:
        b=http('/pages/ajax/skill_search.php?q=cc001_sk01_1');need('skill-search' in b,'skill search markup absent')
    print('HTTP_SKILL_SEARCH_PASS',flush=True)
    t=php_json('smoke_targets.php',SITE,approved/'import-plan.json').get('tag');need(bool(t),'no tag smoke target')
    b=html.unescape(http('/pages/skill_by_tag.php?'+urlencode({'tag':t['tag']})))
    need(t['name_tcn'] in b,'tag page did not show known tagged skill');print('HTTP_SKILL_TAG_PASS',flush=True)
    b=http('/pages/calculator.php')
    for r in p['changes']:
        if r['table']!='cost_unlight':continue
        for level,v in r['fields'].items():
            i=(r['key']['ID']-1)*10+int(level[1])+(5 if level[0]=='R' else 0);n=i*100+v['after']
            need(re.search(r'\bvalue\s*=\s*["\']'+str(n)+r'["\']',b) is not None,'calculator did not render expected value '+str(n))
    print('HTTP_CALCULATOR_PASS',flush=True)
    b=http('/pages/admin/card_assets_download.php?generation='+read(STATE/'db-current.json')['generation']+'&file=CharaCards.json',(302,303,403))
    need('"frames"' not in b and '"filename"' not in b and 'cloudflare ray id' not in b.lower() and 'sorry, you have been blocked' not in b.lower(),'unauthorized download response not verified')
    print('HTTP_DOWNLOAD_DENIED_PASS',flush=True)
def restore_files(manifest):
    for r in reversed(manifest['files']):
        dst=Path(r['path']);new=r['new_hash'];old=r['old_hash']
        if not dst.exists():
            need(old is None,'original file disappeared: '+str(dst));continue
        h=sha(dst)
        if h==old:continue
        need(h==new,'rollback refuses changed file: '+str(dst))
        if old is None:dst.unlink()
        else:
            src=PRIVATE/'release-originals'/r['backup'];need(sha(src)==old,'original backup hash mismatch')
            file_install(dst,src.read_bytes(),r['mode'],r['uid'],r['gid'])
def uninstall(manifest,db=True):
    if (Path('/etc/systemd/system')/(UNIT+'.timer')).exists():
        subprocess.run(['systemctl','disable','--now',UNIT+'.timer'],check=False)
    need(not is_active(UNIT+'.service'),'sync service still running; do not interrupt it')
    if db:print('DB_ROLLBACK',json.dumps(recover_latest(),ensure_ascii=False),flush=True)
    with writer_lock():restore_files(manifest)
    if shutil.which('restorecon'):subprocess.run(['restorecon',str(DASH),str(COST)],check=True)
    systemctl('daemon-reload')
    write(PRIVATE/'release-state.json',{'status':'ROLLED_BACK','at':now()},0o600)
    print('ROLLBACK_PASS; private backups retained',flush=True)
def release():
    current_files();check_schedules()
    approval=read(HERE/'approved'/'approval.json')
    need(read(HERE/'release-audit.json')['plan_key']==approval['plan_key'],'approval mismatch')
    need(read(HERE/'release-audit.json').get('rehearsal',{}).get('verification',{}).get('status')=='PASS','runtime audit absent')
    nginx=grp.getgrnam('nginx').gr_gid
    manifest={'files':[],'at':now()};installed=False
    try:
        with writer_lock():
            residue=current_files();patch_files()
            if residue:archive_rollback()
            PRIVATE.mkdir(mode=0o700);os.chown(PRIVATE,0,0);os.chmod(PRIVATE,0o700)
            STATE.mkdir(mode=0o750);os.chown(STATE,0,nginx);os.chmod(STATE,0o2750)
            OPT.mkdir(mode=0o750);os.chown(OPT,0,nginx)
            originals=PRIVATE/'release-originals';originals.mkdir(mode=0o700)
            pending=[]
            for dst,src in [(DASH,HERE/'dashboard.patched.php'),(COST,HERE/'cost-audit.patched.php')]+[(x,HERE/y) for x,y in NEW.items()]:
                b=src.read_bytes();old=dst.exists()
                if old:
                    st=dst.stat();mode=stat.S_IMODE(st.st_mode);uid=st.st_uid;gid=st.st_gid
                    name=str(len(manifest['files']))+'.original';atomic(originals/name,dst.read_bytes(),0o600)
                else:
                    st=DASH.stat();mode=0o644;uid=0 if str(dst).startswith('/etc/systemd/') else st.st_uid;gid=0 if str(dst).startswith('/etc/systemd/') else st.st_gid;name=None
                row={'path':str(dst),'old_hash':sha(dst) if old else None,'new_hash':digest(b),'backup':name,'uid':uid,'gid':gid,'mode':mode}
                manifest['files'].append(row);pending.append((dst,b,mode,uid,gid))
            write(PRIVATE/'release-manifest.json',manifest,0o600);installed=True
            for n in RUNTIME:file_install(OPT/n,(HERE/n).read_bytes(),0o640,0,nginx)
            shutil.copytree(HERE/'approved',PRIVATE/'first-approved');os.chmod(PRIVATE/'first-approved',0o700)
            for dst,b,mode,uid,gid in pending:file_install(dst,b,mode,uid,gid)
        if shutil.which('restorecon'):
            subprocess.run(['restorecon',str(DASH),str(COST),*[str(p) for p in NEW if str(p).startswith(str(SITE))]],check=True)
        systemctl('daemon-reload')
        try:systemctl('start',UNIT+'.service')
        except subprocess.CalledProcessError:
            # Output only this service's bounded, already-sanitized SYNC_FAIL line.
            journal=subprocess.run(['journalctl','-u',UNIT+'.service','-n','30','--no-pager','-o','cat'],capture_output=True,text=True)
            for line in journal.stdout.splitlines():
                if line.startswith('SYNC_FAIL '):print(line[:400],flush=True)
            raise
        receipt=read(PRIVATE/'first-complete.json');need(receipt.get('status')=='PASS','first sync receipt absent')
        need(read(STATE/'db-current.json')==receipt,'DB status mismatch')
        for name in ('status.json','db-current.json','db-audit.json'):
            cmd('runuser','-u','nginx','--','test','-r',STATE/name)
        cmd('runuser','-u','nginx','--','test','-r',STATE/'versions'/receipt['generation']/'CharaCards.json')
        systemctl('start','ulgg-cost-audit.service')
        need(cmd('systemctl','show','ulgg-cost-audit.service','-p','Result','--value').strip()==b'success','cost audit failed')
        print('COST_AUDIT_PASS',flush=True)
        smoke(HERE/'approved')
        systemctl('enable','--now',UNIT+'.timer')
        need(is_active(UNIT+'.timer'),'timer inactive')
        write(PRIVATE/'release-state.json',{'status':'RELEASE_PASS','at':now(),'receipt':receipt},0o600)
        print('RELEASE_PASS',json.dumps(receipt,ensure_ascii=False),flush=True)
        print(cmd('systemctl','list-timers',UNIT+'.timer','--all','--no-pager').decode(errors='replace'),flush=True)
        print('ADMIN_AUTHENTICATED_UI_MANUAL: /pages/admin_dashboard.php ; check current and prior snapshot downloads.',flush=True)
    except BaseException:
        if installed:
            print('RELEASE_FAILURE; attempting guarded rollback',flush=True)
            uninstall(manifest)
        raise
def main():
    a=argparse.ArgumentParser();a.add_argument('mode',choices=['audit','release','rollback']);args=a.parse_args()
    need(os.name=='posix' and os.geteuid()==0,'run as existing authorized owner root on VPS')
    os.umask(0o077)
    if args.mode=='audit':
        current_files();check_schedules();patch_files()
        cmd('systemd-analyze','verify',HERE/(UNIT+'.service'),HERE/(UNIT+'.timer'))
        body=http('/pages/calculator.php')
        need(re.search(r'\bvalue\s*=\s*["\']\d{3,}["\']',body) is not None,'calculator baseline content missing')
        result=service_audit()
        write(HERE/'release-audit.json',result,0o600)
        print('AUDIT_PASS '+json.dumps(result,ensure_ascii=False),flush=True)
    elif args.mode=='release':
        need((HERE/'release-audit.json').is_file(),'audit required')
        if input('Type RELEASE ULGG-CardAssets-V3-R2: ').strip()!='RELEASE ULGG-CardAssets-V3-R2':raise SystemExit('ABORT')
        release()
    else:
        need((PRIVATE/'release-manifest.json').is_file(),'no release manifest')
        if input('Type ROLLBACK ULGG-CardAssets-V3-R2: ').strip()!='ROLLBACK ULGG-CardAssets-V3-R2':raise SystemExit('ABORT')
        s=read(PRIVATE/'release-state.json');need(s.get('status')=='RELEASE_PASS','release not active')
        latest=read(PRIVATE/'latest-run.json');need(read(PRIVATE/'first-complete.json')['generation']==s['receipt']['generation'],'first receipt mismatch')
        need(latest['at']<=s['at'],'later sync exists; separate reviewed DB rollback needed')
        uninstall(read(PRIVATE/'release-manifest.json'))
if __name__=='__main__':
    try:main()
    except Exception as e:
        print('STOP:',type(e).__name__,str(e) if not isinstance(e,subprocess.CalledProcessError) else 'command failed: '+str(e.cmd[0]),file=sys.stderr,flush=True);sys.exit(1)
