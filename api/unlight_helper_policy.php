<?php
declare(strict_types=1);

$policy = [
    'schema' => 1,
    'policy_revision' => 4,

    'enabled' => true,

    'min_version' => '0.2.5',

    'max_cache_hours' => 24,

    'features' => [
        'deck_read' => true,
        'deck_write' => true,
        'shop_read' => true,
        'shop_bulk_buy' => true,
    ],

    'message' => 'Beta 測試中；已向官方詢問第三方工具使用規範。',
];

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($method === 'HEAD') {
    http_response_code(200);
    exit;
}

http_response_code(200);

echo json_encode(
    $policy,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_PRETTY_PRINT
);
