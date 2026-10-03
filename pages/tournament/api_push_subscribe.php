<?php
require_once __DIR__ . '/../../config.php';

$pdo = $db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function jsonResponse(array $data, int $statusCode = 200): void
{
  http_response_code($statusCode);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  jsonResponse([
    'ok' => false,
    'message' => 'Method not allowed',
  ], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)(
  $_SESSION['username']
  ?? $_SESSION['nickname']
  ?? ''
));

if ($userId <= 0 && $username === '') {
  jsonResponse([
    'ok' => false,
    'message' => '請先登入後再訂閱賽事推播。',
  ], 401);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
  jsonResponse([
    'ok' => false,
    'message' => 'JSON 格式錯誤。',
  ], 400);
}

$action = trim((string)($data['action'] ?? 'subscribe'));
$tournamentId = (int)($data['tournament_id'] ?? 1);

if ($tournamentId <= 0) {
  $tournamentId = 1;
}

$subscription = $data['subscription'] ?? null;

if (!is_array($subscription)) {
  jsonResponse([
    'ok' => false,
    'message' => '缺少 subscription 資料。',
  ], 400);
}

$endpoint = trim((string)($subscription['endpoint'] ?? ''));
$p256dh = trim((string)($subscription['keys']['p256dh'] ?? ''));
$auth = trim((string)($subscription['keys']['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
  jsonResponse([
    'ok' => false,
    'message' => 'subscription 欄位不完整。',
  ], 400);
}

$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

try {
  if ($action === 'unsubscribe') {
    $stmt = $pdo->prepare("
      UPDATE tournament_push_subscriptions
      SET
        is_active = 0,
        updated_at = CURRENT_TIMESTAMP
      WHERE endpoint = :endpoint
      LIMIT 1
    ");

    $stmt->execute([
      ':endpoint' => $endpoint,
    ]);

    jsonResponse([
      'ok' => true,
      'message' => '已取消賽事推播訂閱。',
    ]);
  }

  $stmt = $pdo->prepare("
    INSERT INTO tournament_push_subscriptions (
      tournament_id,
      user_id,
      username,
      endpoint,
      p256dh,
      auth,
      user_agent,
      is_active
    ) VALUES (
      :tournament_id,
      :user_id,
      :username,
      :endpoint,
      :p256dh,
      :auth,
      :user_agent,
      1
    )
    ON DUPLICATE KEY UPDATE
      tournament_id = VALUES(tournament_id),
      user_id = VALUES(user_id),
      username = VALUES(username),
      p256dh = VALUES(p256dh),
      auth = VALUES(auth),
      user_agent = VALUES(user_agent),
      is_active = 1,
      updated_at = CURRENT_TIMESTAMP
  ");

  $stmt->execute([
    ':tournament_id' => $tournamentId,
    ':user_id' => $userId > 0 ? $userId : null,
    ':username' => $username,
    ':endpoint' => $endpoint,
    ':p256dh' => $p256dh,
    ':auth' => $auth,
    ':user_agent' => $userAgent,
  ]);

  jsonResponse([
    'ok' => true,
    'message' => '已訂閱 ULGG 杯賽事消息推播。',
  ]);
} catch (Throwable $e) {
  jsonResponse([
    'ok' => false,
    'message' => '訂閱處理失敗：' . $e->getMessage(),
  ], 500);
}