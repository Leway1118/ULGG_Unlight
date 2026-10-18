<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
 * ADMIN_RAID_FOUNDER_STATS_V1
 *
 * 跟 UL.GG 現有管理員權限一致：
 * permission / ack >= 2。
 */
$isAdmin =
    (int)($_SESSION['permission'] ?? 0) >= 2
    ||
    (int)($_SESSION['ack'] ?? 0) >= 2
    ||
    (int)($_SESSION['check_ack'] ?? 0) >= 2;

if (!$isAdmin) {
    out([
        'ok' => false,
        'error' => 'admin_required',
    ], 403);
}

$founder = trim(
    (string)($_GET['founder'] ?? '')
);

$days = trim(
    (string)($_GET['days'] ?? 'all')
);

if ($founder === '') {
    out([
        'ok' => false,
        'error' => 'founder_required',
    ], 400);
}

if (
    function_exists('mb_strlen')
    && mb_strlen($founder, 'UTF-8') > 80
) {
    out([
        'ok' => false,
        'error' => 'founder_too_long',
    ], 400);
}

if (!in_array(
    $days,
    ['all', '30', '7'],
    true
)) {
    $days = 'all';
}

$url =
    'http://127.0.0.1:8766'
    . '/api/raid-player-stats'
    . '?days='
    . rawurlencode($days);

/*
 * Bot API 會 unquote() X-ULGG-Player，
 * 所以這裡 URL encode 玩家名稱。
 */
$headers = [
    'Accept: application/json',
    'X-ULGG-Player: ' . rawurlencode($founder),
];

$body = null;
$status = 0;

if (function_exists('curl_init')) {

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $result = curl_exec($ch);

    if ($result !== false) {
        $body = (string)$result;
    }

    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

} else {

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => implode(
                "\r\n",
                $headers
            ),
        ],
    ]);

    $result = @file_get_contents(
        $url,
        false,
        $context
    );

    if ($result !== false) {
        $body = (string)$result;
    }

    $status = 200;
}

if ($body === null) {
    out([
        'ok' => false,
        'error' => 'raid_service_unavailable',
    ], 502);
}

$data = json_decode(
    $body,
    true
);

if (!is_array($data)) {
    out([
        'ok' => false,
        'error' => 'invalid_raid_service_response',
    ], 502);
}

http_response_code(
    $status >= 400
    ? $status
    : 200
);

echo json_encode(
    $data,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
);
