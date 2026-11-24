<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
use Ulgg\Checkpoint2026\Tests\FixtureData;
function ident(string $value): string {
    ok((bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $value), 'UNSAFE_SYNTH_IDENTIFIER');
    return chr(96).$value.chr(96);
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

guard();
if(PHP_SAPI!=='cli'||$argc!==2)throw new RuntimeException('CLI_ONLY');
$db=new PDO('mysql:host=m3c-db;port=3306;dbname=ulgg_m3c_synthetic;charset=utf8mb4',
    'root','synthetic-m3c-root-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
eq($db->query('SELECT DATABASE()')->fetchColumn(),'ulgg_m3c_synthetic');
switch($argv[1]){
case 'seed':
    foreach(syntheticSchemas() as $sql)$db->exec($sql);
    seedFixture($db);
    $db->exec("CREATE USER 'm3c_reader'@'%' IDENTIFIED BY 'synthetic-m3c-reader-only'");
    foreach(allowedColumns() as $table=>$columns)
        $db->exec('GRANT SELECT ('.implode(',',array_map('ident',$columns)).') ON ulgg_m3c_synthetic.'.ident($table)." TO 'm3c_reader'@'%'");
    break;
case 'revise':
    eq($db->exec('UPDATE arena_unlight SET ack2=1 WHERE id=6'),1);
    eq($db->exec("UPDATE tournament_match_games SET result_status='verified' WHERE id=11"),1);
    break;
case 'missing':
    eq($db->exec('DELETE FROM arena_player_match_result WHERE match_id=1'),1);
    break;
default: throw new RuntimeException('UNKNOWN_COMMAND');
}
echo 'M3C_ADMIN_SYNTHETIC_'.$argv[1]."_PASS\n";
