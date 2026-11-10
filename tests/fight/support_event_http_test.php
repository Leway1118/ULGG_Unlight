<?php
declare(strict_types=1);

/**
 * Hermetic HTTP-decision regression for the original VPS support marker.
 * Child PHP processes simulate method, query and Fetch Metadata; no network or DB.
 * Does NOT exercise web-server access logs, TLS, cookies or rate-limits.
 */
$endpoint = dirname(__DIR__, 2) . '/api/support_event.php';
if (!is_file($endpoint)) {
    fwrite(STDERR, "[FAIL] support event endpoint missing\n");
    exit(1);
}
$cases = [
    ['valid impression, same-origin', 'POST', ['e' => 'impression', 'src' => 'fight'], 'same-origin', 204],
    ['valid TW click, same-origin', 'POST', ['e' => 'click_tw', 'src' => 'fight'], 'same-origin', 204],
    ['valid BMC click, same-origin', 'POST', ['e' => 'click_bmc', 'src' => 'fight'], 'same-origin', 204],
    ['missing fetch metadata (current legacy behavior)', 'POST', ['e' => 'impression', 'src' => 'fight'], null, 204],
    ['disallowed GET', 'GET', ['e' => 'impression', 'src' => 'fight'], null, 405],
    ['disallowed HEAD', 'HEAD', ['e' => 'impression', 'src' => 'fight'], null, 405],
    ['invalid event', 'POST', ['e' => 'erase', 'src' => 'fight'], 'same-origin', 400],
    ['invalid source', 'POST', ['e' => 'click_tw', 'src' => 'home'], 'same-origin', 400],
    ['missing query', 'POST', [], 'same-origin', 400],
    ['array event rejected', 'POST', ['e' => ['impression'], 'src' => 'fight'], 'same-origin', 400],
    ['array source rejected', 'POST', ['e' => 'impression', 'src' => ['fight']], 'same-origin', 400],
    ['cross-site blocked', 'POST', ['e' => 'impression', 'src' => 'fight'], 'cross-site', 403],
    ['same-site blocked', 'POST', ['e' => 'impression', 'src' => 'fight'], 'same-site', 403],
];
foreach ($cases as [$label, $method, $query, $fetchSite, $expected]) {
    $server = ['REQUEST_METHOD' => $method];
    if ($fetchSite !== null) {
        $server['HTTP_SEC_FETCH_SITE'] = $fetchSite;
    }
    $source = '$_SERVER = ' . var_export($server, true) . ';'
        . '$_GET = ' . var_export($query, true) . ';'
        . 'register_shutdown_function(static function(): void { echo "HTTP_STATUS=" . http_response_code() . "\\n"; });'
        . 'require ' . var_export($endpoint, true) . ';';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $source], $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "[FAIL] child PHP unavailable for {$label}\n");
        exit(1);
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0 || trim($out) !== 'HTTP_STATUS=' . $expected || trim($err) !== '') {
        fwrite(STDERR, "[FAIL] {$label}: expected {$expected}, got exit={$exit}, stdout=" . var_export($out, true) . ", stderr=" . var_export($err, true) . "\n");
        exit(1);
    }
    echo "[PASS] {$label} => {$expected}\n";
}
echo "[PASS] original support_event.php HTTP decision matrix (13 cases)\n";
echo "[LIMIT] CLI-only; no HTTP server, logging, throttling or E2E test\n";
