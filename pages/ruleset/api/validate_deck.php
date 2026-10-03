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

function calculateCostPunishment(array $costs): int
{
    if (count($costs) !== 3) {
        throw new InvalidArgumentException('角色牌必須正好三張。');
    }

    $punishment = 0;
    $differences = [
        abs($costs[0] - $costs[1]),
        abs($costs[0] - $costs[2]),
        abs($costs[1] - $costs[2]),
    ];

    foreach ($differences as $difference) {
        if ($difference > 6) {
            $punishment += 5;
        }

        if ($difference > 13) {
            $punishment += 5;
        }
    }

    return $punishment;
}

function calculateDeckCost(array $costs): int
{
    return array_sum($costs) + calculateCostPunishment($costs);
}

/**
 * 解析目前固定格式的角色 COST TOML。
 *
 * 支援：
 * cc001_01 = 99 # comment
 */
function loadCharacterCostRules(string $filePath): array
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException('Ruleset TOML 無法讀取。');
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Ruleset TOML 讀取失敗。');
    }

    $rules = [];

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!preg_match(
            '/^([A-Za-z0-9_]+)\s*=\s*(\d+)(?:\s*#.*)?$/',
            $line,
            $matches
        )) {
            throw new RuntimeException(
                sprintf('Ruleset TOML 第 %d 行格式錯誤。', $lineNumber + 1)
            );
        }

        $cardCode = strtolower($matches[1]);
        $cost = (int)$matches[2];

        $rules[$cardCode] = $cost;
    }

    if ($rules === []) {
        throw new RuntimeException('Ruleset TOML 沒有任何角色 COST。');
    }

    return $rules;
}

/**
 * Watcher charaIndex 是官方零基底索引。
 * 網站 unlight.id 是一基底，因此固定 +1。
 */
function resolveDeckCharacterCosts(
    PDO $db,
    array $deck,
    array $rules
): array {
    $charaCodes = $deck['chara'] ?? null;
    $charaIndexes = $deck['charaIndex'] ?? null;

    if (!is_array($charaCodes) || !is_array($charaIndexes)) {
        throw new InvalidArgumentException('牌組缺少 chara 或 charaIndex。');
    }

    if (count($charaCodes) !== 3 || count($charaIndexes) !== 3) {
        throw new InvalidArgumentException('牌組必須包含三張角色牌。');
    }

    $stmt = $db->prepare(<<<'SQL'
        SELECT
            id,
            name,
            level,
            chara_code,
            filename
        FROM unlight
        WHERE id = :id
        LIMIT 1
    SQL);

    $costs = [];

    for ($i = 0; $i < 3; $i++) {
        $watcherCode = strtolower(trim((string)$charaCodes[$i]));

        $watcherIndex = filter_var(
            $charaIndexes[$i],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );

        if ($watcherCode === '' || $watcherIndex === false) {
            throw new InvalidArgumentException('角色牌索引或代碼格式錯誤。');
        }

        $databaseCardId = $watcherIndex + 1;

        $stmt->execute([
            ':id' => $databaseCardId,
        ]);

        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            throw new RuntimeException('角色牌無法對應資料庫。');
        }

        if (strtolower((string)$card['chara_code']) !== $watcherCode) {
            throw new RuntimeException('Watcher 角色代碼與資料庫不一致。');
        }

        $filename = strtolower(trim((string)$card['filename']));

        if ($filename === '' || !array_key_exists($filename, $rules)) {
            throw new RuntimeException('角色牌不存在於目前 Ruleset。');
        }

        $filenameLevel = 0;

        if (preg_match('/_(\d{2})$/', $filename, $levelMatches)) {
            $filenameLevel = (int)$levelMatches[1];
        }

        $databaseLevel = (int)($card['level'] ?? 0);

        $displayLevel = $databaseLevel > 0
            ? $databaseLevel
            : $filenameLevel;

        $costs[] = [
            'card_id' => $filename,
            'name' => (string)$card['name'],
            'level' => $displayLevel,
            'ruleset_cost' => (int)$rules[$filename],
        ];
    }

    return $costs;
}

function resolveDeckExtraCosts(
    PDO $db,
    array $deck
): array {
    $weaponIds = $deck['weapon'] ?? [];
    $eventIds = $deck['eventIndex'] ?? [];

    if (!is_array($weaponIds) || !is_array($eventIds)) {
        throw new InvalidArgumentException(
            '牌組 weapon 或 eventIndex 格式錯誤。'
        );
    }

    $weaponStmt = $db->prepare(
        'SELECT cost FROM unlight_weapon WHERE id = :id LIMIT 1'
    );

    $eventStmt = $db->prepare(
        'SELECT cost FROM unlight_eventindex WHERE id = :id LIMIT 1'
    );

    $weaponCost = 0;
    $eventCost = 0;

    foreach ($weaponIds as $weaponId) {
        if ($weaponId === null) {
            continue;
        }

        if (!is_int($weaponId) && !ctype_digit((string)$weaponId)) {
            throw new InvalidArgumentException('武器 ID 格式錯誤。');
        }

        $weaponStmt->execute([
            ':id' => (int)$weaponId,
        ]);

        $cost = $weaponStmt->fetchColumn();

        if ($cost === false) {
            throw new RuntimeException(
                sprintf('找不到武器 ID %d。', (int)$weaponId)
            );
        }

        $weaponCost += (int)$cost;
    }

    foreach ($eventIds as $eventId) {
        if ($eventId === null) {
            continue;
        }

        if (!is_int($eventId) && !ctype_digit((string)$eventId)) {
            throw new InvalidArgumentException('事件卡 ID 格式錯誤。');
        }

        $eventStmt->execute([
            ':id' => (int)$eventId,
        ]);

        $cost = $eventStmt->fetchColumn();

        if ($cost === false) {
            throw new RuntimeException(
                sprintf('找不到事件卡 ID %d。', (int)$eventId)
            );
        }

        // 相同事件卡重複出現時，每張都要計算
        $eventCost += (int)$cost;
    }

    return [
        'weapon_cost' => $weaponCost,
        'event_cost' => $eventCost,
    ];
}

function deckIsLegalForCategory(
    int $category,
    int $player1Cost,
    int $player2Cost,
    array $weeklyCost
): bool {
    return match ($category) {
        1 => $player1Cost === (int)$weeklyCost['cost1']
            && $player2Cost === (int)$weeklyCost['cost1'],

        2 => $player1Cost === (int)$weeklyCost['cost2']
            && $player2Cost === (int)$weeklyCost['cost2'],

        3 => $player1Cost === (int)$weeklyCost['cost3']
            && $player2Cost === (int)$weeklyCost['cost3'],

        4 => $player1Cost >= (int)$weeklyCost['cost4']
            && $player2Cost >= (int)$weeklyCost['cost4']
            && abs($player1Cost - $player2Cost)
            <= (int)$weeklyCost['cost4_match_tolerance'],

        default => false,
    };
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
    failResponse(
        401,
        'INVALID_WATCHER_TOKEN',
        'Watcher API Token 無效。'
    );
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    failResponse(400, 'EMPTY_BODY', '請提供牌組資料。');
}

try {
    $input = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    failResponse(400, 'INVALID_JSON', 'JSON 格式錯誤。');
}

if (!is_array($input)) {
    failResponse(400, 'INVALID_BODY', '請提供 JSON Object。');
}

$roomId = trim((string)($input['room_id'] ?? ''));
$playerA = trim((string)($input['player_a'] ?? ''));
$playerB = trim((string)($input['player_b'] ?? ''));
$deckA = $input['deck_a'] ?? null;
$deckB = $input['deck_b'] ?? null;
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
    failResponse(422, 'INVALID_ROOM_ID', 'room_id 格式不正確。');
}

if ($playerA === '' || $playerB === '' || $playerA === $playerB) {
    failResponse(422, 'INVALID_PLAYERS', '玩家名稱格式不正確。');
}

if (!is_array($deckA) || !is_array($deckB)) {
    failResponse(422, 'INVALID_DECKS', 'deck_a 與 deck_b 必須是 Object。');
}

if (!isset($db) || !$db instanceof PDO) {
    failResponse(500, 'DATABASE_UNAVAILABLE', '資料庫連線尚未建立。');
}



try {


    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->beginTransaction();

    $matchStmt = $db->prepare(<<<'SQL'
        SELECT
            id,
            rule_version_id,
            weekly_cost_id,
            cost_category,
            player1_queue_id,
            player2_queue_id,
            player1_game_name,
            player2_game_name,
            match_status,
            game_room_id,
            created_at
        FROM custom_matches
        WHERE game_room_id = :game_room_id
          AND match_status = 'WAITING_DECK'
        LIMIT 1
        FOR UPDATE
    SQL);

    $matchStmt->execute([
        ':game_room_id' => $roomId,
    ]);

    $match = $matchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        $db->rollBack();

        failResponse(
            404,
            'WAITING_DECK_MATCH_NOT_FOUND',
            '找不到此房間對應的 WAITING_DECK 比賽。'
        );
    }

    $matchId = (int)$match['id'];
    $matchCreatedTimestamp = strtotime(
        (string)$match['created_at']
    );

    $snapshotTimestamp =
        ((int)$snapshotDateMs) / 1000;

    if (
        $matchCreatedTimestamp === false
        || $snapshotTimestamp < $matchCreatedTimestamp
    ) {
        $db->rollBack();

        failResponse(
            409,
            'STALE_ROOM_SNAPSHOT',
            'Watcher 房間資料早於本場配對建立時間。',
            [
                'ulgg_match_id' => $matchId,
                'snapshot_date_ms' => (int)$snapshotDateMs,
                'match_created_at' =>
                (string)$match['created_at'],
            ]
        );
    }
    $matchRuleVersionId = trim(
        (string)$match['rule_version_id']
    );

    $rulesetMap = defined('ULGG_RULESET_TOML_BY_VERSION')
        ? constant('ULGG_RULESET_TOML_BY_VERSION')
        : [];

    if (
        !is_array($rulesetMap)
        || !isset($rulesetMap[$matchRuleVersionId])
    ) {
        $db->rollBack();

        failResponse(
            409,
            'LOCKED_RULESET_NOT_FOUND',
            '找不到本場鎖定的 Ruleset Version。',
            [
                'rule_version_id' => $matchRuleVersionId,
            ]
        );
    }

    $rulesetTomlPath = (string)$rulesetMap[$matchRuleVersionId];
    $rules = loadCharacterCostRules($rulesetTomlPath);

    $matchPlayer1 = trim((string)$match['player1_game_name']);
    $matchPlayer2 = trim((string)$match['player2_game_name']);

    if ($playerA === $matchPlayer1 && $playerB === $matchPlayer2) {
        $player1Deck = $deckA;
        $player2Deck = $deckB;
    } elseif ($playerA === $matchPlayer2 && $playerB === $matchPlayer1) {
        $player1Deck = $deckB;
        $player2Deck = $deckA;
    } else {
        $db->rollBack();

        failResponse(
            409,
            'PLAYER_MISMATCH',
            '房間玩家與 Match 玩家不一致。'
        );
    }

    $weeklyStmt = $db->prepare(<<<'SQL'
        SELECT
            id,
            cost1,
            cost2,
            cost3,
            cost4,
            cost4_operator,
            cost4_match_tolerance,
            import_status,
            matchmaking_enabled
        FROM quickmatch_weekly_cost
        WHERE id = :weekly_cost_id
        LIMIT 1
        LOCK IN SHARE MODE
    SQL);

    $weeklyStmt->execute([
        ':weekly_cost_id' => (int)$match['weekly_cost_id'],
    ]);

    $weeklyCost = $weeklyStmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$weeklyCost
        || (string)$weeklyCost['import_status'] !== 'APPROVED'
        || (int)$weeklyCost['matchmaking_enabled'] !== 1
    ) {
        throw new RuntimeException('Match 使用的每週 COST 已失效。');
    }

    $player1CharacterCards = resolveDeckCharacterCosts(
        $db,
        $player1Deck,
        $rules
    );

    $player2CharacterCards = resolveDeckCharacterCosts(
        $db,
        $player2Deck,
        $rules
    );

    $player1CharacterCosts = array_map(
        static fn(array $card): int => (int)$card['ruleset_cost'],
        $player1CharacterCards
    );

    $player2CharacterCosts = array_map(
        static fn(array $card): int => (int)$card['ruleset_cost'],
        $player2CharacterCards
    );

    $player1CharacterSubtotal = array_sum($player1CharacterCosts);
    $player2CharacterSubtotal = array_sum($player2CharacterCosts);

    $player1Penalty = calculateCostPunishment($player1CharacterCosts);
    $player2Penalty = calculateCostPunishment($player2CharacterCosts);

    $player1ExtraCosts = resolveDeckExtraCosts(
        $db,
        $player1Deck
    );

    $player2ExtraCosts = resolveDeckExtraCosts(
        $db,
        $player2Deck
    );

    $player1WeaponCost =
        $player1ExtraCosts['weapon_cost'];

    $player1EventCost =
        $player1ExtraCosts['event_cost'];

    $player2WeaponCost =
        $player2ExtraCosts['weapon_cost'];

    $player2EventCost =
        $player2ExtraCosts['event_cost'];

    $player1DeckCost =
        $player1CharacterSubtotal
        + $player1Penalty
        + $player1WeaponCost
        + $player1EventCost;

    $player2DeckCost =
        $player2CharacterSubtotal
        + $player2Penalty
        + $player2WeaponCost
        + $player2EventCost;


    $costCategory = (int)$match['cost_category'];
    $cost4Operator = strtoupper(
        (string)($weeklyCost['cost4_operator'] ?? 'MIN')
    );

    $player1Legal = false;
    $player2Legal = false;
    $pairLegal = true;

    switch ($costCategory) {
        case 1:
            $targetCost = (int)$weeklyCost['cost1'];
            $player1Legal = $player1DeckCost === $targetCost;
            $player2Legal = $player2DeckCost === $targetCost;
            break;

        case 2:
            $targetCost = (int)$weeklyCost['cost2'];
            $player1Legal = $player1DeckCost === $targetCost;
            $player2Legal = $player2DeckCost === $targetCost;
            break;

        case 3:
            $targetCost = (int)$weeklyCost['cost3'];
            $player1Legal = $player1DeckCost === $targetCost;
            $player2Legal = $player2DeckCost === $targetCost;
            break;

        case 4:
            $targetCost = (int)$weeklyCost['cost4'];

            if ($cost4Operator === 'EXACT') {
                $player1Legal = $player1DeckCost === $targetCost;
                $player2Legal = $player2DeckCost === $targetCost;
            } else {
                $player1Legal = $player1DeckCost >= $targetCost;
                $player2Legal = $player2DeckCost >= $targetCost;
            }

            $pairLegal =
                abs($player1DeckCost - $player2DeckCost)
                <= (int)$weeklyCost['cost4_match_tolerance'];
            break;

        default:
            throw new RuntimeException('Match COST 類別無效。');
    }

    $isLegal = $player1Legal && $player2Legal && $pairLegal;

    $player1AuditJson = json_encode(
        [
            'player_name' => $matchPlayer1,
            'cards' => $player1CharacterCards,
            'character_subtotal' => $player1CharacterSubtotal,
            'weapon_cost' => $player1WeaponCost,
            'event_cost' => $player1EventCost,
            'difference_penalty' => $player1Penalty,
            'non_character_cost' =>
            $player1WeaponCost
                + $player1EventCost,
            'final_cost' => $player1DeckCost,
            'target_cost' => $targetCost,
            'legal' => $player1Legal,
            'player_legal' => $player1Legal,
            'pair_legal' => $pairLegal,
            'overall_legal' => $isLegal,
        ],
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );

    $player2AuditJson = json_encode(
        [
            'player_name' => $matchPlayer2,
            'cards' => $player2CharacterCards,
            'character_subtotal' => $player2CharacterSubtotal,
            'weapon_cost' => $player2WeaponCost,
            'event_cost' => $player2EventCost,
            'difference_penalty' => $player2Penalty,
            'non_character_cost' =>
            $player2WeaponCost
                + $player2EventCost,
            'final_cost' => $player2DeckCost,
            'target_cost' => $targetCost,
            'legal' => $player2Legal,
            'player_legal' => $player2Legal,
            'pair_legal' => $pairLegal,
            'overall_legal' => $isLegal,
        ],
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );




    if ($isLegal) {
        $updateMatchStmt = $db->prepare(<<<'SQL'
        UPDATE custom_matches
        SET
            match_status = 'READY',
            deck_validated_at = NOW(),
            player1_deck_audit_json = :player1_deck_audit_json,
            player2_deck_audit_json = :player2_deck_audit_json,
            cancellation_code = NULL,
            cancellation_message = NULL
        WHERE id = :match_id
          AND match_status = 'WAITING_DECK'
    SQL);

        $updateMatchStmt->execute([
            ':player1_deck_audit_json' => $player1AuditJson,
            ':player2_deck_audit_json' => $player2AuditJson,
            ':match_id' => $matchId,
        ]);

        if ($updateMatchStmt->rowCount() !== 1) {
            throw new RuntimeException('Match 狀態已被其他請求更新。');
        }
        $verifyQueueStmt = $db->prepare(<<<'SQL'
            UPDATE custom_match_queue
            SET
                queue_status = 'VERIFIED',
                cancelled_at = NULL
            WHERE id IN (
                :player1_queue_id,
                :player2_queue_id
            )
            AND queue_status = 'MATCHED'
        SQL);

        $verifyQueueStmt->execute([
            ':player1_queue_id' =>
            (int)$match['player1_queue_id'],

            ':player2_queue_id' =>
            (int)$match['player2_queue_id'],
        ]);

        if ($verifyQueueStmt->rowCount() !== 2) {
            throw new RuntimeException(
                '牌組驗證完成，但 Queue 終點狀態更新失敗。'
            );
        }
        $db->commit();

        respond(200, [
            'ok' => true,
            'data' => [
                'ulgg_match_id' => $matchId,
                'game_room_id' => $roomId,
                'match_status' => 'READY',
            ],
        ]);
    }

    $cancellationMessage = match (true) {
        !$player1Legal && !$player2Legal =>
        '雙方牌組皆未符合本場指定的 COST 規則。',

        !$player1Legal =>
        '玩家 1 的牌組未符合本場指定的 COST 規則。',

        !$player2Legal =>
        '玩家 2 的牌組未符合本場指定的 COST 規則。',

        !$pairLegal =>
        '雙方牌組 COST 差距超過本場允許範圍。',

        default =>
        '牌組驗證未通過，本場配對已取消。',
    };

    $cancelMatchStmt = $db->prepare(<<<'SQL'
    UPDATE custom_matches
    SET
        match_status = 'CANCELLED',
        deck_validated_at = NOW(),
        cancelled_at = NOW(),
        player1_deck_audit_json = :player1_deck_audit_json,
        player2_deck_audit_json = :player2_deck_audit_json,
        cancellation_code = :cancellation_code,
        cancellation_message = :cancellation_message
    WHERE id = :match_id
      AND match_status = 'WAITING_DECK'
SQL);

    $cancelMatchStmt->execute([
        ':player1_deck_audit_json' => $player1AuditJson,
        ':player2_deck_audit_json' => $player2AuditJson,
        ':cancellation_code' => 'DECK_COST_MISMATCH',
        ':cancellation_message' => $cancellationMessage,
        ':match_id' => $matchId,
    ]);

    if ($cancelMatchStmt->rowCount() !== 1) {
        throw new RuntimeException('Match 取消狀態已被其他請求更新。');
    }

    $cancelQueueStmt = $db->prepare(<<<'SQL'
    UPDATE custom_match_queue
    SET
        queue_status = 'CANCELLED',
        cancelled_at = NOW()
    WHERE id IN (:player1_queue_id, :player2_queue_id)
      AND queue_status = 'MATCHED'
SQL);

    $cancelQueueStmt->execute([
        ':player1_queue_id' => (int)$match['player1_queue_id'],
        ':player2_queue_id' => (int)$match['player2_queue_id'],
    ]);

    $db->commit();

    respond(200, [
        'ok' => true,
        'data' => [
            'ulgg_match_id' => $matchId,
            'game_room_id' => $roomId,
            'match_status' => 'CANCELLED',
        ],
    ]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[ULGG validate_deck] ' . $e->getMessage());

    failResponse(
        500,
        'DECK_VALIDATION_FAILED',
        '牌組驗證失敗，請稍後再試。'
    );
}
