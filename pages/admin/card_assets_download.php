<?php
declare(strict_types=1);
require_once __DIR__.'/../../config.php';
if((int)($_SESSION['permission']??0)<2){http_response_code(403);exit;}
require_once __DIR__.'/../../includes/card_assets_status.php';
$s=ulAssetsRead('status.json');$g=$_GET['generation']??'';$n=$_GET['file']??'';
if(!$s||!ulAssetsGeneration($g)||!in_array($g,[$s['generation']??null,$s['previous_generation']??null],true)||!in_array($n,['CharaCards.json','Feats.json','changes.json','weekly_changes.json','db_changes.json'],true)){http_response_code(404);exit;}
$p='/var/lib/ulgg-card-assets/versions/'.$g.'/'.$n;
if($n==='weekly_changes.json'){$w=$s['week']??'';if($g!==($s['generation']??null)||!is_string($w)||!preg_match('/\A[0-9]{4}-W[0-9]{2}\z/D',$w)){http_response_code(404);exit;}$p='/var/lib/ulgg-card-assets/weeks/'.$w.'/'.$g.'.json';}
if(!is_file($p)||!is_readable($p)||realpath($p)!==$p||filesize($p)>10485760){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Disposition: attachment; filename="'.$n.'"');readfile($p);
