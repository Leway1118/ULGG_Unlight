<?php
declare(strict_types=1);
require_once __DIR__.'/dynamic_metrics.php';
function qa_daily_compare(?array $prior,array $now): array {
 if ($prior===null) return ['baseline'=>null,'new_observed'=>null,'new_confirmed'=>null,'error'=>null];
 $new=[];$confirmed=[];$errors=[];
 foreach($now['metrics'] as $k=>$metrics)foreach($metrics as $name=>$v){
    $p=$prior['metrics'][$k][$name]??[];
    $was=in_array($p['source']??'', ['observed','low_sample'],true);
    $is=in_array($v['source'],['observed','low_sample'],true);
    if(!$was&&$is)$new[$name][]=$k;
    if(($p['source']??null)!=='observed'&&$v['source']==='observed')$confirmed[$name][]=$k;
    if(($p['source']??null)==='estimated'&&$v['source']==='observed'&&is_numeric($p['value']??null))
        $errors[$name][]=$v['value']-$p['value'];
 }
 $mae=[];foreach($errors as $m=>$e)$mae[$m]=['n'=>count($e),'mae'=>array_sum(array_map('abs',$e))/count($e),'bias'=>array_sum($e)/count($e)];
 return ['baseline'=>$prior['manifest_sha256'],'new_observed'=>array_map('count',$new),'new_confirmed'=>array_map('count',$confirmed),'error'=>$mae];
}
function qa_daily_store(string $dir,array $now): array {
 if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('history mkdir');
 $lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('history lock');
 try {
   $p=$dir.'/'.$now['manifest_sha256'].'.json';
   $prior=null;$day=null;$latest=null;
   foreach(glob($dir.'/*.json') as $f){
      $x=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR);
      if(basename($f)!==$x['manifest_sha256'].'.json')throw new RuntimeException('history identity');
      if($f===$p)continue;
      if($x['as_of_utc']>$now['as_of_utc'])throw new RuntimeException('nonmonotonic anchor');
      if($x['as_of_utc']===$now['as_of_utc'])continue;
      if($latest===null||$x['as_of_utc']>$latest['as_of_utc'])$latest=$x;
      if($x['day']<$now['day']&&($day===null||$x['as_of_utc']>$day['as_of_utc']))$day=$x;
   }
   $now['incremental']=qa_daily_compare($latest,$now);
   $now['daily']=qa_daily_compare($day,$now);
   // Preserve the first genuine prior model estimate, even through a low-sample phase.
   $ledger=$latest['first_estimates']??[];
   foreach($now['metrics'] as $k=>$mm)foreach($mm as $n=>$v)
      if($v['source']==='estimated'&&($v['basis']??'model')==='model'&&!isset($ledger[$k][$n]))
         $ledger[$k][$n]=['value'=>$v['value'],'as_of_utc'=>$now['as_of_utc']];
   $errors=[];
   foreach($ledger as $k=>$mm)foreach($mm as $n=>$v){
      $c=$now['metrics'][$k][$n]??[];
      if(($c['source']??null)==='observed'&&$v['as_of_utc']<$now['as_of_utc'])$errors[$n][]=$c['value']-$v['value'];
   }
   $now['historical_error']=[];foreach($errors as $n=>$e)$now['historical_error'][$n]=['n'=>count($e),'mae'=>array_sum(array_map('abs',$e))/count($e),'bias'=>array_sum($e)/count($e)];
   $now['first_estimates']=$ledger;
   $raw=json_encode($now,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
   if(is_file($p)){if(file_get_contents($p)!==$raw)throw new RuntimeException('immutable history conflict');}
   else{if(file_put_contents($p.'.tmp',$raw)===false||!rename($p.'.tmp',$p))throw new RuntimeException('history write');}
   return $now;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
 $root=__DIR__;putenv('ULGG_PUBLIC_DATA_DIR='.$root.'/full_candidate');
 putenv('QA_TEST_MODEL='.$root.'/model/approved.json');
 $hash=hash_file('sha256',$root.'/full_candidate/manifest.json');
 putenv('QA_TEST_MANIFEST_SHA='.$hash);
 require_once $root.'/contract_reference/inc/bootstrap.php';
 require_once $root.'/candidate_php/g2_route_valh.php';
 $model=g2_route_load();if(!$model)throw new RuntimeException('model gate');
 $cat=qpub_catalog_rows();$raw=qpub_window_rows('all','quests');$by=array_column($raw,null,'quest_key');
 $metrics=[];$policy=['qp'=>5,'qph'=>5,'valh'=>5,'time'=>5];$keys=[];
 foreach($cat as $c){$k=$c['quest_key'];$keys[]=$k;$r=$by[$k]??[];$a=$r['qa_metrics']??[];$e=$model[$k]??[];
    foreach(['qp'=>'estimated_qp','qph'=>'estimated_qph','valh'=>'estimated_valh','time'=>'estimated_sec'] as $n=>$f){
       $v=$a[$n]??[];$allowed=(bool)$e;
       if(in_array($n,['qph','time','valh'],true)&&!empty($e['reward_unpriced']))$allowed=false;
       if(in_array($n,['qph','time'],true)&&!empty($e['time_unverified']))$allowed=false;
       $m=qa_metric($v['value']??null,(int)($v['samples']??0),5,$e[$f]??null,$allowed);
       if($n==='valh'&&($v['samples']??0)>0&&is_numeric($v['value']??null))$m=['value'=>$v['value'],'source'=>'estimated','basis'=>'observed_run_valuation','samples'=>$v['samples']];
       elseif($m['source']==='estimated')$m['basis']='model';
       $metrics[$k][$n]=$m;
    }
 }
 $anchor=qpub_read_json('manifest.json')['as_of_utc'];
 $now=['status'=>'LOCAL_CANDIDATE','manifest_sha256'=>$hash,'as_of_utc'=>$anchor,'day'=>(new DateTimeImmutable($anchor))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d'),'metrics'=>$metrics,'coverage'=>qa_coverage($keys,$metrics,$policy)];
 $out=qa_daily_store($root.'/daily_history',$now);
 unset($out['metrics'],$out['first_estimates']);
 file_put_contents($root.'/G10_DAILY_REPORT.json',json_encode($out,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
 echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;
}
