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

$matchId = filter_var(
    $_GET['match'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($userId === false) {
    respond(401, [
        'ok' => false,
        'error' => [
            'code' => 'AUTH_REQUIRED',
            'message' => '請先登入。',
        ],
    ]);
}

if ($matchId === false) {
    respond(422, [
        'ok' => false,
        'error' => [
            'code' => 'INVALID_MATCH_ID',
            'message' => '比賽編號格式不正確。',
        ],
    ]);
}

if (!isset($db) || !$db instanceof PDO) {
    respond(500, [
        'ok' => false,
        'error' => [
            'code' => 'DATABASE_UNAVAILABLE',
            'message' => '資料庫連線尚未建立。',
        ],
    ]);
}

try {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    cleanupExpiredCustomMatches($db);
    $db->beginTransaction();

    /*
     * 讀取並鎖定 Match。
     * 房間頁每 3 秒輪詢一次，因此可在這裡處理 5 分鐘未開房超時。
     */
    $stmt = $db->prepare(
        <<<'SQL'
        SELECT
            id,
            player1_user_id,
            player2_user_id,
            player1_game_name,
            player2_game_name,
            match_status,
            game_room_id,
            game_room_password,
            game_room_password_updated_at,
            created_at,
            room_linked_at,
            deck_validated_at,
            finished_at,
            cancelled_at,
            cancellation_code,
            cancellation_message
        FROM custom_matches
        WHERE id = :match_id
        LIMIT 1
        FOR UPDATE
        SQL
    );

    $stmt->execute([
        ':match_id' => (int)$matchId,
    ]);

    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($match)) {
        $db->rollBack();

        respond(404, [
            'ok' => false,
            'error' => [
                'code' => 'MATCH_NOT_FOUND',
                'message' => '找不到這場配對。',
            ],
        ]);
    }

    $isParticipant =
        (int)$match['player1_user_id'] === (int)$userId
        || (int)$match['player2_user_id'] === (int)$userId;

    if (!$isParticipant) {
        $db->rollBack();

        respond(403, [
            'ok' => false,
            'error' => [
                'code' => 'MATCH_ACCESS_DENIED',
                'message' => '你不是這場配對的玩家。',
            ],
        ]);
    }







    $db->commit();

    respond(200, [
        'ok' => true,
        'data' => [
            'match_id' => (int)$match['id'],
            'match_status' => (string)$match['match_status'],

            'game_room_id' => $match['game_room_id'] !== null
                ? (string)$match['game_room_id']
                : null,
            'game_room_password' =>
            $match['game_room_password'] !== null
                ? (string)$match['game_room_password']
                : null,

            'game_room_password_updated_at' =>
            $match['game_room_password_updated_at'],

            'created_at' => $match['created_at'],
            'room_linked_at' => $match['room_linked_at'],
            'deck_validated_at' => $match['deck_validated_at'],
            'finished_at' => $match['finished_at'],
            'cancelled_at' => $match['cancelled_at'],

            'cancellation_code' =>
            $match['cancellation_code'] !== null
                ? (string)$match['cancellation_code']
                : null,

            'cancellation_message' =>
            $match['cancellation_message'] !== null
                ? (string)$match['cancellation_message']
                : null,

            'players' => [
                [
                    'name' => (string)$match['player1_game_name'],
                ],
                [
                    'name' => (string)$match['player2_game_name'],
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[ULGG match_status] ' . $e->getMessage());
    respond(500, [
        'ok' => false,
        'error' => [
            'code' => 'MATCH_STATUS_FAILED',
            'message' => '讀取比賽狀態失敗。',
        ],
    ]);
}
