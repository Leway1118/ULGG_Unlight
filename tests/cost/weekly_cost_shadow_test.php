<?php
declare(strict_types=1);
require_once __DIR__.'/../../scripts/ruleset/lib/WeeklyCostShadow.php';
$passed=0;$failed=0;$cases=[];
function testShadow(string $name,bool $ok):void{
    global $passed,$failed,$cases;
    $cases[$name]=$ok?'PASS':'FAIL';
    if($ok)$passed++;else $failed++;
}
$ts=(new DateTimeImmutable('2026-11-19 16:00:00',new DateTimeZone('Asia/Taipei')))->getTimestamp();
$good=['gid'=>'1849827110428090','title'=>'Maintenance Information (2026/11/19)',
    'url'=>'https://steamstore-a.akamaihd.net/news/externalpost/steam_community_announcements/1849827110428090',
    'date'=>$ts,'contents'=>'《Alexandre》Category 1: COST 53 Category 2: COST 64 Category 3: COST 79 Category 4: COST 90+'];
$wrong=$good;$wrong['title']='Maintenance Information (2026/09/01)';
$none=$good;$none['contents']='Maintenance notice without a ranking update';
$corrupt=$good;$corrupt['contents']='COST announcement does not list all categories';
$r=weeklyCostShadowReport([$good,$wrong,$none,$corrupt]);
testShadow('READ_ONLY_STATUS',$r['status']==='SHADOW_READONLY');
testShadow('COUNTS_TOTAL',$r['articles']===4);
testShadow('COUNTS_READY',$r['ready_detected']===1);
testShadow('COUNTS_REVIEW',$r['review']===2);
testShadow('COUNTS_SKIPPED',$r['skipped']===1);
testShadow('NO_WRITES',$r['would_write']===0);
testShadow('NO_APPROVAL',$r['would_approve']===0);
testShadow('ZONE_COUNT',($r['candidate_zones']['ALEXANDRE']??0)===1);
testShadow('DATE_CONFLICT_COUNT',($r['review_reasons']['TITLE_PUBLICATION_DAY_MISMATCH']??0)===1);
testShadow('INCOMPLETE_PARSE_COUNT',($r['review_reasons']['COST_KEYWORD_BUT_NO_VALID_FOUR_BUCKETS']??0)===1);
$r2=weeklyCostShadowReport([$good,$wrong,$none,$corrupt]);
testShadow('DETERMINISTIC_SHADOW',$r===$r2);
try{weeklyCostShadowReport(['not-article']);testShadow('REJECT_MALFORMED_NEWS',false);}
catch(InvalidArgumentException $e){testShadow('REJECT_MALFORMED_NEWS',true);}
echo json_encode(['result'=>$failed?'FAIL':'PASS','passed'=>$passed,
    'failed'=>$failed,'checks'=>$cases],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($failed?1:0);
