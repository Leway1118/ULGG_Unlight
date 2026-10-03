<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$founder = trim((string)($_SESSION['username'] ?? ''));

if ($founder === '') {
    http_response_code(401);

    echo json_encode([
        'ok' => false,
        'error' => 'authentication_required',
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

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
        'X-ULGG-Player: ' . rawurlencode($founder),
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
