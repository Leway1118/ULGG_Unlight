#!/usr/bin/env python3
"""Optional owner-operated Simple Deploy entry point, only creates inactive drafts after successful release."""
import argparse, hashlib, json, re, subprocess, sys
from pathlib import Path

RUNNER=Path('/root/ulgg-server-deploy.py')
DRAFT=Path('/var/www/html/unlight/scripts/news_draft.php')
LOCK='/run/lock/ulgg-git-sync.lock'
def receipt(bundle,expected,news):
    raw=bundle.read_bytes()
    if not re.fullmatch('[a-f0-9]{64}',expected) or hashlib.sha256(raw).hexdigest()!=expected:
        raise ValueError('bundle hash mismatch')
    name=json.loads(raw)['manifest']['release']
    if not re.fullmatch(r'[A-Za-z0-9_.-]{1,64}',name):raise ValueError('invalid release')
    if set(news)-{'type','title','body','link'}:raise ValueError('unexpected public news keys')
    return {'status':'RELEASE_PASS','exit_code':0,'release':name,'public_news':news}
def release_with_draft(bundle,expected,news,run=subprocess.run):
    r=receipt(bundle,expected,news)
    payload=json.dumps(r,ensure_ascii=False).encode('utf8')
    # Preview validates public copy before deployment; it does not load DB config.
    check=run(['/usr/bin/php',str(DRAFT),'--preview'],input=payload)
    if check.returncode:return check.returncode
    result=run([sys.executable,str(RUNNER),'release',str(bundle),'--sha256',expected])
    if result.returncode:return result.returncode
    # Existing runner returned success after its RELEASE_PASS gates, not a PR or audit.
    # Serialize duplicate checks with other owner-operated release hooks.
    try:
        draft=run(['/usr/bin/flock','-n',LOCK,'/usr/bin/php',str(DRAFT),'--insert-draft'],input=payload)
        if draft.returncode:
            print('RELEASE_SUCCEEDED_NEWS_DRAFT_PENDING: retry draft separately after reviewing release receipt',file=sys.stderr)
            return 3
    except OSError:
        print('RELEASE_SUCCEEDED_NEWS_DRAFT_PENDING: draft command unavailable',file=sys.stderr)
        return 3
    return 0
def main():
    p=argparse.ArgumentParser();p.add_argument('bundle',type=Path);p.add_argument('--sha256',required=True);p.add_argument('--news',type=Path,required=True);a=p.parse_args()
    if not sys.stdin.isatty():raise SystemExit('interactive owner approval required by deployment runner')
    news=json.loads(a.news.read_text(encoding='utf-8-sig'))
    if not isinstance(news,dict):raise SystemExit('news object required')
    raise SystemExit(release_with_draft(a.bundle,a.sha256,news))
if __name__=='__main__':main()
