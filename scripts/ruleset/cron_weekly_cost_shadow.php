<?php
declare(strict_types=1);
/**
 * D5-J cron-compatible shadow: no arguments = read-only public Steam news audit.
 * Never loads config.php, never connects to any DB, never writes a file.
 *
 * Legacy production 21:15 cron must continue to target the unchanged
 * scripts/ruleset/import_weekly_cost.php until separately authorized cutover.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(2);}
require_once __DIR__.'/lib/WeeklyCostShadow.php';
try{
    $file=null;
    foreach(array_slice($argv,1) as $arg){
        if(str_starts_with($arg,'--fixture=')){
            if($file!==null)throw new RuntimeException('DUPLICATE_FIXTURE_OPTION');
            $file=substr($arg,10);
        }elseif(in_array($arg,['--live-readonly','--cron-shadow'],true)){
            continue;
        }else{
            throw new RuntimeException('UNSUPPORTED_SHADOW_OPTION');
        }
    }
    if($file!==null){
        if(!is_file($file)||filesize($file)>6000000)throw new RuntimeException('FIXTURE_FILE_INVALID');
        $input=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(isset($input['items'])&&is_array($input['items']))$news=$input['items'];
        elseif(isset($input['appnews']['newsitems'])&&is_array($input['appnews']['newsitems']))
            $news=$input['appnews']['newsitems'];
        elseif(is_array($input)&&($input===[]||array_keys($input)===range(0,count($input)-1)))
            $news=$input;
        else throw new RuntimeException('FIXTURE_SCHEMA_INVALID');
    }else{
        $news=weeklyCostFetchNews(100);
    }
    if(count($news)>100)throw new RuntimeException('TOO_MANY_NEWS_ITEMS');
    $report=weeklyCostShadowReport($news);
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){
    fwrite(STDERR,"D5J_SHADOW_STOP=".$e->getMessage()."\n");
    exit(2);
}
