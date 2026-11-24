<?php
declare(strict_types=1);
require_once __DIR__.'/../../scripts/ruleset/lib/WeeklyCostPolicy.php';
require_once __DIR__.'/../../scripts/ruleset/lib/WeeklyCostSource.php';
$pass=0;$fail=0;$names=[];
function t(string $name,bool $yes):void{
    global $pass,$fail,$names;
    $names[$name]=$yes?'PASS':'FAIL';$yes?$pass++:$fail++;
}
function mk(string $gid,string $titleDay,string $publishedDay,
            array $cost=[54,61,77,90],int $pad=0,bool $fornheil=false):array {
    $tz=new DateTimeZone('Asia/Taipei');
    $time=(new DateTimeImmutable($publishedDay.' 17:15:00',$tz))->getTimestamp();
    $body=str_repeat('X',$pad).' 《Alexandre》Category 1: COST '.$cost[0].
        ' Category 2: COST '.$cost[1].' Category 3: COST '.$cost[2].
        ' Category 4: COST '.$cost[3].'+';
    if($fornheil)$body.=' 《Fornheil》Category 1: COST 43 Category 2: COST 53'.
        ' Category 3: COST 70 Category 4: COST 90+';
    return ['gid'=>$gid,'title'=>'Maintenance Information ('.str_replace('-','/',$titleDay).')',
        'date'=>$time,'url'=>'https://steamstore-a.akamaihd.net/news/externalpost/steam_community_announcements/'.$gid,
        'contents'=>$body,'feedname'=>'steam_community_announcements'];
}
function sampleExisting(array $n,array $c,string $zone='ALEXANDRE'):array{
    $cost=$c['profiles'][$zone];
    return ['id'=>1,'ladder_zone_code'=>$zone,'effective_date'=>$c['candidate_date'],
        'source_gid'=>$c['source_gid'],'source_url'=>$c['source_url'],
        'source_title'=>$c['source_title'],'source_published_at'=>$c['published_local'],
        'cost4_operator'=>'MIN','import_status'=>'APPROVED',
        'cost1'=>$cost['cost1'],'cost2'=>$cost['cost2'],
        'cost3'=>$cost['cost3'],'cost4'=>$cost['cost4']];
}
try {
    $long=mk('1847920302233787','2026-10-29','2026-10-29',[54,61,77,90],6100);
    $nov=mk('1849827110428090','2026-11-19','2026-11-19',[53,64,79,90],7000);
    $badOctober=mk('1845383656381576','2026-09-01','2026-10-01',[53,67,76,90],5300);
    $late=mk('1844115010500754','2026-09-15','2026-09-21',[58,67,73,90]);
    $normal=mk('1849192144181775','2026-11-12','2026-11-12',[58,62,74,90]);
    t('NEWS_URL_FETCHES_FULL_CONTENT',str_contains(weeklyCostBuildNewsUrl(100),'maxlength=0'));
    t('NEWS_URL_LOOKBACK_100',str_contains(weeklyCostBuildNewsUrl(100),'count=100'));
    try{weeklyCostBuildNewsUrl(101);t('NEWS_COUNT_OVER_100_REJECTED',false);}catch(InvalidArgumentException $e){t('NEWS_COUNT_OVER_100_REJECTED',true);}
    t('OFFICIAL_LONG_OCT29_PARSER',weeklyCostParseProfiles($long)['ALEXANDRE']['cost3']===77);
    t('OFFICIAL_LONG_NOV19_PARSER',weeklyCostParseProfiles($nov)['ALEXANDRE']['cost1']===53);
    t('OFFICIAL_LONG_BUFFER_GT_5000',strlen($long['contents'])>5000&&strlen($nov['contents'])>5000);
    t('OCT01_MISTITLED_QUARANTINE',weeklyCostClassifyNews($badOctober)['status']==='REVIEW_DATE_CONFLICT');
    t('OCT01_NOT_MAPPED_TO_SEPT01',weeklyCostClassifyNews($badOctober)['status']!=='READY_DETECTED');
    t('SEP15_LATE_PUBLICATION_QUARANTINE',weeklyCostClassifyNews($late)['status']==='REVIEW_DATE_CONFLICT');
    t('OCT29_ALLOWED_DETECTED',weeklyCostClassifyNews($long)['status']==='READY_DETECTED');
    t('NOV19_ALLOWED_DETECTED',weeklyCostClassifyNews($nov)['status']==='READY_DETECTED');
    t('NOV12_VALID_COST',weeklyCostClassifyNews($normal)['profiles']['ALEXANDRE']['cost4']===90);
    $both=mk('1849192144181775','2026-11-12','2026-11-12',[58,62,74,90],0,true);
    t('TWO_ZONE_PARSE',count(weeklyCostParseProfiles($both))===2);
    t('NO_FORNHEIL_REQUIRED',count(weeklyCostParseProfiles($normal))===1);
    $wrong=$normal;$wrong['url']=str_replace($wrong['gid'],'1849827110428090',$wrong['url']);
    t('URL_GID_MISMATCH_QUARANTINE',weeklyCostClassifyNews($wrong)['status']==='REVIEW_SOURCE_CONFLICT');
    $nonHttps=$normal;$nonHttps['url']=str_replace('https:','http:',$normal['url']);
    t('HTTP_URL_REJECTED',weeklyCostClassifyNews($nonHttps)['status']==='REVIEW_SOURCE_CONFLICT');
    $badHost=$normal;$badHost['url']=str_replace('steamstore-a.akamaihd.net','attacker.example',$normal['url']);
    t('UNAPPROVED_HOST_REJECTED',weeklyCostClassifyNews($badHost)['status']==='REVIEW_SOURCE_CONFLICT');
    $noGid=$normal;unset($noGid['gid']);
    t('MISSING_GID_REJECTED',weeklyCostClassifyNews($noGid)['status']==='REVIEW_SOURCE_CONFLICT');
    $noDate=$normal;$noDate['date']=0;
    t('MISSING_TIMESTAMP_REJECTED',weeklyCostClassifyNews($noDate)['status']==='REVIEW_DATE_CONFLICT');
    $invalidTitle=$normal;$invalidTitle['title']='Maintenance Information (2026/11/32)';
    t('INVALID_TITLE_DAY_REJECTED',weeklyCostClassifyNews($invalidTitle)['status']==='REVIEW_DATE_CONFLICT');
    $empty=$normal;$empty['contents']='No cost announcement';
    t('NO_COST_SKIPPED',weeklyCostClassifyNews($empty)['status']==='SKIP_NO_COST');
    $invalidCost=$normal;$invalidCost['contents']=str_replace('Category 4: COST 90+','Category 4: COST 89+',$normal['contents']);
    t('NOT_90PLUS_REJECTED',weeklyCostParseProfiles($invalidCost)===[]);
    t('MALFORMED_COST_GETS_MANUAL_REVIEW',
      weeklyCostClassifyNews($invalidCost)['status']==='REVIEW_PARSE_INCOMPLETE');
    $c=weeklyCostClassifyNews($normal);$row=sampleExisting($normal,$c);
    t('NEW_DATE_AND_GID_INSERT_DETECTED',weeklyCostDecide($c,'ALEXANDRE',null,null)['action']==='INSERT_DETECTED');
    t('APPROVED_SAME_EVIDENCE_UNCHANGED',weeklyCostDecide($c,'ALEXANDRE',$row,$row)['action']==='UNCHANGED');
    $diffGid=$row;$diffGid['id']=5;
    t('DOUBLE_UNIQUE_COLLISION_REJECTED',weeklyCostDecide($c,'ALEXANDRE',$row,$diffGid)['action']==='REVIEW_CONFLICT');
    t('SAME_DATE_DIFFERENT_GID_REJECTED',weeklyCostDecide($c,'ALEXANDRE',$row,null)['action']==='REVIEW_CONFLICT');
    t('SAME_GID_DIFFERENT_DATE_REJECTED',weeklyCostDecide($c,'ALEXANDRE',null,$row)['action']==='REVIEW_CONFLICT');
    $badCost=$row;$badCost['cost2']++;
    t('COST_DRIFT_REJECTED',weeklyCostDecide($c,'ALEXANDRE',$badCost,$badCost)['action']==='REVIEW_CONFLICT');
    $badPublished=$row;$badPublished['source_published_at']='2026-10-01 22:37:59';
    t('APPROVED_SOURCE_TIMESTAMP_DRIFT_REJECTED',weeklyCostDecide($c,'ALEXANDRE',$badPublished,$badPublished)['action']==='REVIEW_CONFLICT');
    $badURL=$row;$badURL['source_url']='https://steamstore-a.akamaihd.net/news/externalpost/steam_community_announcements/1845383656381576';
    t('APPROVED_SOURCE_URL_DRIFT_REJECTED',weeklyCostDecide($c,'ALEXANDRE',$badURL,$badURL)['action']==='REVIEW_CONFLICT');
    t('DATE_REVIEW_NEVER_INSERT',weeklyCostDecide(weeklyCostClassifyNews($badOctober),'ALEXANDRE',null,null)['action']==='REVIEW');
    $raw=json_encode(['appnews'=>['newsitems'=>[$long,$nov]]],JSON_THROW_ON_ERROR);
    t('SOURCE_TRANSPORT_READONLY',count(weeklyCostFetchNews(100,fn($url)=>$raw))===2);
    try{weeklyCostFetchNews(100,fn($url)=>str_repeat('X',6000001));t('SOURCE_OVERSIZE_REJECTED',false);}
    catch(RuntimeException $e){t('SOURCE_OVERSIZE_REJECTED',true);}
    try{weeklyCostFetchNews(100,fn($url)=>'{"appnews":{}}');t('SOURCE_SCHEMA_INVALID_REJECTED',false);}
    catch(RuntimeException $e){t('SOURCE_SCHEMA_INVALID_REJECTED',true);}
    $private=getenv('ULGG_COST_OFFICIAL_FIXTURE');
    if(is_string($private)&&$private!==''){
        $doc=json_decode((string)file_get_contents($private),true,512,JSON_THROW_ON_ERROR);
        $refs=[];foreach($doc['items'] as $news)$refs[(string)$news['gid']]=weeklyCostClassifyNews($news);
        t('REAL_OCT29_FULL_CONTENT',($refs['1847920302233787']['profiles']['ALEXANDRE']['cost1']??null)===54);
        t('REAL_NOV19_FULL_CONTENT',($refs['1849827110428090']['profiles']['ALEXANDRE']['cost2']??null)===64);
        t('REAL_OCT01_MISTITLE_REJECTED',$refs['1845383656381576']['status']==='REVIEW_DATE_CONFLICT');
        t('REAL_SEPT15_LATE_REJECTED',$refs['1844115010500754']['status']==='REVIEW_DATE_CONFLICT');
        t('REAL_OLD_SEPT01_LATE_REJECTED',$refs['1842846814453200']['status']==='REVIEW_DATE_CONFLICT');
        t('REAL_NOV12_VALID',$refs['1849192144181775']['status']==='READY_DETECTED');
    }
}catch(Throwable $e){$fail++;$names['UNEXPECTED_EXCEPTION']=$e->getMessage();}
echo json_encode(['result'=>$fail?'FAIL':'PASS','passed'=>$pass,'failed'=>$fail,
  'checks'=>$names],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
exit($fail?1:0);
