<?php

declare(strict_types=1);

header(
    'Content-Type: application/json; charset=utf-8'
);
header(
    'Cache-Control: no-store, max-age=0'
);

function respond(
    int $status,
    array $payload
): never {
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    !== 'POST'
) {
    header('Allow: POST');

    respond(
        405,
        [
            'ok' => false,
            'error' => 'method_not_allowed',
        ]
    );
}

$body = file_get_contents(
    'php://input'
);

if (
    $body === false
    || strlen($body) > 4096
) {
    respond(
        413,
        [
            'ok' => false,
            'error' => 'payload_too_large',
        ]
    );
}

$decoded = json_decode(
    $body,
    true
);

if (!is_array($decoded)) {
    respond(
        400,
        [
            'ok' => false,
            'error' => 'json_invalid',
        ]
    );
}

if (!function_exists('curl_init')) {
    respond(
        503,
        [
            'ok' => false,
            'error' => 'proxy_unavailable',
        ]
    );
}

$clientIp = (
    $_SERVER['REMOTE_ADDR']
    ?? 'unknown'
);

$handle = curl_init(
    'http://127.0.0.1:8766'
    . '/api/community/raid-fragment-report'
);

if ($handle === false) {
    respond(
        503,
        [
            'ok' => false,
            'error' => 'proxy_unavailable',
        ]
    );
}

curl_setopt_array(
    $handle,
    [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-ULGG-Client-IP: '
                . $clientIp,
        ],
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT_MS => 2500,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_NOPROXY => '*',
    ]
);

$response = curl_exec(
    $handle
);

$curlError = curl_errno(
    $handle
);

$status = (int) curl_getinfo(
    $handle,
    CURLINFO_RESPONSE_CODE
);

curl_close(
    $handle
);

if (
    $response === false
    || $curlError !== 0
) {
    respond(
        503,
        [
            'ok' => false,
            'error' => 'upstream_unavailable',
        ]
    );
}

if ($status < 100) {
    $status = 502;
}

http_response_code(
    $status
);

echo $response;
