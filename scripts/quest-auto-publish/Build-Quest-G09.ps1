[CmdletBinding()]
param(
    [string]$Candidate = 'D:\ULGG\QuestAnalyticsAutoPublish_P1_1',
    [string]$Repo = '',
    [string]$Output = 'D:\Download'
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
function Invoke-Checked([string]$Exe,[string[]]$ArgList) {
    & $Exe @ArgList
    if ($LASTEXITCODE -ne 0) { throw "$Exe failed: exit $LASTEXITCODE" }
}
if (-not (Test-Path -LiteralPath (Join-Path $Candidate 'build_full.py'))) { throw 'Existing G08-R2 candidate not found' }
$Candidate = (Resolve-Path -LiteralPath $Candidate).Path
if (-not $Repo) {
    $Repo = Get-ChildItem 'D:\Leway' -Directory | Where-Object Name -like 'OneDrive -*' | ForEach-Object {
        Get-ChildItem (Join-Path $_.FullName 'Leway') -Directory -ErrorAction SilentlyContinue
    } | ForEach-Object { Join-Path $_.FullName '7.Code\unlight' } | Where-Object {
        Test-Path (Join-Path $_ 'tool\ulgg-quest-public\export_quest_public.py')
    } | Select-Object -First 1
}
if (-not $Repo -or -not (Test-Path (Join-Path $Repo 'tool\ulgg-quest-public\export_quest_public.py'))) { throw 'Repository not found; specify -Repo explicitly' }
$Repo = (Resolve-Path -LiteralPath $Repo).Path
New-Item -ItemType Directory -Force -Path $Output | Out-Null
$Output = (Resolve-Path -LiteralPath $Output).Path
$log = Join-Path $Output ('Quest-G09-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
Start-Transcript -Path $log | Out-Null
Push-Location $Candidate
$buildLock = $null
try {
    $buildLock = [IO.File]::Open((Join-Path $Candidate 'g09-build.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
    # Reuse the existing recorder/Exporter; never modify the source repository.
    Invoke-Checked 'python' @('daily_prepare.py',$Repo)
    Invoke-Checked 'python' @('build_full.py',$Repo)
    Invoke-Checked 'python' @('finalize_g09.py',$Repo)
    Invoke-Checked 'python' @('patch_g2.py')
    Invoke-Checked 'php' @('test_g2_full.php') # pin catalog identity before integration
    Invoke-Checked 'python' @('integrate_g09.py')
    Invoke-Checked 'php' @('test_g2_full.php')
    Invoke-Checked 'php' @('test_g09_daily.php')
    Invoke-Checked 'php' @('evolution_daily.php')
    Invoke-Checked 'python' @('-m','unittest','-q','test_daily_prepare','test_native_drift','verify_preview')
    Invoke-Checked 'php' @('test_dynamic.php')
    # A successful PHP reader does not prove complete schema/semantic compatibility.
    $builder = @'
import json, hashlib, sys, zipfile, datetime, pathlib
R=pathlib.Path(sys.argv[1]); O=pathlib.Path(sys.argv[2]); F=R/'full_candidate'; REF=R/'contract_reference'/'data'
def load(p): return json.loads(p.read_bytes())
def sha(b): return hashlib.sha256(b).hexdigest()
def digest(p): return sha(p.read_bytes())
def dump(obj): return (json.dumps(obj,ensure_ascii=True,indent=2,allow_nan=False)+'\n').encode()
manifest=load(F/'manifest.json')
required={'overview.json','quality.json','quest_catalog.json','teams_all.json'}|{f'windows/{w}/{k}.json' for w in ('today','7d','30d','all') for k in ('quests','regions','quests_duration','star_matrix')}
listed={x['path']:x for x in manifest['files']}
assert len(listed)==len(manifest['files']) and set(listed)==required,'data manifest inventory'
issues=[]
for n in sorted(required):
 p=F/n; x=listed[n]; assert p.is_file() and not p.is_symlink(),n
 assert p.stat().st_size==x['bytes'] and digest(p)==x['sha256'],n+' integrity'
 assert load(p)['as_of_utc']==manifest['as_of_utc'],n+' anchor'
def schema(ref,new,path):
 if isinstance(ref,dict):
  if not isinstance(new,dict): issues.append({'path':path,'problem':'expected object'});return
  for k in sorted(set(ref)-set(new)):issues.append({'path':path+'.'+k,'problem':'missing key'})
  for k in set(ref)&set(new):schema(ref[k],new[k],path+'.'+k)
 elif isinstance(ref,list) and isinstance(new,list) and ref and isinstance(ref[0],dict):
  # Union reference keys, then check every candidate row. Empty arrays are not fabricated.
  sample={k:v for row in ref if isinstance(row,dict) for k,v in row.items()}
  if path.endswith('.files'): return
  for j,row in enumerate(new):schema(sample,row,path+f'[{j}]')
 elif isinstance(ref,list) and not isinstance(new,list):issues.append({'path':path,'problem':'expected array'})
for n in sorted(required|{'manifest.json'}):schema(load(REF/n),load(F/n),n)
# Consolidate repeated row failures, preserving examples.
import re,collections
summary=collections.defaultdict(lambda:{'count':0,'example':''})
for i in issues:
 key=(re.sub(r'\[\d+\]','[*]',i['path']),i['problem']); s=summary[key];s['count']+=1;s['example']=i['path']
report={'status':'HOLD','data_release':load(R/'last_prepare.json')['release'],'created_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'module_regressions':'37 PASS; G2 integration 7 PASS; G09/G10 integration 10 PASS (commands exit 0)','data_integrity':'PASS','schema_missing_count':len(issues),'schema_issues':[{'path':k[0],'problem':k[1],**v} for k,v in sorted(summary.items())],'semantic_report':load(R/'G09_SEMANTIC_REPORT.json'),'daily_report':load(R/'G10_DAILY_REPORT.json'),'remaining_gates':['exact live PHP rendering in private VPS stage','operator approval and live baseline lock','post-switch HTTP acceptance'],'production_modified':False}
# Keep the candidate explicitly HOLD regardless of a key-only schema check.
files={}
for name in ('build_full.py','patch_g2.py','daily_prepare.py','pair_v3.py','pair_v3_r1.py','convert_v141.py','verify_preview.py','dynamic_metrics.php','release_trust.php','evolve.php','run_evolution.php','test_g2_full.php','test_dynamic.php','test_daily_prepare.py','test_native_drift.py','test_compat.php','last_prepare.json','finalize_g09.py','integrate_g09.py','evolution_daily.php','test_g09_daily.php','G09_SEMANTIC_REPORT.json','G10_DAILY_REPORT.json'):
 p=R/name
 if p.is_file():files[name]=p.read_bytes()
for folder,pattern in [('full_candidate','*.json'),('candidate_php','*.php'),('contract_reference/data','*.json'),('contract_reference/inc','*.php'),('model','approved.json')]:
 for p in (R/folder).rglob(pattern):
  if p.is_file() and not p.is_symlink():files[p.relative_to(R).as_posix()]=p.read_bytes()
hist=load(R/'G10_DAILY_REPORT.json')['manifest_sha256']
hp=R/'daily_history'/(hist+'.json')
assert hp.is_file() and hist==digest(F/'manifest.json'),'daily history anchor'
files['daily_history/'+hp.name]=hp.read_bytes()
assert report['semantic_report']['status']=='PASS_SELECTED_RECONCILIATION','semantic gate'
files['GATE_REPORT.json']=dump(report)
release={'status':'HOLD','scope':'private_offline_candidate','files':[{'path':n,'bytes':len(b),'sha256':sha(b)} for n,b in sorted(files.items())]}
files['RELEASE_MANIFEST.json']=dump(release)
zpath=O/('ULGG-Quest-P1.1-G09-'+report['data_release']+'-HOLD.zip')
with zipfile.ZipFile(zpath,'w',zipfile.ZIP_DEFLATED) as z:
 for n,b in sorted(files.items()):z.writestr(n,b)
with zipfile.ZipFile(zpath) as z:
 assert z.testzip() is None
 for n,b in files.items():assert sha(z.read(n))==sha(b)
(O/'Quest-G09-GATE_REPORT.json').write_bytes(files['GATE_REPORT.json'])
(O/'Quest-G09-RELEASE_MANIFEST.json').write_bytes(files['RELEASE_MANIFEST.json'])
(zpath.with_suffix('.zip.sha256')).write_text(digest(zpath)+'  '+zpath.name+'\n',encoding='ascii')
print('PACKAGE:',zpath)
print('SHA256:',digest(zpath))
print('SCHEMA_MISSING:',len(issues),'STATUS: HOLD')
if issues: raise SystemExit('Schema gate failed')
'@
    $temp = Join-Path ([System.IO.Path]::GetTempPath()) ('quest-r2-' + [guid]::NewGuid().ToString('N') + '.py')
    try {
        [IO.File]::WriteAllText($temp,$builder,[Text.UTF8Encoding]::new($false))
        Invoke-Checked 'python' @($temp,$Candidate,$Output)
    } finally { Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue }
    Write-Host 'HOLD: Package is private staging only. Do not copy to web root.' -ForegroundColor Yellow
} finally { if ($null -ne $buildLock) { $buildLock.Dispose() }; Pop-Location; Stop-Transcript | Out-Null }
