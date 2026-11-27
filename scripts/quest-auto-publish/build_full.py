"""Offline v1.4.1 tree from the exact R2 input snapshot; no production writes."""
import sys,json,hashlib,tempfile,statistics,collections,math
from datetime import timedelta,timezone
# R2_CONTRACT_COMPLETION
from pathlib import Path
from datetime import datetime
import daily_prepare as dp
import convert_v141 as cv
R=cv.ROOT
OUT=R/'full_candidate'
def main(repo):
    sys.path.insert(0,str(repo/'tool/ulgg-quest-public'))
    import export_quest_public as ex
    old=cv.load(cv.REF/'manifest.json')
    cv.SRC=R/"staging"/cv.load(R/"last_prepare.json")["release"]
    release=cv.verify_source()
    anchor=ex.parse_utc(release['as_of_utc'])
    data=repo/'tool/unlight-card-tracker/detector/data'
    a,ha=dp.read_snapshot(data/'quest_history_v4_shadow.jsonl')
    b,hb=dp.read_snapshot(data/'quest_history.jsonl')
    expected=cv.load(R/'last_prepare.json')
    if (ha,hb)!=(expected['v4_sha'],expected['v3_sha']):raise ValueError('source snapshot drift; regenerate R2 first')
    merged,counts,_=dp.merge(a,b)
    catalog=cv.load(cv.REF/'quest_catalog.json')
    ix={(x['zone_key'],x['quest_id']):x for x in catalog['rows']}
    def identity(r):
        c=ix.get((r['zone_key'],r['quest_id']))
        if c and c['quest_name']==r['quest_name']:return c
        if r['quest_id'] is None:
            matches=[x for x in catalog['rows'] if x['zone_key']==r['zone_key'] and x['quest_name']==r['quest_name']]
            if len(matches)==1 and matches[0]['quest_id']==0:return matches[0]
        return None
    def row(r):
        c=identity(r)
        if not c:return None
        return {**r,'quest_key':c['quest_key'],'quest_id':c['quest_id'],'observed':bool(r['has_run']),'average_value':r['value_per_quest'],'name_ambiguous':c['name_ambiguous'],'low_sample':min(r['qp_samples'],r['value_samples'])<5}
    def put(path,obj):
        cv.write(OUT/path,{**obj,'as_of_utc':release['as_of_utc']})
    with tempfile.TemporaryDirectory(dir=R) as tmp:
        p=Path(tmp)/'history.jsonl'
        p.write_bytes(b''.join((json.dumps(r,ensure_ascii=False)+'\n').encode() for r in merged))
        src=ex.LiveSource(ex.DEFAULT_PANEL,anchor);src.module.HISTORY_PATH=p;src.module.pymysql=None
        runs=src.all_runs()
        panel=src.module
        all_doc=cv.load(cv.SRC/'all.json')
        observed={x['quest_key'] for r in all_doc['quests'] if (x:=row(r)) and x['observed']}
        for c in catalog['rows']:c['observed_all_period']=c['quest_key'] in observed
        put('quest_catalog.json',catalog)
        audit={}
        qref=cv.load(cv.REF/'quality.json')
        labels=qref['duration_labels']
        tref=cv.load(cv.REF/'teams_all.json')
        panel_version=src.window_snapshot('all')['version']
        native_source=src.window_snapshot('all')['source']
        all_summary=src.window_snapshot('all')['summary']
        def public_status(name):
            raw=native_source.get(name) or {}
            return {k:raw.get(k) for k in qref[name]}
        def ratio(n,d):return round(n/d,4) if d else 0.0
        overview={};quality={}
        for w in cv.WINDOWS:
            d=cv.load(cv.SRC/(w+'.json'))
            qr=[x for r in d['quests'] if (x:=row(r)) is not None]
            for x in qr:x['observed_all_period']=x['quest_key'] in observed
            snap=src.window_snapshot(w)
            summary=snap['summary']
            native_regions={str(x['zone_key']):x for x in summary['zone_ranking']}
            wr=panel.filter_runs(runs,w,'all')
            eligible=[r for r in wr if r.get('eligible')]
            rr=[]
            for x in d['regions']:
                native=native_regions[x['zone_key']]
                zeligible=[r for r in eligible if str(r.get('zone_key'))==x['zone_key']]
                value_n=sum(r.get('value_estimate') is not None and (r.get('elapsed_sec') or 0)>0 for r in zeligible)
                rr.append({**x,'zone_name':x['region_name'],**x['ap'],'discard_rate_percent':x['discard']['rate_percent'],
                           **{k:native.get(k) for k in ['average_qp','average_value','best_duration','best_duration_qph','value_per_ap']},
                           'value_coverage_percent':round(100*ratio(value_n,len(zeligible)),1)})
            for k,rows in [('quests',qr),('regions',rr)]:
                put(f'windows/{w}/{k}.json',dict(window=w,kind=k,duration_scope='all',count=len(rows),rows=rows))
            blocks=[]
            for i in range(11):
                selected=panel.filter_runs(runs,w,str(i))
                cells=[]
                for z in sorted({r['zone_key'] for r in selected if r.get('eligible') and r.get('zone_key') not in (None,'unknown')}):
                    for raw in panel.build_current_region_quest_stats_all_durations(selected,z):
                        x=row(ex.project_quest({'zone_key':z,'region_name':panel.load_zone_names().get(z)},raw))
                        if x and x['qp_samples']>=5 and x['value_samples']>=5:cells.append(x)
                blocks.append(dict(duration_index=i,duration=labels[str(i)],rows=cells,count=len(cells)))
            put(f'windows/{w}/quests_duration.json',dict(window=w,kind='quests_duration',min_qp_samples=5,min_valh_samples=5,durations=blocks,suppression='minimum_5_each'))
            star=all_doc['star_matrix']
            starrows=[]
            for sr in star['duration_rows']:
                counts=sr['counts']
                if isinstance(counts,list):
                    if len(counts)!=len(star['stars']):raise ValueError('star count shape')
                    counts=dict(zip(map(str,star['stars']),counts))
                starrows.append({**sr,'counts':counts})
            put(f'windows/{w}/star_matrix.json',dict(window=w,kind='star_matrix',matrix_scope=star['scope'],window_filtered=False,model=star['model'],stars=star['stars'],baseline_total=star['baseline_total'],resolved=star['resolved'],hidden_unknown=star['hidden_unknown'],prior_strength=star['prior_strength'],prior_duration_index=star['prior_duration_index'],count=len(starrows),rows=starrows,source=cv.load(cv.REF/f'windows/{w}/star_matrix.json')['source']))
            qknown=[r for r in eligible if r.get('qp') is not None and (r.get('elapsed_sec') or 0)>0]
            vknown=[r for r in eligible if r.get('value_estimate') is not None and (r.get('elapsed_sec') or 0)>0]
            qc,qr_cov=panel._confidence(len(eligible),len(qknown))
            vc,vr_cov=panel._confidence(len(eligible),len(vknown))
            best=max((r for r in rr if r['qph'] is not None),key=lambda r:r['qph'],default={})
            overview[w]={'eligible_samples':d['overview']['eligible'],'total_run_ids':d['overview']['runs_total'],'region_count':len(rr),
                         'qp_coverage_ratio':round(qr_cov,4),'qp_coverage_percent':round(qr_cov*100,1),
                         'value_coverage_ratio':round(vr_cov,4),'value_coverage_percent':round(vr_cov*100,1),
                         'confidence':qc,'value_confidence':vc,
                         'total_qp':round(sum(r['qp'] for r in qknown),2) if qknown else None,
                         'total_value':round(sum(r['value_estimate'] for r in vknown),2) if vknown else None,
                         'best_region_zone_key':best.get('zone_key'),'best_region_zone_name':best.get('zone_name'),
                         'best_region_qph':best.get('qph'),'best_region_valh':best.get('valh'),'best_region_median_text':best.get('median_text'),
                         'wallclock_samples':summary['wallclock']['samples']}
            quality[w]={'history_exists':True,'diagnostics':{**d['overview']['diagnostics'],'eligible':d['overview']['eligible'],'total_run_ids':d['overview']['runs_total']},'star_hidden_unknown':star['hidden_unknown']}
            audit[w]={'unmatched':len(d['quests'])-len(qr),'duration_cells':sum(x['count'] for x in blocks)}
        teams=[]
        builder=ex.ReleaseBuilder(src,anchor)
        for t in builder.build_teams():
            if min(t['eligible'],t['qph_samples'],t['valh_samples'])<5:continue
            rr=[r for r in runs if r.get('eligible') and src.team_of(r)==t['team']]
            zones=collections.Counter(r.get('zone_key') for r in rr)
            qs=collections.Counter()
            for r in rr:
                z=r.get('zone_key'); qname=r.get('quest_name')
                candidates=[c for c in catalog['rows'] if c['zone_key']==z and c['quest_name']==qname]
                if len(candidates)==1:qs[candidates[0]['quest_key']]+=1
            bykey={c['quest_key']:c for c in catalog['rows']}
            modes={r.get('team_mode') for r in rr if r.get('team_mode')}
            # Composite score uses the existing Panel P90 geometric weighting.
            ref=all_summary['composite_contract']
            qref90=ref['qp_ref_p90'];vref90=ref['val_ref_p90']
            score=round(100*(t['qph']/qref90)**.60*(math.log1p(t['valh'])/math.log1p(vref90))**.40,2) if qref90 and vref90 and t['qph'] is not None and t['valh'] is not None else None
            teams.append({**t,'team_mode':next(iter(modes)) if len(modes)==1 else None,'composite_score':score,'eligible_samples':t['eligible'],'elapsed_seconds':round(sum(r['elapsed_sec'] for r in rr if r.get('elapsed_sec')),2),'region_count':sum(n>=5 for z,n in zones.items() if z),'quest_count':sum(n>=5 for n in qs.values()),'regions':[dict(zone_key=z,zone_name=panel.load_zone_names().get(z,z),samples=n) for z,n in zones.items() if z and n>=5],'quests':[dict(quest_key=k,zone_key=bykey[k]['zone_key'],quest_name=bykey[k]['quest_name'],samples=n) for k,n in qs.items() if n>=5],'association_complete':False})
        put('teams_all.json',dict(period='all',min_eligible=5,min_qph_samples=5,min_valh_samples=5,min_association_samples=5,count=len(teams),rows=teams,suppression=tref['suppression'],association_suppression=tref['association_suppression']))
        put('overview.json',dict(windows=list(cv.WINDOWS),by_window=overview))
        put('quality.json',dict(history_exists=True,records=len(merged),runs_total=len(runs),json_error_count=0,catalog_available=True,catalog_rows=len(catalog['rows']),by_window=quality,duration_labels=labels,value_engine=public_status('value_engine'),quest_battle_db=public_status('quest_battle_db')))
    files=[dict(path=p.relative_to(OUT).as_posix(),bytes=p.stat().st_size,sha256=cv.sha(p)) for p in sorted(OUT.rglob('*.json')) if p.name!='manifest.json']
    cutoffs={'today':anchor.astimezone(timezone(timedelta(hours=8))).replace(hour=0,minute=0,second=0,microsecond=0).astimezone(timezone.utc),'7d':anchor-timedelta(days=7),'30d':anchor-timedelta(days=30),'all':None}
    policies={k:old[k] for k in ('window_definition','team_rules','quest_identity','quest_duration','association_rules','star_matrix','notes')}
    generated=ex.parse_utc(release['generated_at_utc'])
    put('manifest.json',dict(**policies,contract_version=old['contract_version'],schema_version=old['schema_version'],generated_at_utc=release['generated_at_utc'],windows=list(cv.WINDOWS),files=files,status='HOLD',audit=audit,publish_after_snapshot=generated>=anchor,anchor_in_future=anchor>datetime.now(timezone.utc),source={'panel_version':panel_version,'data_kind':'merged_v4_v3_local_snapshot','history_exists':True,'records':len(merged)},cutoffs_utc={k:ex.utc_iso(v) if v is not None else None for k,v in cutoffs.items()}))
    print(json.dumps({'audit':audit,'teams':len(teams),'files':len(files)}))
if __name__=='__main__':main(Path(sys.argv[1]))
