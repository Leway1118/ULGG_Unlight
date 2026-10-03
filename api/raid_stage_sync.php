<?php
declare(strict_types=1);

// RAID_STAGE_SYNC_PROXY_V1
// Public HTTPS bridge to the localhost-only Raid Bot API.
// This file stores no secret. It forwards the client's signed request.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));

if ($method === 'GET') {
    $action = 'pending';
    $target = 'http://127.0.0.1:8766/api/stage-sync/pending';
    $body = '';
} elseif ($method === 'POST') {
    $action = 'report';
    $target = 'http://127.0.0.1:8766/api/stage-sync/report';
    $body = (string)file_get_contents('php://input');
} else {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode([
        'ok' => false,
        'error' => 'method_not_allowed',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$timestamp = trim((string)($_SERVER['HTTP_X_STAGE_TIMESTAMP'] ?? ''));
$signature = trim((string)($_SERVER['HTTP_X_STAGE_SIGNATURE'] ?? ''));
$clientAction = strtolower(trim((string)($_SERVER['HTTP_X_STAGE_ACTION'] ?? '')));

if ($timestamp === '' || $signature === '' || $clientAction !== $action) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'stage_signature_headers_required',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$headers = [
    'Accept: application/json',
    'X-Stage-Timestamp: ' . $timestamp,
    'X-Stage-Signature: ' . $signature,
    'X-Stage-Action: ' . $clientAction,
];

$options = [
    'http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers) . "\r\n",
        'timeout' => 8,
        'ignore_errors' => true,
    ],
];

if ($method === 'POST') {
    $headers[] = 'Content-Type: application/json';
    $options['http']['header'] = implode("\r\n", $headers) . "\r\n";
    $options['http']['content'] = $body;
}

$context = stream_context_create($options);
$response = @file_get_contents($target, false, $context);
$status = 502;

if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $match)) {
            $status = (int)$match[1];
            break;
        }
    }
}

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'raid_bot_unreachable',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($status < 100 || $status > 599) {
    $status = 502;
}

http_response_code($status);
echo $response;
