<?php
declare(strict_types=1);
/**
 * D5-I isolated replacement proposal.
 *
 * Explicit modes:
 *   --fixture=FILE --audit-offline      (zero DB access)
 *   --live-readonly                     (read public Steam API only; zero DB access)
 *   --fixture=FILE --apply-staging      (localhost:13305/ulgg_d5i_staging ONLY)
 *
 * No production apply path by design. Existing scheduled importer MUST NOT be
 * switched to this runner until a separate production release is authorized.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/lib/WeeklyCostPolicy.php';
require_once __DIR__.'/lib/WeeklyCostSource.php';
require_once __DIR__.'/lib/WeeklyCostStaging.php';

function d5iLoadFixture(string $file): array {
    if(!is_file($file)||filesize($file)>6000000)throw new RuntimeException('FIXTURE_FILE_UNAVAILABLE');
    $d=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(isset($d['items'])&&is_array($d['items']))return $d['items'];
    if(isset($d['appnews']['newsitems'])&&is_array($d['appnews']['newsitems']))
        return $d['appnews']['newsitems'];
    if(is_array($d)&&($d===[]||array_keys($d)===range(0,count($d)-1)))return $d;
    throw new RuntimeException('FIXTURE_NEWS_SCHEMA_INVALID');
}
try {
    $opts=getopt('',['fixture:','audit-offline','live-readonly','apply-staging']);
    $apply=array_key_exists('apply-staging',$opts);
    $live=array_key_exists('live-readonly',$opts);
    $audit=array_key_exists('audit-offline',$opts);
    $fixture=$opts['fixture']??null;
    if(($apply+$live+$audit)!==1||($live&&$fixture!==null)||
       (($apply||$audit)&&(!is_string($fixture)||$fixture==='')))
        throw new RuntimeException('EXPLICIT_SAFE_MODE_REQUIRED');
    $news=$live?weeklyCostFetchNews(100):d5iLoadFixture($fixture);
    if(count($news)>100)throw new RuntimeException('NEWS_COUNT_EXCEEDS_LIMIT');
    if($apply){
        $dsn=getenv('ULGG_COST_STAGING_DSN');
        $user=getenv('ULGG_COST_STAGING_USER');
        $pass=getenv('ULGG_COST_STAGING_PASSWORD');
        if($dsn!=='mysql:host=127.0.0.1;port=13305;dbname=ulgg_d5i_staging;charset=utf8mb4'||
           !is_string($user)||!is_string($pass)||$pass==='')
            throw new RuntimeException('LOCAL_STAGING_CREDENTIAL_GUARD');
        $pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false]);
        $report=weeklyCostStageApply($pdo,$news);
    }else{
        $report=['status'=>'AUDIT_ONLY_NO_DB','articles'=>count($news),
            'ready_detected'=>0,'review'=>0,'skipped'=>0,'items'=>[]];
        foreach($news as $item){
            $c=weeklyCostClassifyNews($item);
            if($c['status']==='READY_DETECTED')$report['ready_detected']++;
            elseif($c['status']==='SKIP_NO_COST')$report['skipped']++;
            else $report['review']++;
            if($c['status']!=='SKIP_NO_COST'){
                $report['items'][]=['gid'=>$c['source_gid'],
                    'candidate_date'=>$c['candidate_date'],
                    'status'=>$c['status'],'zones'=>array_keys($c['profiles']),
                    'reasons'=>$c['reasons']];
            }
        }
    }
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){
    fwrite(STDERR,"D5I_STOP=".$e->getMessage()."\n");
    exit(2);
}
