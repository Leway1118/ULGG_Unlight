<?php
declare(strict_types=1);
require_once __DIR__.'/dynamic_metrics.php';
require_once __DIR__.'/release_trust.php';
function qa_evolve(array $catalog,array $rows,array $model): array {
 $out=[];$policy=['qp'=>5,'qph'=>5,'valh'=>5,'time'=>5];
 foreach($catalog as $key){
  $r=$rows[$key]??[];$e=$model[$key]??[];$valid=isset($model[$key]);
  $qp= max(0,(int)($r['qp_samples']??0)-(int)($r['qp_nominal_samples']??0));
  // Without a per-run provenance split, mixed nominal/actual aggregates cannot be called measured.
  $pure=($r['qp_nominal_samples']??0)===0;
  $out[$key]=[
   'qp'=>qa_metric($pure?($r['qp']??null):null,$pure?$qp:0,5,$e['estimated_qp']??null,$valid),
   'qph'=>qa_metric($pure?($r['qph']??null):null,$pure?$qp:0,5,$e['estimated_qph']??null,$valid&&empty($e['time_unverified'])&&empty($e['reward_unpriced'])),
   // VAL is a valuation estimate even when based on observed runs.
   'valh'=>qa_metric(null,0,5,$e['estimated_valh']??null,$valid&&empty($e['reward_unpriced'])),
   'time'=>qa_metric($r['median_sec']??null,(int)($r['completed_count']??0),5,$e['estimated_sec']??null,$valid&&empty($e['time_unverified'])&&empty($e['reward_unpriced']))
  ];
  if(isset($r['valh'])&&($r['value_samples']??0)>0) $out[$key]['valh']=['value'=>$r['valh'],'source'=>'estimated','basis'=>'observed_run_valuation','samples'=>$r['value_samples']];
 }
 return ['metrics'=>$out,'coverage'=>qa_coverage($catalog,$out,$policy)];
}
function qa_transition(array $prior,array $current): array {
 $new=[];foreach($current as $key=>$metrics)foreach($metrics as $metric=>$v)
 if(in_array($v['source'],['observed','low_sample'],true)&&!in_array($prior[$key][$metric]['source']??'', ['observed','low_sample'],true))$new[$metric][]=$key;
 return $new;
}
function qa_cell(array $metric): string {
 if($metric['value']===null)return '&mdash;';
 $label=['observed'=>'Observed','low_sample'=>'Low sample','estimated'=>'Estimated','unavailable'=>'Unavailable'][$metric['source']];
 return htmlspecialchars(number_format((float)$metric['value'],2).' ('.$label.')',ENT_QUOTES,'UTF-8');
}
