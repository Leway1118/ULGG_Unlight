<?php
require __DIR__.'/../../../pages/admin/checkpoint2026_preview.php';
$valid=['permission'=>2,'ack'=>2,'user_id'=>12,'steam_id'=>'76561190000000000'];
$cases=[
['disabled',$valid,'0',false],['enabled',$valid,'1',true],
['anon',[],'1',false],['nonadmin',array_replace($valid,['permission'=>1,'ack'=>1]),'1',false],
['string_permission',array_replace($valid,['permission'=>'2']),'1',false],
['ack_mismatch',array_replace($valid,['ack'=>1]),'1',false],
['bad_user',array_replace($valid,['user_id'=>0]),'1',false],
['bad_steam',array_replace($valid,['steam_id'=>'123']),'1',false],
['flag_other',$valid,'true',false]];
foreach($cases as [$name,$session,$flag,$want]){
 if(checkpointPreviewAllowed($session,$flag)!==$want)throw new RuntimeException($name);
 echo "FT03A_GATE_PASS $name\n";
}
