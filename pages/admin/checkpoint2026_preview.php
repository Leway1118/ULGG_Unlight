<?php
declare(strict_types=1);

use ULGG\Checkpoint2026\PreviewAccess;

function cpHeaders(): void
{
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; connect-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
}
function cpDeny(): void { cpHeaders(); http_response_code(404); exit; }

cpHeaders();
$access = __DIR__ . '/../../lib/checkpoint2026/PreviewAccess.php';
if (!is_file($access)) cpDeny();
require_once $access;
if (!PreviewAccess::requestAllowed((string)($_SERVER['REQUEST_METHOD'] ?? ''), (string)($_SERVER['QUERY_STRING'] ?? ''))) cpDeny();
if (!PreviewAccess::enabled()) cpDeny();
if (session_status() === PHP_SESSION_NONE) {
    if (!isset($_COOKIE[session_name()]) || !is_string($_COOKIE[session_name()]) || $_COOKIE[session_name()] === '') cpDeny();
    session_cache_limiter('');
    if (!@session_start(['read_and_close'=>true,'use_strict_mode'=>1,'use_only_cookies'=>1])) cpDeny();
}
if (!PreviewAccess::authorized($_SESSION ?? [])) cpDeny();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$html = PreviewAccess::verifiedHtml();
if ($html === null) cpDeny();
cpHeaders();
header('Content-Type: text/html; charset=utf-8');
echo $html;
