<?php
declare(strict_types=1);
require __DIR__.'/evolution_daily.php';
$n=0;function ck($v,$s){global $n;if(!$v)throw new RuntimeException($s);echo "PASS $s\n";++$n;}
$root=sys_get_temp_dir().'/qa_history_'.bin2hex(random_bytes(8));
$a=['manifest_sha256'=>str_repeat('a',64),'as_of_utc'=>'2026-11-26T00:00:00Z','day'=>'2026-11-26','metrics'=>['k'=>['qp'=>['value'=>20,'source'=>'estimated','basis'=>'model']]]];
$b=['manifest_sha256'=>str_repeat('b',64),'as_of_utc'=>'2026-11-27T00:00:00Z','day'=>'2026-11-27','metrics'=>['k'=>['qp'=>['value'=>12,'source'=>'low_sample']]]];
$c=['manifest_sha256'=>str_repeat('c',64),'as_of_utc'=>'2026-11-27T01:00:00Z','day'=>'2026-11-27','metrics'=>['k'=>['qp'=>['value'=>10,'source'=>'observed']]]];
try{
$x=qa_daily_store($root,$a);ck($x['daily']['baseline']===null,'first snapshot not yesterday');
$x=qa_daily_store($root,$b);ck($x['daily']['new_observed']['qp']===1,'new observed');
$x=qa_daily_store($root,$c);ck($x['incremental']['new_confirmed']['qp']===1,'low to confirmed');
ck($x['historical_error']['qp']['mae']===10 && $x['historical_error']['qp']['bias']===-10,'first prior estimate retained');
ck(qa_daily_store($root,$c)===$x,'immutable repeat');
try{qa_daily_store($root,$a);ck(false,'older release rejected');}catch(RuntimeException $e){ck($e->getMessage()==='nonmonotonic anchor','older release rejected');}
}finally{foreach(glob($root.'/*.json') as $p)unlink($p);unlink($root.'/.lock');rmdir($root);}
putenv('ULGG_PUBLIC_DATA_DIR='.__DIR__.'/full_candidate');
putenv('QA_TEST_MODEL='.__DIR__.'/model/approved.json');
putenv('QA_TEST_MANIFEST_SHA='.hash_file('sha256',__DIR__.'/full_candidate/manifest.json'));
define('QPUB_VERSION','ulgg.quest_public.v1.4');
define('QPUB_DATA_DIR_OVERRIDE',__DIR__.'/full_candidate');
define('QPUB_WINDOWS',['today','7d','30d','all']);
define('QPUB_DURATIONS',range(0,10));
define('QPUB_DURATION_LABELS',['0m','3m','10m','30m','1h','2h','4h','8h','16h','1d','3d']);
define('QPUB_LOW_SAMPLE',5);
define('QPUB_TEAM_MIN_ELIGIBLE',5);define('QPUB_TEAM_MIN_QPH',5);define('QPUB_TEAM_MIN_VALH',5);
define('QPUB_TEAM_MIN_ASSOC',5);define('QPUB_DURATION_MIN_QP',5);define('QPUB_DURATION_MIN_VALH',5);
require __DIR__.'/contract_reference/inc/helpers.php';
require __DIR__.'/candidate_php/data.php';
require __DIR__.'/candidate_php/g2_route_valh.php';
$model=g2_route_load();ck(count($model)===1042,'candidate data and model load');
$k=array_key_first($model);
$row=qpub_quest_view_row(['quest_key'=>$k],['observed'=>true,'median_sec'=>42,'median_text'=>'0m 42s','qp'=>0,'qa_metrics'=>['qp'=>['value'=>0,'samples'=>5],'time'=>['value'=>42,'samples'=>5]]],'window');
$r=g2_route_rows([$row],$model,true)[0];
ck($r['g2_sort_qp']===0&&$r['g2_metrics']['qp']['source']==='observed','real view preserves zero');
ck(g2_route_cell($r,'time')==='0m 42s','real view preserves time');
ck(!str_contains(g2_route_cell($r,'qp'),'g2-estimate'),'actual not labeled estimate');
echo "TOTAL $n PASS\n";
