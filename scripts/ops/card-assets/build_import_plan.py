"""Build a reviewable first-import plan from the actual VPS preflight + official mirrored assets."""
from pathlib import Path
import argparse, collections, hashlib, json, re

def canonical(value):
    return json.dumps(value,sort_keys=True,ensure_ascii=False,separators=(',',':'))

def sha(value):
    return hashlib.sha256(canonical(value).encode()).hexdigest()

def index(rows,key,cast=lambda x:x):
    out={}
    for row in rows:
        k=cast(row[key])
        if k in out:raise ValueError('duplicate key '+str(k))
        out[k]=row
    return out

def norm_int(v):
    return None if v is None else int(v)

def norm_req(v):
    if isinstance(v,str):v=json.loads(v)
    if isinstance(v,dict):v=v.get('require',v.get('cost',v))
    return v

def plan(db,all_cards,all_feats):
    cards=index(all_cards,'id',int)
    feats=index(all_feats,'id',int)
    oldcards=index(db['cards'],'id',int)
    oldskills=index(db['skills'],'skill_code')
    oldcost=index(db['costs'],'ID',int)
    changes=[]; inserts=[]; mapped={}; reviewed=[]; affected=[]
    def change(table,key,before,after):
        diff={k:{'before':before[k],'after':v} for k,v in after.items() if before[k]!=v}
        if diff:
            existing=next((row for row in changes if row['table']==table and row['key']==key),None)
            if existing:
                if existing['fields'].keys() & diff.keys():raise ValueError('duplicate planned field')
                existing['fields'].update(diff)
            else:changes.append(dict(table=table,key=key,fields=diff))
    for i,r in sorted(oldcards.items()):
        group=(i-1)//10+1;c=oldcost.get(group,{})
        if i<1 or not (r['ico'] or '').strip() or not re.fullmatch('[LR][1-5]',r['level'] or '') or norm_int(c.get('on_stage'))!=1:continue
        x=cards.get(i)
        if not x or x['kind']!=0 or x['chara']!='cc%03d'%group or ('R' if '_r' in x['filename'] else 'L')+str(x['level'])!=r['level']:
            raise ValueError('card identity mismatch '+str(i))
        expected_code=x['chara']
        expected_filename=expected_code+('_r' if r['level'][0]=='R' else '_')+('%02d'%int(r['level'][1]))
        if x['filename']!=expected_filename:raise ValueError('official filename identity mismatch '+str(i))
        if 'chara_code' not in r or 'filename' not in r:raise ValueError('missing actual database identity columns')
        identity_before={'chara_code':r['chara_code'],'filename':r['filename']}
        identity_after={'chara_code':expected_code,'filename':expected_filename}
        if identity_before!=identity_after:
            actual_skills=[r['skill%d_code'%n] for n in range(1,5) if r['skill%d_code'%n]]
            official_skills=[feats[sid]['texture'] for sid in x['skill'] if sid in feats]
            same_stats=all(norm_int(r[k])==x[v] for k,v in [('HP','hp'),('ATK','atk'),('DEF','def'),('cost','cost')])
            legacy=(i in (541,542,543,544,545,551,552,553,554,555) and
                    r['chara_code']==expected_code and r['filename']==expected_code+'_r'+('%02d'%int(r['level'][1])) and
                    same_stats and actual_skills==official_skills and len(official_skills)==len(x['skill']))
            blank=(i in (691,692,693,694,695,696) and r['chara_code']=='' and r['filename']=='' and
                   all(r[k] is None for k in ('HP','ATK','DEF')) and not actual_skills and
                   norm_int(r['cost'])==x['cost'] and norm_int(c[r['level']])==x['cost'])
            if not (legacy or blank):raise ValueError('unreviewed database identity mismatch '+str(i))
        reviewed.append(i)
        if len(x['skill'])>4:raise ValueError('too many skills '+str(i))
        newcodes=[]
        for sid in x['skill']:
            f=feats.get(sid)
            if not f:raise ValueError('missing released skill '+str(sid))
            code=f['texture']
            if not re.fullmatch(r'cc\d+_sk\d+(?:_[a-zA-Z0-9]+)?',code):
                raise ValueError('unexpected code '+code)
            if not code.startswith(x['chara']+'_') or f['phase'] not in (0,1,2) or any(t not in (0,1,2) for t in f['distance']):
                raise ValueError('skill semantics '+code)
            fields=dict(name_tcn=f['name_tcn'],info_tcn=f['caption_tcn'],phase=f['phase'],
                        range_mask=','.join(map(str,sorted(set(f['distance'])))),require_json=f['require'])
            if code in mapped and mapped[code]['fields']!=fields:raise ValueError('conflicting released texture '+code)
            if code not in mapped:mapped[code]=dict(fields=fields,source_card_id=i,character_code=x['chara'],source_hash=sha(f),official_ids=[])
            if sid not in mapped[code]['official_ids']:mapped[code]['official_ids'].append(sid)
            newcodes.append(code)
        newcodes+=([None]*(4-len(newcodes)))
        cols={'HP':'hp','ATK':'atk','DEF':'def','cost':'cost'}
        before={col:norm_int(r[col]) for col in cols};after={col:x[k] for col,k in cols.items()}
        for n,code in enumerate(newcodes,1):
            col='skill%d_code'%n; before[col]=r[col] or None;after[col]=code
        before.update(identity_before);after.update(identity_after)
        oldlen=len(changes);change('unlight',{'id':i},before,after)
        level=r['level']
        change('cost_unlight',{'ID':group},{level:norm_int(c[level])},{level:x['cost']})
        if len(changes)!=oldlen:affected.append(i)
    for code,x in sorted(mapped.items()):
        after=x['fields']
        if code in oldskills:
            r=oldskills[code];before={k:r[k] for k in after}
            before['phase']=norm_int(before['phase'])
            before['range_mask']=','.join(map(str,sorted(set(int(v) for v in (before['range_mask'] or '').split(',') if v))))
            before['require_json']=norm_req(before['require_json'])
            change('unlight_skill',{'skill_code':code},before,after)
        else:inserts.append(dict(skill_code=code,**x))
    return dict(schema=1,source_hashes={'cards':sha(all_cards),'feats':sha(all_feats),'db_report':sha(db)},
                scope=dict(released_cards=len(reviewed),released_ids=reviewed,skill_codes=len(mapped),affected_cards=affected,
                           excluded_official_character_cards=len([r for r in all_cards if r['kind']==0])-len(reviewed)),
                changes=changes,new_skills=inserts,protected=['note_player_tcn','unlight_skill_tag','historical_matches','weekly_cost_rules'],
                limitations=['Existing source_hash contract not inspected; no existing hash overwrite.',
                'Card slot and evolution fields not included in preflight rows; not included in this first plan.',
                'No unpublished or unillustrated cards inserted.'])

def main():
    p=argparse.ArgumentParser();p.add_argument('report',type=Path);a=p.parse_args()
    read=lambda n:json.loads((a.report/n).read_text(encoding='utf-8-sig'))
    result=plan(read('db.json'),read('official_cards.json'),read('official_feats.json'))
    (a.report/'import-plan.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf8')
    counts=collections.Counter(x['table'] for x in result['changes'])
    print('PLAN_PASS',dict(counts),'NEW_SKILLS',len(result['new_skills']),'RELEASED',result['scope']['released_cards'])
    print('PLAN_SHA256',hashlib.sha256((a.report/'import-plan.json').read_bytes()).hexdigest())
if __name__=='__main__':main()
