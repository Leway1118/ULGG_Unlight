<?php
declare(strict_types=1);
/**
 * Access-log-only anonymous event marker. No database, cookies, or app log.
 * Existing server access logs may still contain IP/UA as they normally do.
 * Never expose those raw logs in an analytics dashboard.
 */
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$event = $_GET['e'] ?? '';
$source = $_GET['src'] ?? '';
if (!is_string($event) || !is_string($source) || $source !== 'fight' ||
    !in_array($event, ['impression', 'click_tw', 'click_bmc'], true)) {
    http_response_code(400);
    exit;
}
$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    http_response_code(403);
    exit;
}
// No user data persisted by this PHP script. Counts come from existing access logs.
http_response_code(204);
exit;
