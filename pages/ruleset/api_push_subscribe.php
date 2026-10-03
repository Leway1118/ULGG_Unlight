<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, [
        'ok' => false,
        'message' => '只接受 POST。',
    ]);
}

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($userId === false) {
    respond(401, [
        'ok' => false,
        'message' => '請先登入。',
    ]);
}

$input = json_decode(
    file_get_contents('php://input') ?: '',
    true
);

$subscription = $input['subscription'] ?? null;

if (!is_array($subscription)) {
    respond(422, [
        'ok' => false,
        'message' => '缺少推播訂閱資料。',
    ]);
}

$endpoint = trim((string)($subscription['endpoint'] ?? ''));
$keys = $subscription['keys'] ?? [];

$p256dh = trim((string)($keys['p256dh'] ?? ''));
$auth = trim((string)($keys['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    respond(422, [
        'ok' => false,
        'message' => '推播訂閱資料不完整。',
    ]);
}

$stmt = $db->prepare("
  INSERT INTO ulgg_push_subscriptions (
    user_id,
    endpoint,
    endpoint_hash,
    p256dh_key,
    auth_key,
    is_active,
    created_at,
    updated_at
  ) VALUES (
    :user_id,
    :endpoint,
    :endpoint_hash,
    :p256dh_key,
    :auth_key,
    1,
    NOW(),
    NOW()
  )
  ON DUPLICATE KEY UPDATE
    user_id = VALUES(user_id),
    p256dh_key = VALUES(p256dh_key),
    auth_key = VALUES(auth_key),
    is_active = 1,
    updated_at = NOW()
");

$stmt->execute([
    ':user_id' => $userId,
    ':endpoint' => $endpoint,
    ':endpoint_hash' => hash('sha256', $endpoint),
    ':p256dh_key' => $p256dh,
    ':auth_key' => $auth,
]);

respond(200, [
    'ok' => true,
]);
