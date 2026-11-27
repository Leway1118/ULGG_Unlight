<?php
declare(strict_types=1);
require __DIR__.'/dynamic_metrics.php';
require __DIR__.'/release_trust.php';
$n=0;
function check($condition,$name){global $n;if(!$condition)throw new RuntimeException($name);++$n;echo "PASS $name\n";}
function rejects(callable $f,$name){try{$f();}catch(Throwable $e){check(true,$name);return;}check(false,$name);}
check(qa_metric(0,5,5,20,true)['value']===0,'observed zero');
check(qa_metric(12,1,5,20,true)['source']==='low_sample','low sample');
check(qa_metric(null,0,5,20,true)['source']==='estimated','estimated');
check(qa_metric(null,0,5,20,false)['source']==='unavailable','untrusted model');
check(qa_metric(null,0,5,null,true)['source']==='unavailable','missing');
check(qa_metric(12,5,5,20,true)['source']==='observed','transition');
check(qa_metric(NAN,5,5,20,true)['source']==='estimated','invalid actual');
$old=['a'=>['qp'=>qa_metric(null,0,5,20,true)]];
$new=['a'=>['qp'=>qa_metric(12,5,5,20,true)]];
check(qa_error($old,$new)['qp']['mae']===8,'MAE');
check(qa_error($old,$new)['qp']['bias']===-8,'bias');
check(qa_coverage(['a','b'],$new,['qp'=>5])['qp']['unavailable']===1,'dynamic coverage');
$e=['quest_key'=>'a','estimated_qp'=>20,'estimated_qph'=>1200,'estimated_valh'=>600,'known_value'=>10,'estimated_sec'=>60];
$m=['schema'=>'ULGG_G2_FULL_CATALOG_SIMULATION_V3','policy'=>'model_simulation_only_missing_no_observed_override','estimates'=>[$e]];
check(count(qa_model_index($m,['a','b']))===1,'subset model');
check(count(qa_model_index($m,['a','b','c']))===1,'catalog growth');
rejects(fn()=>qa_model_index($m,['b']),'unknown identity');
$bad=$m;$bad['estimates'][]=$e;rejects(fn()=>qa_model_index($bad,['a']),'duplicate model');
$bad=$m;$bad['estimates'][0]['estimated_qph']=0;rejects(fn()=>qa_model_index($bad,['a']),'arithmetic mismatch');
$root=sys_get_temp_dir().'/qa_test_'.bin2hex(random_bytes(8));mkdir($root);
try{
 $doc=['as_of_utc'=>'2026-11-27T00:00:00Z','rows'=>[]];file_put_contents($root.'/test.json',json_encode($doc));
 $manifest=['contract_version'=>'ulgg.quest_public.v1.4','as_of_utc'=>$doc['as_of_utc'],'files'=>[['path'=>'test.json','sha256'=>hash_file('sha256',$root.'/test.json'),'bytes'=>filesize($root.'/test.json')]]];
 file_put_contents($root.'/manifest.json',json_encode($manifest));$trusted=hash_file('sha256',$root.'/manifest.json');
 check(qa_verified_release($root,$trusted,['test.json'])['as_of_utc']===$doc['as_of_utc'],'trusted manifest');
 rejects(fn()=>qa_verified_release($root,str_repeat('0',64),['test.json']),'untrusted manifest');
 file_put_contents($root.'/test.json','{}');clearstatcache();
 rejects(fn()=>qa_verified_release($root,$trusted,['test.json']),'tampered file');
}finally{unlink($root.'/test.json');unlink($root.'/manifest.json');rmdir($root);}
echo "TOTAL $n PASS\n";
