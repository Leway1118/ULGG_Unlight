<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';

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

function requestHeader(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$serverKey] ?? ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    failResponse(405, 'METHOD_NOT_ALLOWED', '只接受 POST 請求。');
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($contentType, 'application/json')) {
    failResponse(
        415,
        'UNSUPPORTED_MEDIA_TYPE',
        'Content-Type 必須是 application/json。'
    );
}

/*
 * Watcher 專用 API Token。
 * 建議在 config.php 或 .env 載入後定義：
 *
 * define('ULGG_WATCHER_API_TOKEN', '請換成長度足夠的隨機字串');
 */
$expectedToken = defined('ULGG_WATCHER_API_TOKEN')
    ? trim((string)constant('ULGG_WATCHER_API_TOKEN'))
    : '';

if ($expectedToken === '') {
    failResponse(
        503,
        'WATCHER_TOKEN_NOT_CONFIGURED',
        'Watcher API Token 尚未設定。'
    );
}

$providedToken = requestHeader('X-ULGG-Watcher-Token');

if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    failResponse(401, 'INVALID_WATCHER_TOKEN', 'Watcher API Token 無效。');
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    failResponse(400, 'EMPTY_BODY', '請提供房間資料。');
}

try {
    $input = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    failResponse(400, 'INVALID_JSON', 'JSON 格式錯誤。');
}

if (!is_array($input)) {
    failResponse(400, 'INVALID_BODY', '請提供 JSON Object。');
}

$roomId = trim((string)($input['room_id'] ?? ''));
$playerA = trim((string)($input['player_a'] ?? ''));
$playerB = trim((string)($input['player_b'] ?? ''));

$snapshotDateMs = filter_var(
    $input['snapshot_date_ms'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);
if ($snapshotDateMs === false) {
    failResponse(
        422,
        'INVALID_SNAPSHOT_DATE',
        'Watcher snapshot_date_ms 格式不正確。'
    );
}

if (
    $roomId === ''
    || strlen($roomId) > 64
    || !preg_match('/^[A-Za-z0-9_-]+$/', $roomId)
) {
    failResponse(
        422,
        'INVALID_ROOM_ID',
        'room_id 格式不正確。'
    );
}

if ($playerA === '' || $playerB === '') {
    failResponse(
        422,
        'INVALID_PLAYERS',
        'player_a 與 player_b 都不能為空。'
    );
}

if ($playerA === $playerB) {
    failResponse(
        422,
        'DUPLICATE_PLAYER_NAME',
        'player_a 與 player_b 不可相同。'
    );
}

if (mb_strlen($playerA, 'UTF-8') > 45 || mb_strlen($playerB, 'UTF-8') > 45) {
    failResponse(
        422,
        'PLAYER_NAME_TOO_LONG',
        '玩家名稱長度不可超過 45 個字元。'
    );
}

if (!isset($db) || !$db instanceof PDO) {
    failResponse(500, 'DATABASE_UNAVAILABLE', '資料庫連線尚未建立。');
}

try {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->beginTransaction();

    /*
     * 雙方名稱採無視順序的完全相同比對。
     * 先抓最多兩筆，用來偵測同名玩家或重複待綁定比賽造成的歧義。
     */
    $matchStmt = $db->prepare(<<<'SQL'
        SELECT
            id,
            match_status,
            game_room_id,
            player1_game_name,
            player2_game_name,
            created_at
        FROM custom_matches
        WHERE match_status = 'WAITING_ROOM'
            AND game_room_id IS NULL
            AND created_at <= FROM_UNIXTIME(
                :snapshot_date_seconds
            )
            AND (
                (
                    player1_game_name = :player_a_1
                    AND player2_game_name = :player_b_1
                )
                OR
                (
                    player1_game_name = :player_b_2
                    AND player2_game_name = :player_a_2
                )
          )
        ORDER BY created_at ASC, id ASC
        LIMIT 2
        FOR UPDATE
    SQL);
    $matchStmt->execute([
        ':snapshot_date_seconds' => ((int)$snapshotDateMs) / 1000,

        ':player_a_1' => $playerA,
        ':player_b_1' => $playerB,
        ':player_b_2' => $playerB,
        ':player_a_2' => $playerA,
    ]);
    $matches = $matchStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($matches) === 0) {
        $db->rollBack();
        failResponse(
            404,
            'WAITING_MATCH_NOT_FOUND',
            '找不到符合雙方玩家名稱的 WAITING_ROOM 比賽。',
            [
                'room_id' => $roomId,
                'player_a' => $playerA,
                'player_b' => $playerB,
            ]
        );
    }

    if (count($matches) > 1) {
        $db->rollBack();
        failResponse(
            409,
            'AMBIGUOUS_WAITING_MATCH',
            '找到多筆相同玩家組合的待綁定比賽，需由管理員處理。',
            [
                'match_ids' => array_map(
                    static fn(array $match): int => (int)$match['id'],
                    $matches
                ),
            ]
        );
    }

    $match = $matches[0];
    $matchId = (int)$match['id'];
    $roomHostSlot = null;

    if ($playerA === (string)$match['player1_game_name']) {
        $roomHostSlot = 'PLAYER1';
    } elseif ($playerA === (string)$match['player2_game_name']) {
        $roomHostSlot = 'PLAYER2';
    }

    if ($roomHostSlot === null) {
        $db->rollBack();

        failResponse(
            409,
            'ROOM_HOST_NOT_RESOLVED',
            '無法判定遊戲房間建立者。'
        );
    }

    /*
     * 避免同一個遊戲房間被綁到另一場尚未取消的 ULGG Match。
     */
    $roomConflictStmt = $db->prepare(<<<'SQL'
        SELECT id
        FROM custom_matches
        WHERE game_room_id = :game_room_id
            AND id <> :match_id
            AND match_status IN (
                'WAITING_ROOM',
                'WAITING_DECK'
            )
        LIMIT 1
        FOR UPDATE
    SQL);
    $roomConflictStmt->execute([
        ':game_room_id' => $roomId,
        ':match_id' => $matchId,
    ]);
    $conflictMatchId = $roomConflictStmt->fetchColumn();

    if ($conflictMatchId !== false) {
        $db->rollBack();
        failResponse(
            409,
            'ROOM_ALREADY_LINKED',
            '這個遊戲房間已綁定其他 ULGG Match。',
            [
                'conflict_match_id' => (int)$conflictMatchId,
            ]
        );
    }

    $updateStmt = $db->prepare(<<<'SQL'
        UPDATE custom_matches
        SET
            game_room_id = :game_room_id,
            room_host_slot = :room_host_slot,
            match_status = 'WAITING_DECK',
            room_linked_at = NOW()
        WHERE id = :match_id
          AND match_status = 'WAITING_ROOM'
          AND game_room_id IS NULL
    SQL);
    $updateStmt->execute([
        ':game_room_id' => $roomId,
        ':match_id' => $matchId,
        ':room_host_slot' => $roomHostSlot,
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('房間綁定狀態已被其他請求更新。');
    }

    $db->commit();

    respond(200, [
        'ok' => true,
        'data' => [
            'ulgg_match_id' => $matchId,
            'game_room_id' => $roomId,
            'match_status' => 'WAITING_DECK',
            'player1_game_name' => (string)$match['player1_game_name'],
            'player2_game_name' => (string)$match['player2_game_name'],
        ],
    ]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[ULGG link_room] ' . $e->getMessage());

    failResponse(
        500,
        'LINK_ROOM_FAILED',
        '綁定遊戲房間失敗，請稍後再試。'
    );
}
