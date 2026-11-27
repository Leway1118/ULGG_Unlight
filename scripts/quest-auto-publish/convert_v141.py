"""Partial, offline compatibility candidate. Never writes a production tree."""
from pathlib import Path
import json,hashlib
ROOT=Path(__file__).resolve().parent
SRC=ROOT/"staging"/"20261127T042213Z-2d4daf1a"
REF=ROOT/"contract_reference"/"data"
OUT=ROOT/"compat_preview"
WINDOWS=("today","7d","30d","all")
def load(p): return json.loads(p.read_bytes())
def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def write(p,d):
    p.parent.mkdir(parents=True,exist_ok=True)
    p.write_bytes((json.dumps(d,ensure_ascii=False,indent=2,allow_nan=False)+"\n").encode("utf-8"))
def verify_source():
    m=load(SRC/"manifest.json")
    for f in m["files"]:
        p=SRC/f["name"]
        if p.stat().st_size!=f["bytes"] or sha(p)!=f["sha256"]: raise ValueError("source integrity")
    return m
def build():
    m=verify_source()
    catalog=load(REF/"quest_catalog.json")["rows"]
    index={(r["zone_key"],r["quest_id"]):r for r in catalog}
    if len(index)!=len(catalog): raise ValueError("ambiguous catalog keys")
    audit={}
    for w in WINDOWS:
        d=load(SRC/(w+".json"))
        if d["as_of_utc"]!=m["as_of_utc"]: raise ValueError("anchor mismatch")
        rows=[]; unmatched=[]
        for r in d["quests"]:
            cat=index.get((r["zone_key"],r["quest_id"]))
            if not cat or cat["quest_name"]!=r["quest_name"]:
                unmatched.append(r);continue
            row={k:r[k] for k in ("zone_key","region_name","quest_id","quest_name","quest_type","star","occurrences","completed_count","qp","qph","qp_samples","valh","value_samples","search_ap","execute_ap","min_battles","median_sec","median_text","mean_text")}
            row.update(quest_key=cat["quest_key"],observed=bool(r["has_run"]),average_value=r["value_per_quest"],name_ambiguous=cat["name_ambiguous"])
            rows.append(row)
        regions=[]
        for r in d["regions"]:
            row={k:r[k] for k in ("zone_key","eligible_samples","qph","qph_samples","valh","valh_samples","confidence","coverage_percent","median_text","mean_text","total_qp","total_value","composite_score")}
            row.update(zone_name=r["region_name"],**r["ap"])
            regions.append(row)
        for kind,items in (("quests",rows),("regions",regions)):
            write(OUT/"windows"/w/(kind+".json"),dict(window=w,as_of_utc=d["as_of_utc"],kind=kind,duration_scope=d["duration_scope"],count=len(items),rows=items))
        audit[w]={"matched":len(rows),"unmatched":len(unmatched),"regions":len(regions)}
    holds=["unmatched identities","duration statistics not rebuilt","team associations not rebuilt","G2 pinned sources and counts","production manifest drift","least privilege not verified"]
    files=[dict(path=p.relative_to(OUT).as_posix(),bytes=p.stat().st_size,sha256=sha(p)) for p in sorted(OUT.glob("windows/*/*.json"))]
    write(OUT/"preview_manifest.json",dict(status="HOLD",source_release=m["release_id"],as_of_utc=m["as_of_utc"],files=files,audit=audit,holds=holds))
    print(json.dumps(audit))
if __name__=="__main__":build()
