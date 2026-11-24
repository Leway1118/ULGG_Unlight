<?php
declare(strict_types=1);
require __DIR__.'/../../../lib/checkpoint2026/PreviewAccess.php';
use ULGG\Checkpoint2026\PreviewAccess as A;
$valid=['permission'=>2,'ack'=>2,'user_id'=>12,'steam_id'=>'76561190000000000'];
$cases=[
 ['int_admin',$valid,true],
 ['string_admin',array_replace($valid,['permission'=>'2','ack'=>'2','user_id'=>'12','steam_id'=>76561190000000000]),true],
 ['mixed_scalar',array_replace($valid,['permission'=>'2']),true],
 ['superadmin',array_replace($valid,['permission'=>3,'ack'=>3]),true],
 ['anonymous',[],false],
 ['member',array_replace($valid,['permission'=>'1','ack'=>1]),false],
 ['mismatch',array_replace($valid,['ack'=>1]),false],
 ['malformed',array_replace($valid,['permission'=>'2evil','ack'=>'2evil']),false],
 ['float',array_replace($valid,['permission'=>2.0]),false],
 ['bool',array_replace($valid,['permission'=>true]),false],
 ['leading_zero',array_replace($valid,['permission'=>'02']),false],
 ['overflow',array_replace($valid,['user_id'=>'999999999999999999999999999999']),false],
 ['bad_user',array_replace($valid,['user_id'=>0]),false],
 ['bad_steam',array_replace($valid,['steam_id'=>'123']),false],
 ['float_steam',array_replace($valid,['steam_id'=>7.656119e16]),false],
 ['array_role',array_replace($valid,['permission'=>[2]]),false],
];
foreach($cases as [$name,$session,$expected]){
 if(A::authorized($session)!==$expected)throw new RuntimeException($name);
 echo "FT03BR2_POLICY_PASS $name\n";
}
foreach([['GET','',true],['GET','utm_source=chatgpt.com',true],['GET','user=12',false],['GET','utm_source=chatgpt.com&user=12',false],['GET','utm_source=chatgpt.com&utm_source=chatgpt.com',false],['GET','utm_source%3Dchatgpt.com',false],['POST','',false],['HEAD','',false]] as [$method,$query,$expected]){
 if(A::requestAllowed($method,$query)!==$expected)throw new RuntimeException('request gate');
 echo "FT03BR2_REQUEST_PASS $method $query\n";
}
