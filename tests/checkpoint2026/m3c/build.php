<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
use Ulgg\Checkpoint2026\{R3Reconciliation as R,ImmutableSnapshotLedger,ReadOnlyStagingPdo,AnnualSourceBundleGate};
guard();
if(PHP_SAPI!=='cli'||$argc!==2||!is_dir('/stage'))throw new RuntimeException('STAGING_CLI_ONLY');
$mode=$argv[1];$db=reader();$file='/stage/ledger.sqlite';
function publish(string $state):void{
    file_put_contents('/stage/active.tmp',$state."\n",LOCK_EX);
    rename('/stage/active.tmp','/stage/active');
}
function report(string $mode,array $data):void{
    file_put_contents('/stage/'.$mode.'.json',json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    echo 'M3C_'.$mode."_PASS\n";
}
if($mode==='privileges'){
    $grants=$db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $select=0;
    foreach($grants as $g){
        if(str_contains($g,'GRANT SELECT ('))$select++;
        ok(!preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|ALL PRIVILEGES|GRANT OPTION)\b/i',$g),'ELEVATED_GRANT');
    }
    eq($select,7);
    $denied=0;
    foreach(['SELECT * FROM game_user','SELECT ci_hidden_probe FROM arena_unlight',
        'UPDATE arena_unlight SET ack1=0','DELETE FROM arena_unlight',
        'INSERT INTO arena_unlight(id) VALUES(999)','CREATE TABLE refused(x INT)',
        'ALTER TABLE arena_unlight ADD COLUMN refused INT','DROP TABLE arena_unlight'] as $sql){
        try{$db->query($sql);}catch(PDOException $e){
            ok(in_array((int)($e->errorInfo[1]??0),[1044,1142,1143,1227],true),'NOT_PERMISSION_DENIAL');
            $denied++;continue;
        }
        throw new RuntimeException('PRIVILEGE_BYPASS');
    }
    report($mode,['status'=>'PASS','principal'=>'m3c_reader@%','column_grants'=>$select,'denials'=>$denied]);
    exit;
}
if(in_array($mode,['v1','v2'],true)){
    $rev=$mode==='v1'?1:2;
    eq(is_file($file),$rev===2);
    $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($rev===1)ImmutableSnapshotLedger::createSyntheticSchema($pdo);
    $l=new ImmutableSnapshotLedger($pdo,keyFor('ledger'));
    [$p,$c,$public]=observe($db,false,$rev);
    [$pb,$cb]=observe($db,true,$rev);
    $a=privateAppend($l,$db,false,$rev,$p,$c);
    $b=privateAppend($l,$db,true,$rev,$pb,$cb);
    $pub=publicAppend($l,$rev,$c,$public);
    eq((int)$pdo->query('SELECT COUNT(*) FROM checkpoint2026_snapshots')->fetchColumn(),$rev*3);
    $data=['status'=>'PASS','revision'=>$rev,'scopes'=>[],'pvp_observed'=>count($p['events']),
        'cup_observed'=>count($c['events'])];
    foreach(['A'=>scope(),'B'=>scope(true),'PUBLIC'=>AnnualSourceBundleGate::CUP_SCOPE] as $name=>$s){
        $record=$l->verifyAndRead($s,$rev);$snap=$record['snapshot'];
        $data['scopes'][$name]=['hash'=>$record['record_hash'],'previous'=>$record['previous_hash'],
            'payload_sha256'=>$snap['payload_sha256'],'pvp'=>$snap['payload']['pvp']['verified_matches']??null,
            'cup'=>$snap['payload']['cup']['verified_games'],'evidence'=>$snap['r3_reconciliation']];
        eq($snap['payload']['cup']['personal_cup_status'],'UNKNOWN');
    }
    eq($data['scopes']['A']['pvp'],$rev+2);eq($data['scopes']['B']['pvp'],1);
    eq($data['scopes']['PUBLIC']['cup'],$rev+1);
    publish((string)$rev);report($mode,$data);exit;
}
$pdo=ReadOnlyStagingPdo::open($file,'/stage');$l=new ImmutableSnapshotLedger($pdo,keyFor('ledger'));
$before=[];foreach([scope(),scope(true),AnnualSourceBundleGate::CUP_SCOPE] as $s)
    foreach([1,2] as $v)$before[]=$l->verifyAndRead($s,$v)['record_hash'];
if($mode==='rollback'){
    eq(trim(file_get_contents('/stage/active')),'2');
    publish('1');
    foreach(['UPDATE checkpoint2026_snapshots SET revision=99','DELETE FROM checkpoint2026_snapshots'] as $sql)
        denies(fn()=>$pdo->exec($sql),'readonly');
    report($mode,['status'=>'PASS','active_before'=>2,'active_after'=>1,'records'=>6,'denied_writes'=>2,'record_hashes'=>$before]);
    exit;
}
if($mode==='forged'||$mode==='missing'||$mode==='history-conflict'){
    [$p,$c]=observe($db,false,2);
    if($mode==='forged')$p['receipt']['signature']=str_repeat('0',64);
    $res=reconcile($p,'PVP_MATCH_FACT',2);
    if($mode==='history-conflict'){
        eq($res['status'],'VERIFIED');
        $bs=bindings();$bs[]=$bs[1];$h=history($bs);
        $owner=owner([],$h);eq($owner['status'],'UNKNOWN');
        // Execute actual writer against a disposable in-memory ledger; must fail before append.
        $tmp=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        ImmutableSnapshotLedger::createSyntheticSchema($tmp);$test=new ImmutableSnapshotLedger($tmp,keyFor('ledger'));
        denies(fn()=>Ulgg\Checkpoint2026\R3SnapshotWriter::append($test,scope(),2,ASOF,CUTOFF2,$db,
            Ulgg\Checkpoint2026\Tests\FixtureData::sessionA(),keyFor('scope'),$p,$c,keys(),$h),'R3_HISTORICAL_OWNERSHIP_UNKNOWN');
        eq((int)$tmp->query('SELECT COUNT(*) FROM checkpoint2026_snapshots')->fetchColumn(),0);
        $reason='R3_HISTORICAL_OWNERSHIP_UNKNOWN';
    }else{
        $reason=$mode==='forged'?'BAD_SIGNATURE':'MISSING_EVENT';
        eq($res['status'],'UNKNOWN');eq($res['reason'],$reason);
        // Exercise writer on the real inputs, not a caller flag.
        denies(fn()=>privateAppend($l,$db,false,2,$p,$c),'R3_INDEPENDENT_RECONCILIATION_REQUIRED');
    }
    $after=[];foreach([scope(),scope(true),AnnualSourceBundleGate::CUP_SCOPE] as $s)
        foreach([1,2] as $v)$after[]=$l->verifyAndRead($s,$v)['record_hash'];
    eq($before,$after);eq((int)$pdo->query('SELECT COUNT(*) FROM checkpoint2026_snapshots')->fetchColumn(),6);
    report($mode,['status'=>'PASS','source_status'=>'UNKNOWN','reason'=>$reason,'records_unchanged'=>true,'record_hashes'=>$after]);
    publish('UNKNOWN:'.$mode);exit;
}
throw new RuntimeException('UNKNOWN_MODE');
