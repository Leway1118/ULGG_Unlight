<?php
require_once __DIR__ . '/../../config.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$pdo = $db;

header('Content-Type: text/html; charset=utf-8');

function h($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = trim((string)(
  $_SESSION['username']
  ?? $_SESSION['nickname']
  ?? ''
));

if ($userId <= 0 && $username === '') {
  http_response_code(401);
  echo '請先登入後再測試推播。';
  exit;
}

if (!appVapidConfigured()) {
  echo 'VAPID 設定不存在，請先確認 config.php。';
  exit;
}

$stmt = $pdo->prepare("
  SELECT
    id,
    endpoint,
    p256dh,
    auth,
    username
  FROM tournament_push_subscriptions
  WHERE tournament_id = 1
    AND is_active = 1
    AND (
      user_id = :user_id
      OR username = :username
    )
  ORDER BY id DESC
  LIMIT 1
");

$stmt->execute([
  ':user_id' => $userId,
  ':username' => $username,
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  echo '找不到你的 active 推播訂閱資料。請先到 ULGG 杯頁面按「訂閱賽事消息推播」。';
  exit;
}

$payload = [
  'title' => 'ULGG 杯測試推播',
  'body' => '這是一則測試通知。如果你收到，代表 Web Push 訂閱與發送功能正常。',
  'url' => '/pages/tournament/ulgg_cup.php',
  'icon' => '/favicon.ico',
  'badge' => '/favicon.ico',
];

$auth = [
  'VAPID' => [
    'subject' => ULGG_VAPID_SUBJECT,
    'publicKey' => ULGG_VAPID_PUBLIC_KEY,
    'privateKey' => ULGG_VAPID_PRIVATE_KEY,
  ],
];

$webPush = new WebPush($auth);

$subscription = Subscription::create([
  'endpoint' => $row['endpoint'],
  'publicKey' => $row['p256dh'],
  'authToken' => $row['auth'],
]);

try {
  $webPush->queueNotification(
    $subscription,
    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
  );

  $sent = 0;
  $failed = 0;
  $expired = 0;
  $messages = [];

  foreach ($webPush->flush() as $report) {
    if ($report->isSuccess()) {
      $sent++;
      $messages[] = '推播送出成功。';
    } else {
      $failed++;
      $messages[] = '推播送出失敗：' . $report->getReason();

      if ($report->isSubscriptionExpired()) {
        $expired++;

        $stmtExpired = $pdo->prepare("
          UPDATE tournament_push_subscriptions
          SET
            is_active = 0,
            updated_at = CURRENT_TIMESTAMP
          WHERE id = :id
          LIMIT 1
        ");

        $stmtExpired->execute([
          ':id' => (int)$row['id'],
        ]);
      }
    }
  }

  echo '<h2>ULGG Web Push 測試結果</h2>';
  echo '<p>訂閱者：' . h($row['username'] ?: $username) . '</p>';
  echo '<p>成功：' . h($sent) . '，失敗：' . h($failed) . '，失效：' . h($expired) . '</p>';

  echo '<pre style="white-space:pre-wrap;background:#111;color:#bfffe3;padding:12px;border-radius:8px;">';
  echo h(implode("\n", $messages));
  echo '</pre>';

  echo '<p><a href="/pages/tournament/ulgg_cup.php">返回 ULGG 杯</a></p>';
} catch (Throwable $e) {
  http_response_code(500);

  echo '<h2>推播測試失敗</h2>';
  echo '<pre style="white-space:pre-wrap;background:#111;color:#ffd0d0;padding:12px;border-radius:8px;">';
  echo h($e->getMessage());
  echo '</pre>';
}
