<?php
declare(strict_types=1);
require_once __DIR__.'/../../scripts/ruleset/lib/WeeklyCostStaging.php';
$dsn=getenv('ULGG_COST_STAGING_DSN');
$user=getenv('ULGG_COST_STAGING_USER');
$password=getenv('ULGG_COST_STAGING_PASSWORD');
$fixture=getenv('ULGG_COST_OFFICIAL_FIXTURE');
if(!is_string($dsn)||!is_string($user)||!is_string($password)||
   !is_string($fixture)||!is_file($fixture))throw new RuntimeException('MISSING_ISOLATED_STAGE_FIXTURE');
$db=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false]);
$pass=0;$fail=0;$checks=[];
function verifyCase(string $name,bool $ok):void{
    global $pass,$fail,$checks;$checks[$name]=$ok?'PASS':'FAIL';
    $ok?$pass++:$fail++;
}
function rowOne(PDO $db,string $day):array{
    $s=$db->prepare("SELECT * FROM quickmatch_weekly_cost WHERE effective_date=? AND ladder_zone_code='ALEXANDRE'");
    $s->execute([$day]);return $s->fetch(PDO::FETCH_ASSOC)?:[];
}
function stageArticle(string $gid,string $day,array $v):array {
    $ts=(new DateTimeImmutable($day.' 15:00:00',new DateTimeZone('Asia/Taipei')))->getTimestamp();
    return ['gid'=>$gid,'title'=>'Maintenance Information ('.str_replace('-','/',$day).')',
        'date'=>$ts,'url'=>'https://steamstore-a.akamaihd.net/news/externalpost/steam_community_announcements/'.$gid,
        'feedname'=>'steam_community_announcements',
        'contents'=>'《Alexandre》Category 1: COST '.$v[0].' Category 2: COST '.$v[1].
            ' Category 3: COST '.$v[2].' Category 4: COST '.$v[3].'+'];
}
function deny(string $label,callable $f):void {
    try{$f();verifyCase($label,false);}
    catch(PDOException $e){verifyCase($label,($e->errorInfo[1]??0)===1142||($e->errorInfo[0]??'')==='42000');}
}
$news=json_decode((string)file_get_contents($fixture),true,512,JSON_THROW_ON_ERROR)['items'];
$septBefore=rowOne($db,'2026-09-01');
$firstCount=(int)$db->query('SELECT COUNT(*) FROM quickmatch_weekly_cost')->fetchColumn();
verifyCase('ISOLATED_FIXTURE_BASELINE_FIVE',$firstCount===5);
verifyCase('SEPT01_APPROVED_BASELINE',($septBefore['import_status']??'')==='APPROVED');
verifyCase('SEPT01_SOURCE_GID_URL_MISMATCH_BASELINE',
    $septBefore['source_gid']==='1842846814453200'&&
    str_contains($septBefore['source_url'],'1845383656381576'));
$repeat=weeklyCostStageApply($db,$news);
verifyCase('SAME_OFFICIAL_BATCH_INSERTS_ZERO',$repeat['inserted']===0);
verifyCase('EXACT_PREVIOUS_ROWS_IDEMPOTENT',$repeat['unchanged']===4);
verifyCase('MISMATCHES_QUARANTINED',$repeat['review']===4);
verifyCase('NEVER_UPDATES_OR_APPROVES',$repeat['approved']===0&&$repeat['updated']===0);
verifyCase('STAGING_COUNT_UNCHANGED',(int)$db->query('SELECT COUNT(*) FROM quickmatch_weekly_cost')->fetchColumn()===$firstCount);
verifyCase('SEPT01_APPROVED_BEFORE_IMAGE_INTACT',rowOne($db,'2026-09-01')===$septBefore);
verifyCase('OCT29_INSERTED_DETECTED',rowOne($db,'2026-10-29')['import_status']==='DETECTED');
verifyCase('NOV19_INSERTED_DETECTED',rowOne($db,'2026-11-19')['import_status']==='DETECTED');
verifyCase('NEW_ROWS_MATCHMAKING_DISABLED',(int)rowOne($db,'2026-11-19')['matchmaking_enabled']===0);
verifyCase('NEW_ROWS_C4_IS_MIN',rowOne($db,'2026-11-19')['cost4_operator']==='MIN');
$duplicateDate=stageArticle('1900000000000011','2026-11-12',[58,62,74,90]);
$r=weeklyCostStageApply($db,[$duplicateDate]);
verifyCase('EXISTING_DATE_DIFFERENT_GID_QUARANTINE',$r['review']===1&&$r['inserted']===0);
$duplicateGid=stageArticle('1849192144181775','2026-11-13',[58,62,74,90]);
$r=weeklyCostStageApply($db,[$duplicateGid]);
verifyCase('EXISTING_GID_DIFFERENT_DATE_QUARANTINE',$r['review']===1&&$r['inserted']===0);
$corruptSept=stageArticle('1842846814453200','2026-09-01',[58,68,75,90]);
$r=weeklyCostStageApply($db,[$corruptSept]);
verifyCase('APPROVED_SOURCE_MISMATCH_QUARANTINE',$r['review']===1&&$r['inserted']===0);
verifyCase('APPROVED_ROW_STILL_UNCHANGED',rowOne($db,'2026-09-01')===$septBefore);
$badUrl=$duplicateDate;$badUrl['url']=str_replace($badUrl['gid'],'1900000000000022',$badUrl['url']);
$r=weeklyCostStageApply($db,[$badUrl]);
verifyCase('URL_GID_MISMATCH_ZERO_WRITES',$r['review']===1&&$r['inserted']===0);
$made1=stageArticle('1900000000000031','2026-12-08',[56,65,75,90]);
$made2=stageArticle('1900000000000032','2026-12-15',[55,65,78,90]);
$rollback=false;
try{
 weeklyCostStageApply($db,[$made1,$made2],static function(int $inserted):void{
     if($inserted===1)throw new RuntimeException('TEST_INJECTED_FAILURE');
 });
}catch(RuntimeException $e){$rollback=$e->getMessage()==='TEST_INJECTED_FAILURE';}
verifyCase('FORCED_INNODB_FAILURE_THROWN',$rollback);
verifyCase('ROLLBACK_LEAVES_NO_FIRST_ROW',rowOne($db,'2026-12-08')===[]);
verifyCase('ROLLBACK_LEAVES_NO_SECOND_ROW',rowOne($db,'2026-12-15')===[]);
verifyCase('ROLLBACK_RETAINS_APPROVED_BEFORE_IMAGE',rowOne($db,'2026-09-01')===$septBefore);
verifyCase('ROLLBACK_TOTAL_COUNT_UNCHANGED',(int)$db->query('SELECT COUNT(*) FROM quickmatch_weekly_cost')->fetchColumn()===$firstCount);
deny('STAGING_ACCOUNT_UPDATE_DENIED',fn()=>$db->exec('UPDATE quickmatch_weekly_cost SET source_title=source_title WHERE 1=0'));
deny('STAGING_ACCOUNT_DELETE_DENIED',fn()=>$db->exec('DELETE FROM quickmatch_weekly_cost WHERE 1=0'));
deny('STAGING_ACCOUNT_DDL_DENIED',fn()=>$db->exec('CREATE TABLE d5i_forbidden_test (id INT)'));
deny('STAGING_ACCOUNT_APPROVAL_INSERT_DENIED',fn()=>$db->exec(
 "INSERT INTO quickmatch_weekly_cost (ladder_zone_code,effective_date,import_status) VALUES ('ALEXANDRE','2026-12-22','APPROVED')"));
deny('STAGING_ACCOUNT_MATCHMAKING_ENABLE_DENIED',fn()=>$db->exec(
 "INSERT INTO quickmatch_weekly_cost (ladder_zone_code,effective_date,matchmaking_enabled) VALUES ('ALEXANDRE','2026-12-22',1)"));
verifyCase('APPROVED_COUNT_STILL_ONE',(int)$db->query("SELECT COUNT(*) FROM quickmatch_weekly_cost WHERE import_status='APPROVED'")->fetchColumn()===1);
verifyCase('FINAL_COUNT_STABLE',(int)$db->query('SELECT COUNT(*) FROM quickmatch_weekly_cost')->fetchColumn()===$firstCount);
echo json_encode(['result'=>$fail?'FAIL':'PASS','passed'=>$pass,'failed'=>$fail,
    'checks'=>$checks,'staging_rows'=>$firstCount,
    'real_official_replay'=>$repeat,'production_writes'=>0],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
exit($fail?1:0);
