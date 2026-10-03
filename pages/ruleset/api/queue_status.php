<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_match_cleanup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    failResponse(405, 'METHOD_NOT_ALLOWED', '只接受 GET 請求。');
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

if (!isset($db) || !$db instanceof PDO) {
    failResponse(500, 'DATABASE_UNAVAILABLE', '資料庫連線尚未建立。');
}

try {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    cleanupExpiredCustomMatches($db);

    $stmt = $db->prepare(<<<'SQL'
        SELECT
            q.id,
            q.rule_version_id,
            q.weekly_cost_id,
            q.cost_category,
            q.queue_status,
            q.matched_queue_id,
            q.ulgg_match_id,
            q.queued_at,
            q.matched_at,
            q.cancelled_at,
            CASE q.cost_category
                WHEN 1 THEN w.cost1
                WHEN 2 THEN w.cost2
                WHEN 3 THEN w.cost3
                WHEN 4 THEN w.cost4
                ELSE NULL
            END AS selected_cost,
            m.match_status,
            m.game_room_id,
            m.created_at AS match_created_at,
            m.room_linked_at,
            m.deck_validated_at
        FROM custom_match_queue AS q
        LEFT JOIN quickmatch_weekly_cost AS w
          ON w.id = q.weekly_cost_id
        LEFT JOIN custom_matches AS m
          ON m.id = q.ulgg_match_id
        WHERE q.user_id = :user_id
          AND q.steam_id = :steam_id
          AND (
            q.queue_status = 'QUEUED'
            OR (
              q.queue_status = 'MATCHED'
              AND m.match_status IN (
                'WAITING_ROOM',
                'WAITING_DECK'
              )
            )
          )
        ORDER BY q.id DESC
        LIMIT 1
    SQL);
    $stmt->execute([
        ':user_id' => $userId,
        ':steam_id' => $steamId,
    ]);
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$queue) {
        respond(200, [
            'ok' => true,
            'data' => [
                'has_active_queue' => false,
                'queue_status' => 'IDLE',
            ],
        ]);
    }

    $costCategory = (int)$queue['cost_category'];
    $selectedCost = $queue['selected_cost'] !== null
        ? (int)$queue['selected_cost']
        : null;

    $costLabel = $selectedCost !== null ? (string)$selectedCost : '?';
    if ($costCategory === 4 && $selectedCost !== null) {
        $costLabel .= '+';
    }

    respond(200, [
        'ok' => true,
        'data' => [
            'has_active_queue' => true,
            'queue_id' => (int)$queue['id'],
            'queue_status' => (string)$queue['queue_status'],
            'rule_version_id' => (string)$queue['rule_version_id'],
            'weekly_cost_id' => (int)$queue['weekly_cost_id'],
            'cost_category' => $costCategory,
            'selected_cost' => $selectedCost,
            'cost_label' => $costLabel,
            'matched_queue_id' => $queue['matched_queue_id'] !== null
                ? (int)$queue['matched_queue_id']
                : null,
            'ulgg_match_id' => $queue['ulgg_match_id'] !== null
                ? (int)$queue['ulgg_match_id']
                : null,
            'match_status' => $queue['match_status'] !== null
                ? (string)$queue['match_status']
                : null,
            'game_room_id' => $queue['game_room_id'] !== null
                ? (string)$queue['game_room_id']
                : null,
            'queued_at' => $queue['queued_at'],
            'matched_at' => $queue['matched_at'],
            'match_created_at' => $queue['match_created_at'],
            'room_linked_at' => $queue['room_linked_at'],
            'deck_validated_at' => $queue['deck_validated_at'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[ULGG queue_status] ' . $e->getMessage());

    failResponse(
        500,
        'QUEUE_STATUS_FAILED',
        '讀取配對狀態失敗，請稍後再試。'
    );
}
