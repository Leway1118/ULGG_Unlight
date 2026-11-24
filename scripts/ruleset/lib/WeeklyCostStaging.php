<?php
declare(strict_types=1);
require_once __DIR__.'/WeeklyCostPolicy.php';
/**
 * D5-I safe staging writer: NEVER connects to a production DB.
 * A same-batch unexpected failure rolls back all safe insertions.
 * Source conflicts become review events, not implicit updates.
 */
function weeklyCostStageApply(PDO $pdo,array $news,?callable $testFault=null): array {
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')
        throw new RuntimeException('STAGING_ONLY_MYSQL');
    $env=$pdo->query("SELECT DATABASE() db,@@port port,@@bind_address bind")->fetch(PDO::FETCH_ASSOC);
    if(($env['db']??'')!=='ulgg_d5i_staging'||
       (int)($env['port']??0)!==13305||($env['bind']??'')!=='127.0.0.1')
        throw new RuntimeException('STAGING_IDENTITY_GUARD_FAIL');
    if($pdo->inTransaction())throw new RuntimeException('OUTER_TRANSACTION_NOT_ALLOWED');
    $results=['status'=>'STAGING_APPLIED','articles'=>count($news),'inserted'=>0,
        'unchanged'=>0,'review'=>0,'skipped'=>0,'approved'=>0,'updated'=>0,
        'review_items'=>[],'changes'=>[]];
    $byDate=$pdo->prepare('SELECT * FROM quickmatch_weekly_cost WHERE effective_date=? AND ladder_zone_code=? FOR UPDATE');
    $byGid=$pdo->prepare('SELECT * FROM quickmatch_weekly_cost WHERE source_gid=? AND ladder_zone_code=? FOR UPDATE');
    $insert=$pdo->prepare("INSERT INTO quickmatch_weekly_cost
      (ladder_zone_code,effective_date,cost1,cost2,cost3,cost4,
       source_gid,source_title,source_url,source_published_at,parser_version)
      VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $pdo->beginTransaction();
    try{
        foreach($news as $article){
            $item=weeklyCostClassifyNews($article);
            if($item['status']==='SKIP_NO_COST'){$results['skipped']++;continue;}
            if($item['status']!=='READY_DETECTED'){
                $results['review']++;
                $results['review_items'][]=['gid'=>$item['source_gid'],
                    'candidate_date'=>$item['candidate_date'],
                    'status'=>$item['status'],'reasons'=>$item['reasons']];
                continue;
            }
            foreach($item['profiles'] as $zone=>$c){
                $byDate->execute([$item['candidate_date'],$zone]);
                $rowD=$byDate->fetch(PDO::FETCH_ASSOC)?:null;
                $byGid->execute([$item['source_gid'],$zone]);
                $rowG=$byGid->fetch(PDO::FETCH_ASSOC)?:null;
                $decision=weeklyCostDecide($item,$zone,$rowD,$rowG);
                $action=$decision['action'];
                if($action==='INSERT_DETECTED'){
                    $insert->execute([$zone,$item['candidate_date'],
                        $c['cost1'],$c['cost2'],$c['cost3'],$c['cost4'],
                        $item['source_gid'],$item['source_title'],
                        $item['source_url'],$item['published_local'],ULGG_COST_PARSER_VERSION]);
                    $results['inserted']++;
                    $results['changes'][]=['action'=>'INSERT_DETECTED',
                        'zone'=>$zone,'date'=>$item['candidate_date'],'gid'=>$item['source_gid']];
                    if($testFault!==null)$testFault($results['inserted']);
                }elseif($action==='UNCHANGED'){
                    $results['unchanged']++;
                }else{
                    $results['review']++;
                    $results['review_items'][]=['zone'=>$zone,
                        'gid'=>$item['source_gid'],'candidate_date'=>$item['candidate_date'],
                        'status'=>$action,'reasons'=>[$decision['reason']]];
                }
            }
        }
        $pdo->commit();
        return $results;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
