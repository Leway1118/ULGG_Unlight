<?php
declare(strict_types=1);
$root=__DIR__;
$cat=json_decode(file_get_contents($root.'/contract_reference/data/quest_catalog.json'),true);
$i=[];foreach($cat['rows'] as $r){unset($r['observed_all_period']);ksort($r);$i[$r['quest_key']]=$r;}ksort($i);
$pin=hash('sha256',json_encode($i,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
$p=$root.'/candidate_php/g2_route_valh.php';
$s=file_get_contents($p);if(str_contains($s,'CATALOG_IDENTITY_PIN'))file_put_contents($p,str_replace('CATALOG_IDENTITY_PIN',$pin,$s));
putenv('ULGG_PUBLIC_DATA_DIR='.$root.'/full_candidate');
putenv('QA_TEST_MODEL='.$root.'/model/approved.json');
putenv('QA_TEST_MANIFEST_SHA='.hash_file('sha256',$root.'/full_candidate/manifest.json'));
require_once $root.'/contract_reference/inc/bootstrap.php';
require_once $root.'/candidate_php/g2_route_valh.php';
$n=0;function ok($v,$name){global $n;if(!$v)throw new RuntimeException($name);++$n;echo "PASS $name\n";}
ok(qpub_contract_warnings()===[],'full PHP contract');
$model=g2_route_load();ok(count($model)===1042,'approved model dynamic release');
$k=array_key_first($model);
$r=g2_route_rows([['quest_key'=>$k,'qp'=>0,'qph'=>null,'valh'=>null,'observed'=>true]],$model,true)[0];
ok($r['g2_sort_qp']===0,'observed zero preserved');
ok(!str_contains(g2_route_cell($r,'qp'),'g2-estimate'),'observed cell not estimated');
ok(isset($r['g2_estimate']),'per metric model available');
$r2=g2_route_rows([['quest_key'=>$k,'qp'=>12,'qph'=>300,'valh'=>200,'observed'=>true]],$model,true)[0];
ok($r2['g2_sort_qph']===300 && $r2['g2_sort_valh']===200,'observed metrics win');
putenv('QA_TEST_MANIFEST_SHA='.str_repeat('0',64));
ok(g2_route_load()===[],'untrusted manifest rejected');
echo "TOTAL $n PASS\n";
