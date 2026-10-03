<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../../config.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$pdo = $db;

$matchCode = strtoupper(trim($_GET['match'] ?? 'M01'));
$tournamentId = 1;

$pageTitleText = 'ULGG 杯場次 ' . $matchCode;
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "ulgg_cup_2026";

ob_start();

function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if (!function_exists('ulgg_norm_name')) {
  function ulgg_norm_name($name): string
  {
    $name = trim((string)$name);
    $name = mb_strtolower($name, 'UTF-8');
    $name = preg_replace('/\s+/u', '', $name);
    return $name ?? '';
  }
}

if (!function_exists('ulgg_same_char_set')) {
  function ulgg_same_char_set(array $a, array $b): bool
  {
    $a = array_map('intval', $a);
    $b = array_map('intval', $b);
    sort($a);
    sort($b);
    return $a === $b;
  }
}

if (!function_exists('ulgg_find_deck_code_by_chars')) {
  function ulgg_find_deck_code_by_chars(PDO $pdo, int $tournamentId, int $playerId, array $chars): array
  {
    $chars = array_values(array_map('intval', $chars));

    $stmt = $pdo->prepare("
      SELECT deck_no, char1_id, char2_id, char3_id
      FROM tournament_player_decks
      WHERE tournament_id = :tournament_id
        AND player_id = :player_id
        AND is_public = 1
      ORDER BY deck_no ASC
    ");
    $stmt->execute([
      ':tournament_id' => $tournamentId,
      ':player_id' => $playerId,
    ]);

    while ($deck = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $deckChars = [
        (int)$deck['char1_id'],
        (int)$deck['char2_id'],
        (int)$deck['char3_id'],
      ];

      if (ulgg_same_char_set($chars, $deckChars)) {
        return [
          'deck_code' => chr(64 + (int)$deck['deck_no']),
          'confidence' => 'auto_id',
        ];
      }
    }

    return [
      'deck_code' => null,
      'confidence' => null,
    ];
  }
}

if (!function_exists('ulgg_player_name_hits_arena')) {
  function ulgg_player_name_hits_arena(array $playerNames, string $arenaName): bool
  {
    $arenaKey = ulgg_norm_name($arenaName);
    if ($arenaKey === '') return false;

    foreach ($playerNames as $name) {
      if (ulgg_norm_name($name) === $arenaKey) {
        return true;
      }
    }

    return false;
  }
}

if (!function_exists('ulgg_auto_fill_game_decks')) {
  function ulgg_auto_fill_game_decks(PDO $pdo, int $gameId): bool
  {
    if ($gameId <= 0) return false;

    $stmt = $pdo->prepare("
      SELECT
        g.*,
        a.name_p1,
        a.name_p2,
        a.e1,
        a.e2,
        a.e3,
        a.u1,
        a.u2,
        a.u3,
        p1.display_name AS p1_display_name,
        p1.game_name AS p1_game_name,
        p1.tonamel_entry_name AS p1_tonamel_entry_name,
        p1.tonamel_user_name AS p1_tonamel_user_name,
        p2.display_name AS p2_display_name,
        p2.game_name AS p2_game_name,
        p2.tonamel_entry_name AS p2_tonamel_entry_name,
        p2.tonamel_user_name AS p2_tonamel_user_name
      FROM tournament_match_games g
      JOIN arena_unlight a ON a.id = g.arena_id
      LEFT JOIN tournament_players p1 ON p1.id = g.player1_id
      LEFT JOIN tournament_players p2 ON p2.id = g.player2_id
      WHERE g.id = :game_id
      LIMIT 1
    ");
    $stmt->execute([':game_id' => $gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) return false;

    $tournamentId = (int)$game['tournament_id'];
    $player1Id = (int)$game['player1_id'];
    $player2Id = (int)$game['player2_id'];

    $eChars = [(int)$game['e1'], (int)$game['e2'], (int)$game['e3']];
    $uChars = [(int)$game['u1'], (int)$game['u2'], (int)$game['u3']];

    $p1Names = [
      $game['p1_display_name'] ?? '',
      $game['p1_game_name'] ?? '',
      $game['p1_tonamel_entry_name'] ?? '',
      $game['p1_tonamel_user_name'] ?? '',
    ];

    $p2Names = [
      $game['p2_display_name'] ?? '',
      $game['p2_game_name'] ?? '',
      $game['p2_tonamel_entry_name'] ?? '',
      $game['p2_tonamel_user_name'] ?? '',
    ];

    $p1Side = null;
    $p2Side = null;

    if (ulgg_player_name_hits_arena($p1Names, (string)($game['name_p1'] ?? ''))) {
      $p1Side = 'e';
    } elseif (ulgg_player_name_hits_arena($p1Names, (string)($game['name_p2'] ?? ''))) {
      $p1Side = 'u';
    }

    if (ulgg_player_name_hits_arena($p2Names, (string)($game['name_p1'] ?? ''))) {
      $p2Side = 'e';
    } elseif (ulgg_player_name_hits_arena($p2Names, (string)($game['name_p2'] ?? ''))) {
      $p2Side = 'u';
    }

    if ($p1Side === null && $p2Side !== null) {
      $p1Side = $p2Side === 'e' ? 'u' : 'e';
    }

    if ($p2Side === null && $p1Side !== null) {
      $p2Side = $p1Side === 'e' ? 'u' : 'e';
    }

    if ($p1Side === null || $p2Side === null || $p1Side === $p2Side) {
      return false;
    }

    $p1Chars = $p1Side === 'e' ? $eChars : $uChars;
    $p2Chars = $p2Side === 'e' ? $eChars : $uChars;

    $p1Deck = ulgg_find_deck_code_by_chars($pdo, $tournamentId, $player1Id, $p1Chars);
    $p2Deck = ulgg_find_deck_code_by_chars($pdo, $tournamentId, $player2Id, $p2Chars);

    $stmtUpdate = $pdo->prepare("
      UPDATE tournament_match_games
      SET
        player1_char1_id = :p1c1,
        player1_char2_id = :p1c2,
        player1_char3_id = :p1c3,
        player2_char1_id = :p2c1,
        player2_char2_id = :p2c2,
        player2_char3_id = :p2c3,
        player1_deck_code = :p1_deck_code,
        player1_deck_confidence = :p1_confidence,
        player2_deck_code = :p2_deck_code,
        player2_deck_confidence = :p2_confidence,
        updated_at = NOW()
      WHERE id = :game_id
      LIMIT 1
    ");

    $stmtUpdate->execute([
      ':p1c1' => $p1Chars[0],
      ':p1c2' => $p1Chars[1],
      ':p1c3' => $p1Chars[2],
      ':p2c1' => $p2Chars[0],
      ':p2c2' => $p2Chars[1],
      ':p2c3' => $p2Chars[2],
      ':p1_deck_code' => $p1Deck['deck_code'],
      ':p1_confidence' => $p1Deck['confidence'],
      ':p2_deck_code' => $p2Deck['deck_code'],
      ':p2_confidence' => $p2Deck['confidence'],
      ':game_id' => $gameId,
    ]);

    return true;
  }
}
/* 加入共用推播 function */
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
    return [
      'ok' => false,
      'message' => '推播略過：match_id 無效。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'skipped' => false,
    ];
  }

  if (!in_array($eventType, ['match_confirmed', 'match_forfeit', 'match_scheduled', 'match_start_soon'], true)) {
    return [
      'ok' => false,
      'message' => '推播略過：event_type 無效。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'skipped' => false,
    ];
  }

  if (
    !defined('ULGG_VAPID_PUBLIC_KEY') ||
    !defined('ULGG_VAPID_PRIVATE_KEY') ||
    !defined('ULGG_VAPID_SUBJECT')
  ) {
    return [
      'ok' => false,
      'message' => '推播略過：VAPID 設定不存在。',
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'skipped' => false,
    ];
  }

  try {
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
        m.scheduled_at,

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
      LEFT JOIN tournament_players p1
        ON p1.id = m.player1_id
      LEFT JOIN tournament_players p2
        ON p2.id = m.player2_id
      LEFT JOIN tournament_players w
        ON w.id = m.winner_player_id
      WHERE m.id = :match_id
      LIMIT 1
    ");

    $stmt->execute([
      ':match_id' => $matchId,
    ]);

    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
      return [
        'ok' => false,
        'message' => '推播略過：找不到場次。',
        'sent' => 0,
        'failed' => 0,
        'expired' => 0,
        'skipped' => false,
      ];
    }

    $tournamentId = (int)($match['tournament_id'] ?? 1);
    $winnerId = (int)($match['winner_player_id'] ?? 0);
    $status = (string)($match['status'] ?? '');
    $scheduledAt = trim((string)($match['scheduled_at'] ?? ''));

    if (in_array($eventType, ['match_confirmed', 'match_forfeit'], true)) {
      if ($winnerId <= 0 || !in_array($status, ['confirmed', 'finished'], true)) {
        return [
          'ok' => false,
          'message' => '推播略過：場次尚未正式結算。',
          'sent' => 0,
          'failed' => 0,
          'expired' => 0,
          'skipped' => false,
        ];
      }
    }

    if (in_array($eventType, ['match_scheduled', 'match_start_soon'], true)) {
      if ($scheduledAt === '') {
        return [
          'ok' => false,
          'message' => '推播略過：尚未設定預計對戰時間。',
          'sent' => 0,
          'failed' => 0,
          'expired' => 0,
          'skipped' => false,
        ];
      }

      if (in_array($status, ['confirmed', 'finished'], true)) {
        return [
          'ok' => false,
          'message' => '推播略過：此場次已結算。',
          'sent' => 0,
          'failed' => 0,
          'expired' => 0,
          'skipped' => false,
        ];
      }

      if ((int)($match['player1_id'] ?? 0) <= 0 || (int)($match['player2_id'] ?? 0) <= 0) {
        return [
          'ok' => false,
          'message' => '推播略過：雙方選手資料尚未完整。',
          'sent' => 0,
          'failed' => 0,
          'expired' => 0,
          'skipped' => false,
        ];
      }
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

    $scheduledText = '';

    if ($scheduledAt !== '') {
      $scheduledText = date('n/j H:i', strtotime($scheduledAt));
    }

    if ($eventType === 'match_forfeit') {
      $title = 'ULGG 杯棄賽判定';
      $body = $roundName . '｜' . $matchCode . '：' . $loserName . ' 棄賽，' . $winnerName . ' 晉級。';
    } elseif ($eventType === 'match_scheduled') {
      $title = 'ULGG 杯對戰時間公告';
      $body = $roundName . '｜' . $matchCode . '：' . $p1Name . ' vs ' . $p2Name . '，預計 ' . $scheduledText . ' 開戰。';
    } elseif ($eventType === 'match_start_soon') {
      $title = 'ULGG 杯賽前提醒';
      $body = $roundName . '｜' . $matchCode . ' 將於 10 分鐘後開始：' . $p1Name . ' vs ' . $p2Name . '。';
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
        'message' => '推播已送過，略過重複推播。',
        'sent' => (int)$exists['sent_count'],
        'failed' => (int)$exists['failed_count'],
        'expired' => 0,
        'skipped' => true,
      ];
    }

    $stmtSubs = $pdo->prepare("
      SELECT
        id,
        username,
        endpoint,
        p256dh,
        auth
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
      return [
        'ok' => true,
        'message' => '沒有 active 訂閱者，已建立推播紀錄但未送出。',
        'sent' => 0,
        'failed' => 0,
        'expired' => 0,
        'skipped' => false,
      ];
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
      ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        http_code = VALUES(http_code),
        error_message = VALUES(error_message),
        created_at = CURRENT_TIMESTAMP
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
            SET
              is_active = 0,
              updated_at = CURRENT_TIMESTAMP
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

    if ($sent > 0) {
      $stmtLastPush = $pdo->prepare("
        UPDATE tournament_push_subscriptions
        SET last_push_at = CURRENT_TIMESTAMP
        WHERE tournament_id = :tournament_id
          AND is_active = 1
      ");

      $stmtLastPush->execute([
        ':tournament_id' => $tournamentId,
      ]);
    }

    return [
      'ok' => true,
      'message' => '推播成功 ' . $sent . ' 人，失敗 ' . $failed . ' 人，失效 ' . $expired . ' 人。',
      'sent' => $sent,
      'failed' => $failed,
      'expired' => $expired,
      'skipped' => false,
    ];
  } catch (Throwable $e) {
    return [
      'ok' => false,
      'message' => '推播失敗：' . $e->getMessage(),
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'skipped' => false,
    ];
  }
}

function appendPushMessage(array $result, array $pushResult): string
{
  $message = (string)($result['message'] ?? '');

  if (!empty($result['ok'])) {
    $message .= '｜' . (string)($pushResult['message'] ?? '推播未回傳結果。');
  }

  return $message;
}
function clearTournamentPushLog(PDO $pdo, int $tournamentId, int $matchId, string $eventType): void
{
  $stmtFind = $pdo->prepare("
    SELECT id
    FROM tournament_push_logs
    WHERE tournament_id = :tournament_id
      AND match_id = :match_id
      AND event_type = :event_type
  ");

  $stmtFind->execute([
    ':tournament_id' => $tournamentId,
    ':match_id' => $matchId,
    ':event_type' => $eventType,
  ]);

  $ids = $stmtFind->fetchAll(PDO::FETCH_COLUMN);

  if (!$ids) {
    return;
  }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));

  $stmtTargets = $pdo->prepare("
    DELETE FROM tournament_push_log_targets
    WHERE push_log_id IN ($placeholders)
  ");
  $stmtTargets->execute(array_map('intval', $ids));

  $stmtLogs = $pdo->prepare("
    DELETE FROM tournament_push_logs
    WHERE id IN ($placeholders)
  ");
  $stmtLogs->execute(array_map('intval', $ids));
}

function upsertTournamentStartSoonJob(PDO $pdo, array $match, string $createdBy = ''): array
{
  $matchId = (int)($match['id'] ?? 0);
  $tournamentId = (int)($match['tournament_id'] ?? 1);
  $matchCode = (string)($match['match_code'] ?? '');
  $scheduledAt = trim((string)($match['scheduled_at'] ?? ''));

  if ($matchId <= 0 || $matchCode === '') {
    return ['ok' => false, 'message' => '賽前提醒建立失敗：場次資料不完整。'];
  }

  if ($scheduledAt === '') {
    $stmtCancel = $pdo->prepare("
      UPDATE tournament_push_jobs
      SET
        status = 'cancelled',
        updated_at = CURRENT_TIMESTAMP
      WHERE tournament_id = :tournament_id
        AND match_id = :match_id
        AND event_type = 'match_start_soon'
        AND status = 'pending'
    ");

    $stmtCancel->execute([
      ':tournament_id' => $tournamentId,
      ':match_id' => $matchId,
    ]);

    return ['ok' => true, 'message' => '已取消賽前提醒。'];
  }

  $scheduledTs = strtotime($scheduledAt);
  if (!$scheduledTs) {
    return ['ok' => false, 'message' => '賽前提醒建立失敗：預計時間格式錯誤。'];
  }

  $runAtTs = $scheduledTs - 600;

  if ($runAtTs <= time()) {
    $stmtCancel = $pdo->prepare("
      UPDATE tournament_push_jobs
      SET
        status = 'cancelled',
        updated_at = CURRENT_TIMESTAMP
      WHERE tournament_id = :tournament_id
        AND match_id = :match_id
        AND event_type = 'match_start_soon'
        AND status = 'pending'
    ");

    $stmtCancel->execute([
      ':tournament_id' => $tournamentId,
      ':match_id' => $matchId,
    ]);

    return ['ok' => true, 'message' => '距離開賽不足 10 分鐘，不建立賽前提醒。'];
  }

  $runAt = date('Y-m-d H:i:s', $runAtTs);
  $payloadUrl = '/pages/tournament/ulgg_cup_match.php?match=' . urlencode($matchCode);

  $stmt = $pdo->prepare("
    INSERT INTO tournament_push_jobs (
      tournament_id,
      match_id,
      match_code,
      event_type,
      run_at,
      status,
      payload_title,
      payload_body,
      payload_url,
      created_by
    ) VALUES (
      :tournament_id,
      :match_id,
      :match_code,
      'match_start_soon',
      :run_at,
      'pending',
      'ULGG 杯賽前提醒',
      NULL,
      :payload_url,
      :created_by
    )
    ON DUPLICATE KEY UPDATE
      match_code = VALUES(match_code),
      run_at = VALUES(run_at),
      status = 'pending',
      payload_title = VALUES(payload_title),
      payload_body = NULL,
      payload_url = VALUES(payload_url),
      push_log_id = NULL,
      error_message = NULL,
      created_by = VALUES(created_by),
      updated_at = CURRENT_TIMESTAMP
  ");

  $stmt->execute([
    ':tournament_id' => $tournamentId,
    ':match_id' => $matchId,
    ':match_code' => $matchCode,
    ':run_at' => $runAt,
    ':payload_url' => $payloadUrl,
    ':created_by' => $createdBy,
  ]);

  return ['ok' => true, 'message' => '已建立賽前 10 分鐘提醒：' . date('n/j H:i', $runAtTs)];
}

function adminUpdateMatchScheduledAt(PDO $pdo, array $match, string $scheduledAtRaw): array
{
  $matchId = (int)($match['id'] ?? 0);
  $tournamentId = (int)($match['tournament_id'] ?? 1);

  if ($matchId <= 0) {
    return ['ok' => false, 'message' => 'match_id 無效，無法設定預計時間。'];
  }

  if (in_array((string)($match['status'] ?? ''), ['confirmed', 'finished'], true)) {
    return ['ok' => false, 'message' => '此場次已結算，不能設定預計時間。'];
  }

  $scheduledAtRaw = trim($scheduledAtRaw);

  if ($scheduledAtRaw === '') {
    $scheduledAt = null;
  } else {
    $ts = strtotime($scheduledAtRaw);

    if (!$ts) {
      return ['ok' => false, 'message' => '預計對戰時間格式錯誤。'];
    }

    $scheduledAt = date('Y-m-d H:i:s', $ts);
  }

  $adminName = currentLoginName();
  if ($adminName === '') {
    $adminName = 'admin';
  }

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      UPDATE tournament_matches
      SET
        scheduled_at = :scheduled_at,
        note = CONCAT(
          COALESCE(note, ''),
          CASE WHEN note IS NULL OR note = '' THEN '' ELSE '\n' END,
          :note
        ),
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :match_id
      LIMIT 1
    ");

    $note = '[' . date('Y-m-d H:i:s') . '] 管理員設定預計對戰時間：' .
      ($scheduledAt ?: '清空') . ' by ' . $adminName;

    $stmt->execute([
      ':scheduled_at' => $scheduledAt,
      ':note' => $note,
      ':match_id' => $matchId,
    ]);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    return ['ok' => false, 'message' => '設定預計時間失敗：' . $e->getMessage()];
  }

  $stmtReload = $pdo->prepare("
    SELECT *
    FROM tournament_matches
    WHERE id = :match_id
    LIMIT 1
  ");

  $stmtReload->execute([
    ':match_id' => $matchId,
  ]);

  $newMatch = $stmtReload->fetch(PDO::FETCH_ASSOC);

  if (!$newMatch) {
    return ['ok' => false, 'message' => '設定完成，但重新讀取場次失敗。'];
  }

  clearTournamentPushLog($pdo, $tournamentId, $matchId, 'match_scheduled');

  $jobResult = upsertTournamentStartSoonJob($pdo, $newMatch, $adminName);

  if ($scheduledAt === null) {
    return [
      'ok' => true,
      'message' => '已清空預計對戰時間。' . '｜' . $jobResult['message'],
    ];
  }

  $pushResult = sendTournamentMatchPush(
    $pdo,
    $matchId,
    'match_scheduled',
    $adminName
  );

  return [
    'ok' => true,
    'message' =>
    '已設定預計對戰時間：' . date('n/j H:i', strtotime($scheduledAt)) .
      '｜' . $jobResult['message'] .
      '｜' . $pushResult['message'],
  ];
}
/* 手動選雙方牌組匯入小局 */
/* ============================ */
/* 讀取單副牌組 */
function getTournamentDeckById(PDO $pdo, int $deckId, int $tournamentId, int $playerId): ?array
{
  if ($deckId <= 0 || $tournamentId <= 0 || $playerId <= 0) {
    return null;
  }

  $stmt = $pdo->prepare("
    SELECT
      d.id,
      d.tournament_id,
      d.player_id,
      d.seed_no,
      d.deck_no,
      d.deck_label,
      d.char1_id,
      d.char2_id,
      d.char3_id,
      c1.cost AS char1_cost,
      c2.cost AS char2_cost,
      c3.cost AS char3_cost
    FROM tournament_player_decks d
    LEFT JOIN unlight c1 ON c1.id = d.char1_id
    LEFT JOIN unlight c2 ON c2.id = d.char2_id
    LEFT JOIN unlight c3 ON c3.id = d.char3_id
    WHERE d.id = :deck_id
      AND d.tournament_id = :tournament_id
      AND d.player_id = :player_id
    LIMIT 1
  ");

  $stmt->execute([
    ':deck_id' => $deckId,
    ':tournament_id' => $tournamentId,
    ':player_id' => $playerId,
  ]);

  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function deckCostFromRow(array $deck): ?int
{
  $costs = [
    $deck['char1_cost'] ?? null,
    $deck['char2_cost'] ?? null,
    $deck['char3_cost'] ?? null,
  ];

  $sum = 0;
  foreach ($costs as $cost) {
    if ($cost === null || $cost === '') {
      return null;
    }
    $sum += (int)$cost;
  }

  return $sum;
}
/* 手動匯入小局 */
function importManualDecksToTournamentGame(PDO $pdo, array $match, array $post, string $importUser = 'manual_import'): array
{
  $matchId = (int)($match['id'] ?? 0);
  $tournamentId = (int)($match['tournament_id'] ?? 1);
  $p1Id = (int)($match['player1_id'] ?? 0);
  $p2Id = (int)($match['player2_id'] ?? 0);

  if ($matchId <= 0 || $p1Id <= 0 || $p2Id <= 0) {
    return ['ok' => false, 'message' => '場次或玩家資料不完整，無法手動匯入。'];
  }

  $p1DeckId = (int)($post['manual_deck']['p1'] ?? 0);
  $p2DeckId = (int)($post['manual_deck']['p2'] ?? 0);

  if ($p1DeckId <= 0 || $p2DeckId <= 0) {
    return ['ok' => false, 'message' => '請選擇雙方出戰牌組。'];
  }

  $p1Deck = getTournamentDeckById($pdo, $p1DeckId, $tournamentId, $p1Id);
  $p2Deck = getTournamentDeckById($pdo, $p2DeckId, $tournamentId, $p2Id);

  if (!$p1Deck || !$p2Deck) {
    return ['ok' => false, 'message' => '找不到指定的玩家牌組，請確認牌組資料。'];
  }

  $manualTimeRaw = trim((string)($post['manual_update_time'] ?? ''));
  $ts = $manualTimeRaw !== '' ? strtotime($manualTimeRaw) : time();

  if (!$ts) {
    return ['ok' => false, 'message' => '對戰時間格式錯誤。'];
  }

  $updateTime = date('Y-m-d H:i:s', $ts);
  $roomId = 'ULGG_MANUAL_' . preg_replace('/[^A-Z0-9_-]/i', '', (string)$match['match_code']) . '_' . date('YmdHis', $ts) . '_' . $p1DeckId . '_' . $p2DeckId;

  $alreadyImported = checkTournamentRoomAlreadyImported($pdo, $tournamentId, $roomId);
  if ($alreadyImported) {
    return ['ok' => false, 'message' => '這筆手動小局已匯入過。'];
  }

  $p1Cost = deckCostFromRow($p1Deck);
  $p2Cost = deckCostFromRow($p2Deck);
  $roomCost = ($p1Cost !== null && $p2Cost !== null && $p1Cost === $p2Cost) ? $p1Cost : null;

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      INSERT INTO arena_unlight (
        room_id,
        region,
        cost,
        stage,
        e1,
        e2,
        e3,
        u1,
        u2,
        u3,
        win,
        lose,
        tie,
        judge_source,
        update_time,
        username,
        name_p1,
        bp_p1,
        win_p1,
        draw_p1,
        lose_p1,
        name_p2,
        bp_p2,
        win_p2,
        draw_p2,
        lose_p2,
        ack1,
        ack2,
        is_read
      ) VALUES (
        :room_id,
        'MATCH',
        :cost,
        NULL,
        :e1,
        :e2,
        :e3,
        :u1,
        :u2,
        :u3,
        0,
        0,
        0,
        'ULGG_MANUAL_DECK',
        :update_time,
        :username,
        :name_p1,
        0,
        0,
        0,
        0,
        :name_p2,
        0,
        0,
        0,
        0,
        0,
        0,
        0
      )
    ");

    $stmt->execute([
      ':room_id' => $roomId,
      ':cost' => $roomCost,
      ':e1' => (int)$p1Deck['char1_id'],
      ':e2' => (int)$p1Deck['char2_id'],
      ':e3' => (int)$p1Deck['char3_id'],
      ':u1' => (int)$p2Deck['char1_id'],
      ':u2' => (int)$p2Deck['char2_id'],
      ':u3' => (int)$p2Deck['char3_id'],
      ':update_time' => $updateTime,
      ':username' => $importUser,
      ':name_p1' => (string)($match['player1_game_name'] ?? ''),
      ':name_p2' => (string)($match['player2_game_name'] ?? ''),
    ]);

    $arenaId = (int)$pdo->lastInsertId();

    $pdo->commit();

    $linkResult = importArenaToTournamentMatchGame(
      $pdo,
      $match,
      $arenaId,
      $roomId,
      null,
      $p1Cost,
      $p2Cost,
      (string)$p1Deck['deck_label'],
      (string)$p2Deck['deck_label']
    );

    if (!$linkResult['ok']) {
      return ['ok' => false, 'message' => '已建立一般對戰DB，但連結賽事小局失敗：' . $linkResult['error']];
    }

    $renumberResult = renumberMatchGamesByTime($pdo, $matchId);

    if (!$renumberResult['ok']) {
      return ['ok' => true, 'message' => '已手動匯入小局，但重新排序失敗：' . $renumberResult['error']];
    }

    return ['ok' => true, 'message' => '已從雙方牌組手動匯入賽事小局，請再由管理員判定勝負。'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    return ['ok' => false, 'message' => '手動匯入小局失敗：' . $e->getMessage()];
  }
}
/* 權限 helper */
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
  /*
   * ULGG 權限規則：
   * game_user.ack
   * 0 = guest / unverified
   * 1 = verified user
   * 2 = admin
   * 3 = super admin
   */

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

  $role = strtolower(trim((string)(
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? ''
  )));

  if (in_array($role, ['admin', 'administrator', 'staff', 'mod', 'moderator', 'tournament_admin'], true)) {
    return true;
  }

  $permission = strtolower(trim((string)$permissionRaw));

  if (in_array($permission, ['admin', 'staff', 'tournament_admin'], true)) {
    return true;
  }

  if (!empty($_SESSION['is_admin'])) {
    return true;
  }

  return false;
}

function canViewTournamentGameDetail(PDO $pdo, array $match): bool
{
  if (isTournamentAdmin()) {
    return true;
  }

  $loginName = normalizePlayerName(currentLoginName());

  if ($loginName === '') {
    return false;
  }

  $p1 = normalizePlayerName((string)($match['player1_game_name'] ?? ''));
  $p2 = normalizePlayerName((string)($match['player2_game_name'] ?? ''));

  return $loginName === $p1 || $loginName === $p2;
}
$isAdmin = isTournamentAdmin();
if (isset($_GET['debug_admin']) && $_GET['debug_admin'] === '1') {
  echo '<pre style="background:#111;color:#0f0;padding:12px;z-index:9999;position:relative;">';
  echo '$isAdmin = ';
  var_dump($isAdmin);

  echo PHP_EOL . '$_SESSION = ' . PHP_EOL;
  print_r($_SESSION);

  echo '</pre>';
}
/* 自動重編 function */
function renumberMatchGamesByTime(PDO $pdo, int $matchId): array
{
  if ($matchId <= 0) {
    return [
      'ok' => false,
      'error' => 'match_id 無效'
    ];
  }

  try {
    $pdo->beginTransaction();

    // 先依時間抓出所有小局
    $stmtList = $pdo->prepare("
      SELECT
        g.id
      FROM tournament_match_games g
      LEFT JOIN arena_unlight a
        ON a.id = g.arena_id
      WHERE g.match_id = :match_id
      ORDER BY
        a.update_time ASC,
        g.id ASC
    ");
    $stmtList->execute([
      ':match_id' => $matchId
    ]);

    $ids = $stmtList->fetchAll(PDO::FETCH_COLUMN);

    if (!$ids) {
      $pdo->commit();

      return [
        'ok' => true,
        'error' => '',
        'count' => 0
      ];
    }

    // 第一步：先全部改成負數，避免 UNIQUE(match_id, game_no) 撞號
    $stmtTemp = $pdo->prepare("
      UPDATE tournament_match_games
      SET
        game_no = :temp_game_no,
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
      LIMIT 1
    ");

    $tempNo = -1;

    foreach ($ids as $id) {
      $stmtTemp->execute([
        ':temp_game_no' => $tempNo,
        ':id' => (int)$id
      ]);

      $tempNo--;
    }

    // 第二步：依時間重新編成 Game 1, 2, 3...
    $stmtUpdate = $pdo->prepare("
      UPDATE tournament_match_games
      SET
        game_no = :game_no,
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
      LIMIT 1
    ");

    $gameNo = 1;

    foreach ($ids as $id) {
      $stmtUpdate->execute([
        ':game_no' => $gameNo,
        ':id' => (int)$id
      ]);

      $gameNo++;
    }

    $pdo->commit();

    return [
      'ok' => true,
      'error' => '',
      'count' => count($ids)
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    return [
      'ok' => false,
      'error' => $e->getMessage()
    ];
  }
}
/* 自動判斷前一場 */
function getRoom2StatsByTournamentSides(array $room, array $match): array
{
  $sideMap = mapRoom2ToTournamentSides($room, $match);

  $leftName = normalizePlayerName((string)($sideMap['left']['name'] ?? ''));
  $rightName = normalizePlayerName((string)($sideMap['right']['name'] ?? ''));

  $playerAName = normalizePlayerName((string)($room['playerA']['name'] ?? ''));
  $playerBName = normalizePlayerName((string)($room['playerB']['name'] ?? ''));

  $leftSource = null;
  $rightSource = null;

  if ($leftName === $playerAName) {
    $leftSource = $room['playerA'] ?? [];
  } elseif ($leftName === $playerBName) {
    $leftSource = $room['playerB'] ?? [];
  }

  if ($rightName === $playerAName) {
    $rightSource = $room['playerA'] ?? [];
  } elseif ($rightName === $playerBName) {
    $rightSource = $room['playerB'] ?? [];
  }

  return [
    'left' => [
      'name' => $sideMap['left']['name'] ?? '',
      'win' => (int)($leftSource['win'] ?? 0),
      'draw' => (int)($leftSource['draw'] ?? 0),
      'lose' => (int)($leftSource['lose'] ?? 0),
    ],
    'right' => [
      'name' => $sideMap['right']['name'] ?? '',
      'win' => (int)($rightSource['win'] ?? 0),
      'draw' => (int)($rightSource['draw'] ?? 0),
      'lose' => (int)($rightSource['lose'] ?? 0),
    ],
  ];
}
function getLastImportedMatchGame(PDO $pdo, int $matchId): ?array
{
  $stmt = $pdo->prepare("
    SELECT
      g.id AS game_id,
      g.game_no,
      g.match_id,
      g.player1_id,
      g.player2_id,
      g.winner_player_id,
      g.result_status,

      a.id AS arena_id,
      a.room_id,
      a.name_p1,
      a.name_p2,
      a.win_p1,
      a.draw_p1,
      a.lose_p1,
      a.win_p2,
      a.draw_p2,
      a.lose_p2,
      a.win,
      a.lose,
      a.tie,
      a.update_time
    FROM tournament_match_games g
    INNER JOIN arena_unlight a
      ON a.id = g.arena_id
    WHERE g.match_id = :match_id
    ORDER BY
      g.game_no DESC,
      g.id DESC
    LIMIT 1
  ");

  $stmt->execute([
    ':match_id' => $matchId
  ]);

  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
}
function inferAndUpdatePreviousGameResult(
  PDO $pdo,
  array $match,
  array $currentRoom
): array {
  $matchId = (int)($match['id'] ?? 0);

  if ($matchId <= 0) {
    return [
      'ok' => false,
      'updated' => false,
      'message' => 'match_id 無效'
    ];
  }

  $prev = getLastImportedMatchGame($pdo, $matchId);

  if (!$prev) {
    return [
      'ok' => true,
      'updated' => false,
      'message' => '目前沒有上一場可判斷'
    ];
  }

  // 如果上一場已經有勝者或已經是平手，就不要重複判斷
  if (!empty($prev['winner_player_id']) || (int)$prev['tie'] === 1 || $prev['result_status'] === 'verified') {
    return [
      'ok' => true,
      'updated' => false,
      'message' => '上一場已有結果，不重複判斷'
    ];
  }

  $currentStats = getRoom2StatsByTournamentSides($currentRoom, $match);

  $matchP1Name = normalizePlayerName((string)($match['player1_game_name'] ?? ''));
  $matchP2Name = normalizePlayerName((string)($match['player2_game_name'] ?? ''));

  $arenaP1Name = normalizePlayerName((string)($prev['name_p1'] ?? ''));
  $arenaP2Name = normalizePlayerName((string)($prev['name_p2'] ?? ''));

  /*
   * 取得上一場匯入當下，賽事左邊/右邊玩家的累積戰績。
   * 因為 arena_unlight 的 p1/p2 不一定等於賽事左右，所以要對名字。
   */
  if ($arenaP1Name === $matchP1Name) {
    $prevLeft = [
      'win' => (int)$prev['win_p1'],
      'draw' => (int)$prev['draw_p1'],
      'lose' => (int)$prev['lose_p1'],
      'raw_side' => 'p1',
    ];
    $prevRight = [
      'win' => (int)$prev['win_p2'],
      'draw' => (int)$prev['draw_p2'],
      'lose' => (int)$prev['lose_p2'],
      'raw_side' => 'p2',
    ];
  } else {
    $prevLeft = [
      'win' => (int)$prev['win_p2'],
      'draw' => (int)$prev['draw_p2'],
      'lose' => (int)$prev['lose_p2'],
      'raw_side' => 'p2',
    ];
    $prevRight = [
      'win' => (int)$prev['win_p1'],
      'draw' => (int)$prev['draw_p1'],
      'lose' => (int)$prev['lose_p1'],
      'raw_side' => 'p1',
    ];
  }

  $leftWinDiff = $currentStats['left']['win'] - $prevLeft['win'];
  $leftDrawDiff = $currentStats['left']['draw'] - $prevLeft['draw'];
  $leftLoseDiff = $currentStats['left']['lose'] - $prevLeft['lose'];

  $rightWinDiff = $currentStats['right']['win'] - $prevRight['win'];
  $rightDrawDiff = $currentStats['right']['draw'] - $prevRight['draw'];
  $rightLoseDiff = $currentStats['right']['lose'] - $prevRight['lose'];

  $winnerPlayerId = null;
  $isTie = false;

  // 賽事左邊玩家勝：左 win +1，右 lose +1
  if ($leftWinDiff === 1 && $rightLoseDiff === 1) {
    $winnerPlayerId = (int)$match['player1_id'];
  }

  // 賽事右邊玩家勝：右 win +1，左 lose +1
  if ($rightWinDiff === 1 && $leftLoseDiff === 1) {
    $winnerPlayerId = (int)$match['player2_id'];
  }

  // 平手：雙方 draw +1
  if ($leftDrawDiff === 1 && $rightDrawDiff === 1) {
    $isTie = true;
  }

  if (!$winnerPlayerId && !$isTie) {
    return [
      'ok' => true,
      'updated' => false,
      'message' =>
      '無法由累積戰績判斷上一場結果。' .
        ' 左側變化 W/D/L=' . $leftWinDiff . '/' . $leftDrawDiff . '/' . $leftLoseDiff .
        '，右側變化 W/D/L=' . $rightWinDiff . '/' . $rightDrawDiff . '/' . $rightLoseDiff
    ];
  }

  /*
   * 更新 arena_unlight.win / lose / tie
   * arena 規則：
   * win = 1 代表 arena P2 勝
   * lose = 1 代表 arena P1 勝
   */
  $arenaWin = 0;
  $arenaLose = 0;
  $arenaTie = 0;

  if ($isTie) {
    $arenaTie = 1;
  } else {
    $winnerName = '';

    if ($winnerPlayerId === (int)$match['player1_id']) {
      $winnerName = normalizePlayerName((string)($match['player1_game_name'] ?? ''));
    } elseif ($winnerPlayerId === (int)$match['player2_id']) {
      $winnerName = normalizePlayerName((string)($match['player2_game_name'] ?? ''));
    }

    if ($winnerName === $arenaP1Name) {
      $arenaLose = 1; // arena P1 勝
    } elseif ($winnerName === $arenaP2Name) {
      $arenaWin = 1; // arena P2 勝
    }
  }

  $pdo->beginTransaction();

  try {
    $stmtArena = $pdo->prepare("
      UPDATE arena_unlight
      SET
        win = :win,
        lose = :lose,
        tie = :tie
      WHERE id = :arena_id
      LIMIT 1
    ");

    $stmtArena->execute([
      ':win' => $arenaWin,
      ':lose' => $arenaLose,
      ':tie' => $arenaTie,
      ':arena_id' => (int)$prev['arena_id'],
    ]);

    $stmtGame = $pdo->prepare("
      UPDATE tournament_match_games
      SET
        winner_player_id = :winner_player_id,
        result_status = :result_status,
        note = CONCAT(
          COALESCE(note, ''),
          CASE WHEN note IS NULL OR note = '' THEN '' ELSE '\n' END,
          :note
        ),
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :game_id
      LIMIT 1
    ");

    $stmtGame->execute([
      ':winner_player_id' => $isTie ? null : $winnerPlayerId,
      ':result_status' => $isTie ? 'tie' : 'verified',
      ':note' => '系統於匯入下一場時，依雙方累積 W/D/L 自動判斷結果',
      ':game_id' => (int)$prev['game_id'],
    ]);

    $pdo->commit();

    return [
      'ok' => true,
      'updated' => true,
      'message' => $isTie
        ? '已自動判斷上一場為平手'
        : '已自動判斷上一場勝者'
    ];
  } catch (Throwable $e) {
    $pdo->rollBack();

    return [
      'ok' => false,
      'updated' => false,
      'message' => '更新上一場結果失敗：' . $e->getMessage()
    ];
  }
}
function getImportedRoomIds(PDO $pdo, int $tournamentId): array
{
  $stmt = $pdo->prepare("
    SELECT room_id
    FROM tournament_match_games
    WHERE tournament_id = :tournament_id
      AND room_id IS NOT NULL
      AND room_id <> ''
  ");
  $stmt->execute([
    ':tournament_id' => $tournamentId
  ]);

  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

  $map = [];
  foreach ($rows as $roomId) {
    $map[(string)$roomId] = true;
  }

  return $map;
}
function checkTournamentRoomAlreadyImported(PDO $pdo, int $tournamentId, string $roomId): ?array
{
  $stmt = $pdo->prepare("
    SELECT
      g.id,
      g.match_id,
      g.game_no,
      g.room_id,
      m.match_code
    FROM tournament_match_games g
    LEFT JOIN tournament_matches m
      ON m.id = g.match_id
    WHERE g.tournament_id = :tournament_id
      AND g.room_id = :room_id
    LIMIT 1
  ");
  $stmt->execute([
    ':tournament_id' => $tournamentId,
    ':room_id' => $roomId
  ]);

  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
}

function normalizePlayerName(string $name): string
{
  $name = trim($name);
  $name = preg_replace('/[\s　]+/u', '', $name);
  return $name;
}

function mapArenaRowToTournamentSides(array $row, array $match): array
{
  $tP1 = normalizePlayerName((string)($match['player1_game_name'] ?? ''));
  $tP2 = normalizePlayerName((string)($match['player2_game_name'] ?? ''));

  $aP1 = normalizePlayerName((string)($row['name_p1'] ?? ''));
  $aP2 = normalizePlayerName((string)($row['name_p2'] ?? ''));

  $leftPrefix = 'p1';
  $rightPrefix = 'p2';

  // arena 的 P1 剛好是賽事右邊玩家，代表畫面要左右對調
  if ($aP1 === $tP2 && $aP2 === $tP1) {
    $leftPrefix = 'p2';
    $rightPrefix = 'p1';
  }

  return [
    'left' => [
      'name' => $row['name_' . $leftPrefix] ?? '',
      'bp' => $row['bp_' . $leftPrefix] ?? '',
      'chars' => $leftPrefix === 'p1'
        ? [(int)($row['e1'] ?? 0), (int)($row['e2'] ?? 0), (int)($row['e3'] ?? 0)]
        : [(int)($row['u1'] ?? 0), (int)($row['u2'] ?? 0), (int)($row['u3'] ?? 0)],

      // 注意：這裡固定用 tournament_match_games 的 player1，代表賽事左邊玩家
      'deck_cost' => $row['player1_deck_cost'] ?? null,
      'deck_code' => $row['player1_deck_code'] ?? null,
    ],

    'right' => [
      'name' => $row['name_' . $rightPrefix] ?? '',
      'bp' => $row['bp_' . $rightPrefix] ?? '',
      'chars' => $rightPrefix === 'p1'
        ? [(int)($row['e1'] ?? 0), (int)($row['e2'] ?? 0), (int)($row['e3'] ?? 0)]
        : [(int)($row['u1'] ?? 0), (int)($row['u2'] ?? 0), (int)($row['u3'] ?? 0)],

      // 注意：這裡固定用 tournament_match_games 的 player2，代表賽事右邊玩家
      'deck_cost' => $row['player2_deck_cost'] ?? null,
      'deck_code' => $row['player2_deck_code'] ?? null,
    ],
  ];
}

function mapRoom2ToTournamentSides(array $room, array $match): array
{
  $tP1 = normalizePlayerName((string)($match['player1_game_name'] ?? ''));
  $tP2 = normalizePlayerName((string)($match['player2_game_name'] ?? ''));

  $aNameRaw = (string)($room['playerA']['name'] ?? '');
  $bNameRaw = (string)($room['playerB']['name'] ?? '');

  $aName = normalizePlayerName($aNameRaw);
  $bName = normalizePlayerName($bNameRaw);

  $leftKey = 'A';
  $rightKey = 'B';

  // playerA 是賽事右邊玩家，則左右對調
  if ($aName === $tP2 && $bName === $tP1) {
    $leftKey = 'B';
    $rightKey = 'A';
  }

  $leftPlayer = $room['player' . $leftKey] ?? [];
  $rightPlayer = $room['player' . $rightKey] ?? [];

  $leftDeck = $room['deck' . $leftKey] ?? [];
  $rightDeck = $room['deck' . $rightKey] ?? [];

  return [
    'left' => [
      'name' => $leftPlayer['name'] ?? '',
      'bp' => $leftPlayer['bp'] ?? '',
      'ready' => (int)($room['ready' . $leftKey] ?? 0),
      'deck_cost' => $leftDeck['cost'] ?? null,
      'chars' => [
        isset($leftDeck['charaIndex'][0]) ? room2CharaIndexToUnlightId($leftDeck['charaIndex'][0]) : 0,
        isset($leftDeck['charaIndex'][1]) ? room2CharaIndexToUnlightId($leftDeck['charaIndex'][1]) : 0,
        isset($leftDeck['charaIndex'][2]) ? room2CharaIndexToUnlightId($leftDeck['charaIndex'][2]) : 0,
      ],
    ],
    'right' => [
      'name' => $rightPlayer['name'] ?? '',
      'bp' => $rightPlayer['bp'] ?? '',
      'ready' => (int)($room['ready' . $rightKey] ?? 0),
      'deck_cost' => $rightDeck['cost'] ?? null,
      'chars' => [
        isset($rightDeck['charaIndex'][0]) ? room2CharaIndexToUnlightId($rightDeck['charaIndex'][0]) : 0,
        isset($rightDeck['charaIndex'][1]) ? room2CharaIndexToUnlightId($rightDeck['charaIndex'][1]) : 0,
        isset($rightDeck['charaIndex'][2]) ? room2CharaIndexToUnlightId($rightDeck['charaIndex'][2]) : 0,
      ],
    ],
  ];
}

function scanRoom2Objects(string $path, callable $callback): array
{
  if (!file_exists($path)) {
    return [
      'ok' => false,
      'error' => '找不到 channel2_room.json：' . $path,
    ];
  }

  if (!is_readable($path)) {
    return [
      'ok' => false,
      'error' => 'channel2_room.json 無法讀取，請檢查權限：' . $path,
    ];
  }

  $fh = fopen($path, 'rb');
  if (!$fh) {
    return [
      'ok' => false,
      'error' => '無法開啟 channel2_room.json：' . $path,
    ];
  }

  $depth = 0;
  $inString = false;
  $escape = false;
  $capturing = false;
  $buffer = '';

  while (!feof($fh)) {
    $chunk = fread($fh, 8192);
    if ($chunk === false || $chunk === '') {
      continue;
    }

    $len = strlen($chunk);

    for ($i = 0; $i < $len; $i++) {
      $ch = $chunk[$i];

      if ($inString) {
        if ($capturing) {
          $buffer .= $ch;
        }

        if ($escape) {
          $escape = false;
        } elseif ($ch === '\\') {
          $escape = true;
        } elseif ($ch === '"') {
          $inString = false;
        }

        continue;
      }

      if ($ch === '"') {
        if ($capturing) {
          $buffer .= $ch;
        }
        $inString = true;
        continue;
      }

      if ($ch === '{') {
        $depth++;

        // depth = 1 是最外層 object
        // depth = 2 才是每一個 room object
        if ($depth === 2) {
          $capturing = true;
          $buffer = '{';
          continue;
        }

        if ($capturing) {
          $buffer .= $ch;
        }

        continue;
      }

      if ($ch === '}') {
        if ($capturing) {
          $buffer .= $ch;
        }

        // room object 結束
        if ($depth === 2 && $capturing) {
          $room = json_decode($buffer, true);

          if (is_array($room)) {
            $stop = $callback($room);
            if ($stop === true) {
              fclose($fh);
              return [
                'ok' => true,
                'error' => '',
              ];
            }
          }

          $capturing = false;
          $buffer = '';
        }

        $depth--;
        continue;
      }

      if ($capturing) {
        $buffer .= $ch;
      }
    }
  }

  fclose($fh);

  return [
    'ok' => true,
    'error' => '',
  ];
}

function findRoom2CandidatesFromFile(string $path, string $p1GameName, string $p2GameName, int $limit = 50): array
{
  $candidates = [];

  $result = scanRoom2Objects($path, function ($room) use (&$candidates, $p1GameName, $p2GameName, $limit) {
    if (room2SamePlayers($room, $p1GameName, $p2GameName)) {
      $candidates[] = $room;
    }

    return count($candidates) >= $limit;
  });

  if (!$result['ok']) {
    return [
      'ok' => false,
      'error' => $result['error'],
      'rooms' => [],
    ];
  }

  usort($candidates, function ($a, $b) {
    return ((int)($b['date'] ?? 0)) <=> ((int)($a['date'] ?? 0));
  });

  return [
    'ok' => true,
    'error' => '',
    'rooms' => $candidates,
  ];
}

function getRoom2ByRoomIdFromFile(string $path, string $roomId): array
{
  $found = null;

  $result = scanRoom2Objects($path, function ($room) use (&$found, $roomId) {
    if ((string)($room['room_id'] ?? '') === $roomId) {
      $found = $room;
      return true;
    }

    return false;
  });

  if (!$result['ok']) {
    return [
      'ok' => false,
      'error' => $result['error'],
      'room' => null,
    ];
  }

  return [
    'ok' => true,
    'error' => '',
    'room' => $found,
  ];
}

function room2DateTime($ms): string
{
  if (!$ms) return date('Y-m-d H:i:s');

  $sec = (int)floor(((int)$ms) / 1000);
  if ($sec <= 0) return date('Y-m-d H:i:s');

  return date('Y-m-d H:i:s', $sec);
}

function room2SamePlayers(array $room, string $p1, string $p2): bool
{
  $a = normalizePlayerName((string)($room['playerA']['name'] ?? ''));
  $b = normalizePlayerName((string)($room['playerB']['name'] ?? ''));

  $p1 = normalizePlayerName($p1);
  $p2 = normalizePlayerName($p2);

  return (
    ($a === $p1 && $b === $p2) ||
    ($a === $p2 && $b === $p1)
  );
}





function jsonForDb($value): string
{
  return json_encode($value ?? [], JSON_UNESCAPED_UNICODE);
}
function room2CharaIndexToUnlightId($value): ?int
{
  if ($value === null || $value === '') {
    return null;
  }

  return ((int)$value) + 1;
}
function importRoom2ToArena(PDO $pdo, array $room, string $importUser = 'tournament_import'): array
{
  $roomId = (string)($room['room_id'] ?? '');
  if ($roomId === '') {
    return ['ok' => false, 'error' => 'room_id 為空'];
  }

  $playerA = $room['playerA'] ?? [];
  $playerB = $room['playerB'] ?? [];
  $deckA   = $room['deckA'] ?? [];
  $deckB   = $room['deckB'] ?? [];

  $aName = trim((string)($playerA['name'] ?? ''));
  $bName = trim((string)($playerB['name'] ?? ''));

  if ($aName === '' || $bName === '') {
    return ['ok' => false, 'error' => 'playerA 或 playerB 名稱為空'];
  }

  $deckAIndex = $deckA['charaIndex'] ?? [];
  $deckBIndex = $deckB['charaIndex'] ?? [];

  $e1 = isset($deckAIndex[0]) ? room2CharaIndexToUnlightId($deckAIndex[0]) : null;
  $e2 = isset($deckAIndex[1]) ? room2CharaIndexToUnlightId($deckAIndex[1]) : null;
  $e3 = isset($deckAIndex[2]) ? room2CharaIndexToUnlightId($deckAIndex[2]) : null;

  $u1 = isset($deckBIndex[0]) ? room2CharaIndexToUnlightId($deckBIndex[0]) : null;
  $u2 = isset($deckBIndex[1]) ? room2CharaIndexToUnlightId($deckBIndex[1]) : null;
  $u3 = isset($deckBIndex[2]) ? room2CharaIndexToUnlightId($deckBIndex[2]) : null;

  $weaponA = $deckA['weapon'] ?? [];
  $weaponB = $deckB['weapon'] ?? [];

  $w1 = isset($weaponA[0]) && $weaponA[0] !== null ? (int)$weaponA[0] : null;
  $w2 = isset($weaponA[1]) && $weaponA[1] !== null ? (int)$weaponA[1] : null;
  $w3 = isset($weaponA[2]) && $weaponA[2] !== null ? (int)$weaponA[2] : null;

  $v1 = isset($weaponB[0]) && $weaponB[0] !== null ? (int)$weaponB[0] : null;
  $v2 = isset($weaponB[1]) && $weaponB[1] !== null ? (int)$weaponB[1] : null;
  $v3 = isset($weaponB[2]) && $weaponB[2] !== null ? (int)$weaponB[2] : null;

  $roomCost = $room['cost'] ?? null;

  $deckACost = isset($deckA['cost']) ? (int)$deckA['cost'] : null;
  $deckBCost = isset($deckB['cost']) ? (int)$deckB['cost'] : null;

  // arena_unlight.cost 代表「房間 COST 規則」，不是雙方牌組 COST 相加
  if ($roomCost !== null && $roomCost !== '') {
    $cost = (int)$roomCost;
  } elseif ($deckACost !== null && $deckBCost !== null && $deckACost === $deckBCost) {
    // 若 room.cost 為空，但雙方實際 cost 一樣，可暫時視為此房 COST
    $cost = $deckACost;
  } else {
    // 雙方 cost 不同，不能硬塞 arena_unlight.cost
    $cost = null;
  }

  $stage = isset($room['stage']) ? (int)$room['stage'] : null;

  //$region = (string)($room['region'] ?? '');
  // ULGG 杯匯入 arena_unlight 時，region 固定寫 MATCH
  $region = 'MATCH';
  $updateTime = room2DateTime($room['date'] ?? null);

  $sql = "
    INSERT INTO arena_unlight
    (
      room_id,
      region,
      cost,
      stage,

      e1, e2, e3,
      u1, u2, u3,

      win,
      lose,
      tie,
      judge_source,
      update_time,
      username,

      name_p1,
      bp_p1,
      win_p1,
      draw_p1,
      lose_p1,

      name_p2,
      bp_p2,
      win_p2,
      draw_p2,
      lose_p2,

      ack1,
      ack2,
      is_read,

      w1,
      w2,
      w3,
      eventindex1,

      v1,
      v2,
      v3,
      eventindex2
    )
    VALUES
    (
      :room_id,
      :region,
      :cost,
      :stage,

      :e1, :e2, :e3,
      :u1, :u2, :u3,

      0,
      0,
      0,
      :judge_source,
      :update_time,
      :username,

      :name_p1,
      :bp_p1,
      :win_p1,
      :draw_p1,
      :lose_p1,

      :name_p2,
      :bp_p2,
      :win_p2,
      :draw_p2,
      :lose_p2,

      :ack1,
      :ack2,
      0,

      :w1,
      :w2,
      :w3,
      :eventindex1,

      :v1,
      :v2,
      :v3,
      :eventindex2
    )
    ON DUPLICATE KEY UPDATE
      region = VALUES(region),
      cost = VALUES(cost),
      stage = VALUES(stage),

      e1 = VALUES(e1),
      e2 = VALUES(e2),
      e3 = VALUES(e3),
      u1 = VALUES(u1),
      u2 = VALUES(u2),
      u3 = VALUES(u3),

      update_time = VALUES(update_time),
      username = VALUES(username),

      name_p1 = VALUES(name_p1),
      bp_p1 = VALUES(bp_p1),
      win_p1 = VALUES(win_p1),
      draw_p1 = VALUES(draw_p1),
      lose_p1 = VALUES(lose_p1),

      name_p2 = VALUES(name_p2),
      bp_p2 = VALUES(bp_p2),
      win_p2 = VALUES(win_p2),
      draw_p2 = VALUES(draw_p2),
      lose_p2 = VALUES(lose_p2),

      ack1 = VALUES(ack1),
      ack2 = VALUES(ack2),

      w1 = VALUES(w1),
      w2 = VALUES(w2),
      w3 = VALUES(w3),
      eventindex1 = VALUES(eventindex1),

      v1 = VALUES(v1),
      v2 = VALUES(v2),
      v3 = VALUES(v3),
      eventindex2 = VALUES(eventindex2)
  ";

  $params = [
    ':room_id' => $roomId,
    ':region' => $region,
    ':cost' => $cost,
    ':stage' => $stage,

    ':e1' => $e1,
    ':e2' => $e2,
    ':e3' => $e3,
    ':u1' => $u1,
    ':u2' => $u2,
    ':u3' => $u3,

    ':judge_source' => 'ULGG_MATCH_ROOM2',
    ':update_time' => $updateTime,
    ':username' => $importUser,

    ':name_p1' => $aName,
    ':bp_p1' => (int)($playerA['bp'] ?? 0),
    ':win_p1' => (int)($playerA['win'] ?? 0),
    ':draw_p1' => (int)($playerA['draw'] ?? 0),
    ':lose_p1' => (int)($playerA['lose'] ?? 0),

    ':name_p2' => $bName,
    ':bp_p2' => (int)($playerB['bp'] ?? 0),
    ':win_p2' => (int)($playerB['win'] ?? 0),
    ':draw_p2' => (int)($playerB['draw'] ?? 0),
    ':lose_p2' => (int)($playerB['lose'] ?? 0),

    ':ack1' => 0,
    ':ack2' => 0,

    ':w1' => $w1,
    ':w2' => $w2,
    ':w3' => $w3,
    ':eventindex1' => jsonForDb($deckA['eventIndex'] ?? []),

    ':v1' => $v1,
    ':v2' => $v2,
    ':v3' => $v3,
    ':eventindex2' => jsonForDb($deckB['eventIndex'] ?? []),
  ];

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  // 重新查 arena_unlight.id，因為 ON DUPLICATE KEY UPDATE 時 lastInsertId 不一定可靠
  $stmtFind = $pdo->prepare("
  SELECT id
  FROM arena_unlight
  WHERE room_id = :room_id
  LIMIT 1
");
  $stmtFind->execute([
    ':room_id' => $roomId
  ]);
  $arenaId = (int)$stmtFind->fetchColumn();

  return [
    'ok' => true,
    'error' => '',
    'room_id' => $roomId,
    'arena_id' => $arenaId
  ];
}

function importArenaToTournamentMatchGame(
  PDO $pdo,
  array $match,
  int $arenaId,
  string $roomId,
  ?int $gameNo = null,
  ?int $player1DeckCost = null,
  ?int $player2DeckCost = null,
  ?string $player1DeckCode = null,
  ?string $player2DeckCode = null
): array {

  $matchId = (int)($match['id'] ?? 0);
  $tournamentId = (int)($match['tournament_id'] ?? 1);

  $existing = checkTournamentRoomAlreadyImported($pdo, $tournamentId, $roomId);

  if ($existing) {
    return [
      'ok' => false,
      'error' => '此候選對戰已匯入過：' . ($existing['match_code'] ?? '') . ' / Game ' . ($existing['game_no'] ?? '')
    ];
  }

  $player1Id = isset($match['player1_id']) ? (int)$match['player1_id'] : null;
  $player2Id = isset($match['player2_id']) ? (int)$match['player2_id'] : null;

  if ($matchId <= 0) {
    return [
      'ok' => false,
      'error' => 'match_id 無效'
    ];
  }

  if ($arenaId <= 0 || trim($roomId) === '') {
    return [
      'ok' => false,
      'error' => 'arena_id 或 room_id 無效'
    ];
  }

  // 牌組代號只允許 A/B/C/null
  $player1DeckCode = strtoupper(trim((string)$player1DeckCode));
  $player2DeckCode = strtoupper(trim((string)$player2DeckCode));

  if (!in_array($player1DeckCode, ['A', 'B', 'C'], true)) {
    $player1DeckCode = null;
  }

  if (!in_array($player2DeckCode, ['A', 'B', 'C'], true)) {
    $player2DeckCode = null;
  }

  // 如果沒有指定 game_no，自動找此 match 下一局
  if ($gameNo === null || $gameNo <= 0) {
    $stmtNo = $pdo->prepare("
      SELECT COALESCE(MAX(game_no), 0) + 1
      FROM tournament_match_games
      WHERE match_id = :match_id
    ");
    $stmtNo->execute([
      ':match_id' => $matchId
    ]);

    $gameNo = (int)$stmtNo->fetchColumn();
  }

  $sql = "
    INSERT INTO tournament_match_games
    (
      tournament_id,
      match_id,
      game_no,

      arena_id,
      room_id,

      player1_id,
      player2_id,

      player1_deck_code,
      player1_deck_cost,
      player2_deck_code,
      player2_deck_cost,

      winner_player_id,
      result_status,
      note
    )
    VALUES
    (
      :tournament_id,
      :match_id,
      :game_no,

      :arena_id,
      :room_id,

      :player1_id,
      :player2_id,

      :player1_deck_code,
      :player1_deck_cost,
      :player2_deck_code,
      :player2_deck_cost,

      NULL,
      'pending',
      :note
    )
    ON DUPLICATE KEY UPDATE
      arena_id = VALUES(arena_id),
      room_id = VALUES(room_id),

      player1_id = VALUES(player1_id),
      player2_id = VALUES(player2_id),

      player1_deck_code = VALUES(player1_deck_code),
      player1_deck_cost = VALUES(player1_deck_cost),
      player2_deck_code = VALUES(player2_deck_code),
      player2_deck_cost = VALUES(player2_deck_cost),

      result_status = VALUES(result_status),
      note = VALUES(note),
      updated_at = CURRENT_TIMESTAMP
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':tournament_id' => $tournamentId,
    ':match_id' => $matchId,
    ':game_no' => $gameNo,

    ':arena_id' => $arenaId,
    ':room_id' => $roomId,

    ':player1_id' => $player1Id,
    ':player2_id' => $player2Id,

    ':player1_deck_code' => $player1DeckCode,
    ':player1_deck_cost' => $player1DeckCost,
    ':player2_deck_code' => $player2DeckCode,
    ':player2_deck_cost' => $player2DeckCost,

    ':note' => '由 ULGG 杯場次頁從 room2 匯入並連結 arena_unlight'
  ]);

  // 重新查回此筆 game id
  $stmtFind = $pdo->prepare("
    SELECT id
    FROM tournament_match_games
    WHERE room_id = :room_id
    LIMIT 1
  ");
  $stmtFind->execute([
    ':room_id' => $roomId
  ]);

  $gameId = (int)$stmtFind->fetchColumn();

  return [
    'ok' => true,
    'error' => '',
    'game_id' => $gameId,
    'game_no' => $gameNo,
    'room_id' => $roomId,
    'arena_id' => $arenaId,
    'player1_deck_cost' => $player1DeckCost,
    'player2_deck_cost' => $player2DeckCost
  ];
}

function updateArenaResultForTournamentGame(
  PDO $pdo,
  int $arenaId,
  array $match,
  ?int $winnerPlayerId,
  string $resultStatus
): void {
  if ($arenaId <= 0) {
    return;
  }

  $arenaWin = 0;
  $arenaLose = 0;
  $arenaTie = 0;

  if ($resultStatus === 'tie') {
    $arenaTie = 1;
  }

  if ($resultStatus === 'verified' && $winnerPlayerId) {
    $stmtArena = $pdo->prepare("
      SELECT
        name_p1,
        name_p2
      FROM arena_unlight
      WHERE id = :arena_id
      LIMIT 1
    ");
    $stmtArena->execute([
      ':arena_id' => $arenaId,
    ]);

    $arena = $stmtArena->fetch(PDO::FETCH_ASSOC);

    if ($arena) {
      $arenaP1Name = normalizePlayerName((string)($arena['name_p1'] ?? ''));
      $arenaP2Name = normalizePlayerName((string)($arena['name_p2'] ?? ''));

      $winnerName = '';

      if ($winnerPlayerId === (int)$match['player1_id']) {
        $winnerName = normalizePlayerName((string)($match['player1_game_name'] ?? ''));
      } elseif ($winnerPlayerId === (int)$match['player2_id']) {
        $winnerName = normalizePlayerName((string)($match['player2_game_name'] ?? ''));
      }

      /*
       * arena_unlight 規則：
       * win = 1 代表 arena P2 勝
       * lose = 1 代表 arena P1 勝
       */
      if ($winnerName !== '') {
        if ($winnerName === $arenaP1Name) {
          $arenaLose = 1;
        } elseif ($winnerName === $arenaP2Name) {
          $arenaWin = 1;
        }
      }
    }
  }

  $stmtUpdateArena = $pdo->prepare("
    UPDATE arena_unlight
    SET
      win = :win,
      lose = :lose,
      tie = :tie
    WHERE id = :arena_id
    LIMIT 1
  ");

  $stmtUpdateArena->execute([
    ':win' => $arenaWin,
    ':lose' => $arenaLose,
    ':tie' => $arenaTie,
    ':arena_id' => $arenaId,
  ]);
}
function adminSetTournamentGameResult(
  PDO $pdo,
  array $match,
  int $gameId,
  string $decision,
  string $adminNote = ''
): array {
  $allowed = [
    'p1_win',
    'p2_win',
    'tie',
    'rematch',
    'dispute',
    'rejected',
  ];

  if (!in_array($decision, $allowed, true)) {
    return [
      'ok' => false,
      'message' => '不支援的判定類型。',
    ];
  }

  $stmtGame = $pdo->prepare("
    SELECT
      g.id,
      g.match_id,
      g.arena_id,
      g.game_no,
      g.result_status,
      g.winner_player_id
    FROM tournament_match_games g
    WHERE g.id = :game_id
      AND g.match_id = :match_id
    LIMIT 1
  ");

  $stmtGame->execute([
    ':game_id' => $gameId,
    ':match_id' => (int)$match['id'],
  ]);

  $game = $stmtGame->fetch(PDO::FETCH_ASSOC);

  if (!$game) {
    return [
      'ok' => false,
      'message' => '找不到此小局，或此小局不屬於本場次。',
    ];
  }

  $winnerPlayerId = null;
  $resultStatus = 'pending';
  $decisionText = '';

  switch ($decision) {
    case 'p1_win':
      $winnerPlayerId = (int)$match['player1_id'];
      $resultStatus = 'verified';
      $decisionText = '管理員手動判定：' . ($match['player1_display_name'] ?: $match['player1_game_name'] ?: 'P1') . ' 勝';
      break;

    case 'p2_win':
      $winnerPlayerId = (int)$match['player2_id'];
      $resultStatus = 'verified';
      $decisionText = '管理員手動判定：' . ($match['player2_display_name'] ?: $match['player2_game_name'] ?: 'P2') . ' 勝';
      break;

    case 'tie':
      $winnerPlayerId = null;
      $resultStatus = 'tie';
      $decisionText = '管理員手動判定：平手，依規則重賽';
      break;

    case 'rematch':
      $winnerPlayerId = null;
      $resultStatus = 'rematch';
      $decisionText = '管理員手動判定：斷線或其他原因，依規則重賽';
      break;

    case 'dispute':
      $winnerPlayerId = null;
      $resultStatus = 'dispute';
      $decisionText = '管理員手動判定：爭議中';
      break;

    case 'rejected':
      $winnerPlayerId = null;
      $resultStatus = 'rejected';
      $decisionText = '管理員手動判定：駁回此局，不列入賽事結果';
      break;
  }

  $adminName = currentLoginName();
  if ($adminName === '') {
    $adminName = 'admin';
  }

  $noteParts = [];
  $noteParts[] = '[' . date('Y-m-d H:i:s') . '] ' . $decisionText . ' by ' . $adminName;

  if (trim($adminNote) !== '') {
    $noteParts[] = '備註：' . trim($adminNote);
  }

  $newNote = implode("\n", $noteParts);

  try {
    $pdo->beginTransaction();

    updateArenaResultForTournamentGame(
      $pdo,
      (int)$game['arena_id'],
      $match,
      $winnerPlayerId,
      $resultStatus
    );

    $stmtUpdateGame = $pdo->prepare("
      UPDATE tournament_match_games
      SET
        winner_player_id = :winner_player_id,
        result_status = :result_status,
        note = CONCAT(
          COALESCE(note, ''),
          CASE WHEN note IS NULL OR note = '' THEN '' ELSE '\n' END,
          :note
        ),
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :game_id
      LIMIT 1
    ");

    $stmtUpdateGame->execute([
      ':winner_player_id' => $winnerPlayerId,
      ':result_status' => $resultStatus,
      ':note' => $newNote,
      ':game_id' => $gameId,
    ]);

    if (!in_array((string)$match['status'], ['confirmed', 'finished', 'dispute'], true)) {
      $stmtMatch = $pdo->prepare("
        UPDATE tournament_matches
        SET
          status = 'playing',
          updated_at = CURRENT_TIMESTAMP
        WHERE id = :match_id
        LIMIT 1
      ");
      $stmtMatch->execute([
        ':match_id' => (int)$match['id'],
      ]);
    }

    $pdo->commit();

    return [
      'ok' => true,
      'message' => 'Game ' . (int)$game['game_no'] . ' 已更新判定：' . $decisionText,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    return [
      'ok' => false,
      'message' => '更新小局判定失敗：' . $e->getMessage(),
    ];
  }
}

function calculateTournamentMatchScore(PDO $pdo, array $match): array
{
  $matchId = (int)($match['id'] ?? 0);
  $p1Id = (int)($match['player1_id'] ?? 0);
  $p2Id = (int)($match['player2_id'] ?? 0);

  $result = [
    'p1_score' => 0,
    'p2_score' => 0,
    'winner_player_id' => null,
    'winner_side' => null,
    'can_finalize' => false,
    'message' => '尚未有人達成 2 勝。',
  ];

  if ($matchId <= 0 || $p1Id <= 0 || $p2Id <= 0) {
    $result['message'] = '場次或選手資料不完整，無法結算。';
    return $result;
  }

  $stmt = $pdo->prepare("
    SELECT
      winner_player_id,
      COUNT(*) AS win_count
    FROM tournament_match_games
    WHERE match_id = :match_id
      AND result_status = 'verified'
      AND winner_player_id IS NOT NULL
    GROUP BY winner_player_id
  ");

  $stmt->execute([
    ':match_id' => $matchId,
  ]);

  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $winnerId = (int)$row['winner_player_id'];
    $count = (int)$row['win_count'];

    if ($winnerId === $p1Id) {
      $result['p1_score'] = $count;
    } elseif ($winnerId === $p2Id) {
      $result['p2_score'] = $count;
    }
  }

  if ($result['p1_score'] >= 2 && $result['p2_score'] >= 2) {
    $result['message'] = '雙方都達 2 勝，資料異常，請先檢查小局判定。';
    return $result;
  }

  $p1SeedText = 'Seed ' . (int)(
    $match['player1_seed_no_real']
    ?? $match['player1_seed_no']
    ?? 0
  );

  $p2SeedText = 'Seed ' . (int)(
    $match['player2_seed_no_real']
    ?? $match['player2_seed_no']
    ?? 0
  );

  if ($result['p1_score'] >= 2) {
    $result['winner_player_id'] = $p1Id;
    $result['winner_side'] = 'p1';
    $result['can_finalize'] = true;
    $result['message'] = $p1SeedText . ' 已達 2 勝，可結算晉級。';
    return $result;
  }

  if ($result['p2_score'] >= 2) {
    $result['winner_player_id'] = $p2Id;
    $result['winner_side'] = 'p2';
    $result['can_finalize'] = true;
    $result['message'] = $p2SeedText . ' 已達 2 勝，可結算晉級。';
    return $result;
  }

  return $result;
}
function syncTournamentGameResultsFromArena(PDO $pdo, array $match): array
{
  $matchId = (int)($match['id'] ?? 0);

  if ($matchId <= 0) {
    return [
      'ok' => false,
      'updated' => 0,
      'message' => 'match_id 無效，無法同步小局結果。',
    ];
  }

  $stmt = $pdo->prepare("
    SELECT
      g.id AS game_id,
      g.result_status,
      g.winner_player_id,

      a.id AS arena_id,
      a.name_p1,
      a.name_p2,
      a.win,
      a.lose,
      a.tie
    FROM tournament_match_games g
    INNER JOIN arena_unlight a
      ON a.id = g.arena_id
    WHERE g.match_id = :match_id
      AND g.result_status IN ('candidate', 'pending')
      AND (
        a.win = 1
        OR a.lose = 1
        OR a.tie = 1
      )
  ");

  $stmt->execute([
    ':match_id' => $matchId,
  ]);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    return [
      'ok' => true,
      'updated' => 0,
      'message' => '沒有需要同步的小局。',
    ];
  }

  $matchP1Name = normalizePlayerName((string)($match['player1_game_name'] ?? ''));
  $matchP2Name = normalizePlayerName((string)($match['player2_game_name'] ?? ''));

  $p1Id = (int)($match['player1_id'] ?? 0);
  $p2Id = (int)($match['player2_id'] ?? 0);

  $updated = 0;

  try {
    $pdo->beginTransaction();

    $stmtUpdate = $pdo->prepare("
      UPDATE tournament_match_games
      SET
        winner_player_id = :winner_player_id,
        result_status = :result_status,
        note = CONCAT(
          COALESCE(note, ''),
          CASE WHEN note IS NULL OR note = '' THEN '' ELSE '\n' END,
          :note
        ),
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :game_id
      LIMIT 1
    ");

    foreach ($rows as $row) {
      $arenaP1Name = normalizePlayerName((string)($row['name_p1'] ?? ''));
      $arenaP2Name = normalizePlayerName((string)($row['name_p2'] ?? ''));

      $winnerPlayerId = null;
      $resultStatus = 'pending';
      $note = '';

      if ((int)$row['tie'] === 1) {
        $winnerPlayerId = null;
        $resultStatus = 'tie';
        $note = '系統同步：依 arena_unlight.tie 判定為平手';
      } elseif ((int)$row['lose'] === 1) {
        /*
         * arena_unlight 規則：
         * lose = 1 代表 arena P1 勝
         */
        if ($arenaP1Name === $matchP1Name) {
          $winnerPlayerId = $p1Id;
        } elseif ($arenaP1Name === $matchP2Name) {
          $winnerPlayerId = $p2Id;
        }

        $resultStatus = $winnerPlayerId ? 'verified' : 'dispute';
        $note = '系統同步：依 arena_unlight.lose 判定 arena P1 勝';
      } elseif ((int)$row['win'] === 1) {
        /*
         * arena_unlight 規則：
         * win = 1 代表 arena P2 勝
         */
        if ($arenaP2Name === $matchP1Name) {
          $winnerPlayerId = $p1Id;
        } elseif ($arenaP2Name === $matchP2Name) {
          $winnerPlayerId = $p2Id;
        }

        $resultStatus = $winnerPlayerId ? 'verified' : 'dispute';
        $note = '系統同步：依 arena_unlight.win 判定 arena P2 勝';
      }

      $stmtUpdate->execute([
        ':winner_player_id' => $winnerPlayerId,
        ':result_status' => $resultStatus,
        ':note' => '[' . date('Y-m-d H:i:s') . '] ' . $note,
        ':game_id' => (int)$row['game_id'],
      ]);

      $updated++;
    }

    $pdo->commit();

    return [
      'ok' => true,
      'updated' => $updated,
      'message' => '已同步 ' . $updated . ' 筆小局結果。',
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    return [
      'ok' => false,
      'updated' => 0,
      'message' => '同步小局結果失敗：' . $e->getMessage(),
    ];
  }
}
function finalizeTournamentMatch(PDO $pdo, array $match): array
{
  $matchId = (int)($match['id'] ?? 0);
  $tournamentId = (int)($match['tournament_id'] ?? 1);

  if ($matchId <= 0) {
    return [
      'ok' => false,
      'message' => 'match_id 無效，無法結算。',
    ];
  }

  if (in_array((string)($match['status'] ?? ''), ['confirmed', 'finished'], true)) {
    return [
      'ok' => false,
      'message' => '此場次已經結算過，不重複處理。',
    ];
  }

  $score = calculateTournamentMatchScore($pdo, $match);

  if (empty($score['can_finalize']) || empty($score['winner_player_id'])) {
    return [
      'ok' => false,
      'message' => $score['message'],
    ];
  }

  $winnerPlayerId = (int)$score['winner_player_id'];
  $winnerSide = (string)$score['winner_side'];

  $player1Id = (int)($match['player1_id'] ?? 0);
  $player2Id = (int)($match['player2_id'] ?? 0);

  $loserPlayerId = 0;
  if ($winnerPlayerId === $player1Id) {
    $loserPlayerId = $player2Id;
  } elseif ($winnerPlayerId === $player2Id) {
    $loserPlayerId = $player1Id;
  }

  if ($winnerSide === 'p1') {
    $winnerSeedNo = isset($match['player1_seed_no']) ? (int)$match['player1_seed_no'] : null;
    $winnerName = $match['player1_display_name'] ?: $match['player1_game_name'] ?: 'P1';
  } else {
    $winnerSeedNo = isset($match['player2_seed_no']) ? (int)$match['player2_seed_no'] : null;
    $winnerName = $match['player2_display_name'] ?: $match['player2_game_name'] ?: 'P2';
  }

  $nextMatchCode = trim((string)($match['next_match_code'] ?? ''));
  $nextSlot = isset($match['next_slot']) ? (int)$match['next_slot'] : 0;

  $adminName = currentLoginName();
  if ($adminName === '') {
    $adminName = 'admin';
  }

  $note = '[' . date('Y-m-d H:i:s') . '] 管理員結算本場：' .
    $winnerName . ' 以 ' .
    (int)$score['p1_score'] . ' - ' . (int)$score['p2_score'] .
    ' 晉級。by ' . $adminName;

  try {
    $pdo->beginTransaction();

    // 1. 更新本場結果
    $stmtMatch = $pdo->prepare("
      UPDATE tournament_matches
      SET
        player1_score = :player1_score,
        player2_score = :player2_score,
        winner_player_id = :winner_player_id,
        status = 'confirmed',
        note = CONCAT(
          COALESCE(note, ''),
          CASE WHEN note IS NULL OR note = '' THEN '' ELSE '\n' END,
          :note
        ),
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :match_id
      LIMIT 1
    ");

    $stmtMatch->execute([
      ':player1_score' => (int)$score['p1_score'],
      ':player2_score' => (int)$score['p2_score'],
      ':winner_player_id' => $winnerPlayerId,
      ':note' => $note,
      ':match_id' => $matchId,
    ]);

    // 1.5 更新選手狀態：敗者淘汰、勝者保持 active
    if ($loserPlayerId > 0) {
      $stmtLoser = $pdo->prepare("
        UPDATE tournament_players
        SET
          player_status = 'eliminated',
          updated_at = CURRENT_TIMESTAMP
        WHERE id = :loser_player_id
          AND tournament_id = :tournament_id
        LIMIT 1
      ");

      $stmtLoser->execute([
        ':loser_player_id' => $loserPlayerId,
        ':tournament_id' => $tournamentId,
      ]);
    }

    if ($winnerPlayerId > 0) {
      $stmtWinner = $pdo->prepare("
        UPDATE tournament_players
        SET
          player_status = 'active',
          updated_at = CURRENT_TIMESTAMP
        WHERE id = :winner_player_id
          AND tournament_id = :tournament_id
        LIMIT 1
      ");

      $stmtWinner->execute([
        ':winner_player_id' => $winnerPlayerId,
        ':tournament_id' => $tournamentId,
      ]);
    }

    // 2. 如果有下一場，推進勝者
    if ($nextMatchCode !== '' && in_array($nextSlot, [1, 2], true)) {
      $stmtNext = $pdo->prepare("
        SELECT
          id,
          match_code,
          player1_id,
          player2_id,
          status
        FROM tournament_matches
        WHERE tournament_id = :tournament_id
          AND match_code = :match_code
        LIMIT 1
      ");

      $stmtNext->execute([
        ':tournament_id' => $tournamentId,
        ':match_code' => $nextMatchCode,
      ]);

      $nextMatch = $stmtNext->fetch(PDO::FETCH_ASSOC);

      if (!$nextMatch) {
        throw new RuntimeException('找不到下一場：' . $nextMatchCode);
      }

      $targetPlayerId = $nextSlot === 1
        ? (int)($nextMatch['player1_id'] ?? 0)
        : (int)($nextMatch['player2_id'] ?? 0);

      if ($targetPlayerId > 0 && $targetPlayerId !== $winnerPlayerId) {
        throw new RuntimeException(
          '下一場 ' . $nextMatchCode . ' 的 Slot ' . $nextSlot . ' 已有其他選手，為避免覆蓋，已停止。'
        );
      }

      if ($nextSlot === 1) {
        $stmtAdvance = $pdo->prepare("
          UPDATE tournament_matches
          SET
            player1_id = :winner_player_id,
            player1_seed_no = :winner_seed_no,
            status = CASE
              WHEN player2_id IS NOT NULL THEN 'waiting'
              ELSE status
            END,
            updated_at = CURRENT_TIMESTAMP
          WHERE id = :next_match_id
          LIMIT 1
        ");
      } else {
        $stmtAdvance = $pdo->prepare("
          UPDATE tournament_matches
          SET
            player2_id = :winner_player_id,
            player2_seed_no = :winner_seed_no,
            status = CASE
              WHEN player1_id IS NOT NULL THEN 'waiting'
              ELSE status
            END,
            updated_at = CURRENT_TIMESTAMP
          WHERE id = :next_match_id
          LIMIT 1
        ");
      }

      $stmtAdvance->execute([
        ':winner_player_id' => $winnerPlayerId,
        ':winner_seed_no' => $winnerSeedNo,
        ':next_match_id' => (int)$nextMatch['id'],
      ]);
    }

    $pdo->commit();

    if ($nextMatchCode !== '') {
      return [
        'ok' => true,
        'message' => '已結算本場，' . $winnerName . ' 晉級至 ' . $nextMatchCode . '，敗者已標記淘汰。'
      ];
    }

    return [
      'ok' => true,
      'message' => '已結算本場，' . $winnerName . ' 為本場勝者，敗者已標記淘汰。'
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    return [
      'ok' => false,
      'message' => '結算失敗：' . $e->getMessage(),
    ];
  }
}
function adminForfeitTournamentMatch(
  PDO $pdo,
  array $match,
  string $forfeitSide,
  string $forfeitType,
  string $adminNote = ''
): array {
  $matchId = (int)($match['id'] ?? 0);
  $tournamentId = (int)($match['tournament_id'] ?? 1);

  if ($matchId <= 0) {
    return [
      'ok' => false,
      'message' => 'match_id 無效，無法處理棄賽。',
    ];
  }

  if (in_array((string)($match['status'] ?? ''), ['confirmed', 'finished'], true)) {
    return [
      'ok' => false,
      'message' => '此場次已經結算過，不能再處理棄賽。',
    ];
  }

  if (!in_array($forfeitSide, ['p1', 'p2'], true)) {
    return [
      'ok' => false,
      'message' => '請選擇棄賽方。',
    ];
  }

  $validTypes = [
    'withdrawn' => '主動棄賽',
    'timeout' => '逾時未聯絡',
    'dq' => '違規失格',
    'admin' => '主辦手動判定',
  ];

  if (!isset($validTypes[$forfeitType])) {
    $forfeitType = 'withdrawn';
  }

  $p1Id = (int)($match['player1_id'] ?? 0);
  $p2Id = (int)($match['player2_id'] ?? 0);

  if ($p1Id <= 0 || $p2Id <= 0) {
    return [
      'ok' => false,
      'message' => '本場次雙方選手資料不完整，無法處理棄賽。',
    ];
  }

  if ($forfeitSide === 'p1') {
    $forfeitPlayerId = $p1Id;
    $winnerPlayerId = $p2Id;
    $player1Score = 0;
    $player2Score = 0;
    $forfeitName = $match['player1_display_name'] ?: $match['player1_game_name'] ?: 'P1';
    $winnerName = $match['player2_display_name'] ?: $match['player2_game_name'] ?: 'P2';
    $winnerSeedNo = isset($match['player2_seed_no']) ? (int)$match['player2_seed_no'] : null;
  } else {
    $forfeitPlayerId = $p2Id;
    $winnerPlayerId = $p1Id;
    $player1Score = 0;
    $player2Score = 0;
    $forfeitName = $match['player2_display_name'] ?: $match['player2_game_name'] ?: 'P2';
    $winnerName = $match['player1_display_name'] ?: $match['player1_game_name'] ?: 'P1';
    $winnerSeedNo = isset($match['player1_seed_no']) ? (int)$match['player1_seed_no'] : null;
  }

  $nextMatchCode = trim((string)($match['next_match_code'] ?? ''));
  $nextSlot = isset($match['next_slot']) ? (int)$match['next_slot'] : 0;

  $adminName = currentLoginName();
  if ($adminName === '') {
    $adminName = 'admin';
  }

  $forfeitStatus = $forfeitType === 'dq' ? 'dq' : 'withdrawn';

  $note = '[' . date('Y-m-d H:i:s') . '] 管理員判定棄賽：' .
    $forfeitName . '（' . $validTypes[$forfeitType] . '），' .
    $winnerName . ' 因對手棄賽晉級。比數記錄為 0 - 0。by ' . $adminName;

  if (trim($adminNote) !== '') {
    $note .= "\n備註：" . trim($adminNote);
  }

  try {
    $pdo->beginTransaction();

    $stmtMatch = $pdo->prepare("
      UPDATE tournament_matches
      SET
        player1_score = :player1_score,
        player2_score = :player2_score,
        winner_player_id = :winner_player_id,
        status = 'confirmed',
        note = CONCAT(
          COALESCE(note, ''),
          CASE WHEN note IS NULL OR note = '' THEN '' ELSE '\n' END,
          :note
        ),
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :match_id
      LIMIT 1
    ");

    $stmtMatch->execute([
      ':player1_score' => $player1Score,
      ':player2_score' => $player2Score,
      ':winner_player_id' => $winnerPlayerId,
      ':note' => $note,
      ':match_id' => $matchId,
    ]);

    $stmtForfeitPlayer = $pdo->prepare("
      UPDATE tournament_players
      SET
        player_status = :player_status,
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :player_id
        AND tournament_id = :tournament_id
      LIMIT 1
    ");

    $stmtForfeitPlayer->execute([
      ':player_status' => $forfeitStatus,
      ':player_id' => $forfeitPlayerId,
      ':tournament_id' => $tournamentId,
    ]);

    $stmtWinner = $pdo->prepare("
      UPDATE tournament_players
      SET
        player_status = 'active',
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :player_id
        AND tournament_id = :tournament_id
      LIMIT 1
    ");

    $stmtWinner->execute([
      ':player_id' => $winnerPlayerId,
      ':tournament_id' => $tournamentId,
    ]);

    if ($nextMatchCode !== '' && in_array($nextSlot, [1, 2], true)) {
      $stmtNext = $pdo->prepare("
        SELECT
          id,
          match_code,
          player1_id,
          player2_id
        FROM tournament_matches
        WHERE tournament_id = :tournament_id
          AND match_code = :match_code
        LIMIT 1
      ");

      $stmtNext->execute([
        ':tournament_id' => $tournamentId,
        ':match_code' => $nextMatchCode,
      ]);

      $nextMatch = $stmtNext->fetch(PDO::FETCH_ASSOC);

      if (!$nextMatch) {
        throw new RuntimeException('找不到下一場：' . $nextMatchCode);
      }

      $targetPlayerId = $nextSlot === 1
        ? (int)($nextMatch['player1_id'] ?? 0)
        : (int)($nextMatch['player2_id'] ?? 0);

      if ($targetPlayerId > 0 && $targetPlayerId !== $winnerPlayerId) {
        throw new RuntimeException(
          '下一場 ' . $nextMatchCode . ' 的 Slot ' . $nextSlot . ' 已有其他選手，為避免覆蓋，已停止。'
        );
      }

      if ($nextSlot === 1) {
        $stmtAdvance = $pdo->prepare("
          UPDATE tournament_matches
          SET
            player1_id = :winner_player_id,
            player1_seed_no = :winner_seed_no,
            status = CASE
              WHEN player2_id IS NOT NULL THEN 'waiting'
              ELSE status
            END,
            updated_at = CURRENT_TIMESTAMP
          WHERE id = :next_match_id
          LIMIT 1
        ");
      } else {
        $stmtAdvance = $pdo->prepare("
          UPDATE tournament_matches
          SET
            player2_id = :winner_player_id,
            player2_seed_no = :winner_seed_no,
            status = CASE
              WHEN player1_id IS NOT NULL THEN 'waiting'
              ELSE status
            END,
            updated_at = CURRENT_TIMESTAMP
          WHERE id = :next_match_id
          LIMIT 1
        ");
      }

      $stmtAdvance->execute([
        ':winner_player_id' => $winnerPlayerId,
        ':winner_seed_no' => $winnerSeedNo,
        ':next_match_id' => (int)$nextMatch['id'],
      ]);
    }

    $pdo->commit();

    if ($nextMatchCode !== '') {
      return [
        'ok' => true,
        'message' => '已判定 ' . $forfeitName . ' 棄賽，' . $winnerName . ' 以 ' . $player1Score . ' - ' . $player2Score . ' 晉級至 ' . $nextMatchCode . '。',
      ];
    }

    return [
      'ok' => true,
      'message' => '已判定 ' . $forfeitName . ' 棄賽，' . $winnerName . ' 以 ' . $player1Score . ' - ' . $player2Score . ' 勝出。',
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    return [
      'ok' => false,
      'message' => '棄賽處理失敗：' . $e->getMessage(),
    ];
  }
}

function matchStatusLabel($status)
{
  $map = [
    'pending'   => ['等待晉級', 'status-pending'],
    'waiting'   => ['等待開戰', 'status-waiting'],
    'playing'   => ['進行中', 'status-playing'],
    'reported'  => ['已回報', 'status-reported'],
    'confirmed' => ['已確認', 'status-confirmed'],
    'finished'  => ['已結束', 'status-finished'],
    'dispute'   => ['爭議中', 'status-dispute'],
  ];

  return $map[$status] ?? ['未知', 'status-pending'];
}

function regionIcon($region)
{
  switch ($region) {
    case 'TW':
      return '<i class="fab fa-steam" title="Steam 台服"></i>';
    case 'JP':
      return '<span class="server-icon dmm" title="DMM 日服">D</span>';
    case 'CR':
      return '<span title="跨服">☯</span>';
    case 'MATCH':
      return '<i class="fas fa-trophy" title="比賽專用"></i>';
    default:
      return '';
  }
}

$stages = [
  -1 => '全部',
  0  => '雷城',
  1  => '誘森',
  2  => '垃圾',
  3  => '冰封',
  4  => '人魂',
  5  => '盡村',
  6  => '風暴',
  7  => '峰亥盧',
  8  => '魔都',
  9  => '狂山',
  10 => '魔女山谷',
  11 => '隨機',
  12 => '烏波斯黑湖',
];

function stageName($stage, array $stages): string
{
  if ($stage === null || $stage === '') {
    return '-';
  }

  $stageInt = (int)$stage;
  return $stages[$stageInt] ?? ('未知場景 ' . $stageInt);
}

function arenaResultLabel($row, $focusName)
{
  $win  = (int)($row['win'] ?? 0);
  $lose = (int)($row['lose'] ?? 0);
  $tie  = (int)($row['tie'] ?? 0);

  if ($tie === 1) {
    return ['平', 'result-tie'];
  }

  if ($win + $lose + $tie === 0) {
    return ['未判', 'result-unknown'];
  }

  // arena_unlight 規則：win=1 代表 P2 勝，lose=1 代表 P2 敗/P1勝
  $p1 = (string)($row['name_p1'] ?? '');
  $p2 = (string)($row['name_p2'] ?? '');

  if ($win === 1) {
    $winner = $p2;
  } elseif ($lose === 1) {
    $winner = $p1;
  } else {
    $winner = '';
  }

  if ($winner !== '' && $winner === $focusName) {
    return ['勝', 'result-win'];
  }

  return ['負', 'result-lose'];
}

function getCharCards(PDO $pdo, array $ids)
{
  $ids = array_values(array_filter(array_map('intval', $ids)));
  if (!$ids) return [];

  $placeholders = implode(',', array_fill(0, count($ids), '?'));

  $stmt = $pdo->prepare("
    SELECT id, ico, name, level, cost
    FROM unlight
    WHERE id IN ($placeholders)
  ");
  $stmt->execute($ids);

  $map = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $map[(int)$r['id']] = $r;
  }

  $result = [];
  foreach ($ids as $id) {
    if (isset($map[$id])) {
      $result[] = $map[$id];
    }
  }

  return $result;
}

function renderCharImages(PDO $pdo, array $ids)
{
  $cards = getCharCards($pdo, $ids);

  if (!$cards) {
    return '<div class="t-char-empty">無資料</div>';
  }

  $html = '<div class="t-chars">';
  foreach ($cards as $c) {
    $ico = $c['ico'] ?: '';
    $title = trim(($c['level'] ?? '') . ' ' . ($c['name'] ?? '') . ' / COST ' . ($c['cost'] ?? ''));

    $html .= '
      <div class="t-char-card" title="' . h($title) . '">
        <img src="' . IMG_BASE . h($ico) . '" loading="lazy">
      </div>
    ';
  }
  $html .= '</div>';

  return $html;
}
function getBanCardVisual(PDO $pdo, array $card): ?array
{
  $charId = (int)($card['char_id'] ?? 0);
  $charName = trim((string)($card['char_name'] ?? ''));
  $cardLevel = trim((string)($card['card_level'] ?? ''));

  /*
   * 優先用角色名稱 + 等級找實際卡圖。
   * 因為 tournament_match_ban_cards.char_id 可能是 cost_unlight.ID，
   * 不一定等於 unlight.id。
   */
  if ($charName !== '' && $cardLevel !== '') {
    $stmt = $pdo->prepare("
      SELECT id, ico, name, level, cost
      FROM unlight
      WHERE name = :name
        AND level = :level
      LIMIT 1
    ");

    $stmt->execute([
      ':name' => $charName,
      ':level' => $cardLevel,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      return $row;
    }
  }

  /*
   * fallback 1：如果 char_id 剛好對得到 unlight.id
   */
  if ($charId > 0) {
    $stmt = $pdo->prepare("
      SELECT id, ico, name, level, cost
      FROM unlight
      WHERE id = :id
      LIMIT 1
    ");

    $stmt->execute([
      ':id' => $charId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      return $row;
    }
  }

  /*
   * fallback 2：只用角色名稱找一張代表卡
   */
  if ($charName !== '') {
    $stmt = $pdo->prepare("
      SELECT id, ico, name, level, cost
      FROM unlight
      WHERE name = :name
      ORDER BY cost DESC, id DESC
      LIMIT 1
    ");

    $stmt->execute([
      ':name' => $charName,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      return $row;
    }
  }

  return null;
}

function renderBanCardsVisual(PDO $pdo, array $slotCards): string
{
  $html = '<div class="t-ban-visual-cards">';

  for ($slotNo = 1; $slotNo <= 3; $slotNo++) {
    $card = $slotCards[$slotNo] ?? null;

    if (!$card) {
      $html .= '
        <div class="t-ban-visual-card is-empty">
          <div class="t-ban-empty-card">?</div>
        </div>
      ';
      continue;
    }

    $visual = getBanCardVisual($pdo, $card);

    $charName = (string)($card['char_name'] ?? '-');
    $level = (string)($card['card_level'] ?? '');
    $cost = $card['card_cost'] ?? '';

    $title = trim($charName . ' ' . $level . ' COST ' . $cost);

    if ($visual && !empty($visual['ico'])) {
      $html .= '
        <div class="t-ban-visual-card" title="' . h($title) . '">
          <img src="' . IMG_BASE . h($visual['ico']) . '" loading="lazy">
        </div>
      ';
    } else {
      $html .= '
        <div class="t-ban-visual-card no-image" title="' . h($title) . '">
          <div class="t-ban-no-image-name">' . h($charName) . '</div>
        </div>
      ';
    }
  }

  $html .= '</div>';

  return $html;
}
function getPlayerDecksForBan(PDO $pdo, int $tournamentId, int $playerId): array
{
  if ($tournamentId <= 0 || $playerId <= 0) {
    return [];
  }

  $stmt = $pdo->prepare("
    SELECT
      d.id,
      d.tournament_id,
      d.player_id,
      d.seed_no,
      d.deck_no,
      d.deck_label,
      d.char1_id,
      d.char2_id,
      d.char3_id,
      d.deck_json,
      c1.name AS char1_name,
      c1.level AS char1_level,
      c1.cost AS char1_cost,
      c1.ico AS char1_ico,
      c2.name AS char2_name,
      c2.level AS char2_level,
      c2.cost AS char2_cost,
      c2.ico AS char2_ico,
      c3.name AS char3_name,
      c3.level AS char3_level,
      c3.cost AS char3_cost,
      c3.ico AS char3_ico
    FROM tournament_player_decks d
    LEFT JOIN unlight c1 ON c1.id = d.char1_id
    LEFT JOIN unlight c2 ON c2.id = d.char2_id
    LEFT JOIN unlight c3 ON c3.id = d.char3_id
    WHERE d.tournament_id = :tournament_id
      AND d.player_id = :player_id
      AND d.is_public = 1
    ORDER BY d.deck_no ASC
  ");

  $stmt->execute([
    ':tournament_id' => $tournamentId,
    ':player_id' => $playerId,
  ]);

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getTournamentMatchBanCards(PDO $pdo, int $matchId): array
{
  $stmt = $pdo->prepare("
    SELECT
      id,
      tournament_id,
      match_id,
      char_id,
      char_name,
      card_level,
      card_cost,
      ban_side,
      slot_no,
      source,
      note
    FROM tournament_match_ban_cards
    WHERE match_id = :match_id
    ORDER BY
      FIELD(ban_side, 'p1', 'p2', 'unknown'),
      slot_no ASC,
      id ASC
  ");

  $stmt->execute([
    ':match_id' => $matchId,
  ]);

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveTournamentMatchBanDecks(PDO $pdo, array $match, array $post): array
{
  $matchId = (int)($match['id'] ?? 0);
  $tournamentId = (int)($match['tournament_id'] ?? 1);

  if ($matchId <= 0) {
    return [
      'ok' => false,
      'message' => 'match_id 無效，無法儲存 BAN 牌組。',
    ];
  }

  $sidePlayerMap = [
    'p1' => (int)($match['player1_id'] ?? 0),
    'p2' => (int)($match['player2_id'] ?? 0),
  ];

  try {
    $pdo->beginTransaction();

    foreach ($sidePlayerMap as $side => $playerId) {
      $deckId = (int)($post['ban_deck'][$side] ?? 0);

      if ($playerId <= 0) {
        continue;
      }

      if ($deckId <= 0) {
        $stmtDelete = $pdo->prepare("
          DELETE FROM tournament_match_ban_cards
          WHERE match_id = :match_id
            AND ban_side = :ban_side
        ");

        $stmtDelete->execute([
          ':match_id' => $matchId,
          ':ban_side' => $side,
        ]);

        continue;
      }

      $stmtDeck = $pdo->prepare("
        SELECT
          d.id,
          d.tournament_id,
          d.player_id,
          d.seed_no,
          d.deck_no,
          d.deck_label,
          d.char1_id,
          d.char2_id,
          d.char3_id,
          c1.name AS char1_name,
          c1.level AS char1_level,
          c1.cost AS char1_cost,
          c2.name AS char2_name,
          c2.level AS char2_level,
          c2.cost AS char2_cost,
          c3.name AS char3_name,
          c3.level AS char3_level,
          c3.cost AS char3_cost
        FROM tournament_player_decks d
        LEFT JOIN unlight c1 ON c1.id = d.char1_id
        LEFT JOIN unlight c2 ON c2.id = d.char2_id
        LEFT JOIN unlight c3 ON c3.id = d.char3_id
        WHERE d.id = :deck_id
          AND d.tournament_id = :tournament_id
          AND d.player_id = :player_id
        LIMIT 1
      ");

      $stmtDeck->execute([
        ':deck_id' => $deckId,
        ':tournament_id' => $tournamentId,
        ':player_id' => $playerId,
      ]);

      $deck = $stmtDeck->fetch(PDO::FETCH_ASSOC);

      if (!$deck) {
        throw new RuntimeException('找不到 ' . strtoupper($side) . ' 指定的 BAN 牌組。');
      }

      $stmtClear = $pdo->prepare("
        DELETE FROM tournament_match_ban_cards
        WHERE match_id = :match_id
          AND ban_side = :ban_side
      ");

      $stmtClear->execute([
        ':match_id' => $matchId,
        ':ban_side' => $side,
      ]);

      $stmtInsert = $pdo->prepare("
        INSERT INTO tournament_match_ban_cards (
          tournament_id,
          match_id,
          char_id,
          char_name,
          card_level,
          card_cost,
          ban_side,
          slot_no,
          source,
          note,
          created_by,
          updated_by
        ) VALUES (
          :tournament_id,
          :match_id,
          :char_id,
          :char_name,
          :card_level,
          :card_cost,
          :ban_side,
          :slot_no,
          'import',
          :note,
          :created_by,
          :updated_by
        )
      ");

      for ($slotNo = 1; $slotNo <= 3; $slotNo++) {
        $charId = (int)($deck['char' . $slotNo . '_id'] ?? 0);

        if ($charId <= 0) {
          continue;
        }

        $stmtInsert->execute([
          ':tournament_id' => $tournamentId,
          ':match_id' => $matchId,
          ':char_id' => $charId,
          ':char_name' => (string)($deck['char' . $slotNo . '_name'] ?? ''),
          ':card_level' => (string)($deck['char' . $slotNo . '_level'] ?? ''),
          ':card_cost' => $deck['char' . $slotNo . '_cost'] !== null ? (int)$deck['char' . $slotNo . '_cost'] : null,
          ':ban_side' => $side,
          ':slot_no' => $slotNo,
          ':note' => trim((string)($post['ban_note'] ?? '')) . "\n匯入牌組：" . (string)$deck['deck_label'],
          ':created_by' => currentLoginName(),
          ':updated_by' => currentLoginName(),
        ]);
      }
    }

    $pdo->commit();

    return [
      'ok' => true,
      'message' => 'BAN 牌組已匯入。',
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    return [
      'ok' => false,
      'message' => 'BAN 牌組匯入失敗：' . $e->getMessage(),
    ];
  }
}
// 讀取場次
$stmt = $pdo->prepare("
  SELECT
    m.*,

    p1.seed_no AS player1_seed_no_real,
    p1.display_name AS player1_display_name,
    p1.game_name AS player1_game_name,
    p1.tonamel_entry_name AS player1_tonamel_entry_name,
    p1.tonamel_user_name AS player1_tonamel_user_name,
    p1.tonamel_icon_url AS player1_icon,

    p2.seed_no AS player2_seed_no_real,
    p2.display_name AS player2_display_name,
    p2.game_name AS player2_game_name,
    p2.tonamel_entry_name AS player2_tonamel_entry_name,
    p2.tonamel_user_name AS player2_tonamel_user_name,
    p2.tonamel_icon_url AS player2_icon

  FROM tournament_matches m
  LEFT JOIN tournament_players p1
    ON p1.id = m.player1_id
  LEFT JOIN tournament_players p2
    ON p2.id = m.player2_id
  WHERE m.tournament_id = :tournament_id
    AND m.match_code = :match_code
  LIMIT 1
");
$stmt->execute([
  ':tournament_id' => $tournamentId,
  ':match_code' => $matchCode,
]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);
$isMatchLocked = false;

if ($match) {
  $isMatchLocked =
    in_array((string)($match['status'] ?? ''), ['confirmed', 'finished'], true) ||
    (int)($match['winner_player_id'] ?? 0) > 0;
}

if (
  $match &&
  $isAdmin &&
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  ($_POST['action'] ?? '') === 'admin_update_match_schedule'
) {
  if ($isMatchLocked) {
    $_SESSION['upload_success'] = '此場次已結算，不能設定預計對戰時間。';
    header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
    exit;
  }

  $scheduledAtRaw = trim((string)($_POST['scheduled_at'] ?? ''));

  $result = adminUpdateMatchScheduledAt($pdo, $match, $scheduledAtRaw);

  $_SESSION['upload_success'] = $result['message'];

  header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
  exit;
}

if (
  $match &&
  $isAdmin &&
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  ($_POST['action'] ?? '') === 'admin_import_match_ban_decks'
) {
  if ($isMatchLocked) {
    $_SESSION['upload_success'] = '此場次已結算，BAN 資訊已鎖定，不能再修改。';
    header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
    exit;
  }

  $result = saveTournamentMatchBanDecks($pdo, $match, $_POST);

  $_SESSION['upload_success'] = $result['message'];

  header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
  exit;
}
if (
  $match &&
  $isAdmin &&
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  ($_POST['action'] ?? '') === 'admin_set_game_result'
) {
  if ($isMatchLocked) {
    $_SESSION['upload_success'] = '此場次已結算，小局判定已鎖定。';
    header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
    exit;
  }
  $gameId = (int)($_POST['game_id'] ?? 0);
  $decision = trim((string)($_POST['decision'] ?? ''));
  $adminNote = trim((string)($_POST['admin_note'] ?? ''));

  $result = adminSetTournamentGameResult(
    $pdo,
    $match,
    $gameId,
    $decision,
    $adminNote
  );

  $_SESSION['upload_success'] = $result['message'];

  header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
  exit;
}
if (
  $match &&
  $isAdmin &&
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  ($_POST['action'] ?? '') === 'admin_finalize_match'
) {
  $result = finalizeTournamentMatch($pdo, $match);

  if (!empty($result['ok'])) {
    $pushResult = sendTournamentMatchPush(
      $pdo,
      (int)$match['id'],
      'match_confirmed',
      currentLoginName()
    );

    $_SESSION['upload_success'] = appendPushMessage($result, $pushResult);
  } else {
    $_SESSION['upload_success'] = $result['message'];
  }

  header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
  exit;
}
if (
  $match &&
  $isAdmin &&
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  ($_POST['action'] ?? '') === 'admin_forfeit_match'
) {
  $forfeitSide = trim((string)($_POST['forfeit_side'] ?? ''));
  $forfeitType = trim((string)($_POST['forfeit_type'] ?? 'withdrawn'));
  $adminNote = trim((string)($_POST['forfeit_note'] ?? ''));

  $result = adminForfeitTournamentMatch(
    $pdo,
    $match,
    $forfeitSide,
    $forfeitType,
    $adminNote
  );

  if (!empty($result['ok'])) {
    $pushResult = sendTournamentMatchPush(
      $pdo,
      (int)$match['id'],
      'match_forfeit',
      currentLoginName()
    );

    $_SESSION['upload_success'] = appendPushMessage($result, $pushResult);
  } else {
    $_SESSION['upload_success'] = $result['message'];
  }

  header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
  exit;
}

$banCards = [];
$banCardMap = [
  'p1' => [],
  'p2' => [],
];
$banCharRows = [];
$p1DeckRows = [];
$p2DeckRows = [];

$matchGameRows  = [];
$room2Message = '';

if ($match) {
  $p1DeckRows = getPlayerDecksForBan(
    $pdo,
    (int)($match['tournament_id'] ?? $tournamentId),
    (int)($match['player1_id'] ?? 0)
  );

  $p2DeckRows = getPlayerDecksForBan(
    $pdo,
    (int)($match['tournament_id'] ?? $tournamentId),
    (int)($match['player2_id'] ?? 0)
  );
  $room2Candidates = [];
  $room2Message = '';
  $room2Error = '';
  $inferMessage = '';
  if ($isAdmin) {
    syncTournamentGameResultsFromArena($pdo, $match);
  }

  if (!empty($inferResult['updated'])) {
    $inferMessage = $inferResult['message'] . '。';
  }

  $room2Path = __DIR__ . '/../../watcher/channel2_room.json';

  if ($match) {
    $p1Game = trim((string)($match['player1_game_name'] ?? ''));
    $p2Game = trim((string)($match['player2_game_name'] ?? ''));

    // 搜尋 room2 候選
    if (isset($_GET['scan_room2']) && $_GET['scan_room2'] === '1') {
      if ($p1Game === '' || $p2Game === '') {
        $room2Error = '此場次缺少 game_name，無法比對 room2 玩家名稱。';
      } else {
        $scanResult = findRoom2CandidatesFromFile($room2Path, $p1Game, $p2Game, 50);

        if (!$scanResult['ok']) {
          $room2Error = $scanResult['error'];
        } else {
          $room2CandidatesRaw = $scanResult['rooms'];

          $importedRoomIds = getImportedRoomIds($pdo, $tournamentId);

          $room2Candidates = [];

          foreach ($room2CandidatesRaw as $room) {
            $roomId = (string)($room['room_id'] ?? '');

            if ($roomId === '') {
              continue;
            }

            // 已匯入過的 room_id 不再顯示在候選清單
            if (isset($importedRoomIds[$roomId])) {
              continue;
            }

            $room2Candidates[] = $room;
          }

          $importedCount = count($room2CandidatesRaw) - count($room2Candidates);

          $room2Message =
            '已搜尋目前競技場，找到 ' .
            count($room2Candidates) .
            ' 筆尚未匯入的候選對戰。';

          if ($importedCount > 0) {
            $room2Message .= ' 已自動排除 ' . $importedCount . ' 筆已匯入對戰。';
          }
        }
      }
    }
    if (
      $match &&
      $isAdmin &&
      $_SERVER['REQUEST_METHOD'] === 'POST' &&
      ($_POST['action'] ?? '') === 'manual_import_decks_to_match_game'
    ) {
      if ($isMatchLocked) {
        $_SESSION['upload_success'] = '此場次已結算，不能再手動匯入小局。';
        header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
        exit;
      }
      $importUser = $_SESSION['username'] ?? 'manual_import';

      $result = importManualDecksToTournamentGame($pdo, $match, $_POST, $importUser);

      $_SESSION['upload_success'] = $result['message'];

      header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
      exit;
    }
    // 匯入 arena_unlight
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_room2_to_arena') {
      if ($isMatchLocked) {
        $_SESSION['upload_success'] = '此場次已結算，不能再從競技場匯入小局。';
        header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode));
        exit;
      }
      $roomId = trim((string)($_POST['room_id'] ?? ''));

      $alreadyImported = checkTournamentRoomAlreadyImported($pdo, $tournamentId, $roomId);

      if ($alreadyImported) {
        $_SESSION['upload_success'] =
          '此候選對戰已經匯入過：' .
          ($alreadyImported['match_code'] ?? '') .
          ' / Game ' .
          ($alreadyImported['game_no'] ?? '');

        header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode) . '&scan_room2=1');
        exit;
      }
      $roomResult = getRoom2ByRoomIdFromFile($room2Path, $roomId);

      if (!$roomResult['ok']) {
        $_SESSION['upload_success'] = $roomResult['error'];
        header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode) . '&scan_room2=1');
        exit;
      }

      $room = $roomResult['room'];

      if (!$room) {
        $_SESSION['upload_success'] = '找不到指定的候選對戰，可能房間已消失。';
        header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode) . '&scan_room2=1');
        exit;
      }

      if (!room2SamePlayers($room, $p1Game, $p2Game)) {
        $_SESSION['upload_success'] = '此候選對戰的玩家不符合本場次雙方，已拒絕匯入。';
        header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode) . '&scan_room2=1');
        exit;
      }

      $importUser = $_SESSION['username'] ?? 'tournament_import';

      try {
        $inferResult = inferAndUpdatePreviousGameResult($pdo, $match, $room);
        $result = importRoom2ToArena($pdo, $room, $importUser);

        if (!$result['ok']) {
          $_SESSION['upload_success'] = '匯入 arena_unlight 失敗：' . $result['error'];
        } else {

          $sideMap = mapRoom2ToTournamentSides($room, $match);

          $leftDeckCost = $sideMap['left']['deck_cost'] !== null
            ? (int)$sideMap['left']['deck_cost']
            : null;

          $rightDeckCost = $sideMap['right']['deck_cost'] !== null
            ? (int)$sideMap['right']['deck_cost']
            : null;

          $linkResult = importArenaToTournamentMatchGame(
            $pdo,
            $match,
            (int)$result['arena_id'],
            (string)$result['room_id'],
            null,
            $leftDeckCost,
            $rightDeckCost,
            null,
            null
          );
          if ($linkResult['ok']) {
            if (!empty($linkResult['game_id'])) {
              ulgg_auto_fill_game_decks($pdo, (int)$linkResult['game_id']);
            }

            $renumberResult = renumberMatchGamesByTime($pdo, (int)$match['id']);

            if ($renumberResult['ok']) {
              $_SESSION['upload_success'] =
                $inferMessage .
                '已匯入 一般對戰DB 並連結賽事小局，已依時間重新整理場次順序。';
            } else {
              $_SESSION['upload_success'] =
                $inferMessage .
                '已匯入 一般對戰DB，但重新整理場次順序失敗：' .
                $renumberResult['error'];
            }
          }
        }
      } catch (Throwable $e) {
        $_SESSION['upload_success'] = '匯入錯誤：' . $e->getMessage();
      }

      header('Location: ulgg_cup_match.php?match=' . urlencode($matchCode) . '&scan_room2=1');
      exit;
    }
  }
  try {
    $banCards = getTournamentMatchBanCards($pdo, (int)$match['id']);

    foreach ($banCards as $card) {
      $side = (string)($card['ban_side'] ?? 'unknown');
      $slotNo = (int)($card['slot_no'] ?? 0);

      if (isset($banCardMap[$side]) && $slotNo > 0) {
        $banCardMap[$side][$slotNo] = $card;
      }
    }
  } catch (Throwable $e) {
    $banCards = [];
    $banCardMap = [
      'p1' => [],
      'p2' => [],
    ];
  }

  try {
    $stmtBanChars = $pdo->query("
    SELECT
      ID,
      name,
      L1, L2, L3, L4, L5,
      R1, R2, R3, R4, R5
    FROM cost_unlight
    WHERE L1 IS NOT NULL
      AND on_stage = 1
    ORDER BY ID ASC
  ");

    $banCharRows = $stmtBanChars->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $banCharRows = [];
  }

  $p1Game = trim((string)($match['player1_game_name'] ?? ''));
  $p2Game = trim((string)($match['player2_game_name'] ?? ''));

  /* 你已匯入的舊資料 31～34，可以臨時跑一次： */
  /* $stmtFixGames = $pdo->prepare("
  SELECT id
    FROM tournament_match_games
    WHERE tournament_id = :tournament_id
      AND arena_id IS NOT NULL
      AND result_status IN ('pending','verified','tie','rematch','dispute')
      AND (
        player1_deck_code IS NULL
        OR player2_deck_code IS NULL
        OR player1_char1_id IS NULL
        OR player2_char1_id IS NULL
      )
    ORDER BY id ASC
  ");
  $stmtFixGames->execute([':tournament_id' => $tournamentId]);

  while ($row = $stmtFixGames->fetch(PDO::FETCH_ASSOC)) {
    ulgg_auto_fill_game_decks($pdo, (int)$row['id']);
  } */

  if ($p1Game !== '' && $p2Game !== '') {
    $matchGameRows = [];

    if ($match) {
      $stmtGames = $pdo->prepare("
    SELECT
      g.id AS game_id,
      g.game_no,
      g.result_status,
      g.player1_deck_code,
      g.player2_deck_code,
      g.player1_deck_cost,
      g.player2_deck_cost,
      g.winner_player_id,
      g.screenshot_path,
      g.note AS game_note,

      a.id AS arena_id,
      a.room_id,
      a.region,
      a.cost,
      a.stage,

      a.e1,
      a.e2,
      a.e3,
      a.u1,
      a.u2,
      a.u3,

      a.win,
      a.lose,
      a.tie,
      a.judge_source,
      a.update_time,
      a.username,

      a.name_p1,
      a.bp_p1,
      a.win_p1,
      a.draw_p1,
      a.lose_p1,

      a.name_p2,
      a.bp_p2,
      a.win_p2,
      a.draw_p2,
      a.lose_p2,

      a.ack1,
      a.ack2,
      a.is_read,
      a.eventindex1,
      a.eventindex2,

      winner.display_name AS winner_name

    FROM tournament_match_games g
    LEFT JOIN arena_unlight a
      ON a.id = g.arena_id
    LEFT JOIN tournament_players winner
      ON winner.id = g.winner_player_id
    WHERE g.match_id = :match_id
    ORDER BY 
    g.game_no ASC
  ");

      $stmtGames->execute([
        ':match_id' => (int)$match['id']
      ]);

      $matchGameRows = $stmtGames->fetchAll(PDO::FETCH_ASSOC);
    }
  }
}
$matchScore = null;

if ($match) {
  $matchScore = calculateTournamentMatchScore($pdo, $match);
}
$isForfeitMatch = false;
$forfeitWinnerName = '';
$forfeitLoserName = '';
$forfeitScoreText = '';

if ($match && (int)($match['winner_player_id'] ?? 0) > 0 && empty($matchGameRows)) {
  $winnerId = (int)$match['winner_player_id'];
  $p1Id = (int)($match['player1_id'] ?? 0);
  $p2Id = (int)($match['player2_id'] ?? 0);

  $p1Score = (int)($match['player1_score'] ?? 0);
  $p2Score = (int)($match['player2_score'] ?? 0);

  if ($winnerId === $p1Id) {
    $isForfeitMatch = true;
    $forfeitWinnerName = $match['player1_display_name'] ?: $match['player1_game_name'] ?: 'P1';
    $forfeitLoserName = $match['player2_display_name'] ?: $match['player2_game_name'] ?: 'P2';
    $forfeitScoreText = $p1Score . ' - ' . $p2Score;
  } elseif ($winnerId === $p2Id) {
    $isForfeitMatch = true;
    $forfeitWinnerName = $match['player2_display_name'] ?: $match['player2_game_name'] ?: 'P2';
    $forfeitLoserName = $match['player1_display_name'] ?: $match['player1_game_name'] ?: 'P1';
    $forfeitScoreText = $p1Score . ' - ' . $p2Score;
  }
}
?>

<style>
  :root {
    --ul-bg: #1c1f26;
    --ul-bg-soft: #252a34;
    --ul-panel: #2e3440;
    --ul-border: rgba(180, 200, 255, 0.18);
    --ul-blue: #6fa8ff;
    --ul-red: #ff6b6b;
    --ul-green: #4dd599;
    --ul-yellow: #e6c77a;
    --ul-text: #e6eaf2;
    --ul-text-soft: #b8c0d4;
    --ul-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
  }

  .t-page {
    color: var(--ul-text);
  }

  .t-hero {
    background:
      radial-gradient(circle at top left, rgba(111, 168, 255, .25), transparent 34%),
      linear-gradient(180deg, #2e3440, #20242d);
    border: 1px solid var(--ul-border);
    border-radius: 14px;
    padding: 18px;
    box-shadow: var(--ul-shadow);
    margin-bottom: 16px;
  }

  .t-kicker {
    color: var(--ul-blue);
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .08em;
    margin-bottom: 6px;
  }

  .t-title {
    font-size: 28px;
    font-weight: 800;
    margin: 0 0 8px;
  }

  .t-sub {
    color: var(--ul-text-soft);
    font-size: 14px;
  }

  .t-section {
    background: rgba(46, 52, 64, .92);
    border: 1px solid var(--ul-border);
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 14px;
    box-shadow: var(--ul-shadow);
  }

  .t-section-title {
    font-size: 18px;
    font-weight: 800;
    margin: 0 0 12px;
  }

  .t-match-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
  }

  .t-status {
    display: inline-block;
    border-radius: 999px;
    padding: 5px 10px;
    font-weight: 700;
    font-size: 12px;
  }

  .status-pending {
    background: #555;
    color: #fff;
  }

  .status-waiting {
    background: #2f5f9f;
    color: #fff;
  }

  .status-playing {
    background: #b8860b;
    color: #111;
  }

  .status-reported {
    background: #8764c9;
    color: #fff;
  }

  .status-confirmed,
  .status-finished {
    background: #2f8f61;
    color: #fff;
  }

  .status-dispute {
    background: #b73c3c;
    color: #fff;
  }

  .t-vs {
    display: grid;
    grid-template-columns: 1fr 90px 1fr;
    gap: 12px;
    align-items: stretch;
  }

  .t-player {
    background: linear-gradient(180deg, #303643, #242935);
    border: 1px solid var(--ul-border);
    border-radius: 12px;
    padding: 14px;
    display: flex;
    gap: 12px;
    align-items: center;
  }

  .t-player.is-winner {
    position: relative;
    border-color: rgba(255, 212, 121, .62);
    background:
      radial-gradient(circle at top left, rgba(255, 212, 121, .22), transparent 38%),
      linear-gradient(180deg, rgba(57, 76, 61, .96), rgba(36, 45, 40, .96));
    box-shadow:
      0 0 18px rgba(255, 212, 121, .20),
      inset 0 1px 0 rgba(255, 255, 255, .08);
  }

  .t-player.is-winner::after {
    content: "WINNER";
    position: absolute;
    right: 14px;
    top: 12px;
    font-size: 11px;
    font-weight: 950;
    letter-spacing: .08em;
    color: rgba(255, 212, 121, .9);
  }

  .t-player.right.is-winner::after {
    left: 14px;
    right: auto;
  }

  .t-player.is-winner .t-name {
    color: #fff8dc;
    text-shadow: 0 0 10px rgba(255, 212, 121, .28);
  }

  .t-player.is-loser {
    opacity: .58;
    filter: grayscale(.28);
  }

  .advance-badge {
    display: inline-flex;
    align-items: center;
    margin-left: 7px;
    padding: 2px 7px;
    border-radius: 999px;
    background: linear-gradient(135deg, #ffd479, #ff9f43);
    color: #211509;
    font-size: 11px;
    font-weight: 950;
    vertical-align: middle;
  }

  .t-player.right {
    flex-direction: row-reverse;
    text-align: right;
  }

  .match-settle-panel {
    margin-top: 14px;
    padding: 14px;
    border-radius: 14px;
    background:
      radial-gradient(circle at top left, rgba(230, 199, 122, .13), transparent 36%),
      linear-gradient(180deg, rgba(48, 54, 67, .96), rgba(33, 38, 50, .96));
    border: 1px solid rgba(230, 199, 122, .28);
    box-shadow: 0 10px 24px rgba(0, 0, 0, .24);
  }

  .match-settle-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 10px;
  }

  .match-settle-title {
    color: var(--ul-yellow);
    font-size: 15px;
    font-weight: 900;
    letter-spacing: .04em;
  }

  .match-settle-sub {
    color: var(--ul-text-soft);
    font-size: 12px;
    margin-top: 3px;
  }

  .match-settle-score {
    min-width: 76px;
    text-align: center;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(0, 0, 0, .24);
    border: 1px solid rgba(230, 199, 122, .34);
    color: #fff8dc;
    font-size: 18px;
    font-weight: 950;
  }

  .match-settle-body {
    display: grid;
    gap: 6px;
    margin-bottom: 10px;
  }

  .match-settle-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 7px 9px;
    border-radius: 10px;
    background: rgba(0, 0, 0, .16);
    color: var(--ul-text);
    font-size: 13px;
  }

  .match-settle-row.is-winner {
    background:
      radial-gradient(circle at top left, rgba(255, 212, 121, .22), transparent 42%),
      linear-gradient(135deg, rgba(77, 213, 153, .18), rgba(255, 212, 121, .11));
    border: 1px solid rgba(255, 212, 121, .42);
    box-shadow:
      0 0 16px rgba(255, 212, 121, .18),
      inset 0 1px 0 rgba(255, 255, 255, .08);
  }

  .match-settle-row.is-winner span {
    color: #fff8dc;
    font-weight: 950;
  }

  .match-settle-row.is-winner strong {
    color: #ffd479;
  }

  .match-settle-row.is-loser {
    opacity: .58;
    filter: grayscale(.25);
  }

  .match-settle-score.is-finalized {
    background:
      linear-gradient(135deg, rgba(77, 213, 153, .22), rgba(255, 212, 121, .18));
    border-color: rgba(255, 212, 121, .48);
    color: #ffd479;
  }

  .match-settle-row strong {
    color: #fff8dc;
  }

  .match-settle-message {
    margin-top: 6px;
    color: var(--ul-text-soft);
    font-size: 13px;
    line-height: 1.6;
  }

  .match-settle-message strong {
    color: var(--ul-yellow);
  }

  .match-settle-form {
    margin: 0;
  }

  .match-settle-btn {
    width: 100%;
    border: 0;
    border-radius: 12px;
    padding: 10px 14px;
    cursor: pointer;
    background: linear-gradient(135deg, #ffd479, #ff9f43);
    color: #211509;
    font-size: 14px;
    font-weight: 950;
    letter-spacing: .04em;
    box-shadow:
      0 12px 24px rgba(255, 159, 67, .23),
      inset 0 1px 0 rgba(255, 255, 255, .35);
    transition: transform .16s ease, filter .16s ease, box-shadow .16s ease;
  }

  .match-settle-btn:hover {
    transform: translateY(-1px);
    filter: brightness(1.05);
    box-shadow:
      0 16px 30px rgba(255, 159, 67, .32),
      inset 0 1px 0 rgba(255, 255, 255, .42);
  }

  .match-settle-wait,
  .match-settle-done {
    padding: 9px 10px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    text-align: center;
  }

  .match-settle-wait {
    color: rgba(226, 232, 240, .72);
    background: rgba(255, 255, 255, .05);
    border: 1px dashed rgba(180, 200, 255, .20);
  }

  .match-settle-done {
    color: #bfffe3;
    background: rgba(77, 213, 153, .12);
    border: 1px solid rgba(77, 213, 153, .30);
  }

  .t-avatar {
    width: 54px;
    height: 54px;
    border-radius: 50%;
    object-fit: cover;
    background: #111;
    border: 1px solid var(--ul-border);
  }

  .t-avatar-empty {
    width: 54px;
    height: 54px;
    border-radius: 50%;
    background: #111;
    border: 1px dashed var(--ul-border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ul-text-soft);
  }

  .t-seed {
    color: var(--ul-yellow);
    font-size: 12px;
    font-weight: 700;
  }

  .t-name {
    font-size: 22px;
    font-weight: 800;
    color: #fff;
  }

  .t-small {
    color: var(--ul-text-soft);
    font-size: 12px;
    margin-top: 2px;
  }

  .t-vs-mid {
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 24px;
    font-weight: 900;
    color: var(--ul-blue);
  }

  .t-ban-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .t-ban-card {
    background: linear-gradient(180deg, #3a2f37, #29242b);
    border: 1px solid rgba(255, 107, 107, .25);
    border-radius: 10px;
    padding: 12px;
  }

  .t-ban-title {
    color: #ff9d9d;
    font-weight: 800;
    margin-bottom: 5px;
  }

  .t-ban-main {
    font-size: 15px;
    color: #fff;
  }

  .t-ban-card-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }

  .t-ban-char-card {
    border-radius: 14px;
    padding: 13px;
    background:
      radial-gradient(circle at top left, rgba(255, 88, 88, .13), transparent 38%),
      linear-gradient(180deg, rgba(46, 52, 64, .96), rgba(31, 36, 46, .96));
    border: 1px solid rgba(255, 120, 120, .24);
    box-shadow: 0 8px 18px rgba(0, 0, 0, .20);
  }

  .t-ban-char-title {
    color: #ffb4b4;
    font-size: 13px;
    font-weight: 950;
    margin-bottom: 9px;
  }

  .t-ban-char-list {
    display: grid;
    gap: 7px;
  }

  .t-ban-char-row {
    display: grid;
    grid-template-columns: 42px 1fr auto auto;
    gap: 7px;
    align-items: center;
    padding: 8px 9px;
    border-radius: 10px;
    background: rgba(0, 0, 0, .18);
    border: 1px solid rgba(255, 255, 255, .06);
    color: rgba(226, 232, 240, .88);
  }

  .t-ban-char-row strong {
    color: #f8fafc;
    font-size: 13px;
  }

  .t-ban-char-row em {
    color: #ffd479;
    font-style: normal;
    font-size: 12px;
    font-weight: 900;
  }

  .t-ban-char-row b {
    color: rgba(226, 232, 240, .72);
    font-size: 11px;
  }



  .t-ban-admin-form {
    margin-top: 14px;
    padding: 14px;
    border-radius: 14px;
    background:
      radial-gradient(circle at top left, rgba(255, 212, 121, .10), transparent 38%),
      linear-gradient(180deg, rgba(38, 45, 58, .96), rgba(27, 32, 42, .96));
    border: 1px solid rgba(255, 212, 121, .24);
  }

  .t-ban-admin-title {
    color: var(--ul-yellow);
    font-size: 14px;
    font-weight: 950;
    margin-bottom: 10px;
  }

  .t-ban-admin-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
  }

  .t-ban-admin-card {
    border-radius: 12px;
    padding: 12px;
    background: rgba(0, 0, 0, .18);
    border: 1px solid rgba(255, 255, 255, .08);
  }

  .t-ban-admin-card-title {
    color: #f8fafc;
    font-size: 13px;
    font-weight: 900;
    margin-bottom: 9px;
  }

  .t-ban-input-row {
    display: grid;
    grid-template-columns: 90px 1fr 120px;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
  }

  .t-ban-label {
    display: block;
    color: rgba(226, 232, 240, .72);
    font-size: 12px;
    font-weight: 800;
  }

  .t-ban-select,
  .t-ban-note {
    width: 100%;
    border-radius: 10px;
    border: 1px solid rgba(180, 200, 255, .18);
    background: rgba(8, 12, 20, .82);
    color: #f8fafc;
    padding: 9px 10px;
    font-size: 13px;
    outline: none;
  }

  .t-ban-select:focus,
  .t-ban-note:focus {
    border-color: rgba(255, 212, 121, .45);
    box-shadow: 0 0 0 3px rgba(255, 212, 121, .10);
  }

  .t-ban-note {
    resize: vertical;
    margin-bottom: 10px;
  }

  .t-ban-save-btn {
    width: 100%;
    border: 0;
    border-radius: 12px;
    padding: 10px 14px;
    cursor: pointer;
    background: linear-gradient(135deg, #ffd479, #ff9f43);
    color: #211509;
    font-size: 14px;
    font-weight: 950;
    letter-spacing: .04em;
  }

  .t-ban-save-btn:hover {
    filter: brightness(1.06);
  }

  .t-ban-visual-cards {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 4px 0 12px;
    min-height: 76px;
  }

  .t-ban-visual-cards {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 6px 0 14px;
    min-height: 86px;
  }

  .t-ban-visual-card {
    position: relative;
    width: 80px;
    height: 80px;
    overflow: hidden;
    border-radius: 8px;
    border: 1px solid rgba(255, 120, 120, .32);
    background: #111;
    box-shadow:
      0 10px 18px rgba(0, 0, 0, .32),
      0 0 14px rgba(255, 88, 88, .13);
  }

  .t-ban-visual-card:not(:first-child) {
    margin-left: -14px;
  }

  .t-ban-visual-card img {
    width: 80px;
    height: 110px;
    object-fit: cover;
  }

  .t-ban-visual-card.is-empty {
    border-style: dashed;
    border-color: rgba(180, 200, 255, .18);
    background: rgba(0, 0, 0, .18);
    box-shadow: none;
  }

  .t-ban-visual-card.is-empty::after {
    display: none;
  }

  .t-ban-empty-card {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(226, 232, 240, .34);
    font-size: 22px;
    font-weight: 900;
  }

  .t-ban-visual-card.no-image {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 5px;
    text-align: center;
  }

  .t-ban-no-image-name {
    color: #f8fafc;
    font-size: 10px;
    font-weight: 900;
    line-height: 1.2;
  }

  .t-ban-no-image-level {
    margin-top: 3px;
    color: #ffd479;
    font-size: 10px;
    font-weight: 900;
  }



  .t-ban-char-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
  }

  .t-ban-char-subtitle {
    margin-top: 3px;
    color: rgba(226, 232, 240, .58);
    font-size: 12px;
    font-weight: 700;
  }

  .t-ban-side-badge {
    flex: 0 0 auto;
    border-radius: 999px;
    padding: 3px 8px;
    background: rgba(255, 88, 88, .16);
    border: 1px solid rgba(255, 88, 88, .34);
    color: #ffb4b4;
    font-size: 10px;
    font-weight: 950;
    letter-spacing: .08em;
  }

  .t-ban-char-card.left-side {
    border-color: rgba(111, 168, 255, .25);
  }

  .t-ban-char-card.right-side {
    border-color: rgba(255, 120, 120, .25);
  }

  .t-ban-admin-card-hint {
    color: rgba(226, 232, 240, .54);
    font-size: 12px;
    line-height: 1.5;
    margin: -3px 0 10px;
  }

  @media (max-width: 860px) {

    .t-ban-card-grid,
    .t-ban-admin-grid {
      grid-template-columns: 1fr;
    }

    .t-ban-input-row {
      grid-template-columns: 1fr;
    }
  }

  .t-fight-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .t-fight-card {
    display: grid;
    grid-template-columns: 1fr 110px 1fr;
    align-items: center;
    gap: 8px;
    background: linear-gradient(180deg, #303643, #252b36);
    border: 1px solid var(--ul-border);
    border-radius: 12px;
    padding: 10px;
  }

  .admin-game-judge-panel {
    grid-column: 1 / -1;
    margin-top: 8px;
  }

  .t-fight-side {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .t-fight-side.right {
    flex-direction: row-reverse;
    text-align: right;
  }

  .t-fight-info {
    min-width: 90px;
  }

  .t-fight-name {
    font-weight: 800;
    color: #fff;
  }

  .t-bp {
    font-size: 12px;
    color: var(--ul-text-soft);
  }

  .t-mid {
    text-align: center;
    color: var(--ul-text-soft);
    font-size: 12px;
    min-width: 130px;
  }

  .t-room {
    font-size: 11px;
    color: #8fa0bd;
    word-break: break-all;
    margin-top: 4px;
  }

  .result-pill {
    display: inline-block;
    min-width: 42px;
    border-radius: 8px;
    padding: 4px 13px;
    font-weight: 800;
    font-size: 13px;
    margin-bottom: 5px;
  }

  .result-line {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 5px;
  }

  .result-line.right {
    justify-content: flex-end;
  }

  .quick-judge-form {
    display: inline-flex;
    margin: 0;
  }

  .quick-judge-btn {
    border: 0;
    border-radius: 999px;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 900;
    cursor: pointer;
    color: #111827;
    background: linear-gradient(180deg, #e6c77a, #b89a56);
    box-shadow: 0 0 10px rgba(230, 199, 122, .22);
    transition: transform .14s ease, filter .14s ease, box-shadow .14s ease;
    white-space: nowrap;
  }

  .quick-judge-btn:hover {
    transform: translateY(-1px);
    filter: brightness(1.06);
    box-shadow: 0 0 14px rgba(230, 199, 122, .36);
  }

  .result-win {
    background: #2e8f61;
    color: #fff;
  }

  .result-lose {
    background: #b73c3c;
    color: #fff;
  }

  .result-tie {
    background: #b79534;
    color: #111;
  }

  .result-unknown {
    background: #6f6f6f;
    color: #fff;
  }

  .t-chars {
    display: flex;
    align-items: center;
  }

  .t-char-card {
    width: 80px;
    height: 80px;
    overflow: hidden;
    border-radius: 6px;
    border: 1px solid var(--ul-border);
    background: #111;
  }

  .t-char-card:not(:first-child) {
    margin-left: -25px;
  }

  .t-char-card img {
    width: 80px;
    height: 110px;
    object-fit: cover;
  }

  .t-char-empty {
    color: var(--ul-text-soft);
    font-size: 12px;
  }

  .t-empty {
    color: var(--ul-text-soft);
    text-align: center;
    padding: 24px 8px;
    border: 1px dashed var(--ul-border);
    border-radius: 10px;
    background: rgba(0, 0, 0, .12);
  }

  .t-actions {
    margin-top: 12px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .t-btn {
    display: inline-block;
    padding: 8px 12px;
    border-radius: 8px;
    background: linear-gradient(180deg, #263044, #1c2333);
    color: #e6ecff;
    border: 1px solid rgba(140, 180, 255, 0.35);
    text-decoration: none;
    font-weight: 700;
  }

  .t-btn:hover {
    color: #fff;
    text-decoration: none;
    box-shadow: 0 0 12px rgba(120, 170, 255, .45);
  }

  .server-icon.dmm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 4px;
    background: rgba(255, 255, 255, .18);
    color: #fff;
  }

  @media (max-width: 768px) {

    .t-vs,
    .t-fight-card {
      grid-template-columns: 1fr;
    }

    .t-vs-mid {
      padding: 4px 0;
    }

    .t-ban-grid {
      grid-template-columns: 1fr;
    }

    .t-player,
    .t-fight-side {
      flex-direction: row;
      text-align: left;
    }

    .t-mid {
      border-top: 1px solid var(--ul-border);
      border-bottom: 1px solid var(--ul-border);
      padding: 8px 0;
    }

    .admin-game-judge-panel {
      grid-column: auto;
    }

    .result-line.right {
      justify-content: flex-start;
    }
  }

  .t-alert {
    background: rgba(77, 213, 153, .14);
    border: 1px solid rgba(77, 213, 153, .35);
    color: #bfffe3;
    border-radius: 10px;
    padding: 10px 12px;
    margin: 10px 0;
    font-weight: 700;
  }

  .room2-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .room2-card {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    background: linear-gradient(180deg, #303643, #252b36);
    border: 1px solid var(--ul-border);
    border-radius: 12px;
    padding: 12px;
  }

  .room2-main {
    min-width: 0;
  }

  .room2-title {
    color: #fff;
    font-size: 17px;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .room2-vs {
    color: var(--ul-blue);
    font-weight: 900;
    margin: 0 8px;
  }

  .room2-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    color: var(--ul-text-soft);
    font-size: 12px;
    margin-bottom: 4px;
  }

  .room2-id {
    color: #8fa0bd;
    font-size: 11px;
    word-break: break-all;
    margin-top: 5px;
  }

  .room2-action {
    flex: 0 0 auto;
  }

  @media (max-width: 768px) {
    .room2-card {
      flex-direction: column;
      align-items: stretch;
    }

    .room2-action .t-btn {
      width: 100%;
      text-align: center;
    }
  }

  .candidate-card {
    border-color: rgba(230, 199, 122, 0.35);
    background:
      radial-gradient(circle at top, rgba(230, 199, 122, 0.10), transparent 36%),
      linear-gradient(180deg, #303643, #252b36);
  }

  .candidate-label {
    display: inline-block;
    background: linear-gradient(180deg, #b89a56, #8a713a);
    color: #111;
    font-weight: 800;
    font-size: 12px;
    padding: 4px 8px;
    border-radius: 999px;
    margin-bottom: 6px;
  }

  .candidate-import-form {
    margin-top: 8px;
  }

  .candidate-import-btn {
    width: 100%;
    text-align: center;
    border-color: rgba(230, 199, 122, 0.55);
  }

  .candidate-import-btn:hover {
    box-shadow: 0 0 12px rgba(230, 199, 122, 0.45);
  }

  .t-detail-btn {
    padding: 5px 9px;
    font-size: 12px;
  }

  .admin-game-judge-form {
    padding: 10px;
    border-radius: 12px;
    background:
      radial-gradient(circle at top left, rgba(230, 199, 122, .13), transparent 38%),
      rgba(0, 0, 0, .18);
    border: 1px solid rgba(230, 199, 122, .28);
  }

  .admin-game-judge-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 8px;
  }

  .admin-game-judge-current {
    color: rgba(184, 192, 212, .82);
    font-size: 12px;
    font-weight: 700;
  }

  .admin-game-judge-title {
    color: var(--ul-yellow);
    font-size: 12px;
    font-weight: 900;
    margin-bottom: 6px;
  }

  .admin-game-note {
    width: 100%;
    box-sizing: border-box;
    margin-bottom: 6px;
    padding: 6px 8px;
    border-radius: 8px;
    border: 1px solid rgba(180, 200, 255, .18);
    background: rgba(20, 24, 32, .92);
    color: #fff;
    font-size: 12px;
    outline: none;
  }

  .admin-game-note::placeholder {
    color: rgba(184, 192, 212, .58);
  }

  .admin-game-judge-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    justify-content: center;
  }

  .judge-btn {
    border: 0;
    border-radius: 8px;
    padding: 5px 8px;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
    color: #fff;
    transition: transform .14s ease, filter .14s ease, box-shadow .14s ease;
  }

  .judge-btn:hover {
    transform: translateY(-1px);
    filter: brightness(1.08);
  }

  .judge-win {
    background: linear-gradient(180deg, #2f7fd6, #225a9c);
    box-shadow: 0 0 10px rgba(111, 168, 255, .22);
  }

  .judge-yellow {
    background: linear-gradient(180deg, #c79b3b, #8d6c23);
    color: #15100a;
  }

  .judge-red {
    background: linear-gradient(180deg, #c94a4a, #8f2929);
  }

  .judge-gray {
    background: linear-gradient(180deg, #6b7280, #3f4652);
  }

  .manual-import-panel {
    margin-top: 14px;
    padding: 14px;
    border-radius: 14px;
    background:
      radial-gradient(circle at top left, rgba(111, 168, 255, .13), transparent 38%),
      linear-gradient(180deg, rgba(38, 45, 58, .96), rgba(27, 32, 42, .96));
    border: 1px solid rgba(111, 168, 255, .28);
  }

  .manual-import-title {
    color: var(--ul-blue);
    font-size: 14px;
    font-weight: 950;
    margin-bottom: 6px;
  }

  .manual-import-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }

  .manual-import-card {
    padding: 10px;
    border-radius: 12px;
    background: rgba(0, 0, 0, .16);
    border: 1px solid rgba(255, 255, 255, .08);
  }

  @media (max-width: 768px) {
    .manual-import-grid {
      grid-template-columns: 1fr;
    }
  }

  .match-forfeit-panel {
    margin-top: 14px;
    padding: 14px;
    border-radius: 14px;
    background:
      radial-gradient(circle at top left, rgba(255, 107, 107, .14), transparent 38%),
      linear-gradient(180deg, rgba(48, 38, 43, .96), rgba(34, 29, 35, .96));
    border: 1px solid rgba(255, 107, 107, .32);
    box-shadow: 0 10px 24px rgba(0, 0, 0, .24);
  }

  .match-forfeit-title {
    color: #ffb4b4;
    font-size: 15px;
    font-weight: 950;
    margin-bottom: 5px;
  }

  .match-forfeit-sub {
    color: var(--ul-text-soft);
    font-size: 12px;
    line-height: 1.6;
    margin-bottom: 10px;
  }

  .match-forfeit-form {
    margin: 0;
  }

  .match-forfeit-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 10px;
  }

  .match-forfeit-label {
    display: block;
    color: rgba(226, 232, 240, .76);
    font-size: 12px;
    font-weight: 900;
    margin-bottom: 5px;
  }

  .match-forfeit-select,
  .match-forfeit-input {
    width: 100%;
    border-radius: 10px;
    border: 1px solid rgba(180, 200, 255, .18);
    background: rgba(8, 12, 20, .82);
    color: #f8fafc;
    padding: 9px 10px;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
  }

  .match-forfeit-select:focus,
  .match-forfeit-input:focus {
    border-color: rgba(255, 107, 107, .48);
    box-shadow: 0 0 0 3px rgba(255, 107, 107, .10);
  }

  .match-forfeit-btn {
    width: 100%;
    margin-top: 10px;
    border: 0;
    border-radius: 12px;
    padding: 10px 14px;
    cursor: pointer;
    background: linear-gradient(135deg, #ff8a8a, #c94a4a);
    color: #fff;
    font-size: 14px;
    font-weight: 950;
    letter-spacing: .04em;
    box-shadow:
      0 12px 24px rgba(201, 74, 74, .24),
      inset 0 1px 0 rgba(255, 255, 255, .24);
  }

  .match-forfeit-btn:hover {
    filter: brightness(1.06);
  }

  @media (max-width: 768px) {
    .match-forfeit-grid {
      grid-template-columns: 1fr;
    }
  }

  .t-collapsible-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
  }

  .t-collapsible-head .t-section-title {
    margin-bottom: 0;
  }

  .t-lock-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 3px 8px;
    background: rgba(230, 199, 122, .16);
    border: 1px solid rgba(230, 199, 122, .34);
    color: #ffd479;
    font-size: 11px;
    font-weight: 950;
    white-space: nowrap;
  }

  .t-admin-collapse {
    border-radius: 12px;
    background: rgba(0, 0, 0, .16);
    border: 1px solid rgba(180, 200, 255, .16);
    overflow: hidden;
  }

  .t-admin-collapse summary {
    cursor: pointer;
    list-style: none;
    padding: 10px 12px;
    color: #dbe6ff;
    font-size: 13px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }

  .t-admin-collapse summary::-webkit-details-marker {
    display: none;
  }

  .t-admin-collapse summary::before {
    content: "▶";
    color: var(--ul-blue);
    font-size: 11px;
    margin-right: 6px;
  }

  .t-admin-collapse[open] summary::before {
    content: "▼";
  }

  .t-admin-collapse-body {
    padding: 12px;
    border-top: 1px solid rgba(255, 255, 255, .08);
  }

  .t-lock-note {
    color: rgba(226, 232, 240, .72);
    background: rgba(0, 0, 0, .18);
    border: 1px dashed rgba(230, 199, 122, .28);
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 13px;
    line-height: 1.6;
  }

  .t-ban-summary-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    padding: 8px 10px;
    border-radius: 10px;
    background: rgba(0, 0, 0, .18);
    border: 1px solid rgba(255, 255, 255, .08);
    color: rgba(226, 232, 240, .92);
    font-size: 13px;
    line-height: 1.5;
  }

  .t-ban-summary-row.is-empty {
    color: rgba(226, 232, 240, .52);
    justify-content: center;
  }

  .t-ban-summary-label {
    flex: 0 0 auto;
    color: #ffb4b4;
    font-size: 12px;
    font-weight: 950;
  }

  .t-ban-summary-main {
    min-width: 0;
    flex: 1 1 auto;
    color: #f8fafc;
    font-weight: 800;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .t-ban-summary-cost {
    flex: 0 0 auto;
    color: #ffd479;
    font-size: 12px;
    font-weight: 950;
  }

  @media (max-width: 768px) {
    .t-ban-summary-row {
      align-items: flex-start;
      flex-direction: column;
      gap: 4px;
    }

    .t-ban-summary-main {
      white-space: normal;
      overflow: visible;
      text-overflow: clip;
    }
  }

  .t-forfeit-result-card {
    border-radius: 14px;
    padding: 16px;
    background:
      radial-gradient(circle at top left, rgba(255, 212, 121, .16), transparent 38%),
      linear-gradient(180deg, rgba(48, 54, 67, .96), rgba(33, 38, 50, .96));
    border: 1px solid rgba(255, 212, 121, .32);
    box-shadow: 0 10px 24px rgba(0, 0, 0, .24);
    text-align: center;
  }

  .t-forfeit-result-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    padding: 4px 10px;
    border-radius: 999px;
    color: #211509;
    background: linear-gradient(135deg, #ffd479, #ff9f43);
    font-size: 12px;
    font-weight: 950;
  }

  .t-forfeit-result-main {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 8px;
  }

  .t-forfeit-winner {
    color: #fff8dc;
    font-size: 20px;
    font-weight: 950;
    text-shadow: 0 0 10px rgba(255, 212, 121, .28);
  }

  .t-forfeit-loser {
    color: rgba(226, 232, 240, .56);
    font-size: 18px;
    font-weight: 800;
    text-decoration: line-through;
  }

  .t-forfeit-score {
    min-width: 74px;
    padding: 6px 12px;
    border-radius: 999px;
    color: #ffd479;
    background: rgba(0, 0, 0, .24);
    border: 1px solid rgba(255, 212, 121, .34);
    font-size: 18px;
    font-weight: 950;
  }

  .t-forfeit-result-note {
    color: var(--ul-text-soft);
    font-size: 13px;
    line-height: 1.6;
  }

  .t-forfeit-note-detail {
    margin-top: 12px;
    text-align: left;
    background: rgba(0, 0, 0, .18);
    border: 1px solid rgba(180, 200, 255, .16);
    border-radius: 10px;
    overflow: hidden;
  }

  .t-forfeit-note-detail summary {
    cursor: pointer;
    padding: 8px 10px;
    color: #dbe6ff;
    font-size: 12px;
    font-weight: 900;
  }

  .t-forfeit-note-detail pre {
    margin: 0;
    padding: 10px;
    color: rgba(226, 232, 240, .78);
    font-size: 12px;
    line-height: 1.6;
    white-space: pre-wrap;
    border-top: 1px solid rgba(255, 255, 255, .08);
  }

  .manual-time-wrap {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
  }

  .manual-time-input {
    padding-right: 52px;
    min-height: 42px;
    font-weight: 800;
  }

  .manual-time-icon {
    position: absolute;
    right: 7px;
    top: 6px;
    bottom: 6px;
    width: 30px;
    border-radius: 8px;
    background: linear-gradient(180deg, #6fa8ff, #315d9c);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    font-size: 18px;
    box-shadow: 0 0 12px rgba(111, 168, 255, .35);
  }

  .manual-time-input::-webkit-calendar-picker-indicator {
    opacity: 1;
    cursor: pointer;
    width: 23px;
    height: 20px;
    padding: 0;
    margin-right: -44px;
    z-index: 2;
    position: relative;
  }

  .manual-time-input:hover {
    border-color: rgba(111, 168, 255, .55);
    box-shadow: 0 0 0 3px rgba(111, 168, 255, .12);
  }

  .match-schedule-admin-panel {
    margin-top: 14px;
    padding: 14px;
    border-radius: 14px;
    background:
      radial-gradient(circle at top left, rgba(111, 168, 255, .14), transparent 38%),
      linear-gradient(180deg, rgba(38, 45, 58, .96), rgba(27, 32, 42, .96));
    border: 1px solid rgba(111, 168, 255, .30);
    box-shadow: 0 10px 24px rgba(0, 0, 0, .22);
  }

  .match-schedule-admin-title {
    color: #9ed5ff;
    font-size: 14px;
    font-weight: 950;
    margin-bottom: 4px;
  }

  .match-schedule-admin-sub {
    color: rgba(226, 232, 240, .68);
    font-size: 12px;
    line-height: 1.6;
    margin-bottom: 10px;
  }

  .match-schedule-admin-form {
    display: flex;
    gap: 8px;
    align-items: center;
    margin: 0;
  }

  .match-schedule-input {
    flex: 1 1 auto;
    min-height: 42px;
    border-radius: 10px;
    border: 1px solid rgba(180, 200, 255, .22);
    background: rgba(8, 12, 20, .86);
    color: #f8fafc;
    padding: 9px 10px;
    font-size: 14px;
    font-weight: 800;
    outline: none;
  }

  .match-schedule-input:focus {
    border-color: rgba(111, 168, 255, .62);
    box-shadow: 0 0 0 3px rgba(111, 168, 255, .12);
  }

  .match-schedule-btn {
    flex: 0 0 auto;
    min-height: 42px;
    border: 0;
    border-radius: 10px;
    padding: 0 14px;
    cursor: pointer;
    color: #101827;
    background: linear-gradient(135deg, #9ed5ff, #6fa8ff);
    font-size: 13px;
    font-weight: 950;
    box-shadow:
      0 10px 20px rgba(111, 168, 255, .20),
      inset 0 1px 0 rgba(255, 255, 255, .28);
  }

  .match-schedule-btn:hover {
    filter: brightness(1.06);
    transform: translateY(-1px);
  }

  @media (max-width: 768px) {
    .match-schedule-admin-form {
      flex-direction: column;
      align-items: stretch;
    }

    .match-schedule-btn {
      width: 100%;
    }
  }

  .match-schedule-admin-panel>summary {
    list-style: none;
  }

  .match-schedule-admin-panel>summary::-webkit-details-marker {
    display: none;
  }

  .match-schedule-admin-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    cursor: pointer;
  }

  .match-schedule-collapse-icon {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 999px;
    color: #9ed5ff;
    background: rgba(111, 168, 255, .12);
    border: 1px solid rgba(111, 168, 255, .28);
    font-size: 12px;
    font-weight: 900;
    transition: transform .18s ease;
  }

  .match-schedule-admin-panel[open] .match-schedule-collapse-icon {
    transform: rotate(180deg);
  }

  .match-schedule-admin-panel:not([open]) {
    padding-bottom: 14px;
  }

  .match-schedule-admin-panel:not([open]) .match-schedule-admin-form,
  .match-schedule-admin-panel:not([open]) .match-schedule-admin-note {
    display: none;
  }

  .match-schedule-admin-note {
    margin-top: 8px;
    color: rgba(226, 232, 240, .58);
    font-size: 12px;
    line-height: 1.6;
  }

  .match-forfeit-panel>summary {
    list-style: none;
  }

  .match-forfeit-panel>summary::-webkit-details-marker {
    display: none;
  }

  .match-forfeit-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    cursor: pointer;
  }

  .match-forfeit-collapse-icon {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 999px;
    color: #ffb4b4;
    background: rgba(255, 90, 90, .12);
    border: 1px solid rgba(255, 90, 90, .28);
    font-size: 12px;
    font-weight: 900;
    transition: transform .18s ease;
  }

  .match-forfeit-panel[open] .match-forfeit-collapse-icon {
    transform: rotate(180deg);
  }

  .match-forfeit-body {
    margin-top: 12px;
  }

  .match-forfeit-panel:not([open]) .match-forfeit-body {
    display: none;
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container t-page">

      <?php if (!$match): ?>
        <div class="t-section">
          <h2 class="t-section-title">找不到場次</h2>
          <div class="t-empty">
            找不到 <?= h($matchCode) ?>，請確認 tournament_matches 是否已有這筆資料。
          </div>
          <div class="t-actions">
            <a class="t-btn" href="/pages/tournament/ulgg_cup.php">返回 ULGG 杯</a>
          </div>
        </div>
      <?php else: ?>

        <?php
        [$statusText, $statusClass] = matchStatusLabel($match['status']);
        $p1Name = $match['player1_display_name'] ?: ('Seed ' . $match['player1_seed_no']);
        $p2Name = $match['player2_display_name'] ?: ('Seed ' . $match['player2_seed_no']);

        $viewerWinnerId = (int)($match['winner_player_id'] ?? 0);

        $p1ViewerIsWinner = $viewerWinnerId > 0 && $viewerWinnerId === (int)$match['player1_id'];
        $p2ViewerIsWinner = $viewerWinnerId > 0 && $viewerWinnerId === (int)$match['player2_id'];

        $p1ViewerIsLoser = $viewerWinnerId > 0 && !$p1ViewerIsWinner;
        $p2ViewerIsLoser = $viewerWinnerId > 0 && !$p2ViewerIsWinner;
        ?>

        <div class="t-hero">
          <div class="t-match-head">
            <div>
              <div class="t-kicker">Unlight：Revive 非官方 ULGG 杯</div>
              <h1 class="t-title"><?= h($match['match_code']) ?>｜<?= h($match['round_name']) ?></h1>
              <div class="t-sub">
                截止時間：<?= h($match['deadline_text'] ?: '-') ?>
                <?php if (!empty($match['scheduled_at'])): ?>
                  ｜預定時間：<?= h($match['scheduled_at']) ?>
                <?php endif; ?>
              </div>
            </div>
            <div>
              <span class="t-status <?= h($statusClass) ?>"><?= h($statusText) ?></span>
            </div>
          </div>
          <?php if ($isAdmin && !$isMatchLocked): ?>
            <?php
            $scheduledInputValue = '';

            if (!empty($match['scheduled_at'])) {
              $scheduledInputValue = date('Y-m-d\TH:i', strtotime($match['scheduled_at']));
            }
            ?>

            <details class="match-schedule-admin-panel" <?= empty($scheduledInputValue) ? 'open' : '' ?>>
              <summary class="match-schedule-admin-summary">
                <div>
                  <div class="match-schedule-admin-title">管理員預計對戰時間</div>
                  <div class="match-schedule-admin-sub">
                    <?php if (!empty($scheduledInputValue)): ?>
                      目前預定：<?= h(date('Y/m/d H:i', strtotime($match['scheduled_at']))) ?>，點擊展開可修改並重新推播。
                    <?php else: ?>
                      尚未設定預計對戰時間，點擊展開設定。
                    <?php endif; ?>
                  </div>
                </div>

                <span class="match-schedule-collapse-icon">▼</span>
              </summary>

              <form
                method="POST"
                class="match-schedule-admin-form"
                onsubmit="return confirm('確定要更新預計對戰時間並推播公告？');">
                <input type="hidden" name="action" value="admin_update_match_schedule">

                <input
                  type="datetime-local"
                  name="scheduled_at"
                  class="match-schedule-input"
                  value="<?= h($scheduledInputValue) ?>">

                <button type="submit" class="match-schedule-btn">
                  儲存並推播公告
                </button>
              </form>

              <div class="match-schedule-admin-note">
                送出後會立即推播對戰時間公告，並建立開賽前 10 分鐘提醒。
              </div>
            </details>
          <?php endif; ?>

          <div class="t-actions">
            <a class="t-btn" href="/pages/tournament/ulgg_cup.php">← 返回賽程表</a>

            <?php if (!empty($match['player1_game_name']) && !empty($match['player2_game_name'])): ?>
              <a class="t-btn"
                href="/pages/fight.php?player_name=<?= urlencode($match['player1_game_name']) ?>&player2=<?= urlencode($match['player2_game_name']) ?>">
                查看 玩家之間對戰紀錄
              </a>
            <?php endif; ?>


          </div>
        </div>

        <div class="t-section">
          <h2 class="t-section-title">對戰組合</h2>

          <div class="t-vs">
            <div class="t-player <?= $p1ViewerIsWinner ? 'is-winner' : ($p1ViewerIsLoser ? 'is-loser' : '') ?>">
              <?php if (!empty($match['player1_icon'])): ?>
                <img class="t-avatar" src="<?= h($match['player1_icon']) ?>" loading="lazy">
              <?php else: ?>
                <div class="t-avatar-empty">P1</div>
              <?php endif; ?>

              <div>
                <div class="t-seed">
                  Seed <?= h($match['player1_seed_no']) ?>
                  <?php if ($p1ViewerIsWinner): ?>
                    <span class="advance-badge">晉級</span>
                  <?php endif; ?>
                </div>
                <div class="t-name"><?= h($p1Name) ?></div>
                <div class="t-small">UL：<?= h($match['player1_game_name'] ?: '-') ?></div>
              </div>
            </div>

            <div class="t-vs-mid">VS</div>

            <div class="t-player right <?= $p2ViewerIsWinner ? 'is-winner' : ($p2ViewerIsLoser ? 'is-loser' : '') ?>">
              <?php if (!empty($match['player2_icon'])): ?>
                <img class="t-avatar" src="<?= h($match['player2_icon']) ?>" loading="lazy">
              <?php else: ?>
                <div class="t-avatar-empty">P2</div>
              <?php endif; ?>

              <div>
                <div class="t-seed">
                  Seed <?= h($match['player2_seed_no']) ?>
                  <?php if ($p2ViewerIsWinner): ?>
                    <span class="advance-badge">晉級</span>
                  <?php endif; ?>
                </div>
                <div class="t-name"><?= h($p2Name) ?></div>
                <div class="t-small">UL：<?= h($match['player2_game_name'] ?: '-') ?></div>
              </div>
            </div>
          </div>
          <?php if ($isAdmin && $matchScore): ?>
            <?php
            $isWalkoverMatch =
              (int)($match['winner_player_id'] ?? 0) > 0 &&
              empty($matchGameRows);

            $isMatchConfirmed = in_array((string)$match['status'], ['confirmed', 'finished'], true);
            ?>

            <?php if ($isWalkoverMatch): ?>
              <?php
              $walkoverWinnerId = (int)$match['winner_player_id'];

              $p1IsWinner = $walkoverWinnerId === (int)$match['player1_id'];
              $p2IsWinner = $walkoverWinnerId === (int)$match['player2_id'];

              if ($p1IsWinner) {
                $walkoverWinnerName = $p1Name;
                $walkoverLoserName = $p2Name;
              } elseif ($p2IsWinner) {
                $walkoverWinnerName = $p2Name;
                $walkoverLoserName = $p1Name;
              } else {
                $walkoverWinnerName = '-';
                $walkoverLoserName = '-';
              }
              ?>

              <div class="match-settle-panel">
                <div class="match-settle-head">
                  <div>
                    <div class="match-settle-title">管理員結算</div>
                    <div class="match-settle-sub">
                      本場未進行正式小局，已由棄賽 / 主辦判定晉級。
                    </div>
                  </div>

                  <div class="match-settle-score is-finalized">
                    0 - 0
                  </div>
                </div>

                <div class="match-settle-body">
                  <div class="match-settle-row <?= $p1IsWinner ? 'is-winner' : 'is-loser' ?>">
                    <span>
                      <?= $p1IsWinner ? '🏆 ' : '' ?><?= h($p1Name) ?>
                    </span>
                    <strong><?= $p1IsWinner ? '棄賽晉級' : '棄賽 / 敗退' ?></strong>
                  </div>

                  <div class="match-settle-row <?= $p2IsWinner ? 'is-winner' : 'is-loser' ?>">
                    <span>
                      <?= $p2IsWinner ? '🏆 ' : '' ?><?= h($p2Name) ?>
                    </span>
                    <strong><?= $p2IsWinner ? '棄賽晉級' : '棄賽 / 敗退' ?></strong>
                  </div>

                  <div class="match-settle-message">
                    <strong><?= h($walkoverLoserName) ?></strong> 棄賽 / 敗退，
                    <strong><?= h($walkoverWinnerName) ?></strong> 晉級。
                  </div>
                </div>

                <div class="match-settle-done">
                  此場次已結算。
                </div>
              </div>
            <?php else: ?>
              <?php
              $p1Score = (int)$matchScore['p1_score'];
              $p2Score = (int)$matchScore['p2_score'];

              $settleWinnerId = (int)(
                $match['winner_player_id']
                ?: ($matchScore['winner_player_id'] ?? 0)
              );

              $p1IsWinner = $settleWinnerId > 0 && $settleWinnerId === (int)$match['player1_id'];
              $p2IsWinner = $settleWinnerId > 0 && $settleWinnerId === (int)$match['player2_id'];

              $winnerText = '-';

              if ($p1IsWinner) {
                $winnerText = $p1Name;
              } elseif ($p2IsWinner) {
                $winnerText = $p2Name;
              }
              ?>

              <div class="match-settle-panel">
                <div class="match-settle-head">
                  <div>
                    <div class="match-settle-title">管理員結算</div>
                    <div class="match-settle-sub">
                      依已確認小局 verified 統計，任一方達 2 勝即可結算晉級。
                    </div>
                  </div>

                  <div class="match-settle-score <?= $settleWinnerId > 0 ? 'is-finalized' : '' ?>">
                    <?= h($p1Score) ?> - <?= h($p2Score) ?>
                  </div>
                </div>

                <div class="match-settle-body">
                  <div class="match-settle-row <?= $p1IsWinner ? 'is-winner' : ($settleWinnerId > 0 ? 'is-loser' : '') ?>">
                    <span>
                      <?= $p1IsWinner ? '🏆 ' : '' ?><?= h($p1Name) ?>
                    </span>
                    <strong><?= h($p1Score) ?> 勝</strong>
                  </div>

                  <div class="match-settle-row <?= $p2IsWinner ? 'is-winner' : ($settleWinnerId > 0 ? 'is-loser' : '') ?>">
                    <span>
                      <?= $p2IsWinner ? '🏆 ' : '' ?><?= h($p2Name) ?>
                    </span>
                    <strong><?= h($p2Score) ?> 勝</strong>
                  </div>

                  <div class="match-settle-message">
                    <?= h($matchScore['message']) ?>
                    <?php if ($winnerText !== '-'): ?>
                      ｜可晉級者：<strong><?= h($winnerText) ?></strong>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if ($isMatchConfirmed): ?>
                  <div class="match-settle-done">
                    此場次已結算。
                  </div>
                <?php elseif (!empty($matchScore['can_finalize'])): ?>
                  <form
                    method="POST"
                    class="match-settle-form"
                    onsubmit="return confirm('確定要結算本場並讓 <?= h($winnerText) ?> 晉級？此操作會更新主賽程與下一輪。');">
                    <input type="hidden" name="action" value="admin_finalize_match">

                    <button type="submit" class="match-settle-btn">
                      結算本場並晉級
                    </button>
                  </form>
                <?php else: ?>
                  <div class="match-settle-wait">
                    尚未達成結算條件。
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($isAdmin && !in_array((string)$match['status'], ['confirmed', 'finished'], true)): ?>
            <details class="match-forfeit-panel">
              <summary class="match-forfeit-summary">
                <div>
                  <div class="match-forfeit-title">管理員棄賽判定</div>
                  <div class="match-forfeit-sub">
                    點擊展開。棄賽判定會讓對手晉級，比分記錄為 0 - 0。
                  </div>
                </div>

                <span class="match-forfeit-collapse-icon">▼</span>
              </summary>

              <div class="match-forfeit-body">
                <div class="match-forfeit-sub">
                  選手通知棄賽、逾時未聯絡或主辦判定時使用。此操作會直接讓對手因棄賽判定晉級，並推進下一輪。
                </div>

                <form
                  method="POST"
                  class="match-forfeit-form"
                  onsubmit="return confirm('確定要執行棄賽判定？此操作會直接結算本場並推進賽程。');">

                  <input type="hidden" name="action" value="admin_forfeit_match">

                  <div class="match-forfeit-grid">
                    <div>
                      <label class="match-forfeit-label">棄賽方</label>
                      <select name="forfeit_side" class="match-forfeit-select" required>
                        <option value="">請選擇棄賽方</option>
                        <option value="p1"><?= h($p1Name) ?> 棄賽</option>
                        <option value="p2"><?= h($p2Name) ?> 棄賽</option>
                      </select>
                    </div>

                    <div>
                      <label class="match-forfeit-label">原因</label>
                      <select name="forfeit_type" class="match-forfeit-select" required>
                        <option value="withdrawn">主動棄賽</option>
                        <option value="timeout">逾時未聯絡</option>
                        <option value="dq">違規失格</option>
                        <option value="admin">主辦手動判定</option>
                      </select>
                    </div>
                  </div>

                  <label class="match-forfeit-label">備註</label>
                  <input
                    type="text"
                    name="forfeit_note"
                    class="match-forfeit-input"
                    placeholder="例如：選手於 Discord 通知退賽、逾時未回報、主辦確認失格">

                  <button type="submit" class="match-forfeit-btn">
                    確認棄賽並讓對手晉級
                  </button>
                </form>
              </div>
            </details>
          <?php endif; ?>
        </div>

        <div class="t-section">
          <div class="t-collapsible-head">
            <h2 class="t-section-title">Ban 資訊</h2>

            <?php if ($isMatchLocked): ?>
              <span class="t-lock-badge">已鎖定</span>
            <?php endif; ?>
          </div>



          <?php if (!$banCards): ?>
            <div class="t-empty">
              尚未登錄 BAN 角色。之後可由管理員依雙方截圖填入被 BAN 的角色。
            </div>
          <?php else: ?>
            <div class="t-ban-card-grid">
              <?php foreach (
                [
                  'p1' => [
                    'title' => $p1Name . ' 被 BAN 的牌組',
                    'sub' => '由 ' . $p2Name . ' BAN',
                  ],
                  'p2' => [
                    'title' => $p2Name . ' 被 BAN 的牌組',
                    'sub' => '由 ' . $p1Name . ' BAN',
                  ],
                ] as $side => $banBlock
              ): ?>
                <div class="t-ban-char-card <?= $side === 'p1' ? 'left-side' : 'right-side' ?>">
                  <div class="t-ban-char-head">
                    <div>
                      <div class="t-ban-char-title">
                        <?= h($banBlock['title']) ?>
                      </div>
                      <div class="t-ban-char-subtitle">
                        <?= h($banBlock['sub']) ?>
                      </div>
                    </div>

                    <div class="t-ban-side-badge">
                      <?= $side === 'p1' ? 'LEFT' : 'RIGHT' ?>
                    </div>
                  </div>

                  <div class="t-ban-char-list">
                    <?= renderBanCardsVisual($pdo, $banCardMap[$side] ?? []) ?>

                    <?php
                    $banDeckParts = [];
                    $banDeckCost = 0;
                    $hasBanDeckCard = false;

                    for ($slotNo = 1; $slotNo <= 3; $slotNo++) {
                      $card = $banCardMap[$side][$slotNo] ?? null;

                      if (!$card) {
                        continue;
                      }

                      $hasBanDeckCard = true;

                      $level = trim((string)($card['card_level'] ?? ''));
                      $name = trim((string)($card['char_name'] ?? ''));
                      $cost = $card['card_cost'] ?? null;

                      if ($cost !== null && $cost !== '') {
                        $banDeckCost += (int)$cost;
                      }

                      $banDeckParts[] = trim($level . ' ' . $name);
                    }
                    ?>

                    <?php if ($hasBanDeckCard): ?>
                      <div class="t-ban-summary-row">
                        <span class="t-ban-summary-label">牌組</span>
                        <span class="t-ban-summary-main">
                          <?= h(implode(' / ', $banDeckParts)) ?>
                        </span>
                        <span class="t-ban-summary-cost">
                          COST <?= h($banDeckCost) ?>
                        </span>
                      </div>
                    <?php else: ?>
                      <div class="t-ban-summary-row is-empty">
                        尚未登錄
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($isAdmin): ?>
            <?php if ($isMatchLocked): ?>
              <details class="t-admin-collapse t-ban-setting-collapse">
                <summary>
                  管理員 BAN 設定
                  <span class="t-lock-badge">已鎖定</span>
                </summary>

                <div class="t-admin-collapse-body">
                  <div class="t-lock-note">
                    此場次結果已出爐，BAN 設定已鎖定，不再允許修改。
                  </div>
                </div>
              </details>
            <?php else: ?>
              <details class="t-admin-collapse t-ban-setting-collapse" open>
                <summary>
                  管理員 BAN 設定
                </summary>

                <div class="t-admin-collapse-body">
                  <form
                    method="POST"
                    class="t-ban-admin-form"
                    onsubmit="return confirm('確定要匯入 BAN 牌組？這會覆蓋目前已登錄的 BAN 角色。');">

                    <input type="hidden" name="action" value="admin_import_match_ban_decks">

                    <div class="t-ban-admin-title">
                      管理員匯入 BAN 牌組
                    </div>

                    <div class="t-ban-admin-grid">
                      <?php foreach (
                        [
                          'p1' => [
                            'title' => $p1Name . ' 被 BAN 的牌組',
                            'hint' => '請選擇 ' . $p2Name . ' BAN 掉的 ' . $p1Name . ' 牌組',
                            'decks' => $p1DeckRows,
                            'empty' => $p1Name . ' 尚未建立牌組資料',
                          ],
                          'p2' => [
                            'title' => $p2Name . ' 被 BAN 的牌組',
                            'hint' => '請選擇 ' . $p1Name . ' BAN 掉的 ' . $p2Name . ' 牌組',
                            'decks' => $p2DeckRows,
                            'empty' => $p2Name . ' 尚未建立牌組資料',
                          ],
                        ] as $side => $banFormBlock
                      ): ?>
                        <div class="t-ban-admin-card">
                          <div class="t-ban-admin-card-title">
                            <?= h($banFormBlock['title']) ?>
                          </div>

                          <div class="t-ban-admin-card-hint">
                            <?= h($banFormBlock['hint']) ?>
                          </div>

                          <?php if (empty($banFormBlock['decks'])): ?>
                            <div class="t-empty">
                              <?= h($banFormBlock['empty']) ?>
                            </div>
                          <?php else: ?>
                            <select
                              name="ban_deck[<?= h($side) ?>]"
                              class="t-ban-select">
                              <option value="">不匯入 / 清除此方 BAN</option>

                              <?php foreach ($banFormBlock['decks'] as $deck): ?>
                                <?php
                                $deckTextParts = [];

                                for ($i = 1; $i <= 3; $i++) {
                                  $charName = trim((string)($deck['char' . $i . '_name'] ?? ''));
                                  $charLevel = trim((string)($deck['char' . $i . '_level'] ?? ''));

                                  if ($charName !== '') {
                                    $deckTextParts[] = trim($charLevel . ' ' . $charName);
                                  }
                                }

                                $deckText = implode(' / ', $deckTextParts);
                                ?>

                                <option value="<?= h($deck['id']) ?>">
                                  <?= h($deck['deck_label']) ?>：<?= h($deckText) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>

                            <div class="t-ban-deck-preview-list">
                              <?php foreach ($banFormBlock['decks'] as $deck): ?>
                                <div class="t-ban-deck-preview">
                                  <div class="t-ban-deck-preview-title">
                                    <?= h($deck['deck_label']) ?>
                                  </div>

                                  <div class="t-ban-visual-cards">
                                    <?php for ($i = 1; $i <= 3; $i++): ?>
                                      <?php
                                      $ico = (string)($deck['char' . $i . '_ico'] ?? '');
                                      $charName = (string)($deck['char' . $i . '_name'] ?? '-');
                                      $charLevel = (string)($deck['char' . $i . '_level'] ?? '');
                                      $charCost = $deck['char' . $i . '_cost'] ?? '';
                                      $title = trim($charLevel . ' ' . $charName . ' COST ' . $charCost);
                                      ?>

                                      <?php if ($ico !== ''): ?>
                                        <div class="t-ban-visual-card" title="<?= h($title) ?>">
                                          <img src="<?= IMG_BASE . h($ico) ?>" loading="lazy">
                                        </div>
                                      <?php else: ?>
                                        <div class="t-ban-visual-card no-image" title="<?= h($title) ?>">
                                          <div class="t-ban-no-image-name">
                                            <?= h($charName) ?>
                                          </div>
                                        </div>
                                      <?php endif; ?>
                                    <?php endfor; ?>
                                  </div>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <label class="t-ban-label">
                      備註
                    </label>

                    <textarea
                      name="ban_note"
                      class="t-ban-note"
                      rows="2"
                      placeholder="例如：依雙方 BAN 截圖確認"><?= h($banCards[0]['note'] ?? '') ?></textarea>

                    <button type="submit" class="t-ban-save-btn">
                      匯入 BAN 牌組
                    </button>
                  </form>
                </div>
              </details>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if ($isAdmin): ?>
          <div class="t-section">
            <?php if ($isMatchLocked): ?>
              <details class="t-admin-collapse">
                <summary>
                  搜尋競技場 / 手動匯入小局
                  <span class="t-lock-badge">已鎖定</span>
                </summary>

                <div class="t-admin-collapse-body">
                  <div class="t-lock-note">
                    此場次結果已出爐，為避免誤改賽果，已停用競技場搜尋匯入與手動匯入小局。
                  </div>
                </div>
              </details>
            <?php else: ?>

              <div class="t-match-head">
                <div>
                  <h2 class="t-section-title">搜尋目前候選對戰</h2>
                  <div class="t-small">
                    從 當前競技場 搜尋目前房間內是否有本場次雙方玩家。
                  </div>
                </div>

                <div>
                  <a class="t-btn"
                    href="/pages/tournament/ulgg_cup_match.php?match=<?= urlencode($match['match_code']) ?>&scan_room2=1">
                    🔍 搜尋目前競技場
                  </a>
                </div>
              </div>

              <?php if (!empty($_SESSION['upload_success'])): ?>
                <div class="t-alert">
                  <?= h($_SESSION['upload_success']) ?>
                </div>
                <?php unset($_SESSION['upload_success']); ?>
              <?php endif; ?>

              <?php if ($room2Error): ?>
                <div class="t-empty"><?= h($room2Error) ?></div>
              <?php elseif ($room2Message): ?>
                <div class="t-small" style="margin-bottom:10px;">
                  <?= h($room2Message) ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($room2Candidates)): ?>
                <div class="t-fight-list">
                  <?php foreach ($room2Candidates as $room): ?>
                    <?php
                    $roomId = (string)($room['room_id'] ?? '');

                    $sideMap = mapRoom2ToTournamentSides($room, $match);

                    $aName = (string)$sideMap['left']['name'];
                    $bName = (string)$sideMap['right']['name'];

                    $aBp = (int)$sideMap['left']['bp'];
                    $bBp = (int)$sideMap['right']['bp'];

                    $aCost = $sideMap['left']['deck_cost'] !== null ? (int)$sideMap['left']['deck_cost'] : null;
                    $bCost = $sideMap['right']['deck_cost'] !== null ? (int)$sideMap['right']['deck_cost'] : null;

                    $readyA = (int)$sideMap['left']['ready'];
                    $readyB = (int)$sideMap['right']['ready'];

                    $aChars = $sideMap['left']['chars'];
                    $bChars = $sideMap['right']['chars'];

                    $roomDate = room2DateTime($room['date'] ?? null);
                    $showDate = $roomDate ? date('m-d H:i', strtotime($roomDate)) : '-';

                    $region = (string)($room['region'] ?? '');
                    $stageRaw = $room['stage'] ?? '';
                    $showStage = stageName($stageRaw, $stages);



                    $aChars = [
                      isset($aChars[0]) ? (int)$aChars[0] : 0,
                      isset($aChars[1]) ? (int)$aChars[1] : 0,
                      isset($aChars[2]) ? (int)$aChars[2] : 0,
                    ];

                    $bChars = [
                      isset($bChars[0]) ? (int)$bChars[0] : 0,
                      isset($bChars[1]) ? (int)$bChars[1] : 0,
                      isset($bChars[2]) ? (int)$bChars[2] : 0,
                    ];

                    // 候選 room2 還沒有勝負，先固定顯示候選
                    $aResultText = '候選';
                    $bResultText = '候選';
                    $aResultClass = 'result-unknown';
                    $bResultClass = 'result-unknown';
                    ?>

                    <div class="t-fight-card candidate-card">
                      <div class="t-fight-side">
                        <?= renderCharImages($pdo, $aChars) ?>

                        <div class="t-fight-info">
                          <div class="result-pill <?= h($aResultClass) ?>">
                            <?= h($aResultText) ?>
                          </div>

                          <div class="t-fight-name"><?= h($aName) ?></div>
                          <div class="t-bp">BP <?= h($aBp) ?></div>

                          <?php if ($aCost !== null): ?>
                            <div class="t-bp">Deck COST <?= h($aCost) ?></div>
                          <?php endif; ?>

                          <div class="t-bp">
                            Ready <?= $readyA ? '✓' : '×' ?>
                          </div>
                        </div>
                      </div>

                      <div class="t-mid">
                        <div class="candidate-label">候選對戰</div>

                        <div>
                          <?= regionIcon($region) ?> <?= h($region ?: '-') ?>
                        </div>

                        <div><?= h($showDate) ?></div>
                        <div>場景：<?= h($showStage) ?></div>

                        <?php if ($aCost !== null || $bCost !== null): ?>
                          <div>
                            COST：<?= h($aCost ?? '-') ?> vs <?= h($bCost ?? '-') ?>
                          </div>
                        <?php endif; ?>

                        <form method="POST"
                          class="candidate-import-form"
                          onsubmit="return confirm('確定要將這筆候選對戰匯入DB並連結到本場次？');">
                          <input type="hidden" name="action" value="import_room2_to_arena">
                          <input type="hidden" name="room_id" value="<?= h($roomId) ?>">
                          <button type="submit" class="t-btn candidate-import-btn">
                            匯入賽事
                          </button>
                        </form>
                      </div>

                      <div class="t-fight-side right">
                        <?= renderCharImages($pdo, $bChars) ?>

                        <div class="t-fight-info">
                          <div class="result-pill <?= h($bResultClass) ?>">
                            <?= h($bResultText) ?>
                          </div>

                          <div class="t-fight-name"><?= h($bName) ?></div>
                          <div class="t-bp">BP <?= h($bBp) ?></div>

                          <?php if ($bCost !== null): ?>
                            <div class="t-bp">Deck COST <?= h($bCost) ?></div>
                          <?php endif; ?>

                          <div class="t-bp">
                            Ready <?= $readyB ? '✓' : '×' ?>
                          </div>
                        </div>
                      </div>
                    </div>

                  <?php endforeach; ?>
                </div>
              <?php elseif (isset($_GET['scan_room2']) && $_GET['scan_room2'] === '1' && !$room2Error): ?>
                <div class="t-empty">
                  目前 競技場 沒有找到這兩位玩家的對戰房間。
                </div>
              <?php endif; ?>
              <div class="manual-import-panel">
                <div class="manual-import-title">
                  找不到 room2？手動從提交牌組匯入小局
                </div>

                <div class="t-small" style="margin-bottom:10px;">
                  適用於 watcher 沒抓到房間、房間已消失、或僅能依截圖確認雙方出戰牌組的情況。匯入後小局會是待確認狀態，需管理員手動判定勝負。
                </div>

                <form
                  method="POST"
                  class="manual-import-form"
                  onsubmit="return confirm('確定要用雙方提交牌組手動建立一筆賽事小局？');">

                  <input type="hidden" name="action" value="manual_import_decks_to_match_game">

                  <div class="manual-import-grid">
                    <div class="manual-import-card">
                      <label class="t-ban-label">
                        <?= h($p1Name) ?> 出戰牌組
                      </label>

                      <select name="manual_deck[p1]" class="t-ban-select" required>
                        <option value="">請選擇牌組</option>
                        <?php foreach ($p1DeckRows as $deck): ?>
                          <?php
                          $parts = [];
                          for ($i = 1; $i <= 3; $i++) {
                            $level = trim((string)($deck['char' . $i . '_level'] ?? ''));
                            $name = trim((string)($deck['char' . $i . '_name'] ?? ''));
                            if ($name !== '') {
                              $parts[] = trim($level . ' ' . $name);
                            }
                          }
                          ?>
                          <option value="<?= h($deck['id']) ?>">
                            <?= h($deck['deck_label']) ?>：<?= h(implode(' / ', $parts)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="manual-import-card">
                      <label class="t-ban-label">
                        <?= h($p2Name) ?> 出戰牌組
                      </label>

                      <select name="manual_deck[p2]" class="t-ban-select" required>
                        <option value="">請選擇牌組</option>
                        <?php foreach ($p2DeckRows as $deck): ?>
                          <?php
                          $parts = [];
                          for ($i = 1; $i <= 3; $i++) {
                            $level = trim((string)($deck['char' . $i . '_level'] ?? ''));
                            $name = trim((string)($deck['char' . $i . '_name'] ?? ''));
                            if ($name !== '') {
                              $parts[] = trim($level . ' ' . $name);
                            }
                          }
                          ?>
                          <option value="<?= h($deck['id']) ?>">
                            <?= h($deck['deck_label']) ?>：<?= h(implode(' / ', $parts)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <?php
                  $defaultManualUpdateTime = (new DateTime('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d\TH:i');
                  ?>
                  <label class="t-ban-label" style="margin-top:10px;">
                    對戰時間
                  </label>

                  <div class="manual-time-wrap">
                    <input
                      type="datetime-local"
                      name="manual_update_time"
                      class="t-ban-select manual-time-input"
                      value="<?= h($defaultManualUpdateTime) ?>"
                      required>

                    <div class="manual-time-icon">
                      　
                    </div>
                  </div>

                  <button type="submit" class="t-btn candidate-import-btn" style="margin-top:10px;">
                    手動匯入賽事小局
                  </button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="t-section">
          <h2 class="t-section-title">兩人對戰紀錄</h2>

          <?php if (!$matchGameRows): ?>
            <?php if ($isForfeitMatch): ?>
              <div class="t-forfeit-result-card">
                <div class="t-forfeit-result-badge">棄賽判定</div>

                <div class="t-forfeit-result-main">
                  <span class="t-forfeit-winner">
                    <?= h($forfeitWinnerName) ?>
                  </span>

                  <span class="t-forfeit-score">
                    0 - 0
                  </span>

                  <span class="t-forfeit-loser">
                    <?= h($forfeitLoserName) ?>
                  </span>
                </div>

                <div class="t-forfeit-result-note">
                  <?= h($forfeitLoserName) ?> 棄賽，<?= h($forfeitWinnerName) ?> 晉級。
                </div>

                <?php if (!empty($match['note'])): ?>
                  <details class="t-forfeit-note-detail">
                    <summary>查看管理紀錄</summary>
                    <pre><?= h($match['note']) ?></pre>
                  </details>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="t-empty">
                目前尚未有正式匯入到 <?= h($match['match_code']) ?> 的賽事小局。<br>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="t-fight-list">
              <?php foreach ($matchGameRows as $row): ?>
                <?php
                $hasArena = !empty($row['arena_id']);

                $p1 = $hasArena ? (string)$row['name_p1'] : '-';
                $p2 = $hasArena ? (string)$row['name_p2'] : '-';

                if ($hasArena) {
                  $sideMap = mapArenaRowToTournamentSides($row, $match);

                  $p1 = (string)$sideMap['left']['name'];
                  $p2 = (string)$sideMap['right']['name'];

                  $p1Bp = $sideMap['left']['bp'];
                  $p2Bp = $sideMap['right']['bp'];

                  $p1Chars = $sideMap['left']['chars'];
                  $p2Chars = $sideMap['right']['chars'];

                  $p1DeckCost = $sideMap['left']['deck_cost'];
                  $p2DeckCost = $sideMap['right']['deck_cost'];

                  $p1DeckCode = $sideMap['left']['deck_code'];
                  $p2DeckCode = $sideMap['right']['deck_code'];

                  [$p1ResultText, $p1ResultClass] = arenaResultLabel($row, $p1);
                  [$p2ResultText, $p2ResultClass] = arenaResultLabel($row, $p2);
                } else {
                  $p1 = '-';
                  $p2 = '-';

                  $p1Bp = null;
                  $p2Bp = null;

                  $p1Chars = [];
                  $p2Chars = [];

                  $p1DeckCost = null;
                  $p2DeckCost = null;

                  $p1DeckCode = null;
                  $p2DeckCode = null;

                  $p1ResultText = '未連結';
                  $p1ResultClass = 'result-unknown';
                  $p2ResultText = '未連結';
                  $p2ResultClass = 'result-unknown';
                }

                $showDate = !empty($row['update_time'])
                  ? date('m-d H:i', strtotime($row['update_time']))
                  : '-';



                $statusTextMap = [
                  'candidate' => '候選',
                  'pending'   => '待確認',
                  'verified'  => '已確認',
                  'rejected'  => '已駁回',
                  'tie'       => '平手重賽',
                  'rematch'   => '斷線重賽',
                  'dispute'   => '爭議中',
                ];

                $gameStatusText = $statusTextMap[$row['result_status']] ?? $row['result_status'];

                $hasArenaResult =
                  (int)($row['win'] ?? 0) === 1 ||
                  (int)($row['lose'] ?? 0) === 1 ||
                  (int)($row['tie'] ?? 0) === 1;

                $canQuickJudge =
                  $isAdmin &&
                  !$isMatchLocked &&
                  !$hasArenaResult &&
                  in_array((string)$row['result_status'], ['candidate', 'pending'], true);

                $canAdminJudge =
                  $isAdmin &&
                  !$isMatchLocked &&
                  !$hasArenaResult &&
                  in_array((string)$row['result_status'], ['candidate', 'pending'], true);
                ?>

                <div class="t-fight-card">

                  <div class="t-fight-side">
                    <?= $hasArena ? renderCharImages($pdo, $p1Chars) : '<div class="t-char-empty">未連結</div>' ?>

                    <div class="t-fight-info">
                      <div class="result-line">
                        <div class="result-pill <?= h($p1ResultClass) ?>">
                          <?= h($p1ResultText) ?>
                        </div>

                        <?php if ($canQuickJudge): ?>
                          <form
                            method="POST"
                            class="quick-judge-form"
                            onsubmit="return confirm('確定判定 <?= h($match['player1_display_name'] ?: $match['player1_game_name'] ?: 'P1') ?> 於 Game <?= h($row['game_no']) ?> 勝利？');">
                            <input type="hidden" name="action" value="admin_set_game_result">
                            <input type="hidden" name="game_id" value="<?= h($row['game_id']) ?>">
                            <input type="hidden" name="decision" value="p1_win">
                            <button type="submit" class="quick-judge-btn">
                              判此方勝
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>

                      <div class="t-fight-name"><?= h($p1) ?></div>

                      <?php if ($hasArena): ?>
                        <div class="t-bp">BP <?= h($p1Bp) ?></div>
                      <?php endif; ?>

                      <?php if ($p1DeckCost !== null): ?>
                        <div class="t-bp">
                          Deck COST <?= h($p1DeckCost) ?>
                          <?php if (!empty($p1DeckCode)): ?>
                            ｜<?= h($p1DeckCode) ?>牌
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="t-mid">
                    <div>
                      Game <?= h($row['game_no']) ?>
                      ｜<?= h($gameStatusText) ?>
                    </div>

                    <div style="margin-top:6px;">
                      <?php if (canViewTournamentGameDetail($pdo, $match)): ?>
                        <div style="margin-top:6px;">
                          <a class="t-btn t-detail-btn"
                            href="/pages/tournament/ulgg_cup_game.php?match=<?= urlencode($match['match_code']) ?>&game_id=<?= urlencode($row['game_id']) ?>">
                            查看詳細
                          </a>
                        </div>
                      <?php endif; ?>

                    </div>

                    <?php if ($hasArena): ?>
                      <div><?= regionIcon($row['region']) ?> <?= h($row['region'] ?: '-') ?></div>
                      <div><?= h($showDate) ?></div>
                      <div>場景：<?= h(stageName($row['stage'], $stages)) ?></div>

                      <?php if ($row['cost'] !== null && $row['cost'] !== ''): ?>
                        <div>房間 COST：<?= h($row['cost']) ?></div>
                      <?php endif; ?>

                    <?php else: ?>
                      <div class="t-room">尚未連結 一般對戰DB</div>
                    <?php endif; ?>


                  </div>

                  <div class="t-fight-side right">
                    <?= $hasArena ? renderCharImages($pdo, $p2Chars) : '<div class="t-char-empty">未連結</div>' ?>

                    <div class="t-fight-info">
                      <div class="result-line right">
                        <div class="result-pill <?= h($p2ResultClass) ?>">
                          <?= h($p2ResultText) ?>
                        </div>

                        <?php if ($canQuickJudge): ?>
                          <form
                            method="POST"
                            class="quick-judge-form"
                            onsubmit="return confirm('確定判定 <?= h($match['player2_display_name'] ?: $match['player2_game_name'] ?: 'P2') ?> 於 Game <?= h($row['game_no']) ?> 勝利？');">
                            <input type="hidden" name="action" value="admin_set_game_result">
                            <input type="hidden" name="game_id" value="<?= h($row['game_id']) ?>">
                            <input type="hidden" name="decision" value="p2_win">
                            <button type="submit" class="quick-judge-btn">
                              判此方勝
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>

                      <div class="t-fight-name"><?= h($p2) ?></div>

                      <?php if ($hasArena): ?>
                        <div class="t-bp">BP <?= h($p2Bp) ?></div>
                      <?php endif; ?>

                      <?php if ($p2DeckCost !== null): ?>
                        <div class="t-bp">
                          Deck COST <?= h($p2DeckCost) ?>
                          <?php if (!empty($p2DeckCode)): ?>
                            ｜<?= h($p2DeckCode) ?>牌
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <?php if ($canAdminJudge): ?>
                    <div class="admin-game-judge-panel">
                      <form
                        method="POST"
                        class="admin-game-judge-form"
                        onsubmit="return confirm('確定要更新 Game <?= h($row['game_no']) ?> 的判定？');">

                        <input type="hidden" name="action" value="admin_set_game_result">
                        <input type="hidden" name="game_id" value="<?= h($row['game_id']) ?>">

                        <div class="admin-game-judge-head">
                          <div class="admin-game-judge-title">
                            管理員判定｜Game <?= h($row['game_no']) ?>
                          </div>
                          <div class="admin-game-judge-current">
                            目前狀態：<?= h($gameStatusText) ?>
                          </div>
                        </div>

                        <input
                          type="text"
                          name="admin_note"
                          class="admin-game-note"
                          placeholder="可選：判定備註，例如平手、斷線、違規、截圖佐證">

                        <div class="admin-game-judge-buttons">


                          <button
                            type="submit"
                            name="decision"
                            value="tie"
                            class="judge-btn judge-yellow">
                            平手重賽
                          </button>

                          <button
                            type="submit"
                            name="decision"
                            value="rematch"
                            class="judge-btn judge-yellow">
                            斷線重賽
                          </button>

                          <button
                            type="submit"
                            name="decision"
                            value="dispute"
                            class="judge-btn judge-red">
                            爭議中
                          </button>

                          <button
                            type="submit"
                            name="decision"
                            value="rejected"
                            class="judge-btn judge-gray">
                            駁回此局
                          </button>
                        </div>
                      </form>
                    </div>
                  <?php endif; ?>

                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>

    </div>
  </section>
</div>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>