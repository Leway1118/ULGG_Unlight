<?php
declare(strict_types=1);
/**
 * Offline-only performance model. 70k fake matches / 140k per-side facts.
 * Single in-memory SQLite DB; no production credentials, no network.
 */
require_once __DIR__.'/../../lib/checkpoint2026/IdentityGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/IdentityReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPFactReader.php';

use Ulgg\Checkpoint2026\PvPFactReader;

if (PHP_SAPI!=='cli' || $argc!==2) {
    fwrite(STDERR,"M3B_SYNTHETIC_CLI_ONLY report.json\n");
    exit(2);
}
$out=$argv[1];
if(!is_string($out)||$out==='' || !is_dir(dirname($out))){
    throw new RuntimeException('EXPLICIT_EXISTING_OUTPUT_DIRECTORY_REQUIRED');
}

$started=microtime(true);
$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$db->exec(<<<'SQL'
PRAGMA synchronous = OFF;
PRAGMA temp_store = MEMORY;
CREATE TABLE game_user(
 id INTEGER PRIMARY KEY,steamID TEXT UNIQUE,username TEXT,region TEXT,
 ack INTEGER,apply INTEGER,black_list INTEGER
);
CREATE TABLE arena_unlight(
 id INTEGER PRIMARY KEY,region TEXT,ack1 INTEGER,ack2 INTEGER,
 win INTEGER,lose INTEGER,tie INTEGER,update_time TEXT,
 name_p1 TEXT,name_p2 TEXT
);
CREATE TABLE arena_player_match_result(
 id INTEGER PRIMARY KEY,match_id INTEGER,side TEXT,player_name TEXT,
 update_time TEXT,leader_id INTEGER,back1_id INTEGER,back2_id INTEGER,
 team_key TEXT,cost INTEGER,is_win INTEGER,
 UNIQUE(match_id,side)
);
CREATE INDEX idx_arena_update_time ON arena_unlight(update_time);
CREATE INDEX idx_result_time ON arena_unlight(ack1,ack2,win,lose,tie,update_time);
CREATE INDEX idx_arena_p1_region ON arena_unlight(name_p1,region);
CREATE INDEX idx_arena_p2_region ON arena_unlight(name_p2,region);
CREATE INDEX idx_match ON arena_player_match_result(match_id);
CREATE INDEX idx_update ON arena_player_match_result(update_time);
SQL);
$db->exec(<<<'SQL'
INSERT INTO game_user(id,steamID,username,region,ack,apply,black_list)
VALUES (12,'76561190000000000','SYNTH_A','TW',1,0,0)
SQL);

$insertArena=$db->prepare(<<<'SQL'
INSERT INTO arena_unlight
(id,region,ack1,ack2,win,lose,tie,update_time,name_p1,name_p2)
VALUES(?,?,?,?,?,?,?,?,?,?)
SQL);
$insertResult=$db->prepare(<<<'SQL'
INSERT INTO arena_player_match_result
(match_id,side,player_name,update_time,leader_id,back1_id,back2_id,
 team_key,cost,is_win) VALUES(?,?,?,?,?,?,?,?,?,?)
SQL);

$db->beginTransaction();
for($i=1;$i<=70000;$i++){
    $region=$i%8===0?'JP':'TW';
    $p1=($i%41===0)?'SYNTH_A':'SYNTH_PLAYER_'.($i%71);
    $p2=($i%53===0)?'SYNTH_A':'SYNTH_PLAYER_'.(($i+37)%71);
    $day=($i%300)+1;
    $date=(new DateTimeImmutable('2026-01-01 00:00:00',new DateTimeZone('Asia/Taipei')))
        ->modify('+'.($day-1).' days')->format('Y-m-d H:i:s');
    $winner=$i%3===0?0:1;
    $tie=$i%113===0?1:0;
    $lose=$tie?0:(1-$winner);
    $win=$tie?0:$winner;
    $insertArena->execute([$i,$region,1,1,$win,$lose,$tie,$date,$p1,$p2]);
    $insertResult->execute([$i,'P1',$p1,$date,11,22,33,'11-22-33',20,
        $tie?null:($lose?1:0)]);
    $insertResult->execute([$i,'P2',$p2,$date,11,33,22,'11-22-33',20,
        $tie?null:($win?1:0)]);
}
$db->commit();
$seedElapsed=round((microtime(true)-$started)*1000,2);
$db->exec('PRAGMA query_only = ON');

$session=[
    'steam_id'=>'76561190000000000','user_id'=>12,
    'username'=>'SYNTH_A','user_region'=>'TW',
];
function runReader(PDO $db,array $session,int $iterations=7):array{
    $measure=[];$count=-1;
    for($i=0;$i<$iterations;$i++){
        $begin=microtime(true);
        $r=PvPFactReader::readForSession($db,$session,'2026-11-24 10:00:00');
        $ms=(microtime(true)-$begin)*1000;
        if($r['identity']['status']!=='VERIFIED')throw new RuntimeException('IDENTITY_FAILED');
        if($count!==-1 && $count!==count($r['rows']))throw new RuntimeException('NONDETERMINISTIC_ROW_COUNT');
        $count=count($r['rows']);
        $measure[]=round($ms,3);
    }
    $sorted=$measure;sort($sorted,SORT_NUMERIC);
    return ['count'=>$count,'samples_ms'=>$measure,
        'median_ms'=>$sorted[(int)floor(count($sorted)/2)],
        'p95_ms'=>$sorted[(int)floor((count($sorted)-1)*0.95)]];
}
function explain(PDO $db):array{
    $explain=<<<'SQL'
EXPLAIN QUERY PLAN SELECT m.match_id
FROM arena_unlight a
JOIN arena_player_match_result m ON m.match_id=a.id
WHERE a.region='TW'
  AND m.player_name='SYNTH_A'
  AND ((m.side='P1' AND a.name_p1='SYNTH_A')
    OR (m.side='P2' AND a.name_p2='SYNTH_A'))
  AND a.update_time>='2026-01-01 00:00:00'
  AND a.update_time<='2026-11-24 10:00:00'
  AND m.update_time>='2026-01-01 00:00:00'
  AND m.update_time<='2026-11-24 10:00:00'
ORDER BY m.match_id,m.side
SQL;
    return array_map(static fn($r)=>(string)$r['detail'],$db->query($explain)->fetchAll(PDO::FETCH_ASSOC));
}

$beforePlan=explain($db);
$before=runReader($db,$session);
$db->exec('PRAGMA query_only = OFF');
$db->exec('CREATE INDEX idx_synthetic_candidate_player_time ON arena_player_match_result(player_name,update_time,match_id)');
$db->exec('PRAGMA query_only = ON');
$afterPlan=explain($db);
$after=runReader($db,$session);
if($before['count']!==$after['count'])throw new RuntimeException('INDEX_CHANGED_RESULT');
if($before['count']<100)throw new RuntimeException('BAD_SYNTHETIC_DISTRIBUTION');
$ratio=round($before['median_ms']/max(0.001,$after['median_ms']),2);
$report=[
    'test'=>'ULGG_CHECKPOINT2026_M3B_PVP_PLAN_SYNTHETIC',
    'environment'=>['type'=>'SYNTHETIC_PHP_SQLITE_IN_MEMORY','os_family'=>PHP_OS_FAMILY,'php_version'=>PHP_VERSION,
         'no_prod_db'=>true,'no_game'=>true,'no_network'=>true],
    'fixture'=>['arena_matches'=>70000,'player_result_rows'=>140000,
        'game_user_records'=>1,'tw_jp_regions'=>true,
        'd1_like_indexes'=>['idx_match','idx_update','idx_result_time'],
        'seed_ms'=>$seedElapsed],
    'baseline'=>['query_plan'=>$beforePlan,'results'=>$before],
    'candidate_only'=>[
        'index'=>'(player_name,update_time,match_id)',
        'query_plan'=>$afterPlan,'results'=>$after],
    'median_speedup_ratio'=>$ratio,
    'data_result_identical'=>true,
    'production_index_changes'=>0,
    'interpretation'=>'Synthetic SQLite timings are NOT a production MariaDB latency guarantee',
];
file_put_contents($out,json_encode($report,
    JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
printf("M3B_PERF_SYNTHETIC_PASS rows=140000 matches=70000 selected=%d baseline_median_ms=%.3f candidate_median_ms=%.3f ratio=%.2f\n",
    $before['count'],$before['median_ms'],$after['median_ms'],$ratio);
printf("REPORT=%s\n",$out);
