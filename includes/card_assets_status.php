<?php
declare(strict_types=1);
function ulAssetsRead(string $name): ?array {
    if(!in_array($name,['status.json','attempt.json','db-audit.json','db-current.json'],true))return null;
    $p='/var/lib/ulgg-card-assets/'.$name;
    if(!is_readable($p)||!is_file($p)||filesize($p)>1048576)return null;
    $x=json_decode((string)file_get_contents($p),true);return is_array($x)?$x:null;
}
function ulAssetsGeneration($g):bool{return is_string($g)&&preg_match('/\A[a-f0-9]{64}\z/D',$g)===1;}
function ulAssetsE($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function ulAssetsWarning():?string{
    $s=ulAssetsRead('status.json');$a=ulAssetsRead('attempt.json');$d=ulAssetsRead('db-audit.json');
    if(($a['status']??'')==='FAIL')return '官方素材更新或資料庫同步失敗，請查閱更新紀錄。';
    if(!$s)return '尚未建立官方素材快照。';
    if(strtotime((string)($s['checked_at']??''))<time()-8*86400)return '官方素材超過 8 天未更新。';
    if(($d['status']??'')!=='PASS'||($d['generation']??null)!==($s['generation']??null))return '官方素材與正式資料庫尚未同步。';
    return null;
}
