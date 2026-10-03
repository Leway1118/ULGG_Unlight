<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_match_cleanup.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

/**
 * ULGG MVP matchmaking queue entry point.
 *
 * Contract:
 * - POST JSON: {"cost_category": 1|2|3|4}
 * - Player identity comes only from the authenticated PHP session.
 * - Weekly COST source is the latest APPROVED Alexandre row.
 * - Matching requires the same ruleset version, weekly COST row and category.
 * - Deck legality is NOT checked here; it is checked after matching.
 */

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

function failResponse(
  int $status,
  string $code,
  string $message,
  array $details = []
): never {
  respond($status, [
    'ok' => false,
    'error' => [
      'code' => $code,
      'message' => $message,
      'details' => $details,
    ],
  ]);
}
/**
 * 配對成功後，只推播給本場兩名玩家。
 *
 * 第一版暫時沿用 tournament_push_subscriptions。
 * 推播失敗不得回滾已成立的配對。
 */
function sendMatchFoundPush(
  PDO $db,
  int $matchId,
  int $player1UserId,
  int $player2UserId,
  string $player1Name,
  string $player2Name,
  int $costCategory
): array {
  if (!function_exists('appVapidConfigured') || !appVapidConfigured()) {
    return [
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'reason' => 'VAPID_NOT_CONFIGURED',
    ];
  }

  $stmt = $db->prepare(<<<'SQL'
    SELECT
      id,
      user_id,
      endpoint,
      p256dh_key,
      auth_key
    FROM ulgg_push_subscriptions
    WHERE is_active = 1
      AND user_id IN (:player1_user_id, :player2_user_id)
    ORDER BY id ASC
SQL);

  $stmt->execute([
    ':player1_user_id' => $player1UserId,
    ':player2_user_id' => $player2UserId,
  ]);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    return [
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'reason' => 'NO_ACTIVE_SUBSCRIPTION',
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
  $subscriptionMap = [];
  $queuedEndpoints = [];

  foreach ($rows as $row) {
    $endpoint = trim((string)($row['endpoint'] ?? ''));

    if (
      $endpoint === ''
      || isset($queuedEndpoints[$endpoint])
    ) {
      continue;
    }

    $targetUserId = (int)$row['user_id'];

    if ($targetUserId === $player1UserId) {
      $opponentName = $player2Name;
    } elseif ($targetUserId === $player2UserId) {
      $opponentName = $player1Name;
    } else {
      continue;
    }

    $payload = json_encode([
      'title' => 'ULGG 配對成功',
      'body' =>
      '已找到對手：'
        . $opponentName
        . '。點擊進入對戰房間。',
      'url' =>
      '/pages/ruleset/room.php?match='
        . rawurlencode((string)$matchId),
      'icon' => '/favicon.ico',
      'badge' => '/favicon.ico',
      'tag' => 'ulgg-match-found-' . $matchId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
      continue;
    }

    $subscription = Subscription::create([
      'endpoint' => $endpoint,
      'publicKey' => (string)$row['p256dh_key'],
      'authToken' => (string)$row['auth_key'],
    ]);

    $webPush->queueNotification(
      $subscription,
      $payload,
      [
        'TTL' => 600,
        'urgency' => 'high',
      ]
    );

    $queuedEndpoints[$endpoint] = true;
    $subscriptionMap[$endpoint] = [
      'id' => (int)$row['id'],
    ];
  }

  if (!$subscriptionMap) {
    return [
      'sent' => 0,
      'failed' => 0,
      'expired' => 0,
      'reason' => 'NO_VALID_SUBSCRIPTION',
    ];
  }

  $sent = 0;
  $failed = 0;
  $expired = 0;

  $deactivateStmt = $db->prepare(<<<'SQL'
    UPDATE ulgg_push_subscriptions
    SET
      is_active = 0,
      updated_at = CURRENT_TIMESTAMP
    WHERE id = :id
    LIMIT 1
  SQL);

  $lastPushStmt = $db->prepare(<<<'SQL'
    UPDATE ulgg_push_subscriptions
    SET
      last_push_at = CURRENT_TIMESTAMP,
      updated_at = CURRENT_TIMESTAMP
    WHERE id = :id
    LIMIT 1
  SQL);

  foreach ($webPush->flush() as $report) {
    $endpoint = (string)$report->getRequest()->getUri();
    $subscriptionRow = $subscriptionMap[$endpoint] ?? null;

    if ($subscriptionRow === null) {
      $failed++;
      continue;
    }

    $subscriptionId = (int)$subscriptionRow['id'];

    if ($report->isSuccess()) {
      $sent++;

      $lastPushStmt->execute([
        ':id' => $subscriptionId,
      ]);

      continue;
    }

    $failed++;

    if ($report->isSubscriptionExpired()) {
      $expired++;

      $deactivateStmt->execute([
        ':id' => $subscriptionId,
      ]);
    }

    error_log(
      '[ULGG match push] match='
        . $matchId
        . ' subscription='
        . $subscriptionId
        . ' reason='
        . $report->getReason()
    );
  }

  return [
    'sent' => $sent,
    'failed' => $failed,
    'expired' => $expired,
    'reason' => null,
  ];
}


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Allow: POST');
  failResponse(405, 'METHOD_NOT_ALLOWED', '只接受 POST 請求。');
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($contentType, 'application/json')) {
  failResponse(415, 'UNSUPPORTED_MEDIA_TYPE', 'Content-Type 必須是 application/json。');
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
  failResponse(400, 'EMPTY_BODY', '請提供排隊資料。');
}

try {
  $input = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
  failResponse(400, 'INVALID_JSON', 'JSON 格式錯誤。');
}

if (!is_array($input)) {
  failResponse(400, 'INVALID_BODY', '請提供 JSON Object。');
}

$costCategoryRaw = $input['cost_category'] ?? null;
$costCategory = filter_var(
  $costCategoryRaw,
  FILTER_VALIDATE_INT,
  ['options' => ['min_range' => 1, 'max_range' => 4]]
);

if ($costCategory === false) {
  failResponse(
    422,
    'INVALID_COST_CATEGORY',
    'cost_category 只能是 1、2、3 或 4。'
  );
}

$userId = filter_var(
  $_SESSION['user_id'] ?? null,
  FILTER_VALIDATE_INT,
  ['options' => ['min_range' => 1]]
);
$steamId = trim((string)($_SESSION['steam_id'] ?? ''));

if ($userId === false || $steamId === '') {
  failResponse(401, 'AUTH_REQUIRED', '請先完成網站與 Steam 登入。');
}

if (!preg_match('/^\d{15,20}$/', $steamId)) {
  failResponse(401, 'INVALID_STEAM_SESSION', 'Steam 登入資料格式不正確，請重新登入。');
}

if (!isset($db) || !$db instanceof PDO) {
  failResponse(500, 'DATABASE_UNAVAILABLE', '資料庫連線尚未建立。');
}

/*
 * 第一版只有一條固定天梯，不接受前端指定 Ruleset。
 * 正式環境建議在 config.php 定義 ULGG_ACTIVE_RULE_VERSION_ID。
 */
$ruleVersionId = defined('ULGG_ACTIVE_RULE_VERSION_ID')
  ? trim((string)constant('ULGG_ACTIVE_RULE_VERSION_ID'))
  : 'ulgg-glasses-99-demo@0.1.0';

if ($ruleVersionId === '') {
  failResponse(503, 'RULESET_NOT_CONFIGURED', '目前沒有啟用中的 Ruleset Version。');
}
$queueLockName = 'ulgg_join_queue_user_' . (int)$userId;
$queueLockAcquired = false;
try {
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $lockStmt = $db->prepare(
    'SELECT GET_LOCK(:lock_name, 5)'
  );

  $lockStmt->execute([
    ':lock_name' => $queueLockName,
  ]);

  $queueLockAcquired =
    (int)$lockStmt->fetchColumn() === 1;

  if (!$queueLockAcquired) {
    failResponse(
      409,
      'QUEUE_REQUEST_IN_PROGRESS',
      '目前已有排隊請求正在處理，請稍後再試。'
    );
  }

  register_shutdown_function(
    static function () use (
      $db,
      $queueLockName
    ): void {
      try {
        $releaseStmt = $db->prepare(
          'SELECT RELEASE_LOCK(:lock_name)'
        );

        $releaseStmt->execute([
          ':lock_name' => $queueLockName,
        ]);
      } catch (Throwable) {
        // 連線結束時 MySQL 也會自動釋放 named lock。
      }
    }
  );
  cleanupExpiredCustomMatches($db);

  $db->beginTransaction();

  /* 鎖定本週 Alexandre 規則，避免匯入更新期間讀到不一致資料。 */
  $weeklyStmt = $db->prepare(<<<'SQL'
        SELECT
            id,
            ladder_zone_code,
            effective_date,
            cost1,
            cost2,
            cost3,
            cost4,
            cost4_operator
        FROM quickmatch_weekly_cost
        WHERE ladder_zone_code = 'ALEXANDRE'
          AND import_status = 'APPROVED'
          AND matchmaking_enabled = 1
          AND effective_date <= CURRENT_DATE()
        ORDER BY effective_date DESC, id DESC
        LIMIT 1
        LOCK IN SHARE MODE
    SQL);
  $weeklyStmt->execute();
  $weeklyCost = $weeklyStmt->fetch(PDO::FETCH_ASSOC);

  if (!$weeklyCost) {
    $db->rollBack();
    failResponse(
      503,
      'WEEKLY_COST_UNAVAILABLE',
      '目前找不到可用的 Alexandre 本週 COST。'
    );
  }

  $weeklyCostId = (int)$weeklyCost['id'];

  /*
   * 只有仍在排隊，或尚未完成牌組驗證的配對會阻止再次排隊。
   * READY、FINISHED、CANCELLED 皆視為本次配對流程已結束。
   */
  $existingStmt = $db->prepare(<<<'SQL'
        SELECT
            q.id,
            q.queue_status,
            q.cost_category,
            q.weekly_cost_id,
            q.matched_queue_id,
            q.ulgg_match_id,
            q.queued_at,
            q.matched_at
        FROM custom_match_queue AS q
        WHERE q.user_id = :user_id
          AND (
            q.queue_status = 'QUEUED'
            OR (
              q.queue_status = 'MATCHED'
              AND EXISTS (
                SELECT 1
                FROM custom_matches AS m
                WHERE m.id = q.ulgg_match_id
                  AND m.match_status IN (
                    'WAITING_ROOM',
                    'WAITING_DECK'
                  )
              )
            )
          )
        ORDER BY q.id DESC
        LIMIT 1
        FOR UPDATE
    SQL);
  $existingStmt->execute([':user_id' => $userId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $db->commit();
    respond(200, [
      'ok' => true,
      'data' => [
        'queue_id' => (int)$existing['id'],
        'queue_status' => (string)$existing['queue_status'],
        'cost_category' => (int)$existing['cost_category'],
        'weekly_cost_id' => (int)$existing['weekly_cost_id'],
        'matched_queue_id' => $existing['matched_queue_id'] !== null
          ? (int)$existing['matched_queue_id']
          : null,
        'ulgg_match_id' => $existing['ulgg_match_id'] !== null
          ? (int)$existing['ulgg_match_id']
          : null,
        'queued_at' => $existing['queued_at'],
        'matched_at' => $existing['matched_at'],
        'reused_existing_queue' => true,
      ],
    ]);
  }

  /* 先建立自己的 QUEUED 列，後續再鎖定候選者完成配對。 */
  $insertStmt = $db->prepare(<<<'SQL'
        INSERT INTO custom_match_queue (
            user_id,
            steam_id,
            rule_version_id,
            weekly_cost_id,
            cost_category,
            queue_status,
            matched_queue_id,
            queued_at,
            matched_at,
            cancelled_at
        ) VALUES (
            :user_id,
            :steam_id,
            :rule_version_id,
            :weekly_cost_id,
            :cost_category,
            'QUEUED',
            NULL,
            NOW(),
            NULL,
            NULL
        )
    SQL);
  $insertStmt->execute([
    ':user_id' => $userId,
    ':steam_id' => $steamId,
    ':rule_version_id' => $ruleVersionId,
    ':weekly_cost_id' => $weeklyCostId,
    ':cost_category' => $costCategory,
  ]);

  $queueId = (int)$db->lastInsertId();

  /*
     * MariaDB 10.5 不支援 SKIP LOCKED。
     * 這裡使用 FOR UPDATE 鎖定最早候選者，再以後續 UPDATE rowCount() 驗證配對結果。
     * 第一版只依同 Ruleset、同週、同 COST 類別、先到先配。
     */
  $candidateStmt = $db->prepare(<<<'SQL'
        SELECT
            q.id,
            q.user_id,
            q.steam_id,
            gu.username AS game_name
        FROM custom_match_queue AS q
        INNER JOIN game_user AS gu
            ON gu.id = q.user_id
        WHERE q.id <> :queue_id
          AND q.user_id <> :user_id
          AND q.rule_version_id = :rule_version_id
          AND q.weekly_cost_id = :weekly_cost_id
          AND q.cost_category = :cost_category
          AND q.queue_status = 'QUEUED'
        ORDER BY q.queued_at ASC, q.id ASC
        LIMIT 1
        FOR UPDATE
    SQL);
  $candidateStmt->execute([
    ':queue_id' => $queueId,
    ':user_id' => $userId,
    ':rule_version_id' => $ruleVersionId,
    ':weekly_cost_id' => $weeklyCostId,
    ':cost_category' => $costCategory,
  ]);
  $candidate = $candidateStmt->fetch(PDO::FETCH_ASSOC);

  if (!$candidate) {
    $db->commit();

    respond(201, [
      'ok' => true,
      'data' => [
        'queue_id' => $queueId,
        'queue_status' => 'QUEUED',
        'cost_category' => $costCategory,
        'weekly_cost_id' => $weeklyCostId,
        'rule_version_id' => $ruleVersionId,
        'matched_queue_id' => null,
        'ulgg_match_id' => null,
        'reused_existing_queue' => false,
      ],
    ]);
  }

  $candidateQueueId = (int)$candidate['id'];
  $matchedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
  $ownGameNameStmt = $db->prepare(
    'SELECT username FROM game_user WHERE id = :user_id LIMIT 1'
  );
  $ownGameNameStmt->execute([
    ':user_id' => $userId,
  ]);

  $ownGameName = trim((string)$ownGameNameStmt->fetchColumn());
  $candidateGameName = trim((string)($candidate['game_name'] ?? ''));

  if ($ownGameName === '' || $candidateGameName === '') {
    throw new RuntimeException('無法取得配對玩家的遊戲名稱。');
  }

  /*
     * 配對成功後，在同一個 transaction 中建立正式 ULGG Match。
     * queue 只保留配對紀錄；後續房間、牌組驗證與結果都以 ulgg_matches 為主。
     */
  $insertMatchStmt = $db->prepare(<<<'SQL'
        INSERT INTO custom_matches (
            rule_version_id,
            weekly_cost_id,
            cost_category,
            player1_queue_id,
            player2_queue_id,
            player1_user_id,
            player2_user_id,
            player1_steam_id,
            player2_steam_id,
            player1_game_name,
            player2_game_name,
            match_status,
            created_at
        ) VALUES (
            :rule_version_id,
            :weekly_cost_id,
            :cost_category,
            :player1_queue_id,
            :player2_queue_id,
            :player1_user_id,
            :player2_user_id,
            :player1_steam_id,
            :player2_steam_id,
            :player1_game_name,
            :player2_game_name,
            'WAITING_ROOM',
            :created_at
        )
    SQL);
  $insertMatchStmt->execute([
    ':rule_version_id' => $ruleVersionId,
    ':weekly_cost_id' => $weeklyCostId,
    ':cost_category' => $costCategory,

    // 先進等待池的人固定為 Player 1，負責建立遊戲房間。
    ':player1_queue_id' => $candidateQueueId,
    ':player2_queue_id' => $queueId,

    ':player1_user_id' => (int)$candidate['user_id'],
    ':player2_user_id' => $userId,

    ':player1_steam_id' => (string)$candidate['steam_id'],
    ':player2_steam_id' => $steamId,

    ':player1_game_name' => $candidateGameName,
    ':player2_game_name' => $ownGameName,

    ':created_at' => $matchedAt,
  ]);

  $ulggMatchId = (int)$db->lastInsertId();

  if ($ulggMatchId < 1) {
    throw new RuntimeException('建立 ULGG Match 失敗。');
  }

  $matchStmt = $db->prepare(<<<'SQL'
        UPDATE custom_match_queue
        SET
            queue_status = 'MATCHED',
            matched_queue_id = CASE
                WHEN id = :queue_id THEN :candidate_queue_id
                WHEN id = :candidate_queue_id_case THEN :queue_id_case
            END,
            ulgg_match_id = :ulgg_match_id,
            matched_at = :matched_at
        WHERE id IN (:queue_id_where, :candidate_queue_id_where)
          AND queue_status = 'QUEUED'
    SQL);
  $matchStmt->execute([
    ':queue_id' => $queueId,
    ':candidate_queue_id' => $candidateQueueId,
    ':candidate_queue_id_case' => $candidateQueueId,
    ':queue_id_case' => $queueId,
    ':ulgg_match_id' => $ulggMatchId,
    ':matched_at' => $matchedAt,
    ':queue_id_where' => $queueId,
    ':candidate_queue_id_where' => $candidateQueueId,
  ]);

  if ($matchStmt->rowCount() !== 2) {
    throw new RuntimeException('配對狀態更新衝突，請重新排隊。');
  }

  $db->commit();

  /*
   * Match 已正式成立後才發送通知。
   * 推播失敗不得影響已成立的配對。
   */
  try {
    $pushResult = sendMatchFoundPush(
      $db,
      $ulggMatchId,
      $userId,
      (int)$candidate['user_id'],
      $ownGameName,
      $candidateGameName,
      $costCategory
    );

    error_log(
      '[ULGG match push] match='
        . $ulggMatchId
        . ' sent='
        . (int)$pushResult['sent']
        . ' failed='
        . (int)$pushResult['failed']
        . ' expired='
        . (int)$pushResult['expired']
        . ' reason='
        . (string)($pushResult['reason'] ?? '')
    );
  } catch (Throwable $pushError) {
    error_log(
      '[ULGG match push] match='
        . $ulggMatchId
        . ' exception='
        . $pushError->getMessage()
    );
  }

  respond(201, [
    'ok' => true,
    'data' => [
      'queue_id' => $queueId,
      'queue_status' => 'MATCHED',
      'cost_category' => $costCategory,
      'weekly_cost_id' => $weeklyCostId,
      'rule_version_id' => $ruleVersionId,
      'matched_queue_id' => $candidateQueueId,
      'ulgg_match_id' => $ulggMatchId,
      'match_status' => 'WAITING_ROOM',
      'reused_existing_queue' => false,
    ],
  ]);
} catch (Throwable $e) {
  if ($db instanceof PDO && $db->inTransaction()) {
    $db->rollBack();
  }

  error_log('[ULGG join_queue] ' . $e->getMessage());

  failResponse(
    500,
    'QUEUE_JOIN_FAILED',
    '加入配對佇列失敗，請稍後再試。'
  );
}
