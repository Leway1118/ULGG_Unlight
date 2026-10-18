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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    failResponse(405, 'METHOD_NOT_ALLOWED', '只接受 POST 請求。');
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
    $db->beginTransaction();

    $stmt = $db->prepare(<<<'SQL'
        SELECT
            id,
            queue_status,
            matched_queue_id
        FROM custom_match_queue
        WHERE user_id = :user_id
          AND steam_id = :steam_id
          AND queue_status IN ('QUEUED', 'MATCHED')
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    SQL);
    $stmt->execute([
        ':user_id' => $userId,
        ':steam_id' => $steamId,
    ]);
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$queue) {
        $db->commit();
        respond(200, [
            'ok' => true,
            'data' => [
                'queue_status' => 'IDLE',
                'cancelled' => false,
                'already_idle' => true,
            ],
        ]);
    }

    if ((string)$queue['queue_status'] === 'MATCHED') {
        $db->rollBack();
        failResponse(
            409,
            'MATCH_ALREADY_CREATED',
            '配對已成立，現在不能自行取消。'
        );
    }

    $update = $db->prepare(<<<'SQL'
        UPDATE custom_match_queue
        SET
            queue_status = 'CANCELLED',
            matched_queue_id = NULL,
            matched_at = NULL,
            cancelled_at = NOW()
        WHERE id = :id
          AND user_id = :user_id
          AND steam_id = :steam_id
          AND queue_status = 'QUEUED'
    SQL);
    $update->execute([
        ':id' => (int)$queue['id'],
        ':user_id' => $userId,
        ':steam_id' => $steamId,
    ]);

    if ($update->rowCount() !== 1) {
        throw new RuntimeException('取消排隊時狀態已變更，請重新整理。');
    }

    $db->commit();

    respond(200, [
        'ok' => true,
        'data' => [
            'queue_id' => (int)$queue['id'],
            'queue_status' => 'CANCELLED',
            'cancelled' => true,
            'already_idle' => false,
        ],
    ]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[ULGG cancel_queue] ' . $e->getMessage());

    failResponse(
        500,
        'CANCEL_QUEUE_FAILED',
        '取消排隊失敗，請稍後再試。'
    );
}
