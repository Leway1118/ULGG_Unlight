#!/usr/bin/env python3
"""V3 snapshot contract, two official mirrors; retain immutable weekly and previous-generation reports."""
import argparse, hashlib, json, os, re, tempfile, time
from pathlib import Path
from urllib.parse import urlsplit
from urllib.request import Request, urlopen
from datetime import datetime, timezone, timedelta
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

CONFIG='https://www.playunlight.online/unlight-config.json'
CARD_PATH='/images/assets/Data/CharaCards.json'
FEAT_PATH='/images/assets/Data/data-tcn/Feats.json'
LIMIT=10*1024*1024

def now():return datetime.now(timezone.utc).isoformat()
def read(p):return json.loads(p.read_bytes())
def canon(rows):return json.dumps(sorted(rows,key=lambda r:r['id']),ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
def digest(b):return hashlib.sha256(b).hexdigest()
def atomic(p,b,mode=0o640):
    p.parent.mkdir(parents=True,exist_ok=True)
    fd,t=tempfile.mkstemp(dir=p.parent,prefix='.'+p.name)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),mode) if hasattr(os,'fchmod') else None
            f.write(b);f.flush();os.fsync(f.fileno())
        os.replace(t,p)
    finally:
        if os.path.exists(t):os.unlink(t)
def write(p,obj,mode=0o640):atomic(p,json.dumps(obj,ensure_ascii=False,indent=2).encode(),mode)
def fetch(url):
    with urlopen(Request(url,headers={'User-Agent':'ULGG-CardAssets/1.0','Accept':'application/json'}),timeout=30) as r:
        if urlsplit(r.geturl()).scheme!='https' or urlsplit(r.geturl()).hostname!=urlsplit(url).hostname:raise ValueError('unexpected redirect')
        raw=r.read(LIMIT+1)
        if len(raw)>LIMIT:raise ValueError('response too large')
        return json.loads(raw),r.headers.get('Last-Modified')
def check(rows,feat=False):
    if not isinstance(rows,list) or len(rows)<(1156 if feat else 941):raise ValueError('record count below verified baseline')
    ids={}
    for r in rows:
        if not isinstance(r,dict) or type(r.get('id')) is not int or r['id'] in ids:raise ValueError('invalid or duplicate ID')
        if feat:
            if any(not isinstance(r.get(k),str) for k in ('texture','name_tcn','caption_tcn')) or type(r.get('phase')) is not int or any(not isinstance(r.get(k),list) for k in ('require','distance')):raise ValueError('invalid skill schema')
        else:
            if any(type(r.get(k)) is not int for k in ('cost','hp','atk','def','kind','level')) or any(not isinstance(r.get(k),str) for k in ('filename','chara')):raise ValueError('invalid card schema')
            if r['kind']==0 and (not isinstance(r.get('skill'),list) or any(type(x) is not int for x in r['skill'])):raise ValueError('invalid card skill IDs')
        ids[r['id']]=r
    if not feat and sum(r['kind']==0 and r['chara'].startswith('cc') for r in rows)<710:raise ValueError('character count below verified baseline')
    return ids
def official():
    cfg,_=fetch(CONFIG);hosts=list(dict.fromkeys(cfg['domains']['assets']['urls']))
    if len(hosts)<2:raise ValueError('need two official mirrors')
    bodies=[];meta=[]
    for h in hosts[:2]:
        u=urlsplit(h)
        if u.scheme!='https' or not u.hostname or u.username or u.password or u.port or u.path not in ('','/') or u.query or u.fragment:raise ValueError('invalid mirror URL')
        # Reject IPs, localhost, and unrelated hosts. A new domain format requires review.
        if not re.fullmatch(r'www\.playunlight-[a-z0-9-]+\.(site|com|blog|online)',u.hostname):raise ValueError('unrecognized official CDN hostname')
        c,cm=fetch(h.rstrip('/')+CARD_PATH);f,fm=fetch(h.rstrip('/')+FEAT_PATH)
        check(c);check(f,True);bodies.append((canon(c),canon(f)))
        meta.append({'host':h,'cards_last_modified':cm,'feats_last_modified':fm})
    if bodies[0]!=bodies[1]:raise ValueError('official mirrors disagree')
    return bodies[0][0],bodies[0][1],meta
def detail(old,new):
    if old.keys()-new.keys():raise ValueError('official IDs disappeared; review needed')
    return {'added':[new[i] for i in sorted(new.keys()-old.keys())] if old else [],
            'changed':[{'id':i,'label':new[i].get('filename',new[i].get('name_tcn','')),
            'fields':{k:{'before':old[i].get(k),'after':new[i].get(k)} for k in sorted(old[i].keys()|new[i].keys()) if old[i].get(k)!=new[i].get(k)}} for i in sorted(old.keys()&new.keys()) if old[i]!=new[i]]}
def load_generation(state,g):
    if not isinstance(g,str) or not re.fullmatch('[a-f0-9]{64}',g):raise ValueError('bad generation')
    p=state/'versions'/g;c=(p/'CharaCards.json').read_bytes();f=(p/'Feats.json').read_bytes()
    if digest((digest(c)+digest(f)).encode())!=g:raise ValueError('previous snapshot hash mismatch')
    return check(json.loads(c)),check(json.loads(f),True)
def snapshot(state,card_blob,feat_blob,metadata,stamp=None):
    stamp=stamp or datetime.now(timezone.utc);at=stamp.isoformat()
    new=check(json.loads(card_blob));nf=check(json.loads(feat_blob),True)
    sha=digest(card_blob);fsha=digest(feat_blob);g=digest((sha+fsha).encode())
    pointer=state/'status.json';old_status=read(pointer) if pointer.exists() else None
    oldg=old_status['generation'] if old_status else None
    old,of=load_generation(state,oldg) if oldg else ({},{})
    version=state/'versions'/g;version.mkdir(parents=True,exist_ok=True)
    for name,blob in [('CharaCards.json',card_blob),('Feats.json',feat_blob)]:
        p=version/name
        if p.exists() and p.read_bytes()!=blob:raise ValueError('immutable snapshot mismatch')
        if not p.exists():atomic(p,blob)
    try: taipei=ZoneInfo('Asia/Taipei')
    except ZoneInfoNotFoundError:
        if os.name!='nt':raise
        taipei=timezone(timedelta(hours=8)) # modern Taiwan; Windows test fallback only
    iso=stamp.astimezone(taipei).isocalendar();week=f'{iso.year}-W{iso.week:02d}'
    same=old_status and old_status.get('week')==week
    baseline=old_status.get('week_baseline') if same else oldg
    wo,wf=load_generation(state,baseline) if baseline else ({},{})
    wc=detail(wo,new);ws=detail(wf,nf)
    def counts(d):return {k:len(v) for k,v in d.items()}
    if oldg!=g:
        d={'generation':g,'previous_generation':oldg,'baseline_known':bool(oldg),'checked_at':at,'cards':detail(old,new),'feats':detail(of,nf)}
        # Version reports may already exist after a previous rollback/retry. Keep generation-specific source files immutable.
        if not (version/'changes.json').exists():write(version/'changes.json',d)
        prev=oldg;last=at;changes=d
    else:
        prev=old_status.get('previous_generation');last=old_status.get('last_change_at',at);changes=read(version/'changes.json')
    weekfile=state/'weeks'/week/(g+'.json')
    if not weekfile.exists():write(weekfile,{'week':week,'generation':g,'baseline_generation':baseline,'baseline_known':bool(baseline),'cards':wc,'feats':ws})
    refs={sid for r in new.values() if r['kind']==0 for sid in r['skill']}
    result={'status':'NOOP' if oldg==g else 'UPDATED','checked_at':at,'last_change_at':last,'generation':g,'previous_generation':prev,'cards_sha256':sha,'feats_sha256':fsha,'count':len(new),'character_count':len({r['chara'] for r in new.values() if r['kind']==0}),'character_cards':sum(r['kind']==0 for r in new.values()),'feat_count':len(nf),'mirrors':metadata,'baseline_created':not bool(prev),'cards':counts(changes['cards']),'feats':counts(changes['feats']),'week':week,'week_baseline':baseline,'week_cards':counts(wc),'week_feats':counts(ws),'unresolved_skill_ids':sorted(refs-nf.keys())}
    write(pointer,result);return result
