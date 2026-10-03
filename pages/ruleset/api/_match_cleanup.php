<?php

declare(strict_types=1);

/**
 * 清理已逾時但沒有正常走到終點的 Match。
 *
 * WAITING_ROOM：
 * 配對成立 5 分鐘仍未綁定遊戲房間。
 *
 * WAITING_DECK：
 * 房間綁定 10 分鐘仍未完成牌組驗證。
 */
function cleanupExpiredCustomMatches(PDO $db): array
{
    $roomTimeoutStmt = $db->prepare(<<<'SQL'
        UPDATE custom_matches
        SET
            match_status = 'CANCELLED',
            cancelled_at = NOW(),
            cancellation_code = 'ROOM_TIMEOUT',
            cancellation_message =
                '配對成立後 5 分鐘內未建立遊戲房間。'
        WHERE match_status = 'WAITING_ROOM'
          AND game_room_id IS NULL
          AND created_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    SQL);

    $roomTimeoutStmt->execute();
    $roomTimeoutCount = $roomTimeoutStmt->rowCount();

    $deckTimeoutStmt = $db->prepare(<<<'SQL'
        UPDATE custom_matches
        SET
            match_status = 'CANCELLED',
            cancelled_at = NOW(),
            cancellation_code = 'DECK_TIMEOUT',
            cancellation_message =
                '遊戲房間綁定後 10 分鐘內未完成牌組驗證。'
        WHERE match_status = 'WAITING_DECK'
          AND room_linked_at IS NOT NULL
          AND room_linked_at <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    SQL);

    $deckTimeoutStmt->execute();
    $deckTimeoutCount = $deckTimeoutStmt->rowCount();

    /*
 * CANCELLED Match 對應的 Queue 改為 CANCELLED。
 * READY Match 的 Queue 由 validate_deck.php
 * 在同一個 transaction 中改為 VERIFIED。
 */
    $queueCleanupStmt = $db->prepare(<<<'SQL'
        UPDATE custom_match_queue AS q
        INNER JOIN custom_matches AS m
          ON m.id = q.ulgg_match_id
        SET
            q.queue_status = 'CANCELLED',
            q.cancelled_at = COALESCE(
                q.cancelled_at,
                m.cancelled_at,
                NOW()
            )
        WHERE q.queue_status = 'MATCHED'
          AND m.match_status = 'CANCELLED'
    SQL);

    $queueCleanupStmt->execute();

    return [
        'room_timeout_count' => $roomTimeoutCount,
        'deck_timeout_count' => $deckTimeoutCount,
        'queue_cleanup_count' => $queueCleanupStmt->rowCount(),
    ];
}
