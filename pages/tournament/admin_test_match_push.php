<?php
require_once __DIR__ . '/../../config.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$pdo = $db;

header('Content-Type: text/html; charset=utf-8');

function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function currentLoginName(): string
{
  return trim((string)(
    $_SESSION['username']
    ?? $_SESSION['user_name']
    ?? $_SESSION['game_name']
    ?? ''
  ));
}

function isTournamentAdmin(): bool
{
  $ack = (int)(
    $_SESSION['ack']
    ?? $_SESSION['check_ack']
    ?? 0
  );

  if ($ack >= 2) {
    return true;
  }

  $permissionRaw = $_SESSION['permission'] ?? '';

  if (is_numeric($permissionRaw) && (int)$permissionRaw >= 2) {
    return true;
  }

  return !empty($_SESSION['is_admin']);
}

function tournamentPushPlayerName(array $row, string $side): string
{
  if ($side === 'p1') {
    return $row['p1_display_name']
      ?: ($row['p1_game_name'] ?: ($row['p1_tonamel_entry_name'] ?: 'P1'));
  }

  if ($side === 'p2') {
    return $row['p2_display_name']
      ?: ($row['p2_game_name'] ?: ($row['p2_tonamel_entry_name'] ?: 'P2'));
  }

  return $row['winner_display_name']
    ?: ($row['winner_game_name'] ?: ($row['winner_tonamel_entry_name'] ?: '勝者'));
}

function sendTournamentMatchPush(PDO $pdo, int $matchId, string $eventType, string $createdBy = ''): array
{
  if ($matchId <= 0) {
    return ['ok' => false, 'message' => '推播略過：match_id 無效。'];
  }

  if (!in_array($eventType, ['match_confirmed', 'match_forfeit'], true)) {
    return ['ok' => false, 'message' => '推播略過：event_type 無效。'];
  }

  $stmt = $pdo->prepare("
    SELECT
      m.id,
      m.tournament_id,
      m.round_name,
      m.match_code,
      m.player1_id,
      m.player2_id,
      m.player1_score,
      m.player2_score,
      m.winner_player_id,
      m.status,
      m.note,

      p1.display_name AS p1_display_name,
      p1.game_name AS p1_game_name,
      p1.tonamel_entry_name AS p1_tonamel_entry_name,

      p2.display_name AS p2_display_name,
      p2.game_name AS p2_game_name,
      p2.tonamel_entry_name AS p2_tonamel_entry_name,

      w.display_name AS winner_display_name,
      w.game_name AS winner_game_name,
      w.tonamel_entry_name AS winner_tonamel_entry_name
    FROM tournament_matches m
    LEFT JOIN tournament_players p1 ON p1.id = m.player1_id
    LEFT JOIN tournament_players p2 ON p2.id = m.player2_id
    LEFT JOIN tournament_players w ON w.id = m.winner_player_id
    WHERE m.id = :match_id
    LIMIT 1
  ");

  $stmt->execute([
    ':match_id' => $matchId,
  ]);

  $match = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$match) {
    return ['ok' => false, 'message' => '推播略過：找不到場次。'];
  }

  $tournamentId = (int)($match['tournament_id'] ?? 1);
  $winnerId = (int)($match['winner_player_id'] ?? 0);

  if ($winnerId <= 0 || !in_array((string)$match['status'], ['confirmed', 'finished'], true)) {
    return ['ok' => false, 'message' => '推播略過：場次尚未正式結算。'];
  }

  $p1Name = tournamentPushPlayerName($match, 'p1');
  $p2Name = tournamentPushPlayerName($match, 'p2');
  $winnerName = tournamentPushPlayerName($match, 'winner');

  $p1Score = (int)($match['player1_score'] ?? 0);
  $p2Score = (int)($match['player2_score'] ?? 0);
  $scoreText = $p1Score . ' - ' . $p2Score;

  $p1IsWinner = $winnerId === (int)($match['player1_id'] ?? 0);
  $loserName = $p1IsWinner ? $p2Name : $p1Name;

  $matchCode = (string)$match['match_code'];
  $roundName = (string)$match['round_name'];
  $payloadUrl = '/pages/tournament/ulgg_cup_match.php?match=' . urlencode($matchCode);

  if ($eventType === 'match_forfeit') {
    $title = 'ULGG 杯棄賽判定';
    $body = $roundName . '｜' . $matchCode . '：' . $loserName . ' 棄賽，' . $winnerName . ' 晉級。';
  } else {
    $title = 'ULGG 杯賽果確認';
    $body = $roundName . '｜' . $matchCode . '：' . $p1Name . ' ' . $scoreText . ' ' . $p2Name . '，' . $winnerName . ' 晉級。';
  }

  $stmtExists = $pdo->prepare("
    SELECT id, sent_count, failed_count
    FROM tournament_push_logs
    WHERE tournament_id = :tournament_id
      AND match_id = :match_id
      AND event_type = :event_type
    LIMIT 1
  ");
  $stmtExists->execute([
    ':tournament_id' => $tournamentId,
    ':match_id' => $matchId,
    ':event_type' => $eventType,
  ]);
  $exists = $stmtExists->fetch(PDO::FETCH_ASSOC);

  if ($exists) {
    return [
      'ok' => true,
      'message' => '推播已送過，略過重複推播。成功 ' . (int)$exists['sent_count'] . ' 人，失敗 ' . (int)$exists['failed_count'] . ' 人。',
    ];
  }

  $stmtSubs = $pdo->prepare("
    SELECT id, username, endpoint, p256dh, auth
    FROM tournament_push_subscriptions
    WHERE tournament_id = :tournament_id
      AND is_active = 1
    ORDER BY id ASC
  ");
  $stmtSubs->execute([
    ':tournament_id' => $tournamentId,
  ]);

  $subscriptions = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

  $stmtLog = $pdo->prepare("
    INSERT INTO tournament_push_logs (
      tournament_id,
      match_id,
      match_code,
      event_type,
      payload_title,
      payload_body,
      payload_url,
      sent_count,
      failed_count,
      created_by
    ) VALUES (
      :tournament_id,
      :match_id,
      :match_code,
      :event_type,
      :payload_title,
      :payload_body,
      :payload_url,
      0,
      0,
      :created_by
    )
  ");

  $stmtLog->execute([
    ':tournament_id' => $tournamentId,
    ':match_id' => $matchId,
    ':match_code' => $matchCode,
    ':event_type' => $eventType,
    ':payload_title' => $title,
    ':payload_body' => $body,
    ':payload_url' => $payloadUrl,
    ':created_by' => $createdBy,
  ]);

  $pushLogId = (int)$pdo->lastInsertId();

  if (!$subscriptions) {
    return ['ok' => true, 'message' => '沒有 active 訂閱者，已建立推播紀錄但未送出。'];
  }

  $auth = [
    'VAPID' => [
      'subject' => ULGG_VAPID_SUBJECT,
      'publicKey' => ULGG_VAPID_PUBLIC_KEY,
      'privateKey' => ULGG_VAPID_PRIVATE_KEY,
    ],
  ];

  $payload = json_encode([
    'title' => $title,
    'body' => $body,
    'url' => $payloadUrl,
    'icon' => '/favicon.ico',
    'badge' => '/favicon.ico',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $webPush = new WebPush($auth);
  $subscriptionMap = [];

  foreach ($subscriptions as $sub) {
    $subscription = Subscription::create([
      'endpoint' => $sub['endpoint'],
      'publicKey' => $sub['p256dh'],
      'authToken' => $sub['auth'],
    ]);

    $webPush->queueNotification($subscription, $payload);
    $subscriptionMap[$sub['endpoint']] = $sub;
  }

  $sent = 0;
  $failed = 0;
  $expired = 0;

  $stmtTarget = $pdo->prepare("
    INSERT INTO tournament_push_log_targets (
      push_log_id,
      subscription_id,
      username,
      status,
      http_code,
      error_message
    ) VALUES (
      :push_log_id,
      :subscription_id,
      :username,
      :status,
      :http_code,
      :error_message
    )
  ");

  foreach ($webPush->flush() as $report) {
    $endpoint = (string)$report->getRequest()->getUri();
    $sub = $subscriptionMap[$endpoint] ?? null;

    if (!$sub) {
      $failed++;
      continue;
    }

    $status = 'sent';
    $httpCode = null;
    $errorMessage = null;

    if ($report->isSuccess()) {
      $sent++;
    } else {
      $failed++;
      $status = 'failed';
      $errorMessage = $report->getReason();

      if ($report->isSubscriptionExpired()) {
        $expired++;
        $status = 'expired';

        $stmtExpired = $pdo->prepare("
          UPDATE tournament_push_subscriptions
          SET is_active = 0, updated_at = CURRENT_TIMESTAMP
          WHERE id = :id
          LIMIT 1
        ");
        $stmtExpired->execute([
          ':id' => (int)$sub['id'],
        ]);
      }
    }

    $response = $report->getResponse();
    if ($response) {
      $httpCode = $response->getStatusCode();
    }

    $stmtTarget->execute([
      ':push_log_id' => $pushLogId,
      ':subscription_id' => (int)$sub['id'],
      ':username' => (string)($sub['username'] ?? ''),
      ':status' => $status,
      ':http_code' => $httpCode,
      ':error_message' => $errorMessage,
    ]);
  }

  $stmtUpdateLog = $pdo->prepare("
    UPDATE tournament_push_logs
    SET sent_count = :sent_count,
        failed_count = :failed_count
    WHERE id = :id
    LIMIT 1
  ");
  $stmtUpdateLog->execute([
    ':sent_count' => $sent,
    ':failed_count' => $failed,
    ':id' => $pushLogId,
  ]);

  return [
    'ok' => true,
    'message' => '推播成功 ' . $sent . ' 人，失敗 ' . $failed . ' 人，失效 ' . $expired . ' 人。',
  ];
}

if (!isTournamentAdmin()) {
  http_response_code(403);
  echo '只有管理員可以測試。';
  exit;
}

$matchId = (int)($_GET['match_id'] ?? 0);
$eventType = trim((string)($_GET['event_type'] ?? 'match_confirmed'));

if ($matchId <= 0) {
  echo '<h2>ULGG 正式推播假訊號測試</h2>';
  echo '<p>請帶 match_id，例如：</p>';
  echo '<pre>admin_test_match_push.php?match_id=9&amp;event_type=match_confirmed</pre>';
  exit;
}

$result = sendTournamentMatchPush($pdo, $matchId, $eventType, currentLoginName());

echo '<h2>ULGG 正式推播假訊號測試</h2>';
echo '<p>match_id：' . h($matchId) . '</p>';
echo '<p>event_type：' . h($eventType) . '</p>';
echo '<pre style="white-space:pre-wrap;background:#111;color:#bfffe3;padding:12px;border-radius:8px;">';
print_r($result);
echo '</pre>';
echo '<p><a href="/pages/tournament/ulgg_cup.php">返回 ULGG 杯</a></p>';