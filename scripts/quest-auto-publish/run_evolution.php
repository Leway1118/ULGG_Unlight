<?php
declare(strict_types=1);
require __DIR__.'/evolve.php';
$root=__DIR__;
function read_doc($p){return json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);}
$catalogPath=$root.'/contract_reference/data/quest_catalog.json';
$modelPath=$root.'/model/approved.json';
if(!hash_equals('90593c6e7de3d9fc2e8753f8a22e4a9a68e5d1535fe14c06fa8e09572b09216f',hash_file('sha256',$modelPath)))throw new RuntimeException('model approval');
$doc=read_doc($modelPath);
if(!hash_equals($doc['source_sha256']['quest_catalog.json'],hash_file('sha256',$catalogPath)))throw new RuntimeException('catalog approval');
$catalog=read_doc($catalogPath)['rows'];$keys=array_column($catalog,'quest_key');
$index=[];foreach($catalog as $r)$index[$r['zone_key'].'#'.$r['quest_id']]=$r;
$model=qa_model_index($doc,$keys);
$src=$root.'/staging/20261127T042213Z-2d4daf1a';
$m=read_doc($src.'/manifest.json');
foreach($m['files'] as $f){$p=$src.'/'.$f['name'];if(!hash_equals($f['sha256'],hash_file('sha256',$p))||filesize($p)!==$f['bytes'])throw new RuntimeException('source integrity');}
$rows=[];foreach(read_doc($src.'/all.json')['quests'] as $r){
 $cat=$index[$r['zone_key'].'#'.$r['quest_id']]??null;
 if($cat&&$cat['quest_name']===$r['quest_name'])$rows[$cat['quest_key']]=$r;
}
$baseline=qa_evolve($keys,[],$model);
$current=qa_evolve($keys,$rows,$model);
$history=$root.'/evolution_history';if(!is_dir($history))mkdir($history);
$release=$m['release_id'];$out=['status'=>'HOLD','release'=>$release,'model_sha256'=>hash_file('sha256',$modelPath),'as_of_utc'=>$m['as_of_utc'],'baseline_kind'=>'model_only_counterfactual_not_previous_day','coverage'=>$current['coverage'],'model_to_observed'=>array_map('count',qa_transition($baseline['metrics'],$current['metrics'])),'error'=>qa_error($baseline['metrics'],$current['metrics']),'metrics'=>$current['metrics'],'historical_estimates'=>$baseline['metrics']];
$p=$history.'/'.$release.'.json';$raw=json_encode($out,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
if(is_file($p)){if(file_get_contents($p)!==$raw)throw new RuntimeException('immutable history conflict');}else{file_put_contents($p.'.tmp',$raw,LOCK_EX);rename($p.'.tmp',$p);}
echo json_encode(array_diff_key($out,['metrics'=>1,'historical_estimates'=>1]),JSON_THROW_ON_ERROR),PHP_EOL;
