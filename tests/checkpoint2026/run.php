<?php
declare(strict_types=1);
// D1/M1 isolated TDD. Synthetic facts and in-memory SQLite only.
require_once __DIR__.'/../../lib/checkpoint2026/IdentityGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPAnnualAggregator.php';
require_once __DIR__.'/../../lib/checkpoint2026/CupPublicReader.php';
use Ulgg\Checkpoint2026\IdentityGate;
use Ulgg\Checkpoint2026\PvPAnnualAggregator;
use Ulgg\Checkpoint2026\CupPublicReader;

$tests=[];
function test(string $n,Closure $cb):void{global $tests;$tests[$n]=$cb;}
function eq(mixed $a,mixed $b):void{if($a!==$b){throw new RuntimeException('expected '.var_export($b,true).' actual '.var_export($a,true));}}
function yes(bool $a):void{if(!$a)throw new RuntimeException('assertion failed');}
function sess(array $x=[]):array{return array_replace(['steam_id'=>'76561190000000000','user_id'=>12,'username'=>'SYNTH_A','user_region'=>'TW'],$x);}
function acct(array $x=[]):array{return array_replace(['id'=>12,'steamID'=>'76561190000000000','username'=>'SYNTH_A','region'=>'TW','ack'=>1,'apply'=>0,'black_list'=>0],$x);}
function matchRow(int $id,array $x=[]):array{return array_replace([
 'match_id'=>$id,'side'=>'P1','player_name'=>'SYNTH_A','region'=>'TW',
 'update_time'=>'2026-06-01 08:00:00','truth_update_time'=>'2026-06-01 08:00:00',
 'ack1'=>1,'ack2'=>1,'name_p1'=>'SYNTH_A','name_p2'=>'SYNTH_B',
 'win'=>0,'lose'=>1,'tie'=>0,'is_win'=>1,
 'leader_id'=>11,'back1_id'=>22,'back2_id'=>33,'team_key'=>'11-22-33','cost'=>20,
],$x);}
function calc(array $rows,array $x=[]):array{return PvPAnnualAggregator::aggregate($rows,array_replace(['year'=>2026,'region'=>'TW','player_name'=>'SYNTH_A','as_of'=>'2026-11-24 10:00:00','source_verified'=>true],$x));}
test('identity_valid_owner',function(){ $r=IdentityGate::fromSessionAndAccount(sess(),acct());eq($r['status'],'VERIFIED');eq($r['subject']['user_id'],12);});
test('identity_unbound_pending',function(){eq(IdentityGate::fromSessionAndAccount(sess(),null)['status'],'IDENTITY_UNVERIFIED');eq(IdentityGate::fromSessionAndAccount(sess(),acct(['ack'=>0,'apply'=>1]))['status'],'IDENTITY_UNVERIFIED');});
test('identity_steam_user_mismatch',function(){eq(IdentityGate::fromSessionAndAccount(sess(['steam_id'=>'76561190000000001']),acct())['status'],'IDENTITY_UNVERIFIED');eq(IdentityGate::fromSessionAndAccount(sess(['user_id'=>13]),acct())['status'],'IDENTITY_UNVERIFIED');});
test('identity_name_region_ban',function(){eq(IdentityGate::fromSessionAndAccount(sess(['username'=>'OTHER']),acct())['status'],'IDENTITY_UNVERIFIED');eq(IdentityGate::fromSessionAndAccount(sess(['user_region'=>'JP']),acct())['status'],'IDENTITY_UNVERIFIED');eq(IdentityGate::fromSessionAndAccount(sess(),acct(['black_list'=>1]))['status'],'IDENTITY_UNVERIFIED');});
test('identity_invalid_steam_region',function(){eq(IdentityGate::fromSessionAndAccount(sess(['steam_id'=>'foo']),acct())['status'],'IDENTITY_UNVERIFIED');eq(IdentityGate::fromSessionAndAccount(sess(),acct(['region'=>'MATCH']))['status'],'IDENTITY_UNVERIFIED');});
test('pvp_win_loss_draw_and_rate',function(){
 $r=calc([matchRow(1),matchRow(2,['win'=>1,'lose'=>0,'is_win'=>0]),matchRow(3,['win'=>0,'lose'=>0,'tie'=>1,'is_win'=>null])]);
 eq([$r['verified_matches'],$r['wins'],$r['losses'],$r['draws'],$r['win_rate_pct']],[3,1,1,1,33.3]);eq($r['status'],'VERIFIED');
});
test('pvp_p2_win_and_p2_loss_inverted_from_arena',function(){
 $r=calc([
   matchRow(11,['side'=>'P2','name_p1'=>'SYNTH_B','name_p2'=>'SYNTH_A','win'=>1,'lose'=>0,'is_win'=>1]),
   matchRow(12,['side'=>'P2','name_p1'=>'SYNTH_B','name_p2'=>'SYNTH_A','win'=>0,'lose'=>1,'is_win'=>0])
 ]);
 eq([$r['verified_matches'],$r['wins'],$r['losses']],[2,1,1]);
});
test('pvp_rejects_cr_unverified_source_region',function(){
 $r=calc([matchRow(111,['region'=>'CR'])]);
 eq($r['verified_matches'],0);
 eq($r['excluded_count'],1);
});
test('pvp_exact_asof_allowed_but_after_asof_rejected',function(){
 $r=calc([
  matchRow(30,['update_time'=>'2026-11-24 10:00:00','truth_update_time'=>'2026-11-24 10:00:00']),
  matchRow(31,['update_time'=>'2026-11-24 10:00:01','truth_update_time'=>'2026-11-24 10:00:01'])
 ]);
 eq($r['verified_matches'],1);eq($r['excluded_count'],1);
});
test('pvp_null_without_tie_undecided',function(){ $r=calc([matchRow(1,['win'=>0,'lose'=>0,'tie'=>0,'is_win'=>null])]);eq([$r['verified_matches'],$r['undecided'],$r['draws'],$r['win_rate_pct']],[0,1,0,null]); });
test('pvp_missing_ack_match_region_excluded',function(){
 $r=calc([matchRow(1,['ack2'=>0]),matchRow(2,['region'=>'MATCH']),matchRow(3,['region'=>null]),matchRow(4,['region'=>'JP'])]);
 eq($r['verified_matches'],0);eq($r['excluded_count'],4);eq($r['status'],'PARTIAL');
});
test('pvp_cross_account_side_result_mismatch',function(){
 $r=calc([matchRow(1,['player_name'=>'OTHER']),matchRow(2,['side'=>'P2','name_p2'=>'OTHER']),matchRow(3,['is_win'=>0]),matchRow(4,['tie'=>1,'win'=>1])]);
 eq($r['verified_matches'],0);eq($r['excluded_count'],4);
});
test('pvp_team_canonical_and_three_slots',function(){
 $r=calc([matchRow(1),matchRow(2,['back1_id'=>33,'back2_id'=>22])]);
 eq(count($r['top_teams']),1);eq($r['top_teams'][0]['team_key'],'11-22-33');eq($r['top_teams'][0]['matches'],2);
 eq(count($r['top_characters']),3);eq($r['top_characters'][0]['usage'],2);
});
test('pvp_same_character_once_per_match',function(){
 $r=calc([matchRow(1,['back1_id'=>22,'back2_id'=>22,'team_key'=>'11-22-22'])]);
 $uses=array_column($r['top_characters'],'usage','char_id');eq($uses[11],1);eq($uses[22],1);
});
test('pvp_bad_team_key_excluded_only_from_team',function(){
 $r=calc([matchRow(1,['team_key'=>'11-33-22'])]);eq($r['verified_matches'],1);eq($r['team_data_excluded'],1);eq(count($r['top_teams']),0);
});
test('pvp_exact_duplicate_dedup',function(){
 $m=matchRow(8);$r=calc([$m,$m]);eq($r['verified_matches'],1);eq($r['duplicate_rows_ignored'],1);
});
test('pvp_conflicting_duplicate_rejected',function(){
 $r=calc([matchRow(8),matchRow(8,['leader_id'=>999])]);eq($r['verified_matches'],0);eq($r['excluded_count'],1);
});
test('pvp_year_boundary_and_future_month',function(){
 $r=calc([matchRow(1,['update_time'=>'2025-12-31 23:59:59','truth_update_time'=>'2025-12-31 23:59:59']),matchRow(2,['update_time'=>'2027-01-01 00:00:00','truth_update_time'=>'2027-01-01 00:00:00']),matchRow(3)]);
 eq($r['verified_matches'],1);eq($r['monthly_trend']['2026-12']['status'],'NOT_APPLICABLE');eq($r['monthly_trend']['2026-12']['matches'],null);
});
test('pvp_source_unknown_distinct_from_valid_zero',function(){
 $r=calc([]);eq($r['status'],'VERIFIED');eq($r['verified_matches'],0);eq($r['win_rate_pct'],null);
 $r=calc([],['source_verified'=>false]);eq($r['status'],'UNKNOWN');eq($r['verified_matches'],null);
});
test('pvp_truth_name_and_timestamp_guards',function(){
 $r=calc([matchRow(1,['name_p1'=>'OTHER']),matchRow(2,['truth_update_time'=>'2026-06-02 08:00:00'])]);
 eq($r['verified_matches'],0);eq($r['excluded_count'],2);
});
function cupPdo():PDO{
 $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $p->exec("CREATE TABLE tournament_reports(id INTEGER PRIMARY KEY,tournament_id INTEGER,issue_key TEXT,report_title TEXT,status TEXT,published_at TEXT)");
 $p->exec("CREATE TABLE tournament_report_sections(id INTEGER PRIMARY KEY,report_id INTEGER,section_key TEXT,section_title TEXT,sort_order INTEGER,is_visible INTEGER)");
 $p->exec("CREATE TABLE tournament_matches(id INTEGER PRIMARY KEY,tournament_id INTEGER,status TEXT,player1_id INTEGER,player2_id INTEGER,winner_player_id INTEGER)");
 $p->exec("CREATE TABLE tournament_match_games(id INTEGER PRIMARY KEY,tournament_id INTEGER,match_id INTEGER,game_no INTEGER,result_status TEXT,player1_id INTEGER,player2_id INTEGER,winner_player_id INTEGER)");
 $p->exec("INSERT INTO tournament_reports VALUES (1,1,'final','SYNTH final','published','2026-07-21 10:00:00'),(2,1,'draft','SECRET','draft',NULL),(3,2,'future','FUTURE','published','2027-01-02 10:00:00')");
 $p->exec("INSERT INTO tournament_report_sections VALUES (1,1,'public','PUBLIC',1,1),(2,1,'hidden','SECRET',2,0),(3,2,'draft','SECRET',1,1)");
 $p->exec("INSERT INTO tournament_matches VALUES (1,1,'confirmed',101,102,101),(2,1,'pending',101,102,NULL),(3,1,'finished',101,102,102),(4,2,'confirmed',201,202,201)");
 $p->exec("INSERT INTO tournament_match_games VALUES (10,1,1,1,'verified',101,102,101),(11,1,1,2,'candidate',101,102,102),(12,1,2,1,'verified',101,102,101),(13,1,3,1,'verified',101,102,999),(14,1,3,2,'verified',101,102,102),(15,2,4,1,'verified',201,202,201)");
 $p->exec("ALTER TABLE tournament_match_games ADD COLUMN created_at TEXT DEFAULT '2026-07-20 08:00:00'");
 $p->exec("ALTER TABLE tournament_match_games ADD COLUMN updated_at TEXT DEFAULT '2026-07-20 08:00:00'");
 $p->exec("ALTER TABLE tournament_matches ADD COLUMN updated_at TEXT DEFAULT '2026-07-20 08:00:00'");
 return $p;
}
test('cup_published_only_visible_sections',function(){
 $r=CupPublicReader::read(cupPdo(),2026,'2026-11-24 10:00:00');
 eq(count($r['reports']),1);eq($r['reports'][0]['title'],'SYNTH final');eq(count($r['reports'][0]['sections']),1);eq($r['reports'][0]['sections'][0]['key'],'public');eq($r['status'],'VERIFIED');
});
test('cup_only_verified_valid_winner_finished',function(){
 $r=CupPublicReader::read(cupPdo(),2026,'2026-11-24 10:00:00');eq($r['verified_games'],2);eq($r['verified_games_by_tournament'][1],2);
 yes(!isset($r['verified_games_by_tournament'][2]));
});
test('cup_personal_unknown_and_private_fields_never_leak',function(){
 $r=CupPublicReader::read(cupPdo(),2026,'2026-11-24 10:00:00');eq($r['personal_cup_status'],'UNKNOWN');yes(!isset($r['steam_id'])&&!isset($r['player_name']));
});
test('cup_no_published_means_no_games_visible',function(){
 $p=cupPdo();$p->exec("UPDATE tournament_reports SET status='draft' WHERE id=1");
 $r=CupPublicReader::read($p,2026,'2026-11-24 10:00:00');eq(count($r['reports']),0);eq($r['verified_games'],0);
});
test('cup_asof_does_not_time_travel_to_future_verification',function(){
 $p=cupPdo();
 $p->exec("UPDATE tournament_match_games SET updated_at='2026-12-01 08:00:00' WHERE id=14");
 $r=CupPublicReader::read($p,2026,'2026-11-24 10:00:00');eq($r['verified_games'],1);
});
test('cup_late_match_correction_not_attributed_before_asof',function(){
 $p=cupPdo();
 $p->exec("UPDATE tournament_matches SET updated_at='2026-12-01 08:00:00' WHERE id=1");
 $r=CupPublicReader::read($p,2026,'2026-11-24 10:00:00');eq($r['verified_games'],1);
});
test('cup_only_select_sql_not_write_queries',function(){
 $s=file_get_contents(__DIR__.'/../../lib/checkpoint2026/CupPublicReader.php');
 yes(strpos($s,'FROM tournament_reports')!==false);
 yes(strpos($s,'FROM tournament_match_games')!==false);
 yes(preg_match('/\$pdo->(?:exec|query)\s*\(/',$s)===0);
});
test('cup_asof_before_publication_hides',function(){
 $r=CupPublicReader::read(cupPdo(),2026,'2026-01-01 00:00:00');eq(count($r['reports']),0);
});
$pass=0;$fail=0;
foreach($tests as $name=>$fn){try{$fn();$pass++;echo "PASS $name\n";}catch(Throwable $e){$fail++;echo "FAIL $name : ".get_class($e).": ".$e->getMessage()."\n";}}
echo "CHECKPOINT_D1M1_RESULTS pass=$pass fail=$fail tests=".count($tests)."\n";
exit($fail===0?0:1);
