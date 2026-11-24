<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
use Ulgg\Checkpoint2026\{IdentityScopeResolver,ImmutableSnapshotLedger,ReadOnlyStagingPdo,AnnualSourceBundleGate};
use Ulgg\Checkpoint2026\Tests\FixtureData;
function outputJson(array $data,int $status=200):void{
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
}
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
try{guard();}catch(Throwable $e){outputJson(['error'=>'staging_only'],503);}
if(PHP_SAPI!=='cli-server'||($_SERVER['HTTP_HOST']??'')!=='m3c-app:8080'||
   !preg_match('/^172\.29\.77\.[0-9]{1,3}$/D',$_SERVER['REMOTE_ADDR']??''))
    outputJson(['error'=>'staging_only'],503);
$uri=$_SERVER['REQUEST_URI']??'';
$path=parse_url($uri,PHP_URL_PATH);
if(str_contains($uri,'?'))outputJson(['error'=>'account_selector_forbidden'],403);
$method=$_SERVER['REQUEST_METHOD']??'';
if($path==='/api/checkpoint2026/_fixture_login.php'){
    if($method!=='POST')outputJson(['error'=>'method_not_allowed'],405);
    if(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!=='http://m3c-app:8080')outputJson(['error'=>'origin_forbidden'],403);
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input)||array_keys($input)!==['who']||!in_array($input['who'],['A','B','logout'],true))
        outputJson(['error'=>'invalid_fixture'],400);
    session_name('ULGG_M3C_SYNTH');session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict']);
    session_regenerate_id(true);
    $_SESSION=$input['who']==='logout'?[]:($input['who']==='A'?FixtureData::sessionA():FixtureData::sessionB());
    outputJson(['ok'=>true,'fixture'=>$input['who']]);
}
if($method!=='GET')outputJson(['error'=>'method_not_allowed'],405);
if(in_array($path,['/api/checkpoint2026/me.php','/api/checkpoint2026/public.php'],true)){
    $public=$path==='/api/checkpoint2026/public.php';
    try{
        $scope=AnnualSourceBundleGate::CUP_SCOPE;
        if(!$public){
            session_name('ULGG_M3C_SYNTH');session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict']);
            if(empty($_SESSION['steam_id'])||empty($_SESSION['user_id']))outputJson(['error'=>'authentication_required'],401);
            $resolved=IdentityScopeResolver::fromSession(reader(),$_SESSION,keyFor('scope'));
            if($resolved['status']!=='VERIFIED')outputJson(['error'=>'identity_unverified'],403);
            $scope=$resolved['opaque_scope'];
        }
        $active=trim((string)file_get_contents('/stage/active'));
        if(in_array($active,['UNKNOWN:missing','UNKNOWN:forged','UNKNOWN:history-conflict'],true))
            outputJson(unknownResponse($public));
        if(!in_array($active,['1','2'],true))throw new RuntimeException('BAD_ACTIVE_REVISION');
        $l=new ImmutableSnapshotLedger(ReadOnlyStagingPdo::open('/stage/ledger.sqlite','/stage'),keyFor('ledger'));
        $record=$l->verifyAndRead($scope,(int)$active);$snap=$record['snapshot'];
        if($snap['access']!==($public?'PUBLIC':'PRIVATE'))throw new RuntimeException('ACCESS_MISMATCH');
        // Only DTO, never internal actor bindings or raw evidence.
        unset($snap['r3_reconciliation'],$snap['source_provenance_bundle_sha256']);
        outputJson($snap);
    }catch(Throwable $e){outputJson(unknownResponse($public),503);}
}
$root=__DIR__.'/../../../pages/checkpoint2026/';
if($path==='/pages/checkpoint2026/index.php'){
    header('Content-Type: text/html; charset=utf-8');
    $source=file_get_contents($root.'index.php');
    echo substr($source,strpos($source,'<!doctype html>'));exit;
}
if($path==='/pages/checkpoint2026/story.css'){
    header('Content-Type: text/css; charset=utf-8');readfile($root.'story.css');exit;
}
if($path==='/pages/checkpoint2026/story.js'){
    header('Content-Type: application/javascript; charset=utf-8');
    $js=file_get_contents($root.'story.js');
    $from='state.textContent = "私人 PvP 已通過合成資料驗證；個人 CUP／Raid 仍為 UNKNOWN。";';
    $to='state.textContent = pvp.status === "UNKNOWN" ? "資料來源尚未驗證；個人 PvP／CUP／Raid 為 UNKNOWN。" : "私人 PvP 已通過合成資料驗證；個人 CUP／Raid 仍為 UNKNOWN。";';
    if(substr_count($js,$from)!==1)outputJson(['error'=>'ui_contract_changed'],503);
    echo str_replace($from,$to,$js);exit;
}
outputJson(['error'=>'not_found'],404);
