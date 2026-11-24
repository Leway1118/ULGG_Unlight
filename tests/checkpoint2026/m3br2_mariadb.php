<?php
declare(strict_types=1);

/**
 * UL.GG 2026 M3-B-R2: one-time MariaDB, synthetic fixtures only.
 * Run only in GitHub-hosted CI against a container bound to 127.0.0.1:43306.
 * Administrative PDO exists ONLY to create synthetic schema/roles/facts;
 * every application Reader and all denied-operation checks use a separate
 * column-restricted SELECT-only MariaDB identity.
 */
require_once __DIR__.'/../../lib/checkpoint2026/IdentityGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/IdentityReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/IdentityScopeResolver.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPFactReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/CupPublicReader.php';
require_once __DIR__.'/FixtureData.php';

use Ulgg\Checkpoint2026\IdentityReader;
use Ulgg\Checkpoint2026\IdentityScopeResolver;
use Ulgg\Checkpoint2026\PvPFactReader;
use Ulgg\Checkpoint2026\CupPublicReader;
use Ulgg\Checkpoint2026\Tests\FixtureData;

const DB_NAME = 'ulgg_m3br2_synthetic';
const AS_OF = '2026-11-24 10:00:00';
const SYNTH_ROOT_PASS = 'synthetic-m3br2-test-password-only';
const SYNTH_READER_PASS = 'synthetic-m3br2-reader-only';

function must(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function ident(string $value): string {
    must((bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $value), 'UNSAFE_SYNTH_IDENTIFIER');
    return chr(96).$value.chr(96);
}
function deny(PDO $pdo, string $sql): void {
    try {
        $pdo->exec($sql);
    } catch (PDOException $error) {
        $native = (int)($error->errorInfo[1] ?? 0);
        must((string)$error->getCode() === '42000'
            && in_array($native, [1044, 1142, 1143, 1227], true),
            'WRONG_DENIAL_REASON_'.(string)$error->getCode().'_'.$native);
        return;
    }
    throw new RuntimeException('PRIVILEGE_BYPASS_DETECTED');
}
function check(string $name, Closure $test, array &$report): void {
    $result = $test();
    $report['gates'][] = ['name' => $name, 'result' => 'PASS'];
    if (is_array($result)) { $report['evidence'][$name] = $result; }
    echo 'M3BR2_GATE_PASS '.$name."\n";
}
function explain(PDO $pdo, string $sql): array {
    $rows=$pdo->query('EXPLAIN '.$sql)->fetchAll(PDO::FETCH_ASSOC);
    must(count($rows)>0,'EXPLAIN_EMPTY');
    return array_map(static function(array $row): array {
        return [
            'table' => (string)($row['table'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'possible_keys' => (string)($row['possible_keys'] ?? ''),
            'key' => (string)($row['key'] ?? ''),
            'rows_estimate' => (int)($row['rows'] ?? 0),
            'extra' => (string)($row['Extra'] ?? ''),
        ];
    }, $rows);
}
function sample(Closure $fn, int $repetitions=5): array {
    $times=[]; $digest=null; $count=null;
    for($i=0;$i<$repetitions;$i++){
        $start=hrtime(true);
        $payload=$fn();
        $times[]=round((hrtime(true)-$start)/1000000,3);
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $hash=hash('sha256',$json);
        if($digest!==null) { must($digest===$hash,'NONDETERMINISTIC_READER_RESULTS'); }
        $digest=$hash;
        $count=is_array($payload)?count($payload):1;
    }
    $sorted=$times;sort($sorted,SORT_NUMERIC);
    return [
        'sample_count'=>$repetitions,'selected_rows'=>$count,
        'median_ms'=>$sorted[intdiv(count($sorted),2)],
        'p95_ms'=>$sorted[(int)floor((count($sorted)-1)*0.95)],
        'samples_ms'=>$times,'result_sha256'=>$digest,
    ];
}
function allowedColumns(): array {
    return [
        'game_user'=>['id','steamID','username','region','ack','apply','black_list'],
        'arena_unlight'=>['id','region','update_time','ack1','ack2','name_p1','name_p2','win','lose','tie'],
        'arena_player_match_result'=>['match_id','side','player_name','update_time','leader_id','back1_id','back2_id','team_key','cost','is_win'],
        'tournament_reports'=>['id','tournament_id','issue_key','report_title','status','published_at','created_at','updated_at'],
        'tournament_report_sections'=>['id','report_id','section_key','section_title','sort_order','is_visible','updated_at'],
        'tournament_matches'=>['id','tournament_id','status','player1_id','player2_id','winner_player_id','updated_at'],
        'tournament_match_games'=>['id','tournament_id','match_id','result_status','player1_id','player2_id','winner_player_id','created_at','updated_at'],
    ];
}
function syntheticSchemas(): array {
    return [
        'game_user'=>"CREATE TABLE game_user (
            id INT PRIMARY KEY, steamID VARCHAR(17) UNIQUE NOT NULL,
            username VARCHAR(64) NOT NULL, region VARCHAR(8) NOT NULL,
            ack TINYINT, apply TINYINT, black_list TINYINT,
            ci_hidden_probe VARCHAR(40) DEFAULT 'DENIED') ENGINE=InnoDB",
        'arena_unlight'=>"CREATE TABLE arena_unlight (
            id BIGINT PRIMARY KEY,region VARCHAR(8),ack1 TINYINT,ack2 TINYINT,
            win TINYINT,lose TINYINT,tie TINYINT,update_time DATETIME,
            name_p1 VARCHAR(80),name_p2 VARCHAR(80),
            ci_hidden_probe VARCHAR(40) DEFAULT 'DENIED',
            INDEX idx_arena_update_time(update_time),
            INDEX idx_arena_p1_region(name_p1,region),
            INDEX idx_arena_p2_region(name_p2,region)) ENGINE=InnoDB",
        'arena_player_match_result'=>"CREATE TABLE arena_player_match_result (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,match_id BIGINT NOT NULL,
            side VARCHAR(2) NOT NULL,player_name VARCHAR(80) NOT NULL,
            update_time DATETIME NOT NULL,leader_id INT,back1_id INT,back2_id INT,
            team_key VARCHAR(64),cost INT,is_win TINYINT,
            ci_hidden_probe VARCHAR(40) DEFAULT 'DENIED',
            UNIQUE KEY uq_match_side(match_id,side),
            INDEX idx_fact_match(match_id),INDEX idx_fact_time(update_time)) ENGINE=InnoDB",
        'tournament_reports'=>"CREATE TABLE tournament_reports (
            id INT PRIMARY KEY,tournament_id INT,issue_key VARCHAR(50),
            report_title VARCHAR(100),status VARCHAR(30),published_at DATETIME NULL,
            created_at DATETIME NULL,updated_at DATETIME NULL,
            ci_hidden_probe VARCHAR(40) DEFAULT 'DENIED') ENGINE=InnoDB",
        'tournament_report_sections'=>"CREATE TABLE tournament_report_sections (
            id INT PRIMARY KEY,report_id INT,section_key VARCHAR(50),
            section_title VARCHAR(100),sort_order INT,is_visible TINYINT,
            updated_at DATETIME NULL,
            ci_hidden_probe VARCHAR(40) DEFAULT 'DENIED') ENGINE=InnoDB",
        'tournament_matches'=>"CREATE TABLE tournament_matches (
            id INT PRIMARY KEY,tournament_id INT,status VARCHAR(30),
            player1_id INT,player2_id INT,winner_player_id INT,updated_at DATETIME,
            ci_hidden_probe VARCHAR(40) DEFAULT 'DENIED') ENGINE=InnoDB",
        'tournament_match_games'=>"CREATE TABLE tournament_match_games (
            id INT PRIMARY KEY,tournament_id INT,match_id INT,game_no INT,
            result_status VARCHAR(30),player1_id INT,player2_id INT,
            winner_player_id INT,created_at DATETIME,updated_at DATETIME,
            ci_hidden_probe VARCHAR(40) DEFAULT 'DENIED') ENGINE=InnoDB",
    ];
}
function seedFixture(PDO $admin): void {
    $sqlite=FixtureData::pdo();
    $admin->beginTransaction();
    try {
        foreach(array_keys(syntheticSchemas()) as $table){
            $columns=$sqlite->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);
            $names=array_column($columns,'name');
            $cols=implode(',',array_map('ident',$names));
            $marks=implode(',',array_fill(0,count($names),'?'));
            $insert=$admin->prepare('INSERT INTO '.ident($table).' ('.$cols.') VALUES ('.$marks.')');
            foreach($sqlite->query('SELECT * FROM '.ident($table),PDO::FETCH_ASSOC) as $row){
                $insert->execute(array_values($row));
            }
        }
        $admin->commit();
    } catch(Throwable $error){
        if($admin->inTransaction()){$admin->rollBack();}
        throw $error;
    }
}
function seedLargeSynthetic(PDO $admin): void {
    $dates=[];
    $day=new DateTimeImmutable('2026-01-01 00:00:00',new DateTimeZone('Asia/Taipei'));
    for($d=0;$d<300;$d++){$dates[]=$day->modify('+'.$d.' days')->format('Y-m-d H:i:s');}
    $addArena=$admin->prepare('INSERT INTO arena_unlight
        (id,region,ack1,ack2,win,lose,tie,update_time,name_p1,name_p2)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    $addFact=$admin->prepare('INSERT INTO arena_player_match_result
        (match_id,side,player_name,update_time,leader_id,back1_id,back2_id,team_key,cost,is_win)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    $admin->beginTransaction();
    try {
        for($i=1;$i<=70000;$i++){
            $id=$i+1000;
            $region=($i%8===0)?'JP':'TW';
            $p1=($i%41===0)?'SYNTH_A':'SYNTH_PLAYER_'.($i%71);
            $p2=($i%53===0)?'SYNTH_A':'SYNTH_PLAYER_'.(($i+37)%71);
            $date=$dates[($i-1)%300];
            $tie=($i%113===0)?1:0;
            $win=$tie?0:($i%3===0?0:1);
            $lose=$tie?0:1-$win;
            $addArena->execute([$id,$region,1,1,$win,$lose,$tie,$date,$p1,$p2]);
            $addFact->execute([$id,'P1',$p1,$date,11,22,33,'11-22-33',20,$tie?null:$win]);
            $addFact->execute([$id,'P2',$p2,$date,11,33,22,'11-22-33',20,$tie?null:$lose]);
        }
        $admin->commit();
    } catch(Throwable $error){
        if($admin->inTransaction()){$admin->rollBack();}
        throw $error;
    }
}
function pvpExplainSql(): string {
    return <<<'SQL'
SELECT m.match_id,m.side,m.player_name,m.update_time,a.update_time AS truth_update_time,
       a.region,a.ack1,a.ack2,a.name_p1,a.name_p2,a.win,a.lose,a.tie,
       m.leader_id,m.back1_id,m.back2_id,m.team_key,m.cost,m.is_win
FROM arena_unlight a INNER JOIN arena_player_match_result m ON m.match_id=a.id
WHERE a.region='TW' AND m.player_name='SYNTH_A'
 AND ((m.side='P1' AND a.name_p1='SYNTH_A') OR (m.side='P2' AND a.name_p2='SYNTH_A'))
 AND a.update_time>='2026-01-01 00:00:00' AND a.update_time<='2026-11-24 10:00:00'
 AND m.update_time>='2026-01-01 00:00:00' AND m.update_time<='2026-11-24 10:00:00'
ORDER BY m.match_id ASC,m.side ASC
SQL;
}
function cupReportSql(): string {
    return <<<'SQL'
SELECT r.id AS report_id,r.tournament_id,r.issue_key,r.report_title,r.published_at,
       s.section_key,s.section_title,s.sort_order
FROM tournament_reports r
LEFT JOIN tournament_report_sections s ON s.report_id=r.id AND s.is_visible=1
WHERE r.status='published' AND r.published_at IS NOT NULL
 AND r.published_at>='2026-01-01 00:00:00'
 AND r.published_at<='2026-11-24 10:00:00'
 AND r.published_at<'2027-01-01 00:00:00'
ORDER BY r.published_at,r.id,s.sort_order,s.id
SQL;
}
function cupGamesSql(): string {
    return <<<'SQL'
SELECT g.id AS game_id,g.tournament_id
FROM tournament_match_games g JOIN tournament_matches m
 ON m.id=g.match_id AND m.tournament_id=g.tournament_id
WHERE g.tournament_id IN (1) AND g.result_status='verified'
 AND g.created_at>='2026-01-01 00:00:00' AND g.created_at<='2026-11-24 10:00:00'
 AND g.updated_at<='2026-11-24 10:00:00'
 AND m.updated_at<='2026-11-24 10:00:00'
 AND m.status IN ('confirmed','finished')
 AND m.winner_player_id IN (m.player1_id,m.player2_id)
 AND g.player1_id IS NOT NULL AND g.player2_id IS NOT NULL
 AND g.player1_id<>g.player2_id AND g.winner_player_id IN (g.player1_id,g.player2_id)
 AND g.player1_id IN (m.player1_id,m.player2_id)
 AND g.player2_id IN (m.player1_id,m.player2_id)
ORDER BY g.id
SQL;
}

$report = [
    'task'=>'ULGG_CHECKPOINT2026_M3B_R2',
    'scope'=>'GITHUB_HOSTED_LINUX_EPHEMERAL_MARIADB_SYNTHETIC_ONLY',
    'status'=>'FAIL','php_version'=>PHP_VERSION,'mariadb_version'=>'UNVERIFIED',
    'container_bind'=>'127.0.0.1:43306',
    'privilege_identity'=>'checkpoint_m3br2_ro@%',
    'gates'=>[],'evidence'=>[],'errors'=>[],
    'production_touched'=>false,'real_steam_used'=>false,
];
$output = $argv[1] ?? '';
try {
    must(PHP_SAPI==='cli' && $argc===2 && is_string($output) && is_dir(dirname($output)),
        'EXPLICIT_EXISTING_REPORT_DIR_REQUIRED');
    must(getenv('CI')==='true' && getenv('M3BR2_ISOLATION_GUARD')==='SYNTHETIC_ONLY',
        'NOT_GITHUB_SYNTHETIC_CI');
    foreach(['DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','MYSQL_HOST','MYSQL_PASSWORD',
             'TW_AUTH_TOKEN','STEAM_API_KEY','STEAM_SECRET'] as $secretKey) {
        must(getenv($secretKey)===false || getenv($secretKey)==='', 'LIVE_CONFIG_ENV_BLOCKED_'.$secretKey);
    }
    must(extension_loaded('pdo_mysql') && extension_loaded('pdo_sqlite'),'PDO_DRIVER_MISSING');
    $dsn='mysql:host=127.0.0.1;port=43306;dbname='.DB_NAME.';charset=utf8mb4';
    $options=[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
    ];
    $admin=new PDO($dsn,'root',SYNTH_ROOT_PASS,$options);
    must((string)$admin->query('SELECT DATABASE()')->fetchColumn()===DB_NAME,
        'WRONG_DATABASE_REFUSED');
    $report['mariadb_version']=(string)$admin->query('SELECT VERSION()')->fetchColumn();
    must(str_contains(strtolower($report['mariadb_version']),'mariadb'),'NOT_MARIADB');
    $admin->exec("CREATE USER 'checkpoint_m3br2_ro'@'%' IDENTIFIED BY '".SYNTH_READER_PASS."'");
    foreach(syntheticSchemas() as $sql){$admin->exec($sql);}
    seedFixture($admin);
    foreach(allowedColumns() as $table=>$columns){
        $columnSql=implode(',',array_map('ident',$columns));
        $admin->exec('GRANT SELECT ('.$columnSql.') ON '.ident(DB_NAME).'.'.ident($table).
            " TO 'checkpoint_m3br2_ro'@'%'");
    }
    $ro=new PDO($dsn,'checkpoint_m3br2_ro',SYNTH_READER_PASS,$options);
    check('C01_COLUMN_ROLE_AND_SHOW_GRANTS',function()use($ro){
        $identity=(string)$ro->query('SELECT CURRENT_USER()')->fetchColumn();
        must($identity==='checkpoint_m3br2_ro@%','READER_IDENTITY_NOT_DEDICATED_'.$identity);
        $grants=$ro->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        $selectGrants=0;
        foreach($grants as $grant){
            must(!preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|FILE|SUPER|GRANT OPTION)\b/i',(string)$grant),
                'ELEVATED_ROLE_PRIVILEGES');
            if(str_contains((string)$grant,'SELECT')){
                must(str_contains((string)$grant,'SELECT ('),'TABLE_WIDE_SELECT_FORBIDDEN');
                $selectGrants++;
            }
        }
        must($selectGrants===count(allowedColumns()),'UNEXPECTED_GRANT_COUNT');
        return ['principal'=>$identity,'column_select_grants'=>$selectGrants,
                'global_select_granted'=>false,'grants_sha256'=>hash('sha256',implode("\n",$grants))];
    },$report);
    check('C02_ALLOWLIST_COLUMNS_SELECT',function()use($ro){
        $count=0;
        foreach(allowedColumns() as $table=>$columns){
            foreach($columns as $column){
                $ro->query('SELECT '.ident($column).' FROM '.ident($table).' LIMIT 1')->fetchAll();
                $count++;
            }
        }
        return ['allowed_table_count'=>count(allowedColumns()),'allowed_column_checks'=>$count];
    },$report);
    check('C03_UNLISTED_COLUMN_AND_STAR_DENIED',function()use($ro){
        foreach(array_keys(allowedColumns()) as $table){
            deny($ro,'SELECT ci_hidden_probe FROM '.ident($table).' LIMIT 1');
            deny($ro,'SELECT * FROM '.ident($table).' LIMIT 1');
        }
        deny($ro,'SELECT id FROM checkpoint2026_unapproved_table');
        return ['denials'=>count(allowedColumns())*2+1];
    },$report);
    check('C04_DML_DENIED',function()use($ro,$admin){
        deny($ro,"INSERT INTO game_user(id,steamID,username,region) VALUES(99,'76561190000000099','ATTACK','TW')");
        deny($ro,'UPDATE game_user SET ack=0 WHERE id=12');
        deny($ro,'DELETE FROM game_user WHERE id=12');
        deny($ro,'REPLACE INTO game_user(id,steamID,username,region) VALUES(12,76561190000000000,"X","TW")');
        must((int)$admin->query('SELECT ack FROM game_user WHERE id=12')->fetchColumn()===1,
            'DATA_MUTATED_AFTER_DENIAL');
        return ['denied_insert_update_delete_replace'=>4,'data_unchanged'=>true];
    },$report);
    check('C05_DDL_AND_ADMIN_DENIED',function()use($ro){
        deny($ro,'CREATE TABLE ci_reader_should_not_exist(x INT)');
        deny($ro,'ALTER TABLE game_user ADD COLUMN ci_new INT');
        deny($ro,'DROP TABLE game_user');
        deny($ro,'CREATE INDEX ci_illegal_idx ON game_user(username)');
        deny($ro,"CREATE USER 'ci_elevation_probe'@'%' IDENTIFIED BY 'synthetic'");
        return ['denied_create_alter_drop_index_user'=>5];
    },$report);
    check('C06_IDENTITY_A_B_OPAQUE_SCOPE',function()use($ro){
        $a=IdentityReader::resolve($ro,FixtureData::sessionA());
        $b=IdentityReader::resolve($ro,FixtureData::sessionB());
        must($a['status']==='VERIFIED' && $b['status']==='VERIFIED','IDENTITY_QUERY_FAILED');
        must($a['subject']['region']==='TW' && $b['subject']['region']==='JP','REGION_BINDING_FAILED');
        $key=hash('sha256','M3BR2_EPHEMERAL_SCOPE_HMAC_NOT_PROD',true);
        $scopeA=IdentityScopeResolver::fromSession($ro,FixtureData::sessionA(),$key);
        $scopeB=IdentityScopeResolver::fromSession($ro,FixtureData::sessionB(),$key);
        must($scopeA['status']==='VERIFIED' && $scopeB['status']==='VERIFIED','OPAQUE_SCOPE_NOT_VERIFIED');
        must($scopeA['opaque_scope']!==$scopeB['opaque_scope'],'OPAQUE_SCOPE_COLLISION');
        must(!str_contains(json_encode($scopeA),'7656119'),'OPAQUE_SCOPE_LEAK');
        return ['a_region'=>'TW','b_region'=>'JP','scopes_distinct'=>true,'synthetic_identity_only'=>true];
    },$report);
    check('C07_PVP_STEAM_CROSS_ACCOUNT_IDOR',function()use($ro){
        $a=PvPFactReader::readForSession($ro,FixtureData::sessionA(),AS_OF);
        $b=PvPFactReader::readForSession($ro,FixtureData::sessionB(),AS_OF);
        must($a['identity']['status']==='VERIFIED' && $b['identity']['status']==='VERIFIED',
            'PVP_IDENTITY_UNVERIFIED');
        must(count($a['rows'])===4 && count($b['rows'])===1,'PVP_REGION_ROWS_UNEXPECTED');
        must(array_reduce($a['rows'],static fn($carry,$row)=>$carry&&$row['region']==='TW',true),
            'PVP_TW_CROSS_SCOPE_LEAK');
        must(array_reduce($b['rows'],static fn($carry,$row)=>$carry&&$row['region']==='JP',true),
            'PVP_JP_CROSS_SCOPE_LEAK');
        foreach([
            FixtureData::sessionA(['steam_id'=>FixtureData::sessionB()['steam_id']]),
            FixtureData::sessionA(['user_id'=>13]),
            FixtureData::sessionA(['user_region'=>'JP']),
            FixtureData::sessionA(['username'=>'OTHER']),
        ] as $forged){
            $response=PvPFactReader::readForSession($ro,$forged,AS_OF);
            must($response['source_status']==='UNAUTHORIZED' && count($response['rows'])===0,
                'FORGED_STEAM_SESSION_ACCEPTED');
        }
        return ['tw_rows'=>4,'jp_rows'=>1,'forged_session_denials'=>4,'opponent_cross_region_leak'=>false];
    },$report);
    check('C08_CUP_PUBLIC_READER',function()use($ro){
        $cup=CupPublicReader::read($ro,2026,AS_OF);
        must($cup['status']==='VERIFIED' && $cup['published_report_count']===1
            && $cup['verified_games']===2,'CUP_PUBLIC_RESULTS_MISMATCH');
        $json=json_encode($cup,JSON_THROW_ON_ERROR);
        must(!str_contains($json,'PRIVATE INTERNAL') && !str_contains($json,'SECRET REPORT'),
            'CUP_PRIVATE_DATA_LEAK');
        must($cup['personal_cup_status']==='UNKNOWN','CUP_PERSONAL_MAPPING_INVENTED');
        return ['published_reports'=>1,'verified_games'=>2,'personal_cup_status'=>'UNKNOWN'];
    },$report);
    check('C09_EXPLAIN_PVP_AND_CUP',function()use($ro){
        $pvp=explain($ro,pvpExplainSql());
        $cupReports=explain($ro,cupReportSql());
        $cupGames=explain($ro,cupGamesSql());
        must(count($pvp)>=2 && count($cupReports)>=2 && count($cupGames)>=2,
            'INCOMPLETE_QUERY_PLAN');
        return ['pvp'=>$pvp,'cup_reports'=>$cupReports,'cup_games'=>$cupGames];
    },$report);
    check('C10_CUP_ACTUAL_QUERY_LATENCY',function()use($ro){
        $baseline=sample(static fn()=>CupPublicReader::read($ro,2026,AS_OF));
        must($baseline['median_ms']>=0 && $baseline['median_ms']<10000,'CUP_QUERY_TIMEOUT');
        return $baseline;
    },$report);
    check('C11_MARIADB_70K_140K_READER_PERF',function()use($ro,$admin){
        $start=microtime(true);
        seedLargeSynthetic($admin);
        $seedMs=round((microtime(true)-$start)*1000,2);
        $aCount=(int)$admin->query('SELECT COUNT(*) FROM arena_unlight WHERE id>1000')->fetchColumn();
        $fCount=(int)$admin->query('SELECT COUNT(*) FROM arena_player_match_result WHERE match_id>1000')->fetchColumn();
        must($aCount===70000 && $fCount===140000,'SYNTHETIC_FIXTURE_SIZE_MISMATCH');
        $beforePlan=explain($ro,pvpExplainSql());
        $before=sample(static fn()=>PvPFactReader::readForSession($ro,FixtureData::sessionA(),AS_OF)['rows']);
        must($before['selected_rows']>100,'SYNTHETIC_SELECTIVITY_UNEXPECTED');
        $admin->exec('CREATE INDEX ci_candidate_player_time_match ON arena_player_match_result(player_name,update_time,match_id)');
        $afterPlan=explain($ro,pvpExplainSql());
        $after=sample(static fn()=>PvPFactReader::readForSession($ro,FixtureData::sessionA(),AS_OF)['rows']);
        must($before['selected_rows']===$after['selected_rows']
            && $before['result_sha256']===$after['result_sha256'],'INDEX_CHANGED_READER_RESULTS');
        return [
            'synthetic_matches'=>$aCount,'synthetic_player_rows'=>$fCount,
            'seed_ms'=>$seedMs,'baseline'=>$before,'candidate'=>$after,
            'baseline_explain'=>$beforePlan,'candidate_explain'=>$afterPlan,
            'candidate_index'=>'(player_name,update_time,match_id)',
            'candidate_in_ephemeral_container_only'=>true,
            'production_indexes_changed'=>false,
            'no_mariadb_speedup_assertion'=>true,
        ];
    },$report);
    $report['status']='PASS';
} catch(Throwable $error) {
    $report['errors'][]=get_class($error).': '.$error->getMessage();
    fwrite(STDERR,'M3BR2_FAIL '.$error->getMessage()."\n");
} finally {
    if(is_string($output) && $output!=='' && is_dir(dirname($output))){
        file_put_contents($output,json_encode($report,JSON_THROW_ON_ERROR|
            JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");
    }
}
echo 'M3BR2_FINAL status='.$report['status'].' passed='.count($report['gates'])."\n";
exit($report['status']==='PASS'?0:1);
