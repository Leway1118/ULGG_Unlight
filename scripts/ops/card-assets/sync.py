#!/usr/bin/env python3
"""Owner-approved weekly DB sync. The existing lock and current illustrated/staged scope are mandatory."""
import argparse, contextlib, hashlib, json, os, stat, subprocess, tempfile, time
from pathlib import Path
from refresh import official, snapshot, read, write, atomic, digest, now
from build_import_plan import plan

HERE=Path(__file__).resolve().parent
SITE=Path('/var/www/html/unlight')
STATE=Path('/var/lib/ulgg-card-assets')
PRIVATE=Path('/var/lib/ulgg-card-assets-private')
LOCK=Path('/run/lock/ulgg-git-sync.lock')

def cmd(*a):
    r=subprocess.run([str(x) for x in a],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    return r.stdout

def php_json(script,*args):
    try:
        return json.loads(cmd('/usr/bin/php','-d','opcache.enable_cli=0','-d','display_errors=stderr','-d','zend.exception_ignore_args=1',HERE/script,*args))
    except subprocess.CalledProcessError as e:
        import re
        err=e.stderr.decode('utf-8','replace') if e.stderr else ''
        match=re.search(r'Uncaught RuntimeException: ([A-Za-z0-9_ .-]{1,160}) in ',err)
        sqlstate=re.search(r'SQLSTATE\[([A-Z0-9]+)\]',err)
        startup=re.search(r'(Unable to create lock file|Unable to create shared memory|Read-only file system|Permission denied|opcache.file_cache[^\n]{0,80})',err,re.I)
        reason=match.group(1) if match else ('SQLSTATE '+sqlstate.group(1) if sqlstate else (startup.group(1) if startup else 'see owner-operated PHP diagnostic'))
        raise RuntimeError('PHP '+script+' '+(str(args[0]) if args and str(args[0]) in ('rehearse','apply','verify','rollback') else 'preflight')+' exit='+str(e.returncode)+' '+reason) from None

@contextlib.contextmanager
def writer_lock():
    import fcntl
    fd=os.open(str(LOCK),os.O_RDONLY|os.O_NOFOLLOW)
    try:
        s=os.fstat(fd)
        if not stat.S_ISREG(s.st_mode) or s.st_uid!=0:raise RuntimeError('unexpected existing writer lock')
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if LOCK.stat().st_ino!=s.st_ino:raise RuntimeError('writer lock inode changed')
        yield
    finally:os.close(fd)

def plan_key(p):
    return digest(json.dumps({k:p[k] for k in ('scope','changes','new_skills')},sort_keys=True,ensure_ascii=False,separators=(',',':')).encode())

def db_report():return php_json('preflight.php',SITE)

def prepare(work,c,f,metadata):
    atomic(work/'CharaCards.json',c,0o600);atomic(work/'Feats.json',f,0o600);write(work/'source.json',metadata,0o600)
    report=db_report()
    p=plan(report,json.loads(c),json.loads(f))
    if not p['scope']['released_ids']:raise RuntimeError('empty eligible scope')
    write(work/'import-plan.json',p,0o600)
    return report,p

def summary(p):
    from collections import Counter
    return {'released_cards':p['scope']['released_cards'],'skill_codes':p['scope']['skill_codes'],
            'excluded_cards':p['scope']['excluded_official_character_cards'],'updated':dict(Counter(x['table'] for x in p['changes'])),'new_skills':len(p['new_skills'])}

def audit(work):
    work.mkdir(mode=0o700,parents=True,exist_ok=False)
    c,f,m=official()
    with writer_lock():
        before,p=prepare(work,c,f,m)
        result=php_json('run_import.php','rehearse',SITE,work)
        after=db_report()
        for k in ('cards','skills','costs','notes_count','tags_count'):
            if before[k]!=after[k]:raise RuntimeError('rehearsal rollback mismatch: '+k)
    if result.get('status')!='ROLLED_BACK_REHEARSAL' or result.get('verification',{}).get('status')!='PASS':raise RuntimeError('rehearsal not verified')
    data={'plan_key':plan_key(p),'cards_sha256':digest(c),'feats_sha256':digest(f),'checked_at':now(),'summary':summary(p),'rehearsal':result}
    write(work/'approval.json',data,0o600);return data

def secure_dirs():
    for p in (STATE,PRIVATE):
        s=p.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid!=0 or s.st_mode&0o022:raise RuntimeError('unsafe state directory')
    if PRIVATE.stat().st_mode&0o077:raise RuntimeError('private directory not private')

def execute_once(approved=None):
    secure_dirs()
    if approved:
        approval=read(approved/'approval.json')
        c=(approved/'CharaCards.json').read_bytes();f=(approved/'Feats.json').read_bytes();m=read(approved/'source.json')
        if digest(c)!=approval['cards_sha256'] or digest(f)!=approval['feats_sha256']:raise RuntimeError('approved source hash changed')
    else:c,f,m=official()
    with writer_lock():
        work=Path(tempfile.mkdtemp(prefix='run-',dir=PRIVATE))
        _,p=prepare(work,c,f,m)
        if approved and plan_key(p)!=approval['plan_key']:raise RuntimeError('reviewed plan has changed; rerun audit')
        if (STATE/'status.json').exists():
            old=read(STATE/'status.json');from refresh import load_generation
            oldc,oldf=load_generation(STATE,old['generation'])
            if oldc.keys()-{x['id'] for x in json.loads(c)} or oldf.keys()-{x['id'] for x in json.loads(f)}:raise RuntimeError('official IDs disappeared')
        # Capture exact old dashboard pointers for recovery and retain prior immutable snapshots.
        for n in ('status.json','attempt.json','db-current.json','db-audit.json'):
            src=STATE/n
            if src.exists():atomic(work/('previous-'+n),src.read_bytes(),0o600)
        write(PRIVATE/'latest-run.json',{'work':str(work),'phase':'PREPARED','at':now()},0o600)
        # Source status and DB status are distinct; if DB fails dashboard shows mismatch.
        s=snapshot(STATE,c,f,m)
        backup=work/'backup.json'
        if p['changes'] or p['new_skills']:
            result=php_json('run_import.php','apply',SITE,work,backup)
            if result.get('status')!='COMMITTED' or result.get('verification',{}).get('status')!='PASS':raise RuntimeError('commit not verified')
            v=result['verification']
        else:
            v=php_json('run_import.php','verify',SITE,work)
            if v.get('status')!='PASS':raise RuntimeError('no-op verification failed')
        write(PRIVATE/'latest-run.json',{'work':str(work),'phase':'DB_VERIFIED','at':now()},0o600)
        version=STATE/'versions'/s['generation'];report=version/'db_changes.json'
        if p['changes'] or p['new_skills'] or not report.exists():
            write(report,{'generation':s['generation'],'applied_at':now(),'summary':summary(p),'changes':p['changes'],'new_skills':p['new_skills']})
        receipt={'status':'PASS','generation':s['generation'],'cards_sha256':s['cards_sha256'],'checked_at':now(),'verification':v,'summary':read(report)['summary']}
        write(STATE/'db-current.json',receipt);write(STATE/'db-audit.json',receipt);write(STATE/'attempt.json',{'status':'PASS','at':now()})
        write(PRIVATE/'latest-run.json',{'work':str(work),'phase':'PASS','at':now()},0o600)
        if approved:write(PRIVATE/'first-complete.json',receipt,0o600)
        return receipt

def recover_latest():
    secure_dirs()
    marker=PRIVATE/'latest-run.json'
    if not marker.exists():return {'status':'NO_DB_RUN'}
    p=read(marker);work=Path(p['work'])
    if work.parent!=PRIVATE or not work.name.startswith('run-') or not work.is_dir():raise RuntimeError('invalid recovery path')
    with writer_lock():
        backup=work/'backup.json'
        result=php_json('run_import.php','rollback',SITE,backup) if backup.exists() else {'status':'NO_COMMIT'}
        for n in ('status.json','attempt.json','db-current.json','db-audit.json'):
            old=work/('previous-'+n);dst=STATE/n
            if old.exists():atomic(dst,old.read_bytes())
            elif dst.exists():dst.unlink()
        write(marker,{'work':str(work),'phase':'ROLLED_BACK','at':now()},0o600)
    return result

def main():
    p=argparse.ArgumentParser();p.add_argument('mode',choices=['audit','sync','rollback']);p.add_argument('--work',type=Path);a=p.parse_args()
    if os.name!='posix' or os.geteuid()!=0:raise SystemExit('owner root on VPS only')
    os.umask(0o027)
    if a.mode=='audit':
        if not a.work:raise SystemExit('audit work path required')
        print(json.dumps(audit(a.work),ensure_ascii=False));return
    if a.mode=='rollback':
        print(json.dumps(recover_latest(),ensure_ascii=False));return
    approved=PRIVATE/'first-approved'
    if not (approved/'approval.json').is_file():approved=None
    if approved and (PRIVATE/'first-complete.json').exists():approved=None
    for i in range(1 if approved else 3):
        try:
            receipt=execute_once(approved)
            print('SYNC_PASS '+json.dumps(receipt,ensure_ascii=False),flush=True);return
        except Exception as e:
            # No PHP exception content or DB configuration is exposed in the web state.
            print('SYNC_FAIL '+type(e).__name__+' '+(str(e) if not isinstance(e,subprocess.CalledProcessError) else 'PHP/process verification failed'),flush=True)
            if STATE.is_dir():
                write(STATE/'attempt.json',{'status':'FAIL','at':now()});write(STATE/'db-audit.json',{'status':'FAIL','checked_at':now()})
            # Do not retry a transaction with uncertain postcommit state.
            last=read(PRIVATE/'latest-run.json') if (PRIVATE/'latest-run.json').exists() else {}
            if last.get('phase') in ('PREPARED','DB_VERIFIED') or approved or i==2:raise SystemExit(1)
            time.sleep(300)
if __name__=='__main__':main()
