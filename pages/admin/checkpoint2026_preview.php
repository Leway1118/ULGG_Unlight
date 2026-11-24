<?php
declare(strict_types=1);
/* Candidate only. Deploy ONLY this wrapper and hash-pinned HTML outside webroot.
 * Existing session auth, no DB query or live Steam call. Default OFF. */
function checkpointPreviewAllowed(array $session, string $flag): bool {
    return $flag==='1'
        && isset($session['permission'],$session['ack'],$session['user_id'],$session['steam_id'])
        && is_int($session['permission']) && $session['permission']>=2
        && is_int($session['ack']) && $session['ack']===$session['permission']
        && is_int($session['user_id']) && $session['user_id']>0
        && is_string($session['steam_id']) && preg_match('/^[0-9]{17}$/D',$session['steam_id'])===1;
}
if(PHP_SAPI==='cli')return;
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; connect-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
function checkpointPreviewNotFound():void{http_response_code(404);exit;}
if(getenv('ULGG_CHECKPOINT_PREVIEW_ENABLED')!=='1'||($_SERVER['REQUEST_METHOD']??'')!=='GET'||($_SERVER['QUERY_STRING']??'')!=='')checkpointPreviewNotFound();
if(session_status()===PHP_SESSION_NONE)session_start();
if(!checkpointPreviewAllowed($_SESSION,'1'))checkpointPreviewNotFound();
session_write_close();
$path='/var/lib/ulgg/checkpoint2026-preview/preview.html';
$expected=getenv('ULGG_CHECKPOINT_PREVIEW_SHA256');
if(!is_string($expected)||preg_match('/^[a-f0-9]{64}$/D',$expected)!==1||!is_file($path)||is_link($path))checkpointPreviewNotFound();
$body=file_get_contents($path);
if(!is_string($body)||!hash_equals($expected,hash('sha256',$body)))checkpointPreviewNotFound();
header('Content-Type: text/html; charset=utf-8');
echo $body;
