"""Daily candidate preparation. No VPS access or production writes."""
from pathlib import Path
from collections import defaultdict,Counter
from datetime import datetime,timezone,timedelta
import json,hashlib,sys,tempfile,os,unittest
ROOT=Path(__file__).resolve().parent
REPO=Path(sys.argv[1]) if len(sys.argv)>1 else None
TZ=timezone(timedelta(hours=8))
def dt(s):return datetime.fromisoformat(s.replace("Z","+00:00"))
def digest(b):return hashlib.sha256(b).hexdigest()
def read_snapshot(p):
    raw=p.read_bytes()
    if raw and not raw.endswith(b"\n"):raise ValueError("source ends with incomplete line")
    rows=[json.loads(l) for l in raw.splitlines() if l.strip()]
    if not rows or any(not isinstance(x,dict) for x in rows):raise ValueError("invalid source")
    return rows,digest(raw)
def ident(x):
    q=x.get("quest") or {}
    return tuple(q.get(k) for k in ("quest_pid","map_id","region_id","quest_id"))
from pair_v3 import merge
def write_atomic(p,obj):
    tmp=p.with_suffix(".tmp");tmp.write_text(json.dumps(obj,indent=2,ensure_ascii=False),encoding="utf-8");os.replace(tmp,p)
def activate(root,release,verify,accept):
    result=verify(root/release)
    if not result["ok"]:raise ValueError("manifest verification failed")
    ptr=root/"candidate_pointer.json"
    old=json.loads(ptr.read_text()) if ptr.exists() else {}
    if not accept(root/release):raise ValueError("acceptance rejected; pointer unchanged")
    write_atomic(ptr,{"current":release,"previous":old.get("current"),"mode":"LOCAL_STAGING_ONLY"})
def main(repo):
    sys.path.insert(0,str(repo/"tool/ulgg-quest-public"))
    import export_quest_public as ex
    import publish_stage as ps
    data=repo/"tool/unlight-card-tracker/detector/data"
    out=ROOT/"staging";out.mkdir(exist_ok=True)
    # An exclusive file prevents overlapping manual/daily preparations.
    lock=ROOT/"prepare.lock"
    fd=os.open(lock,os.O_CREAT|os.O_EXCL|os.O_WRONLY)
    try:
        os.close(fd)
        v4,h4=read_snapshot(data/"quest_history_v4_shadow.jsonl");v3,h3=read_snapshot(data/"quest_history.jsonl")
        rows,counts,cutoff=merge(v4,v3)
        anchor=datetime.now(timezone.utc).replace(microsecond=0)
        day=anchor.astimezone(TZ).date().isoformat()
        code_sha=digest(Path(__file__).read_bytes()+ (ROOT/"pair_v3.py").read_bytes())
        report={"code_sha":code_sha,"mode":"LOCAL_STAGING_ONLY","date":day,"v4_records":len(v4),"v3_records":len(v3),"v4_sha":h4,"v3_sha":h3,"counts":counts,"cutoff":cutoff,"production":"BLOCKED_FORMAT_AND_PUBLISH_ENTRY"}
        last=ROOT/"last_prepare.json"
        old=json.loads(last.read_text()) if last.exists() else {}
        if old.get("code_sha")==code_sha and old.get("date")==day and old.get("v4_sha")==h4 and old.get("v3_sha")==h3 and old.get("release") and ps.verify_release(out/old["release"])["ok"]:
            print("SKIP_SAME_DAY_UNCHANGED");return
        with tempfile.TemporaryDirectory(prefix="private-snapshot-",dir=ROOT) as tmp:
            snap=Path(tmp)/"history.jsonl"
            snap.write_bytes(b"".join((json.dumps(r,ensure_ascii=False)+"\n").encode("utf-8") for r in rows))
            source=ex.LiveSource(ex.DEFAULT_PANEL,anchor)
            source.module.HISTORY_PATH=snap
            # Candidate never connects to MySQL. No schema or recorder writes.
            source.module.pymysql=None
            payload=ex.ReleaseBuilder(source,anchor,datetime.now(timezone.utc).replace(microsecond=0)).build_release()
            payload.update(as_of=anchor,generated_at=datetime.now(timezone.utc).replace(microsecond=0),source_kind="local_snapshot")
            result=ex.write_release(payload,out,allow_outside=True)
        report["release"]=result["release_id"]
        activate(out,result["release_id"],ps.verify_release,lambda p:True)
        report["verified"]=True;report["manifest_privacy_findings"]=result["manifest"]["privacy"]["scanner_findings"]
        write_atomic(last,report)
        print(json.dumps(report))
    finally:lock.unlink(missing_ok=True)
if __name__=="__main__":main(REPO)
