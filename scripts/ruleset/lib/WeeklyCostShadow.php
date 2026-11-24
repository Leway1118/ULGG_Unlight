<?php
declare(strict_types=1);
/** D5-J: Side-effect-free no-argument cron-compatible shadow audit. */
require_once __DIR__.'/WeeklyCostPolicy.php';
require_once __DIR__.'/WeeklyCostSource.php';
function weeklyCostShadowReport(array $news):array {
    $result=['status'=>'SHADOW_READONLY','articles'=>count($news),
      'ready_detected'=>0,'review'=>0,'skipped'=>0,'would_write'=>0,
      'would_approve'=>0,'review_reasons'=>[],'candidate_zones'=>[]];
    foreach($news as $raw){
        if(!is_array($raw))throw new InvalidArgumentException('NEWS_ITEM_NOT_OBJECT');
        $c=weeklyCostClassifyNews($raw);
        switch($c['status']){
          case 'READY_DETECTED':
            $result['ready_detected']++;
            foreach(array_keys($c['profiles']) as $zone)
                $result['candidate_zones'][$zone]=($result['candidate_zones'][$zone]??0)+1;
            break;
          case 'SKIP_NO_COST':$result['skipped']++;break;
          default:
            $result['review']++;
            foreach($c['reasons'] as $why)
                $result['review_reasons'][$why]=($result['review_reasons'][$why]??0)+1;
        }
    }
    ksort($result['candidate_zones']);ksort($result['review_reasons']);
    return $result;
}
