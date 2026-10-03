<?php
require_once __DIR__ . '/../../config.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$pdo = $db;

function cronTournamentPlayerName(array $row, string $side): string
{
  if ($side === 'p1') {
    return $row['p1_display_name']
      ?: ($row['p1_game_name'] ?: ($row['p1_tonamel_entry_name'] ?: 'P1'));
  }

  if ($side === 'p2') {
    return $row['p2_display_name']
      ?: ($row['p2_game_name'] ?: ($row['p2_tonamel_entry_name'] ?: 'P2'));
  }

  return '選手';
}

function cronSendTournamentMatchPush(PDO $pdo, int $matchId, string $eventType, string $createdBy = 'cron'): array
{
  if (!in_array($eventType, ['match_start_soon'], true)) {
    return [
      'ok' => false,
      'message' => '不支援的推播類型。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
    ];
  }

  if (
    !defined('ULGG_VAPID_PUBLIC_KEY') ||
    !defined('ULGG_VAPID_PRIVATE_KEY') ||
    !defined('ULGG_VAPID_SUBJECT')
  ) {
    return [
      'ok' => false,
      'message' => 'VAPID 尚未設定。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
    ];
  }

  $stmtMatch = $pdo->prepare("
    SELECT
      m.id,
      m.tournament_id,
      m.round_name,
      m.match_code,
      m.player1_id,
      m.player2_id,
      m.status,
      m.scheduled_at,

      p1.display_name AS p1_display_name,
      p1.game_name AS p1_game_name,
      p1.tonamel_entry_name AS p1_tonamel_entry_name,

      p2.display_name AS p2_display_name,
      p2.game_name AS p2_game_name,
      p2.tonamel_entry_name AS p2_tonamel_entry_name
    FROM tournament_matches m
    LEFT JOIN tournament_players p1
      ON p1.id = m.player1_id
    LEFT JOIN tournament_players p2
      ON p2.id = m.player2_id
    WHERE m.id = :match_id
    LIMIT 1
  ");

  $stmtMatch->execute([
    ':match_id' => $matchId,
  ]);

  $match = $stmtMatch->fetch(PDO::FETCH_ASSOC);

  if (!$match) {
    return [
      'ok' => false,
      'message' => '找不到場次。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
    ];
  }

  $tournamentId = (int)($match['tournament_id'] ?? 1);
  $status = (string)($match['status'] ?? '');
  $scheduledAt = trim((string)($match['scheduled_at'] ?? ''));

  if (in_array($status, ['confirmed', 'finished'], true)) {
    return [
      'ok' => false,
      'message' => '場次已結算，略過賽前提醒。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
    ];
  }

  if ($scheduledAt === '') {
    return [
      'ok' => false,
      'message' => '尚未設定預計時間，略過賽前提醒。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
    ];
  }

  if ((int)($match['player1_id'] ?? 0) <= 0 || (int)($match['player2_id'] ?? 0) <= 0) {
    return [
      'ok' => false,
      'message' => '雙方選手資料尚未完整，略過賽前提醒。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
    ];
  }

  $roundName = (string)($match['round_name'] ?? '');
  $matchCode = (string)($match['match_code'] ?? '');
  $p1Name = cronTournamentPlayerName($match, 'p1');
  $p2Name = cronTournamentPlayerName($match, 'p2');

  $title = 'ULGG 杯賽前提醒';
  $body = $roundName . '｜' . $matchCode . ' 將於 10 分鐘後開始：' . $p1Name . ' vs ' . $p2Name . '。';
  $url = '/pages/tournament/ulgg_cup_match.php?match=' . urlencode($matchCode);

  $stmtLogCheck = $pdo->prepare("
    SELECT id
    FROM tournament_push_logs
    WHERE tournament_id = :tournament_id
      AND match_id = :match_id
      AND event_type = :event_type
    LIMIT 1
  ");

  $stmtLogCheck->execute([
    ':tournament_id' => $tournamentId,
    ':match_id' => $matchId,
    ':event_type' => $eventType,
  ]);

  $oldLogId = $stmtLogCheck->fetchColumn();

  if ($oldLogId) {
    return [
      'ok' => true,
      'message' => '推播已送過，略過重複推播。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'skipped' => true,
      'push_log_id' => (int)$oldLogId,
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
    ':payload_url' => $url,
    ':created_by' => $createdBy,
  ]);

  $pushLogId = (int)$pdo->lastInsertId();

  if (!$subscriptions) {
    return [
      'ok' => true,
      'message' => '沒有 active 訂閱者。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'push_log_id' => $pushLogId,
    ];
  }

  $auth = [
    'VAPID' => [
      'subject' => ULGG_VAPID_SUBJECT,
      'publicKey' => ULGG_VAPID_PUBLIC_KEY,
      'privateKey' => ULGG_VAPID_PRIVATE_KEY,
    ],
  ];

  $webPush = new WebPush($auth);

  $payload = json_encode([
    'title' => $title,
    'body' => $body,
    'url' => $url,
    'icon' => '/favicon.ico',
    'badge' => '/favicon.ico',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  foreach ($subscriptions as $sub) {
    $subscription = Subscription::create([
      'endpoint' => $sub['endpoint'],
      'publicKey' => $sub['p256dh'],
      'authToken' => $sub['auth'],
    ]);

    $webPush->queueNotification($subscription, $payload, [
      'TTL' => 600,
    ]);
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

  $stmtDeactivate = $pdo->prepare("
    UPDATE tournament_push_subscriptions
    SET is_active = 0
    WHERE id = :id
    LIMIT 1
  ");

  $stmtLastPush = $pdo->prepare("
    UPDATE tournament_push_subscriptions
    SET last_push_at = CURRENT_TIMESTAMP
    WHERE id = :id
    LIMIT 1
  ");

  foreach ($webPush->flush() as $index => $report) {
    $sub = $subscriptions[$index] ?? null;

    if (!$sub) {
      continue;
    }

    $status = 'sent';
    $httpCode = null;
    $errorMessage = null;

    if ($report->isSuccess()) {
      $sent++;
      $stmtLastPush->execute([
        ':id' => (int)$sub['id'],
      ]);
    } else {
      $failed++;
      $status = 'failed';
      $errorMessage = $report->getReason();

      if ($report->isSubscriptionExpired()) {
        $expired++;
        $status = 'expired';

        $stmtDeactivate->execute([
          ':id' => (int)$sub['id'],
        ]);
      }
    }

    if (method_exists($report, 'getResponse') && $report->getResponse()) {
      $httpCode = $report->getResponse()->getStatusCode();
    }

    $stmtTarget->execute([
      ':push_log_id' => $pushLogId,
      ':subscription_id' => (int)$sub['id'],
      ':username' => $sub['username'] ?? null,
      ':status' => $status,
      ':http_code' => $httpCode,
      ':error_message' => $errorMessage,
    ]);
  }

  $stmtUpdateLog = $pdo->prepare("
    UPDATE tournament_push_logs
    SET
      sent_count = :sent_count,
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
    'sent' => $sent,
    'failed' => $failed,
    'expired' => $expired,
    'push_log_id' => $pushLogId,
  ];
}

function cronRunTournamentPushJobs(PDO $pdo): void
{
  $stmtJobs = $pdo->prepare("
    SELECT *
    FROM tournament_push_jobs
    WHERE status = 'pending'
      AND run_at <= NOW()
    ORDER BY run_at ASC, id ASC
    LIMIT 10
  ");

  $stmtJobs->execute();
  $jobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);

  if (!$jobs) {
    echo '[' . date('Y-m-d H:i:s') . "] no pending jobs\n";
    return;
  }

  foreach ($jobs as $job) {
    $jobId = (int)$job['id'];
    $matchId = (int)$job['match_id'];
    $eventType = (string)$job['event_type'];

    $stmtLock = $pdo->prepare("
      UPDATE tournament_push_jobs
      SET status = 'processing', updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
        AND status = 'pending'
      LIMIT 1
    ");

    $stmtLock->execute([
      ':id' => $jobId,
    ]);

    if ($stmtLock->rowCount() <= 0) {
      continue;
    }

    try {
      $result = cronSendTournamentMatchPush($pdo, $matchId, $eventType, 'cron');

      if (!empty($result['ok'])) {
        $stmtDone = $pdo->prepare("
          UPDATE tournament_push_jobs
          SET
            status = 'sent',
            push_log_id = :push_log_id,
            error_message = :error_message,
            updated_at = CURRENT_TIMESTAMP
          WHERE id = :id
          LIMIT 1
        ");

        $stmtDone->execute([
          ':push_log_id' => $result['push_log_id'] ?? null,
          ':error_message' => $result['message'] ?? null,
          ':id' => $jobId,
        ]);

        echo '[' . date('Y-m-d H:i:s') . '] job #' . $jobId . ' sent: ' . ($result['message'] ?? '') . "\n";
      } else {
        $stmtFail = $pdo->prepare("
          UPDATE tournament_push_jobs
          SET
            status = 'failed',
            error_message = :error_message,
            updated_at = CURRENT_TIMESTAMP
          WHERE id = :id
          LIMIT 1
        ");

        $stmtFail->execute([
          ':error_message' => $result['message'] ?? 'unknown error',
          ':id' => $jobId,
        ]);

        echo '[' . date('Y-m-d H:i:s') . '] job #' . $jobId . ' failed: ' . ($result['message'] ?? '') . "\n";
      }
    } catch (Throwable $e) {
      $stmtFail = $pdo->prepare("
        UPDATE tournament_push_jobs
        SET
          status = 'failed',
          error_message = :error_message,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
        LIMIT 1
      ");

      $stmtFail->execute([
        ':error_message' => $e->getMessage(),
        ':id' => $jobId,
      ]);

      echo '[' . date('Y-m-d H:i:s') . '] job #' . $jobId . ' exception: ' . $e->getMessage() . "\n";
    }
  }
}

cronRunTournamentPushJobs($pdo);