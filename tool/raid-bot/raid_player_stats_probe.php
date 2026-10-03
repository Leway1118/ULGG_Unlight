<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$days = isset($_GET['days'])
    ? (string)$_GET['days']
    : 'all';

if (!in_array($days, ['all', '30', '7'], true)) {
    $days = 'all';
}

$url =
    'http://127.0.0.1:8766/api/raid-player-stats'
    . '?days='
    . rawurlencode($days);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
    ],
]);

$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);

curl_close($ch);

if ($body === false || $status < 200 || $status >= 300) {
    http_response_code(502);

    echo json_encode([
        'ok' => false,
        'error' => 'raid_stats_backend_unavailable',
        'detail' => $error,
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

echo $body;
