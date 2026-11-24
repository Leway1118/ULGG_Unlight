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
    require_once __DIR__.'/../../../lib/checkpoint2026/'.$name.'.php';
require_once __DIR__.'/../FixtureData.php';

function guard():void{
    if(getenv('M3C_STAGE')!=='SYNTHETIC_INTERNAL_ONLY')throw new RuntimeException('STAGING_DISABLED');
    foreach(['DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','MYSQL_HOST','MYSQL_PASSWORD','TW_AUTH_TOKEN','STEAM_API_KEY','STEAM_SECRET'] as $k)
        if(getenv($k)!==false && getenv($k)!=='')throw new RuntimeException('LIVE_ENV_REFUSED');
}
function reader():PDO{
    guard();
    $db=new PDO('mysql:host=m3c-db;port=3306;dbname=ulgg_m3c_synthetic;charset=utf8mb4',
        'm3c_reader','synthetic-m3c-reader-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if($db->query('SELECT CURRENT_USER()')->fetchColumn()!=='m3c_reader@%' ||
       $db->query('SELECT DATABASE()')->fetchColumn()!=='ulgg_m3c_synthetic')
        throw new RuntimeException('WRONG_DATABASE_PRINCIPAL');
    return $db;
}
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
    return IdentityScopeResolver::fromSession(reader(),
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
            ['kind'=>'game','game_id'=>$id,'tournament_id'=>1,'match_id'=>$id===14?3:1,
             'result_status'=>'verified','player1_id'=>101,'player2_id'=>102,
             'winner_player_id'=>$id===10?101:102,'created_at'=>'2026-07-20 08:00:00',
             'updated_at'=>'2026-07-20 08:00:00'],$rev===2 && $id===11?LATE:ASOF);
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


function expectedB():array{
    $e=expectedPvp(1)[0];$e['id']='PVP:5:P1';$e['event_at']='2026-06-09 08:00:00';
    $e['payload']['actor_id']='SYNTH_ACTOR_B';
    $f=&$e['payload']['fact'];$f['match_id']=5;$f['region']='JP';
    $f['update_time']=$e['event_at'];$f['truth_update_time']=$e['event_at'];
    return [$e];
}
function observe(PDO $db,bool $b,int $rev):array{
    $session=$b?FixtureData::sessionB():FixtureData::sessionA();
    $facts=PvPFactReader::readForSession($db,$session,ASOF);
    eq($facts['identity']['status'],'VERIFIED');
    $pvp=[];$actors=[1=>'SYNTH_ACTOR_A',2=>'SYNTH_ACTOR_A',3=>'SYNTH_ACTOR_A',6=>'SYNTH_ACTOR_A',5=>'SYNTH_ACTOR_B'];
    foreach($facts['rows'] as $f){
        $f=R::normalizePvpFact($f);
        ok(isset($actors[$f['match_id']]),'NO_ACTOR_METADATA');
        $pvp[]=event('PVP:'.$f['match_id'].':'.$f['side'],$f['update_time'],
            ['actor_id'=>$actors[$f['match_id']],'fact'=>$f],$rev===2 && $f['match_id']===6?LATE:ASOF);
    }
    $public=CupPublicReader::read($db,2026,ASOF);
    $cup=[];
    foreach($public['reports'] as $r)
        $cup[]=event('CUP:REPORT:'.$r['report_id'],$r['published_at'],['kind'=>'report','report'=>$r]);
    $stmt=$db->prepare("SELECT g.id,g.tournament_id,g.match_id,g.result_status,g.player1_id,g.player2_id,g.winner_player_id,g.created_at,g.updated_at
      FROM tournament_match_games g JOIN tournament_matches m ON m.id=g.match_id AND m.tournament_id=g.tournament_id
      WHERE g.tournament_id=1 AND g.result_status='verified' AND g.created_at>=? AND g.created_at<=?
      AND g.updated_at<=? AND m.updated_at<=? AND m.status IN ('confirmed','finished')
      AND m.winner_player_id IN(m.player1_id,m.player2_id) AND g.winner_player_id IN(g.player1_id,g.player2_id)
      AND g.player1_id<>g.player2_id AND g.player1_id IN(m.player1_id,m.player2_id)
      AND g.player2_id IN(m.player1_id,m.player2_id) ORDER BY g.id");
    $stmt->execute(['2026-01-01 00:00:00',ASOF,ASOF,ASOF]);
    foreach($stmt->fetchAll() as $r)
        $cup[]=event('CUP:GAME:'.$r['id'],$r['created_at'],['kind'=>'game','game_id'=>(int)$r['id'],
            'tournament_id'=>(int)$r['tournament_id'],'match_id'=>(int)$r['match_id'],
            'result_status'=>$r['result_status'],'player1_id'=>(int)$r['player1_id'],
            'player2_id'=>(int)$r['player2_id'],'winner_player_id'=>(int)$r['winner_player_id'],
            'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']],$rev===2 && (int)$r['id']===11?LATE:ASOF);
    eq(count($cup)-count($public['reports']),$public['verified_games'],'CUP_PARITY');
    return [manifest('PVP_MATCH_FACT',scope($b),$rev,$b?expectedB():expectedPvp($rev),$pvp),
        manifest('CUP_PUBLIC',AnnualSourceBundleGate::CUP_SCOPE,$rev,expectedCup($rev),$cup),$public];
}
function publicAppend(ImmutableSnapshotLedger $l,int $rev,array $c,array $public):array{
    $verified=reconcile($c,'CUP_PUBLIC',$rev);
    eq($verified['status'],'VERIFIED','PUBLIC_RECONCILIATION');
    $watermark=$verified['watermark'];
    $coverage=['mode'=>'SYNTHETIC_STAGING','status'=>'VERIFIED','source_verified'=>true,
        'schema_fingerprint'=>SourceProvenance::SCHEMA_FINGERPRINT,'source_watermark'=>$watermark];
    $snap=ResponseSnapshot::publicCup($public,$coverage);
    $snap['snapshot_revision']='M3C-'.$rev;
    $snap['r3_reconciliation']=['cup_evidence_sha256'=>$verified['evidence_sha256'],'production_authority'=>false];
    $rows=[$public];$s=AnnualSourceBundleGate::CUP_SCOPE;$key=keys()['ledger_source'];
    $claims=['contract'=>'checkpoint2026/provenance-v1','dataset'=>'CUP_PUBLIC','mode'=>'SYNTHETIC_STAGING',
        'issuer'=>'M3B_ISOLATED_TEST_ISSUER','period_start'=>'2026-01-01 00:00:00','as_of'=>ASOF,
        'timezone'=>'Asia/Taipei','schema_fingerprint'=>SourceProvenance::SCHEMA_FINGERPRINT,
        'source_watermark'=>$watermark,'source_revision'=>'m3c-'.$rev,'source_complete'=>true,
        'source_rows'=>1,'expected_rows'=>1,'missing_slices'=>0,'fact_sha256'=>SourceProvenance::hashFacts($rows),
        'scope_hash'=>SourceProvenance::scopeHash($s,$key)];
    $proof=['claims'=>$claims,'signature'=>SourceProvenance::signForSyntheticFixture($claims,$key)];
    return $l->append($s,$rev,$snap,$proof,$key,ASOF,$rows,'CUP_PUBLIC');
}
function privateAppend(ImmutableSnapshotLedger $l,PDO $db,bool $b,int $rev,array $p,array $c):array{
    return W::append($l,scope($b),$rev,ASOF,$rev===1?ASOF:CUTOFF2,$db,
        $b?FixtureData::sessionB():FixtureData::sessionA(),keyFor('scope'),$p,$c,keys(),history());
}
function unknownResponse(bool $public):array{
    $cup=['status'=>'UNKNOWN','verified_games'=>null,'published_report_count'=>null,'reports'=>[],'personal_cup_status'=>'UNKNOWN'];
    $payload=$public?['cup'=>$cup]:['pvp'=>['status'=>'UNKNOWN','verified_matches'=>null,'wins'=>null,
        'losses'=>null,'draws'=>null,'active_months'=>null,'top_characters'=>[],'top_teams'=>[],'monthly_trend'=>[]],
        'cup'=>$cup,'raid'=>['status'=>'UNKNOWN']];
    return ['readiness'=>'STAGING_ONLY','access'=>$public?'PUBLIC':'PRIVATE','source_coverage_status'=>'UNKNOWN',
        'snapshot_revision'=>null,'as_of'=>ASOF,'payload'=>$payload,'payload_sha256'=>hash('sha256',
            json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
}
