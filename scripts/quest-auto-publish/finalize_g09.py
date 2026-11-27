"""Enrich the candidate from the exact R2 source and verify reconciliation.
No production access. Never marks a release approved."""
import sys,json,collections,math,tempfile,statistics,hashlib
from pathlib import Path
import daily_prepare as dp
import convert_v141 as cv
R=cv.ROOT
F=R/'full_candidate'
def check(v,msg):
    if not v:raise ValueError(msg)
def load(p):return json.loads(p.read_bytes())
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def write(p,x):cv.write(p,x)
def main(repo):
    sys.path.insert(0,str(repo/'tool/ulgg-quest-public'))
    import export_quest_public as ex
    prep=load(R/'last_prepare.json')
    srcdir=R/'staging'/prep['release']
    m=load(F/'manifest.json')
    check(m['as_of_utc']==load(srcdir/'manifest.json')['as_of_utc'],'release anchor')
    a,ha=dp.read_snapshot(repo/'tool/unlight-card-tracker/detector/data/quest_history_v4_shadow.jsonl')
    b,hb=dp.read_snapshot(repo/'tool/unlight-card-tracker/detector/data/quest_history.jsonl')
    check((ha,hb)==(prep['v4_sha'],prep['v3_sha']),'snapshot drift; rebuild before finalizing')
    merged,_,_=dp.merge(a,b)
    anchor=ex.parse_utc(m['as_of_utc'])
    catalog=load(F/'quest_catalog.json')['rows']
    keys={r['quest_key']:r for r in catalog}
    names=collections.defaultdict(list)
    for r in catalog:names[(r['zone_key'],r['quest_name'])].append(r['quest_key'])
    audit={}
    def enrich(rows,rr,window):
        groups=collections.defaultdict(list)
        for r in rr:
            if not r.get('eligible'):continue
            ks=names.get((str(r.get('zone_key')),r.get('quest_name')),[])
            if len(ks)==1:groups[ks[0]].append(r)
        seen=set()
        for row in rows:
            k=row['quest_key']; check(k in keys and k not in seen,'identity '+k);seen.add(k)
            check(row['zone_key']==keys[k]['zone_key'] and row['quest_id']==keys[k]['quest_id'],'catalog mismatch')
            runs=groups[k]
            # Never label LEG or NOM as measured. Ambiguous names are not guessed.
            times=[r for r in runs if isinstance(r.get('elapsed_sec'),(float,int)) and r['elapsed_sec']>0]
            act=[r for r in times if r.get('qp_mode')=='ACT' and isinstance(r.get('qp'),(float,int)) and r['qp']>=0]
            vals=[r for r in times if isinstance(r.get('value_estimate'),(float,int)) and r['value_estimate']>=0]
            def metric(value,n,basis):
                return {'value':value,'samples':n,'basis':basis}
            row['qa_metrics']={
              'qp':metric(round(statistics.mean(r['qp'] for r in act),2) if act else None,len(act),'actual_qp'),
              'qph':metric(round(sum(r['qp'] for r in act)*3600/sum(r['elapsed_sec'] for r in act),2) if act else None,len(act),'actual_qp'),
              'time':metric(round(statistics.median(r['elapsed_sec'] for r in times),3) if times else None,len(times),'observed_elapsed'),
              'valh':metric(round(sum(r['value_estimate'] for r in vals)*3600/sum(r['elapsed_sec'] for r in vals),2) if vals else None,len(vals),'observed_run_valuation')}
            if row.get('observed') and len(names[(row['zone_key'],row['quest_name'])])==1:
                check(len(runs)==row['occurrences' if window.endswith(' duration') else 'completed_count'],'completed count '+window+' '+k)
                if window.endswith(' duration'):row['completed_count']=len(runs)
            for v in row['qa_metrics'].values():
                check(v['value'] is None or (math.isfinite(v['value']) and v['value']>=0),'metric invalid')
        return sum(r['qa_metrics']['qp']['samples'] for r in rows)
    with tempfile.TemporaryDirectory(dir=R) as tmp:
        p=Path(tmp)/'history.jsonl'
        p.write_bytes(b''.join((json.dumps(r,ensure_ascii=False)+'\n').encode() for r in merged))
        source=ex.LiveSource(ex.DEFAULT_PANEL,anchor);source.module.HISTORY_PATH=p;source.module.pymysql=None
        runs=source.all_runs();panel=source.module
        for w in cv.WINDOWS:
            rr=panel.filter_runs(runs,w,'all');eligible=[r for r in rr if r.get('eligible')]
            orig=load(srcdir/(w+'.json'))
            overview=load(F/'overview.json')['by_window'][w]
            check(overview['eligible_samples']==len(eligible) and overview['total_run_ids']==len(rr),'overview run reconciliation '+w)
            check(overview['eligible_samples']==orig['overview']['eligible'],'exporter count '+w)
            path=F/f'windows/{w}/quests.json';d=load(path)
            check(d['count']==len(d['rows'])==len(orig['quests']),'quest inventory '+w)
            n=enrich(d['rows'],rr,w);write(path,d)
            path=F/f'windows/{w}/quests_duration.json';d=load(path)
            check([x['duration_index'] for x in d['durations']]==list(range(11)),'duration inventory')
            for block in d['durations']:
                check(block['count']==len(block['rows']),'duration count')
                check(all(r['qp_samples']>=5 and r['value_samples']>=5 for r in block['rows']),'duration sample gate')
                enrich(block['rows'],panel.filter_runs(runs,w,str(block['duration_index'])),w+' duration')
            write(path,d)
            path=F/f'windows/{w}/regions.json';d=load(path)
            by={r['zone_key']:r for r in orig['regions']}
            for r in d['rows']:
                check(all(r[x]==by[r['zone_key']][x] for x in ('qph','valh','qph_samples','valh_samples','eligible_samples')),'region metrics '+w)
            star=load(F/f'windows/{w}/star_matrix.json')
            for row in star['rows']:
                check(sum(row['counts'].values())==row['samples'],'star samples')
            audit[w]={'eligible':len(eligible),'actual_qp_samples_matched':n}
        teams=load(F/'teams_all.json')
        for t in teams['rows']:
            check(min(t['eligible_samples'],t['qph_samples'],t['valh_samples'])>=5,'team sample gate')
            check(all(r['samples']>=5 for r in t['regions']+t['quests']),'association threshold')
    for x in m['files']:
        p=F/x['path'];x.update(bytes=p.stat().st_size,sha256=sha(p))
    write(F/'manifest.json',m)
    write(R/'G09_SEMANTIC_REPORT.json',{'status':'PASS_SELECTED_RECONCILIATION','source_release':prep['release'],'windows':audit,'limitations':['ambiguous names not assigned actual metrics','VAL remains valuation estimate','native panel aggregate policy preserved','actual run comparison for quest counts, region metrics, duration thresholds, star sums and team thresholds; not exhaustive independent formula audit']})
    print(json.dumps(audit))
if __name__=='__main__':main(Path(sys.argv[1]))
