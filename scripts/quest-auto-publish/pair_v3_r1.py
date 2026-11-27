"""Conservative V3 admission; raw records are never modified."""
from collections import defaultdict, Counter
from datetime import datetime
import json, math

def dt(s):
    return datetime.fromisoformat(s.replace("Z","+00:00"))

def ident(r):
    q=r.get("quest") or {}
    return tuple(q.get(k) for k in ("quest_pid","map_id","region_id","quest_id"))

def stable_native(a,b):
    ia,ib=a.get("identity") or {},b.get("identity") or {}
    keys=("map_id","region_id","quest_id","duration_index","runtime_map_id","runtime_region_id","runtime_quest_id","runtime_duration_index","bucket_key")
    if not all(ia.get(k) is not None and ia[k]==ib.get(k) for k in keys):
        return False
    qa,qb=a.get("quest") or {},b.get("quest") or {}
    return (all(qa.get(k)==ia.get("runtime_"+k) for k in ("map_id","region_id","quest_id","duration_index"))
            and ia["quest_id"]==ia["runtime_quest_id"]
            and qb.get("quest_finished") is True and qb.get("quest_end_result")=="win"
            and ia["bucket_key"]==":".join(str(ia[k]) for k in ("map_id","region_id","duration_index")))

def merge(v4,v3):
    cutoff=max(dt(r["ts"]) for r in v4)
    ids={r.get("run_id") for r in v4}
    pids={ident(r)[0] for r in v4 if ident(r)[0]}
    groups=defaultdict(list)
    for r in v3: groups[r.get("run_id")].append(r)
    counts=Counter(); out=list(v4)
    for rid,g in sorted(groups.items(),key=lambda x:str(x[0])):
        if not any(dt(r["ts"])>cutoff for r in g): continue
        unique={json.dumps(r,sort_keys=True,ensure_ascii=False):r for r in g}
        if len(unique)!=len(g): counts["duplicate_events_removed"]+=len(g)-len(unique)
        g=list(unique.values())
        starts=[r for r in g if r["event"]=="START"]
        clears=[r for r in g if r["event"]=="CLEAR"]
        if rid and len(g)==2 and len(starts)==1 and sum(r["event"]=="FAIL" for r in g)==1:
            counts["failed_terminal"]+=1; continue
        if rid and len(g)==1 and len(starts)==1:
            counts["incomplete"]+=1; continue
        if not rid or len(g)!=2 or len(starts)!=1 or len(clears)!=1:
            counts["not_paired"]+=1; continue
        a,b=starts[0],clears[0]
        if rid in ids or dt(a["ts"])<=cutoff: counts["overlap"]+=1; continue
        ia,ib=ident(a),ident(b)
        if None in ia or None in ib or ia[:3]!=ib[:3]:
            counts["identity_mismatch"]+=1; continue
        drift=ia!=ib
        if drift and not stable_native(a,b):
            counts["identity_mismatch"]+=1; continue
        if ia[0] in pids: counts["duplicate_quest"]+=1; continue
        elapsed=b.get("elapsed_sec")
        if dt(a["ts"])>=dt(b["ts"]) or isinstance(elapsed,bool) or not isinstance(elapsed,(int,float)) or not math.isfinite(elapsed) or elapsed<=0:
            counts["invalid_elapsed"]+=1; continue
        if any((r.get("extra") or {}).get(k) for r in (a,b) for k in ("synthetic_start","orphan_clear","interrupted_observation")):
            counts["untrusted"]+=1; continue
        ids.add(rid); pids.add(ia[0]); out.extend([a,b]); counts["accepted_pairs"]+=1
        if drift: counts["accepted_native_drift"]+=1
        if ia[1]==28: counts["map28_pairs"]+=1
    return out,dict(counts),cutoff.isoformat()
