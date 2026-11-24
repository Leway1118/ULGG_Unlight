<?php
declare(strict_types=1);

use Ulgg\Checkpoint2026\{R3Reconciliation as R, R3SnapshotWriter as W, SourceProvenance,
    AnnualSourceBundleGate, IdentityGate, IdentityReader, IdentityScopeResolver,
    PvPFactReader, PvPAnnualAggregator, CupPublicReader, ResponseSnapshot,
    ImmutableSnapshotLedger, ReadOnlyStagingPdo};
use Ulgg\Checkpoint2026\Tests\FixtureData;

foreach(['IdentityGate','IdentityReader','IdentityScopeResolver','PvPFactReader','PvPAnnualAggregator',
    'CupPublicReader','ResponseSnapshot','SourceProvenance','AnnualSourceBundleGate',
    'ImmutableSnapshotLedger','ReadOnlyStagingPdo','R3Reconciliation'] as $name)
    require_once __DIR__.'/../../lib/checkpoint2026/'.$name.'.php';
require_once __DIR__.'/FixtureData.php';

const ASOF='2026-11-24 10:00:00';
const CUTOFF2='2026-11-24 11:00:00';
const LATE='2026-11-24 10:30:00';

function eq(mixed $a,mixed $b,string $why='ASSERT_EQUAL'):void {
    if($a!==$b)throw new RuntimeException($why.': '.json_encode([$a,$b],JSON_THROW_ON_ERROR));
}
function ok(bool $b,string $why='ASSERT_TRUE'):void {if(!$b)throw new RuntimeException($why);}
function denies(Closure $f,string $why):void {
    try{$f();}catch(Throwable $e){
        ok(str_contains($e->getMessage(),$why),'WRONG_DENIAL:'.$e->getMessage());return;
    }
    throw new RuntimeException('EXPECTED_DENIAL:'.$why);
}
function keyFor(string $domain):string{return hash('sha256','R3_SYNTHETIC_ONLY_'.$domain,true);}
function keys():array{
    $out=[];
    foreach(['pvp_upstream','pvp_receipt','cup_upstream','cup_receipt','history','ledger_source'] as $k)
        $out[$k]=keyFor($k);
    return $out;
}
function scope(bool $b=false):string {
    return IdentityScopeResolver::fromSession(FixtureData::pdo(),
        $b?FixtureData::sessionB():FixtureData::sessionA(),keyFor('scope'))['opaque_scope'];
}
function identity():array{return IdentityReader::resolve(FixtureData::pdo(),FixtureData::sessionA());}
function event(string $id,string $at,array $payload,string $ingest=ASOF):array{
    return ['id'=>$id,'event_at'=>$at,'ingested_at'=>$ingest,'payload'=>$payload];
}
function manifest(string $dataset,string $scope,int $revision,array $expected,array $observed,
    ?string $cutoff=null):array {
    $cutoff=$cutoff??($revision===1?ASOF:CUTOFF2);
    $ctx=['mode'=>'SYNTHETIC_STAGING','dataset'=>$dataset,'scope'=>$scope,'period_start'=>'2026-01-01 00:00:00',
        'as_of'=>ASOF,'cutoff'=>$cutoff,'revision'=>$revision,'timezone'=>'Asia/Taipei',
        'schema_fingerprint'=>SourceProvenance::SCHEMA_FINGERPRINT,'watermark'=>ASOF];
    $u=$ctx+['issuer'=>'SYNTH_UPSTREAM_'.$dataset,'expected_count'=>count($expected),
        'expected'=>array_map(fn($x)=>['id'=>$x['id'],'event_at'=>$x['event_at'],
            'sha256'=>R::digest($x['payload'])],$expected)];
    $r=$ctx+['issuer'=>'SYNTH_INGESTION_'.$dataset,'observed_count'=>count($observed),
        'observed_sha256'=>R::digest($observed)];
    $prefix=$dataset==='PVP_MATCH_FACT'?'pvp':'cup';
    return ['upstream'=>R::sign('upstream',$u,keys()[$prefix.'_upstream']),
        'receipt'=>R::sign('receipt',$r,keys()[$prefix.'_receipt']),'events'=>$observed];
}
function reconcile(array $m,string $dataset='PVP_MATCH_FACT',int $rev=1,?string $cutoff=null,?string $scopeOverride=null):array{
    $prefix=$dataset==='PVP_MATCH_FACT'?'pvp':'cup';
    return R::reconcile($dataset,$scopeOverride??($prefix==='pvp'?scope():AnnualSourceBundleGate::CUP_SCOPE),
        ASOF,$cutoff??($rev===1?ASOF:CUTOFF2),$rev,$m['upstream'],$m['receipt'],$m['events'],
        keys()[$prefix.'_upstream'],keys()[$prefix.'_receipt']);
}

/* Independent upstream oracle: explicit source rows, never the result of
 * PvPFactReader/CupPublicReader or a count from the observed database. */
function expectedPvp(int $rev):array{
    $specs=[
        [1,'P1','2026-06-01 08:00:00','SYNTH_A','SYNTH_OPP',0,1,0,1,1,22,33],
        [2,'P2','2026-06-03 08:00:00','SYNTH_OPP','SYNTH_A',0,1,0,1,0,33,22],
        [3,'P1','2026-06-05 08:00:00','SYNTH_A','SYNTH_OPP',0,0,1,1,null,22,33],
        [6,'P1','2026-06-11 08:00:00','SYNTH_A','SYNTH_OPP',0,1,0,$rev===1?0:1,1,22,33],
    ];
    $out=[];
    foreach($specs as [$id,$side,$time,$p1,$p2,$win,$lose,$tie,$ack2,$won,$back1,$back2]){
        $fact=['match_id'=>$id,'side'=>$side,'player_name'=>'SYNTH_A','update_time'=>$time,
            'truth_update_time'=>$time,'region'=>'TW','ack1'=>1,'ack2'=>$ack2,'name_p1'=>$p1,
            'name_p2'=>$p2,'win'=>$win,'lose'=>$lose,'tie'=>$tie,'leader_id'=>11,
            'back1_id'=>$back1,'back2_id'=>$back2,'team_key'=>'11-22-33','cost'=>20,'is_win'=>$won];
        $out[]=event('PVP:'.$id.':'.$side,$time,['actor_id'=>'SYNTH_ACTOR_A','fact'=>$fact],
            $rev===2 && $id===6?LATE:ASOF);
    }
    return $out;
}
function expectedCup(int $rev):array{
    $report=['report_id'=>1,'tournament_id'=>1,'issue_key'=>'final','title'=>'SYNTH CUP Final',
        'published_at'=>'2026-07-21 10:00:00','sections'=>[['key'=>'story','title'=>'Public synthetic story']]];
    $out=[event('CUP:REPORT:1',$report['published_at'],['kind'=>'report','report'=>$report])];
    foreach($rev===1?[10,14]:[10,11,14] as $id){
        $out[]=event('CUP:GAME:'.$id,'2026-07-20 08:00:00',
            ['kind'=>'game','tournament_id'=>1],$rev===2 && $id===11?LATE:ASOF);
    }
    return $out;
}
function bindings():array{
    return [
        ['dataset'=>'PVP_MATCH_FACT','actor_id'=>'SYNTH_ACTOR_A','region'=>'TW','alias'=>'SYNTH_OLD',
            'from'=>'2026-01-01 00:00:00','until'=>'2026-05-01 00:00:00','scope'=>scope()],
        ['dataset'=>'PVP_MATCH_FACT','actor_id'=>'SYNTH_ACTOR_A','region'=>'TW','alias'=>'SYNTH_A',
            'from'=>'2026-05-01 00:00:00','until'=>'2027-01-01 00:00:00','scope'=>scope()],
        ['dataset'=>'PVP_MATCH_FACT','actor_id'=>'SYNTH_ACTOR_B','region'=>'JP','alias'=>'SYNTH_A',
            'from'=>'2026-01-01 00:00:00','until'=>'2027-01-01 00:00:00','scope'=>scope(true)],
        ['dataset'=>'PVP_MATCH_FACT','actor_id'=>'SYNTH_ACTOR_C','region'=>'TW','alias'=>'SYNTH_OLD',
            'from'=>'2026-05-01 00:00:00','until'=>'2027-01-01 00:00:00','scope'=>str_repeat('c',64)],
    ];
}
function history(?array $bs=null):array{
    return R::sign('history',['mode'=>'SYNTHETIC_STAGING','issuer'=>'SYNTH_IDENTITY_REGISTRY',
        'bindings'=>$bs??bindings()],keys()['history']);
}
function owner(array $override=[],?array $h=null):array{
    return R::historicalOwner(array_replace(['dataset'=>'PVP_MATCH_FACT','actor_id'=>'SYNTH_ACTOR_A',
        'alias'=>'SYNTH_A','region'=>'TW','event_at'=>'2026-06-01 08:00:00'],$override),$h??history(),keys()['history']);
}

/* Observation side: real existing Readers over in-memory SQLite.
 * Receipt actor IDs are fixture ingestion metadata, NOT inferred by username.
 * CUP IDs are queried independently from the small fixture's verified games. */
function fixtures(int $rev):array {
    $db=FixtureData::pdo();
    if($rev===2){
        $db->exec('UPDATE arena_unlight SET ack2=1 WHERE id=6');
        $db->exec("UPDATE tournament_match_games SET result_status='verified' WHERE id=11");
    }
    $facts=PvPFactReader::readForSession($db,FixtureData::sessionA(),ASOF)['rows'];
    $actorMeta=[1=>'SYNTH_ACTOR_A',2=>'SYNTH_ACTOR_A',3=>'SYNTH_ACTOR_A',6=>'SYNTH_ACTOR_A'];
    $pvp=[];
    foreach($facts as $f){
        ok(isset($actorMeta[$f['match_id']]),'MISSING_FIXTURE_ACTOR');
        $pvp[]=event('PVP:'.$f['match_id'].':'.$f['side'],$f['update_time'],
            ['actor_id'=>$actorMeta[$f['match_id']],'fact'=>$f],$rev===2 && $f['match_id']===6?LATE:ASOF);
    }
    $public=CupPublicReader::read($db,2026,ASOF);
    $cup=[];
    foreach($public['reports'] as $r)
        $cup[]=event('CUP:REPORT:'.$r['report_id'],$r['published_at'],['kind'=>'report','report'=>$r]);
    $stmt=$db->query("SELECT g.id,g.tournament_id,g.created_at FROM tournament_match_games g
        JOIN tournament_matches m ON m.id=g.match_id AND m.tournament_id=g.tournament_id
        WHERE g.tournament_id=1 AND g.result_status='verified'
        AND m.status IN ('confirmed','finished') AND m.winner_player_id IN(m.player1_id,m.player2_id)
        AND g.winner_player_id IN(g.player1_id,g.player2_id)
        AND g.player1_id<>g.player2_id AND g.player1_id IN(m.player1_id,m.player2_id)
        AND g.player2_id IN(m.player1_id,m.player2_id) ORDER BY g.id");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
        $cup[]=event('CUP:GAME:'.$row['id'],$row['created_at'],['kind'=>'game','tournament_id'=>(int)$row['tournament_id']],
            $rev===2 && (int)$row['id']===11?LATE:ASOF);
    eq(count($cup)-count($public['reports']),$public['verified_games'],'CUP_REAL_READER_PARITY');
    return [manifest('PVP_MATCH_FACT',scope(),$rev,expectedPvp($rev),$pvp),
        manifest('CUP_PUBLIC',AnnualSourceBundleGate::CUP_SCOPE,$rev,expectedCup($rev),$cup)];
}
function writeRevision(ImmutableSnapshotLedger $ledger,int $rev,?array $p=null,?array $c=null,?array $h=null):array{
    [$p0,$c0]=fixtures($rev);
    return W::append($ledger,scope(),$rev,ASOF,$rev===1?ASOF:CUTOFF2,identity(),
        $p??$p0,$c??$c0,keys(),$h??history());
}
function memoryLedger():array{
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    ImmutableSnapshotLedger::createSyntheticSchema($db);
    return [$db,new ImmutableSnapshotLedger($db,keyFor('ledger'))];
}

function unitTests():array{
    [$p,$c]=fixtures(1);
    $expected=expectedPvp(1);
    $tests=[
        'G01_independent_pvp_manifest'=>fn()=>eq(reconcile($p)['count'],4),
        'G01_independent_cup_manifest'=>fn()=>eq(reconcile($c,'CUP_PUBLIC')['count'],3),
        'G01_dataset_cross_replay'=>fn()=>eq(reconcile($p,'CUP_PUBLIC')['status'],'UNKNOWN'),
        'G01_subject_scope_replay'=>fn()=>eq(reconcile($p,'PVP_MATCH_FACT',1,null,scope(true))['status'],'UNKNOWN'),
        'G02_stale_upstream'=>function()use($p){$m=$p;$u=$m['upstream']['claims'];$u['watermark']='2026-11-23 00:00:00';
            $m['upstream']=R::sign('upstream',$u,keys()['pvp_upstream']);eq(reconcile($m)['reason'],'STALE_OR_INVALID_WATERMARK');},
        'G02_stale_receipt'=>function()use($p){$m=$p;$r=$m['receipt']['claims'];$r['watermark']='2026-11-23 00:00:00';
            $m['receipt']=R::sign('receipt',$r,keys()['pvp_receipt']);eq(reconcile($m)['reason'],'STALE_OR_INVALID_WATERMARK');},
        'G02_future_watermark'=>function()use($p){$m=$p;$r=$m['receipt']['claims'];$r['watermark']=CUTOFF2;
            $m['receipt']=R::sign('receipt',$r,keys()['pvp_receipt']);eq(reconcile($m)['reason'],'STALE_OR_INVALID_WATERMARK');},
        'G03_expected_count_not_observed'=>function()use($expected){
            $m=manifest('PVP_MATCH_FACT',scope(),1,$expected,array_slice($expected,0,3));
            eq(reconcile($m)['reason'],'MISSING_EVENT');},
        'G03_equal_count_wrong_ids'=>function()use($expected){
            $rows=$expected;$rows[3]['id']='PVP:999:P1';
            eq(reconcile(manifest('PVP_MATCH_FACT',scope(),1,$expected,$rows))['reason'],'UNEXPECTED_EVENT');},
        'G03_authoritative_zero'=>fn()=>eq(reconcile(manifest('PVP_MATCH_FACT',scope(),1,[],[]))['count'],0),
        'G04_duplicate_observed'=>function()use($expected){
            $rows=$expected;$rows[3]=$rows[0];
            eq(reconcile(manifest('PVP_MATCH_FACT',scope(),1,$expected,$rows))['reason'],'DUPLICATE_OBSERVED_EVENT');},
        'G04_duplicate_expected'=>function()use($expected){
            $rows=$expected;$rows[3]=$rows[0];
            eq(reconcile(manifest('PVP_MATCH_FACT',scope(),1,$rows,$expected))['reason'],'INVALID_OR_DUPLICATE_EXPECTED_EVENT');},
        'G04_late_blocked_then_reconciled'=>function()use($expected){
            $rows=$expected;$rows[3]['ingested_at']=LATE;
            eq(reconcile(manifest('PVP_MATCH_FACT',scope(),1,$expected,$rows))['reason'],'NOT_INGESTED_BY_CUTOFF');
            eq(reconcile(manifest('PVP_MATCH_FACT',scope(),2,$expected,$rows),'PVP_MATCH_FACT',2)['late_arrivals'],1);},
        'G04_wrong_payload_same_id'=>function()use($expected){
            $rows=$expected;$rows[0]['payload']['fact']['cost']=99;
            eq(reconcile(manifest('PVP_MATCH_FACT',scope(),1,$expected,$rows))['reason'],'EVENT_CONTENT_OR_TIME_MISMATCH');},
        'G05_old_alias_effective_date'=>fn()=>eq(owner(['alias'=>'SYNTH_OLD','event_at'=>'2026-04-30 23:59:59'])['scope'],scope()),
        'G05_alias_reuse_other_actor'=>fn()=>eq(owner(['actor_id'=>'SYNTH_ACTOR_C','alias'=>'SYNTH_OLD'])['scope'],str_repeat('c',64)),
        'G05_overlap_conflict'=>function(){
            $b=bindings();$extra=$b[1];$extra['scope']=scope(true);$b[]=$extra;
            eq(owner([],history($b))['status'],'UNKNOWN');},
        'G05_wrong_alias_date'=>fn()=>eq(owner(['alias'=>'SYNTH_OLD'])['status'],'UNKNOWN'),
        'G06_same_name_other_region'=>fn()=>eq(owner(['actor_id'=>'SYNTH_ACTOR_B','region'=>'JP'])['scope'],scope(true)),
        'G06_missing_actor_no_name_fallback'=>fn()=>eq(owner(['actor_id'=>null])['status'],'UNKNOWN'),
        'G06_cup_no_identity_mapping'=>fn()=>eq(owner(['dataset'=>'CUP_PUBLIC'])['status'],'UNKNOWN'),
        'G06_unverified_identity_writer_denied'=>function()use($p,$c){
            [$db,$l]=memoryLedger();denies(fn()=>W::append($l,scope(),1,ASOF,ASOF,['status'=>'UNKNOWN'],$p,$c,keys(),history()),'R3_IDENTITY_NOT_VERIFIED');},
        'G06_cross_scope_writer_denied'=>function()use($expected,$c){
            [$db,$l]=memoryLedger();
            $bad=manifest('PVP_MATCH_FACT',scope(true),1,$expected,$expected);
            denies(fn()=>W::append($l,scope(true),1,ASOF,ASOF,identity(),$bad,$c,keys(),history()),'R3_HISTORICAL_OWNERSHIP_UNKNOWN');},
        'G07_incomplete_cup_blocks_snapshot'=>function()use($p,$c){
            [$db,$l]=memoryLedger();$bad=$c;array_pop($bad['events']);
            denies(fn()=>writeRevision($l,1,$p,$bad),'R3_INDEPENDENT_RECONCILIATION_REQUIRED');
            eq((int)$db->query('SELECT COUNT(*) FROM checkpoint2026_snapshots')->fetchColumn(),0);},
        'G07_historical_conflict_blocks_snapshot'=>function(){
            [$db,$l]=memoryLedger();$b=bindings();$b[]=$b[1];
            denies(fn()=>writeRevision($l,1,null,null,history($b)),'R3_HISTORICAL_OWNERSHIP_UNKNOWN');},
        'G07_renamed_fact_aggregates_after_authority'=>function()use($expected,$c){
            [$db,$l]=memoryLedger();$rows=$expected;$f=&$rows[0]['payload']['fact'];
            $f['player_name']='SYNTH_OLD';$f['name_p1']='SYNTH_OLD';
            $f['update_time']='2026-04-30 23:59:59';$f['truth_update_time']=$f['update_time'];
            $rows[0]['event_at']=$f['update_time'];unset($f);
            $m=manifest('PVP_MATCH_FACT',scope(),1,$rows,$rows);
            writeRevision($l,1,$m,$c);
            $s=$l->verifyAndRead(scope(),1)['snapshot'];
            eq($s['payload']['pvp']['verified_matches'],3);
            eq($s['payload']['pvp']['monthly_trend']['2026-04']['matches'],1);},
        'G08_duplicate_revision_rejected'=>function(){
            [$db,$l]=memoryLedger();writeRevision($l,1);
            denies(fn()=>writeRevision($l,1),'SNAPSHOT_REVISION_NOT_NEXT');},
        'G08_cross_scope_read_rejected'=>function(){
            [$db,$l]=memoryLedger();writeRevision($l,1);
            denies(fn()=>$l->verifyAndRead(scope(true),1),'SNAPSHOT_NOT_FOUND_OR_GAP');},
        'G09_forged_upstream_signature'=>function()use($p){$m=$p;$m['upstream']['claims']['expected_count']=0;
            eq(reconcile($m)['reason'],'BAD_SIGNATURE');},
        'G09_forged_receipt_signature'=>function()use($p){$m=$p;$m['receipt']['claims']['observed_count']=0;
            eq(reconcile($m)['reason'],'BAD_SIGNATURE');},
        'G09_wrong_role_key'=>function()use($p){$m=$p;$m['upstream']=R::sign('upstream',$m['upstream']['claims'],keys()['pvp_receipt']);
            eq(reconcile($m)['reason'],'BAD_SIGNATURE');},
        'G09_same_issuer_key_denied'=>function()use($p){
            eq(R::reconcile('PVP_MATCH_FACT',scope(),ASOF,ASOF,1,$p['upstream'],$p['receipt'],$p['events'],
                keys()['pvp_upstream'],keys()['pvp_upstream'])['reason'],'ISSUERS_NOT_INDEPENDENT');},
        'G09_observed_tamper_without_resign'=>function()use($p){$m=$p;$m['events'][0]['payload']['fact']['cost']=99;
            eq(reconcile($m)['reason'],'RECEIPT_OR_COUNT_MISMATCH');},
        'G09_history_signature_tamper'=>function(){$h=history();$h['claims']['bindings'][1]['scope']=scope(true);
            eq(owner([],$h)['status'],'UNKNOWN');},
        'G09_sealed_reconciliation_root_tamper'=>function(){
            [$db,$l]=memoryLedger();writeRevision($l,1);$db->exec('DROP TRIGGER checkpoint2026_no_snapshot_update');
            $db->exec("UPDATE checkpoint2026_snapshots SET payload_json=replace(payload_json,'checkpoint2026/r3-reconciliation-v1','tampered')");
            denies(fn()=>$l->verifyAndRead(scope(),1),'SNAPSHOT_CHAIN_SEAL_FAILED');},
    ];
    $results=[];
    foreach($tests as $name=>$f){$f();$results[]=['name'=>$name,'status'=>'PASS'];echo "R3_PASS $name\n";}
    return $results;
}

if(PHP_SAPI!=='cli'||getenv('M3BR3_ISOLATION')!=='SYNTHETIC_NETWORK_NONE' || $argc!==3)
    throw new RuntimeException('R3_CONTAINER_CLI_ONLY');
foreach(['DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','MYSQL_HOST','MYSQL_PASSWORD','TW_AUTH_TOKEN','STEAM_API_KEY','STEAM_SECRET'] as $env)
    ok(getenv($env)===false || getenv($env)==='','R3_PRODUCTION_ENV_REFUSED');
$mode=$argv[1];$dir=realpath($argv[2]);
ok($dir==='/evidence' && is_dir($dir),'R3_EXPLICIT_CONTAINER_OUTPUT_ONLY');
$file=$dir.'/ledger.sqlite';$out=$dir.'/'.$mode.'.json';
$r=['mode'=>$mode,'php'=>PHP_VERSION,'status'=>'FAIL','synthetic_only'=>true];
try {
    if($mode==='unit'){
        $r['cases']=unitTests();$r['count']=count($r['cases']);
    }elseif($mode==='write'||$mode==='revise'){
        $rev=$mode==='write'?1:2;
        eq(is_file($file),$rev===2,'FILE_EXISTENCE_GUARD');
        $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        if($rev===1)ImmutableSnapshotLedger::createSyntheticSchema($db);
        $ledger=new ImmutableSnapshotLedger($db,keyFor('ledger'));
        $r['record']=writeRevision($ledger,$rev);
        $r['row_count']=(int)$db->query('SELECT COUNT(*) FROM checkpoint2026_snapshots')->fetchColumn();
        eq($r['row_count'],$rev);
    }elseif(in_array($mode,['verify','rollback','deny-write'],true)){
        ok(is_file($file),'NO_LEDGER');
        $db=ReadOnlyStagingPdo::open($file,$dir);$l=new ImmutableSnapshotLedger($db,keyFor('ledger'));
        $v1=$l->verifyAndRead(scope(),1);$v2=$l->verifyAndRead(scope(),2);
        foreach([1=>$v1,2=>$v2] as $n=>$v){
            $s=$v['snapshot'];$payload=$s['payload'];
            eq($s['snapshot_revision'],'M3BR3-'.$n);eq($s['r3_reconciliation']['revision'],$n);
            eq($payload['pvp']['verified_matches'],$n+2);
            eq($payload['cup']['verified_games'],$n+1);
            eq($payload['cup']['personal_cup_status'],'UNKNOWN');
            eq($s['r3_reconciliation']['pvp_late_arrivals'],$n-1);
            eq($s['r3_reconciliation']['cup_late_arrivals'],$n-1);
            ok(strlen($s['r3_reconciliation']['pvp_evidence_sha256'])===64);
            ok(strlen($s['r3_reconciliation']['cup_evidence_sha256'])===64);
            ok(!str_contains(json_encode($s,JSON_THROW_ON_ERROR),'7656119'));
            $r['versions'][]=['revision'=>$n,'pvp'=>$payload['pvp']['verified_matches'],
                'cup'=>$payload['cup']['verified_games'],'record_hash'=>$v['record_hash'],
                'previous_hash'=>$v['previous_hash'],'payload_sha256'=>$s['payload_sha256'],
                'reconciliation'=>$s['r3_reconciliation']];
        }
        eq($v2['previous_hash'],$v1['record_hash']);
        ok($v1['snapshot']['payload_sha256']!==$v2['snapshot']['payload_sha256']);
        ok($v1['snapshot']['r3_reconciliation']['pvp_evidence_sha256']!==$v2['snapshot']['r3_reconciliation']['pvp_evidence_sha256']);
        ok($v1['snapshot']['r3_reconciliation']['cup_evidence_sha256']!==$v2['snapshot']['r3_reconciliation']['cup_evidence_sha256']);
        if($mode==='rollback'){
            $active=2;eq($l->verifyAndRead(scope(),$active)['revision'],2);
            $active=1;eq($l->verifyAndRead(scope(),$active)['record_hash'],$v1['record_hash']);
            eq($l->verifyAndRead(scope(),2)['record_hash'],$v2['record_hash']);
            $r['read_selection']=['before'=>2,'after'=>1,'v2_preserved'=>true,'mutation'=>false];
        }
        if($mode==='deny-write'){
            foreach(['UPDATE checkpoint2026_snapshots SET revision=9','DELETE FROM checkpoint2026_snapshots'] as $sql)
                denies(fn()=>$db->exec($sql),'readonly');
            $r['denied_write_count']=2;
        }
        eq((int)$db->query('SELECT COUNT(*) FROM checkpoint2026_snapshots')->fetchColumn(),2);
        $r['row_count']=2;
    }else throw new RuntimeException('INVALID_MODE');
    $r['status']='PASS';
} catch(Throwable $e){
    $r['error']=$e->getMessage();
    fwrite(STDERR,$e->getMessage()."\n");
} finally {
    file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
}
echo 'M3BR3_PHASE '.$mode.' '.$r['status']."\n";
exit($r['status']==='PASS'?0:1);
