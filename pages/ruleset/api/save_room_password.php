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
    string $message
): never {
    respond($status, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    failResponse(
        405,
        'METHOD_NOT_ALLOWED',
        '只接受 POST 請求。'
    );
}

$contentType = strtolower(
    (string)($_SERVER['CONTENT_TYPE'] ?? '')
);

if (!str_starts_with($contentType, 'application/json')) {
    failResponse(
        415,
        'UNSUPPORTED_MEDIA_TYPE',
        'Content-Type 必須是 application/json。'
    );
}

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($userId === false) {
    failResponse(
        401,
        'AUTH_REQUIRED',
        '請先登入。'
    );
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    failResponse(
        400,
        'EMPTY_BODY',
        '請提供房間密碼。'
    );
}

try {
    $input = json_decode(
        $rawBody,
        true,
        32,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    failResponse(
        400,
        'INVALID_JSON',
        'JSON 格式錯誤。'
    );
}

if (!is_array($input)) {
    failResponse(
        400,
        'INVALID_BODY',
        '請提供 JSON Object。'
    );
}

$matchId = filter_var(
    $input['match_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($matchId === false) {
    failResponse(
        422,
        'INVALID_MATCH_ID',
        '比賽編號格式不正確。'
    );
}

$gameRoomPassword = trim(
    (string)($input['game_room_password'] ?? '')
);

if ($gameRoomPassword === '') {
    failResponse(
        422,
        'EMPTY_ROOM_PASSWORD',
        '請貼上遊戲產生的房間密碼。'
    );
}

if (mb_strlen($gameRoomPassword, 'UTF-8') > 64) {
    failResponse(
        422,
        'ROOM_PASSWORD_TOO_LONG',
        '房間密碼長度異常。'
    );
}

if (!isset($db) || !$db instanceof PDO) {
    failResponse(
        500,
        'DATABASE_UNAVAILABLE',
        '資料庫連線尚未建立。'
    );
}

try {
    $db->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $db->beginTransaction();

    $matchStmt = $db->prepare(<<<'SQL'
        SELECT
            id,
            player1_user_id,
            player2_user_id,
            match_status
        FROM custom_matches
        WHERE id = :match_id
        LIMIT 1
        FOR UPDATE
    SQL);

    $matchStmt->execute([
        ':match_id' => (int)$matchId,
    ]);

    $match = $matchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        $db->rollBack();

        failResponse(
            404,
            'MATCH_NOT_FOUND',
            '找不到這場配對。'
        );
    }

    if ((int)$match['player1_user_id'] !== (int)$userId) {
        $db->rollBack();

        failResponse(
            403,
            'HOST_REQUIRED',
            '只有本場指定房主可以提供房間密碼。'
        );
    }

    $matchStatus = strtoupper(
        (string)$match['match_status']
    );

    if (!in_array(
        $matchStatus,
        ['WAITING_ROOM', 'WAITING_DECK'],
        true
    )) {
        $db->rollBack();

        failResponse(
            409,
            'MATCH_PASSWORD_LOCKED',
            '目前比賽狀態已不允許修改房間密碼。'
        );
    }

    $updateStmt = $db->prepare(<<<'SQL'
        UPDATE custom_matches
        SET
            game_room_password = :game_room_password,
            game_room_password_updated_at = NOW()
        WHERE id = :match_id
          AND player1_user_id = :user_id
          AND match_status IN (
              'WAITING_ROOM',
              'WAITING_DECK'
          )
    SQL);

    $updateStmt->execute([
        ':game_room_password' => $gameRoomPassword,
        ':match_id' => (int)$matchId,
        ':user_id' => (int)$userId,
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException(
            '房間密碼更新時狀態已改變。'
        );
    }

    $db->commit();

    respond(200, [
        'ok' => true,
        'data' => [
            'match_id' => (int)$matchId,
            'game_room_password' => $gameRoomPassword,
            'game_room_password_updated_at' =>
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ],
    ]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log(
        '[ULGG save_room_password] '
        . $e->getMessage()
    );

    failResponse(
        500,
        'SAVE_ROOM_PASSWORD_FAILED',
        '儲存房間密碼失敗，請稍後再試。'
    );
}