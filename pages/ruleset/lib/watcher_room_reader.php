<?php

declare(strict_types=1);

/**
 * Watcher 單房間 Snapshot Reader
 *
 * 責任：
 * - 驗證 game room ID
 * - 安全組合 snapshot 路徑
 * - 讀取單一 ROOM JSON
 * - 驗證 JSON 與 room_id 一致性
 * - 分離資料狀態、freshness 與讀取錯誤
 */

const WATCHER_ROOM_DEFAULT_MAX_AGE_SECONDS = 300;

/**
 * 回傳 ROOM2 snapshot 根目錄。
 */
function getWatcherRoomSnapshotDirectory(): string
{
    return dirname(__DIR__, 3)
        . '/watcher/room_snapshots/channel2';
}

/**
 * 驗證 Unlight game room ID。
 *
 * 目前觀察到的格式為 32 碼英數字。
 */
function isValidWatcherRoomId(string $roomId): bool
{
    return preg_match('/^[A-Za-z0-9]{32}$/', $roomId) === 1;
}

/**
 * 建立標準錯誤結果。
 */
function buildWatcherRoomReaderError(
    string $code,
    string $message,
    ?string $roomId = null
): array {
    return [
        'ok' => false,
        'lookup_status' => 'ERROR',
        'data_status' => 'UNAVAILABLE',
        'freshness' => [
            'status' => 'UNKNOWN',
            'snapshot_date_ms' => null,
            'age_seconds' => null,
            'max_age_seconds' => null,
        ],
        'room_id' => $roomId,
        'room' => null,
        'warnings' => [],
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];
}

/**
 * 計算 snapshot freshness。
 */
function evaluateWatcherRoomFreshness(
    mixed $snapshotDate,
    int $maxAgeSeconds,
    ?int $nowMs = null
): array {
    if (
        !is_int($snapshotDate)
        && !is_float($snapshotDate)
    ) {
        return [
            'status' => 'UNKNOWN',
            'snapshot_date_ms' => null,
            'age_seconds' => null,
            'max_age_seconds' => $maxAgeSeconds,
        ];
    }

    $snapshotDateMs = (int)$snapshotDate;
    $currentTimeMs = $nowMs ?? (int)round(microtime(true) * 1000);
    $ageMilliseconds = $currentTimeMs - $snapshotDateMs;
    $ageSeconds = $ageMilliseconds / 1000;

    /*
     * 容許最多 5 秒的時鐘偏差。
     * 超過時視為 FUTURE_TIMESTAMP，不直接拿來判罰。
     */
    if ($ageSeconds < -5) {
        return [
            'status' => 'FUTURE_TIMESTAMP',
            'snapshot_date_ms' => $snapshotDateMs,
            'age_seconds' => round($ageSeconds, 3),
            'max_age_seconds' => $maxAgeSeconds,
        ];
    }

    if ($ageSeconds > $maxAgeSeconds) {
        return [
            'status' => 'STALE',
            'snapshot_date_ms' => $snapshotDateMs,
            'age_seconds' => round($ageSeconds, 3),
            'max_age_seconds' => $maxAgeSeconds,
        ];
    }

    return [
        'status' => 'FRESH',
        'snapshot_date_ms' => $snapshotDateMs,
        'age_seconds' => round(max(0, $ageSeconds), 3),
        'max_age_seconds' => $maxAgeSeconds,
    ];
}

/**
 * 檢查 ROOM snapshot 是否具備 COST 驗證所需資料。
 */
function inspectWatcherRoomCompleteness(array $room): array
{
    $warnings = [];

    if (
        !isset($room['playerA'])
        || !is_array($room['playerA'])
    ) {
        $warnings[] = 'PLAYER_A_MISSING';
    }

    if (
        !isset($room['playerB'])
        || !is_array($room['playerB'])
    ) {
        $warnings[] = 'PLAYER_B_MISSING';
    }

    if (
        !isset($room['deckA'])
        || !is_array($room['deckA'])
    ) {
        $warnings[] = 'DECK_A_MISSING';
    }

    if (
        !isset($room['deckB'])
        || !is_array($room['deckB'])
    ) {
        $warnings[] = 'DECK_B_MISSING';
    }

    if (
        !array_key_exists('date', $room)
        || (
            !is_int($room['date'])
            && !is_float($room['date'])
        )
    ) {
        $warnings[] = 'SNAPSHOT_DATE_MISSING_OR_INVALID';
    }

    return [
        'data_status' => $warnings === []
            ? 'COMPLETE'
            : 'INCOMPLETE',
        'warnings' => $warnings,
    ];
}

/**
 * 讀取指定 game room ID 的最新 snapshot。
 *
 * 回傳結果不會丟出例外，所有錯誤都會轉成結構化結果。
 */
function readWatcherRoomSnapshot(
    string $roomId,
    int $maxAgeSeconds = WATCHER_ROOM_DEFAULT_MAX_AGE_SECONDS,
    ?int $nowMs = null
): array {
    $roomId = trim($roomId);

    if (!isValidWatcherRoomId($roomId)) {
        return buildWatcherRoomReaderError(
            'INVALID_ROOM_ID',
            'Game room ID must contain exactly 32 ASCII letters or digits.',
            $roomId
        );
    }

    if ($maxAgeSeconds < 1) {
        return buildWatcherRoomReaderError(
            'INVALID_MAX_AGE',
            'Maximum snapshot age must be at least one second.',
            $roomId
        );
    }

    $snapshotDirectory = getWatcherRoomSnapshotDirectory();
    $snapshotPath = $snapshotDirectory . '/' . $roomId . '.json';

    if (!is_file($snapshotPath)) {
        return [
            'ok' => false,
            'lookup_status' => 'NOT_FOUND',
            'data_status' => 'UNAVAILABLE',
            'freshness' => [
                'status' => 'UNKNOWN',
                'snapshot_date_ms' => null,
                'age_seconds' => null,
                'max_age_seconds' => $maxAgeSeconds,
            ],
            'room_id' => $roomId,
            'room' => null,
            'warnings' => [],
            'error' => [
                'code' => 'SNAPSHOT_NOT_FOUND',
                'message' => 'Watcher room snapshot was not found.',
            ],
        ];
    }

    if (!is_readable($snapshotPath)) {
        return buildWatcherRoomReaderError(
            'SNAPSHOT_NOT_READABLE',
            'Watcher room snapshot is not readable.',
            $roomId
        );
    }

    $rawJson = file_get_contents($snapshotPath);

    if ($rawJson === false) {
        return buildWatcherRoomReaderError(
            'SNAPSHOT_READ_FAILED',
            'Failed to read watcher room snapshot.',
            $roomId
        );
    }

    try {
        $room = json_decode(
            $rawJson,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        return buildWatcherRoomReaderError(
            'SNAPSHOT_INVALID_JSON',
            'Watcher room snapshot contains invalid JSON.',
            $roomId
        );
    }

    if (!is_array($room)) {
        return buildWatcherRoomReaderError(
            'SNAPSHOT_INVALID_STRUCTURE',
            'Watcher room snapshot must be a JSON object.',
            $roomId
        );
    }

    $snapshotRoomId = $room['room_id'] ?? null;

    if (
        !is_string($snapshotRoomId)
        || $snapshotRoomId !== $roomId
    ) {
        return buildWatcherRoomReaderError(
            'SNAPSHOT_ROOM_ID_MISMATCH',
            'Snapshot room_id does not match the requested game room ID.',
            $roomId
        );
    }

    $completeness = inspectWatcherRoomCompleteness($room);
    $freshness = evaluateWatcherRoomFreshness(
        $room['date'] ?? null,
        $maxAgeSeconds,
        $nowMs
    );

    $warnings = $completeness['warnings'];

    if ($freshness['status'] === 'STALE') {
        $warnings[] = 'SNAPSHOT_STALE';
    }

    if ($freshness['status'] === 'FUTURE_TIMESTAMP') {
        $warnings[] = 'SNAPSHOT_FUTURE_TIMESTAMP';
    }

    return [
        'ok' => true,
        'lookup_status' => 'FOUND',
        'data_status' => $completeness['data_status'],
        'freshness' => $freshness,
        'room_id' => $roomId,
        'room' => $room,
        'warnings' => $warnings,
        'error' => null,
    ];
}