<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../config.php';
$pdo = $db; // ⭐ 一定要這行
require_once __DIR__ . '/../lib/base_cost_csv.php';
require_once __DIR__ . '/../lib/ruleset_toml.php';
require_once __DIR__ . '/../lib/deck_cost_evaluator.php';
require_once __DIR__ . '/../lib/watcher_room_reader.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const RULE_SET_ID = 'ulgg/glasses-99-demo';
const RULE_VERSION_ID = 'ulgg-glasses-99-demo@0.1.0';
const RULESET_NAME = '眼鏡99版';
const COST_LIMIT = 60;


function loadCostSources(PDO $db): array
{
    $baseCostFile = __DIR__
        . '/../data/test/cost_cc_pure.csv';

    $rulesetCostFile = __DIR__
        . '/../data/test/cc_asset_cost_demo_v1.toml';

    return [
        'base_cost_map' =>
        loadBaseCostMapFromCsv($baseCostFile),

        'ruleset_cost_map' =>
        loadRulesetCostMap($rulesetCostFile),

        'weapon_cost_map' =>
        loadWeaponCostMap($db),

        'event_cost_map' =>
        loadEventCostMap($db),
    ];
}

function loadWeaponCostMap(PDO $db): array
{
    $stmt = $db->query("
        SELECT id, cost
        FROM unlight_weapon
    ");

    $map = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$row['id']] = (int)($row['cost'] ?? 0);
    }

    return $map;
}


function loadEventCostMap(PDO $db): array
{
    $stmt = $db->query("
        SELECT id, cost
        FROM unlight_eventindex
    ");

    $map = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$row['id']] = (int)($row['cost'] ?? 0);
    }

    return $map;
}


function respondJson(int $statusCode, array $payload): never
{
    http_response_code($statusCode);

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        http_response_code(500);
        echo '{"ok":false,"error":{"code":"JSON_ENCODE_FAILED","message":"Failed to encode response JSON."}}';
        exit;
    }

    echo $json;
    exit;
}

function buildRulesetResponse(): array
{
    return [
        'rule_set_id' => RULE_SET_ID,
        'rule_version_id' => RULE_VERSION_ID,
        'name' => RULESET_NAME,
        'cost_limit' => COST_LIMIT,
    ];
}

function attachCharacterMetadata(
    PDO $db,
    array $evaluation
): array {
    $cards = $evaluation['cards'] ?? [];

    if (!is_array($cards) || $cards === []) {
        return $evaluation;
    }

    $characterIndexes = [];

    foreach ($cards as $card) {
        $characterIndex = $card['chara_index'] ?? null;

        if (is_int($characterIndex) && $characterIndex > 0) {
            $characterIndexes[] = $characterIndex;
        }
    }

    $characterIndexes = array_values(
        array_unique($characterIndexes)
    );

    if ($characterIndexes === []) {
        return $evaluation;
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($characterIndexes), '?')
    );

    $stmt = $db->prepare("
        SELECT id, name, level
        FROM unlight
        WHERE id IN ($placeholders)
    ");

    $stmt->execute($characterIndexes);

    $characterMap = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $characterMap[(int)$row['id']] = [
            'name' => (string)($row['name'] ?? ''),
            'level' => (string)($row['level'] ?? ''),
        ];
    }

    foreach ($cards as $index => $card) {
        $characterIndex = $card['chara_index'] ?? null;

        if (
            !is_int($characterIndex)
            || !isset($characterMap[$characterIndex])
        ) {
            continue;
        }

        $cards[$index]['name'] =
            $characterMap[$characterIndex]['name'];

        $cards[$index]['level'] =
            $characterMap[$characterIndex]['level'];
    }

    $evaluation['cards'] = $cards;

    return $evaluation;
}

function evaluateSingleDeck(
    PDO $db,
    array $deck,
    array $baseCostMap,
    array $rulesetCostMap,
    array $weaponCostMap,
    array $eventCostMap
): array {
    $evaluation = evaluateWatcherDeckCost(
        $deck,
        $baseCostMap,
        $rulesetCostMap,
        $weaponCostMap,
        $eventCostMap,
        COST_LIMIT
    );

    return attachCharacterMetadata(
        $db,
        $evaluation
    );
}

function getRoomOverallStatus(
    array $evaluationA,
    array $evaluationB
): string {
    $statusA = $evaluationA['status'] ?? 'ERROR';
    $statusB = $evaluationB['status'] ?? 'ERROR';

    if ($statusA === 'ILLEGAL' || $statusB === 'ILLEGAL') {
        return 'VIOLATION_FOUND';
    }

    $incompleteStatuses = [
        'UNKNOWN_CARD',
        'INSUFFICIENT_DATA',
    ];

    if (
        in_array($statusA, $incompleteStatuses, true)
        || in_array($statusB, $incompleteStatuses, true)
    ) {
        return 'INCOMPLETE';
    }

    if ($statusA === 'LEGAL' && $statusB === 'LEGAL') {
        return 'PASSED';
    }

    return 'ERROR';
}

function normalizePlayer(array $room, string $side): array
{
    $playerKey = 'player' . $side;
    $player = $room[$playerKey] ?? null;

    if (!is_array($player)) {
        return [
            'side' => $side,
            'name' => null,
            'level' => null,
            'bp' => null,
        ];
    }

    return [
        'side' => $side,
        'name' => isset($player['name'])
            && is_string($player['name'])
            ? $player['name']
            : null,
        'level' => isset($player['level'])
            && is_int($player['level'])
            ? $player['level']
            : null,
        'bp' => isset($player['bp'])
            && is_int($player['bp'])
            ? $player['bp']
            : null,
    ];
}

function isSingleDeckPayload(array $payload): bool
{
    if (isset($payload['deck']) && is_array($payload['deck'])) {
        return true;
    }

    return isset(
        $payload['chara'],
        $payload['charaIndex'],
        $payload['cost']
    );
}

function extractSingleDeck(array $payload): array
{
    if (isset($payload['deck']) && is_array($payload['deck'])) {
        return $payload['deck'];
    }

    return $payload;
}

/* 模式判斷函式 */
function isWatcherRoomLookupPayload(array $payload): bool
{
    return array_key_exists('game_room_id', $payload);
}
function isRoomPayload(array $payload): bool
{
    return array_key_exists('deckA', $payload)
        || array_key_exists('deckB', $payload)
        || array_key_exists('room_id', $payload)
        || array_key_exists('playerA', $payload)
        || array_key_exists('playerB', $payload);
}

function validateRoomPayload(array $room): array
{
    $errors = [];

    if (
        !isset($room['room_id'])
        || !is_string($room['room_id'])
        || trim($room['room_id']) === ''
    ) {
        $errors[] = 'ROOM_ID_MISSING_OR_INVALID';
    }

    if (
        !isset($room['deckA'])
        || !is_array($room['deckA'])
    ) {
        $errors[] = 'DECK_A_MISSING_OR_INVALID';
    }

    if (
        !isset($room['deckB'])
        || !is_array($room['deckB'])
    ) {
        $errors[] = 'DECK_B_MISSING_OR_INVALID';
    }

    return $errors;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respondJson(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Only POST is allowed.',
        ],
    ]);
}

$contentType = strtolower(
    $_SERVER['CONTENT_TYPE'] ?? ''
);

if (!str_contains($contentType, 'application/json')) {
    respondJson(415, [
        'ok' => false,
        'error' => [
            'code' => 'UNSUPPORTED_MEDIA_TYPE',
            'message' => 'Content-Type must be application/json.',
        ],
    ]);
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    respondJson(400, [
        'ok' => false,
        'error' => [
            'code' => 'EMPTY_REQUEST_BODY',
            'message' => 'Request body is empty.',
        ],
    ]);
}

try {
    $payload = json_decode(
        $rawBody,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    respondJson(400, [
        'ok' => false,
        'error' => [
            'code' => 'INVALID_JSON',
            'message' => 'Request body is not valid JSON.',
        ],
    ]);
}

if (!is_array($payload)) {
    respondJson(400, [
        'ok' => false,
        'error' => [
            'code' => 'INVALID_PAYLOAD',
            'message' => 'Request body must be a JSON object.',
        ],
    ]);
}

try {
    $costSources = loadCostSources($db);

    $baseCostMap =
        $costSources['base_cost_map'];

    $rulesetCostMap =
        $costSources['ruleset_cost_map'];

    $weaponCostMap =
        $costSources['weapon_cost_map'];

    $eventCostMap =
        $costSources['event_cost_map'];
} catch (Throwable $exception) {
    respondJson(500, [
        'ok' => false,
        'error' => [
            'code' => 'COST_SOURCE_LOAD_FAILED',
            'message' => $exception->getMessage(),
        ],
    ]);
}
/*
 * Watcher ROOM lookup 模式
 *
 * 瀏覽器只提供 game_room_id，
 * 後端自行讀取可信任的 Watcher snapshot。
 *
 * {
 *   "game_room_id": "LQbpMYmWlZmRfzPoXMFnN0FuHORJG454"
 * }
 */
if (isWatcherRoomLookupPayload($payload)) {
    $gameRoomId = $payload['game_room_id'] ?? null;

    if (!is_string($gameRoomId)) {
        respondJson(400, [
            'ok' => false,
            'mode' => 'WATCHER_ROOM',
            'error' => [
                'code' => 'INVALID_GAME_ROOM_ID',
                'message' => 'game_room_id must be a string.',
            ],
        ]);
    }

    $readerResult = readWatcherRoomSnapshot(
        trim($gameRoomId)
    );

    if ($readerResult['ok'] !== true) {
        $readerErrorCode =
            $readerResult['error']['code']
            ?? 'WATCHER_ROOM_READ_FAILED';

        $statusCode = match ($readerErrorCode) {
            'INVALID_ROOM_ID',
            'INVALID_MAX_AGE' => 400,

            'SNAPSHOT_NOT_FOUND' => 404,

            default => 500,
        };

        respondJson($statusCode, [
            'ok' => false,
            'mode' => 'WATCHER_ROOM',
            'game_room_id' => trim($gameRoomId),
            'lookup_status' =>
            $readerResult['lookup_status']
                ?? 'ERROR',
            'data_status' =>
            $readerResult['data_status']
                ?? 'UNAVAILABLE',
            'freshness' =>
            $readerResult['freshness']
                ?? null,
            'warnings' =>
            $readerResult['warnings']
                ?? [],
            'error' => $readerResult['error'],
        ]);
    }

    $room = $readerResult['room'];

    if (!is_array($room)) {
        respondJson(500, [
            'ok' => false,
            'mode' => 'WATCHER_ROOM',
            'game_room_id' => trim($gameRoomId),
            'error' => [
                'code' => 'WATCHER_ROOM_MISSING',
                'message' => 'Reader returned no room snapshot.',
            ],
        ]);
    }

    $deckA = $room['deckA'] ?? null;
    $deckB = $room['deckB'] ?? null;

    if (!is_array($deckA) || !is_array($deckB)) {
        respondJson(200, [
            'ok' => true,
            'mode' => 'WATCHER_ROOM',
            'ruleset' => buildRulesetResponse(),
            'source' => [
                'lookup_status' =>
                $readerResult['lookup_status'] ?? 'FOUND',
                'data_status' => 'INCOMPLETE',
                'freshness' =>
                $readerResult['freshness'] ?? null,
                'warnings' => array_values(array_unique([
                    ...($readerResult['warnings'] ?? []),
                    'DECK_DATA_NOT_READY',
                ])),
            ],
            'room' => [
                'room_id' => $readerResult['room_id'] ?? $gameRoomId,
                'name' => isset($room['name']) && is_string($room['name'])
                    ? $room['name']
                    : null,
                'region' => isset($room['region']) && is_string($room['region'])
                    ? $room['region']
                    : null,
                'port' => isset($room['port']) && is_int($room['port'])
                    ? $room['port']
                    : null,
                'snapshot_date' =>
                isset($room['date']) && is_numeric($room['date'])
                    ? (int)$room['date']
                    : null,
                'room_source' =>
                isset($room['room_source'])
                    && is_string($room['room_source'])
                    ? $room['room_source']
                    : null,
            ],
            'overall_status' => 'INCOMPLETE',
            'sideA' => [
                'player' => normalizePlayer($room, 'A'),
                'evaluation' => null,
            ],
            'sideB' => [
                'player' => normalizePlayer($room, 'B'),
                'evaluation' => null,
            ],
        ]);
    }

    /*
     * 資料不完整或 snapshot 過期時，
     * 不執行違規判定，也不回傳 VIOLATION_FOUND。
     */
    $dataStatus =
        $readerResult['data_status']
        ?? 'UNAVAILABLE';

    $freshnessStatus =
        $readerResult['freshness']['status']
        ?? 'UNKNOWN';

    if (
        $dataStatus !== 'COMPLETE'
        || $freshnessStatus !== 'FRESH'
    ) {
        respondJson(200, [
            'ok' => true,
            'mode' => 'WATCHER_ROOM',
            'ruleset' => buildRulesetResponse(),
            'source' => [
                'lookup_status' =>
                $readerResult['lookup_status'],
                'data_status' => $dataStatus,
                'freshness' =>
                $readerResult['freshness'],
                'warnings' =>
                $readerResult['warnings'],
            ],
            'room' => [
                'room_id' =>
                $readerResult['room_id'],
                'name' =>
                isset($room['name'])
                    && is_string($room['name'])
                    ? $room['name']
                    : null,
                'region' =>
                isset($room['region'])
                    && is_string($room['region'])
                    ? $room['region']
                    : null,
                'port' =>
                isset($room['port'])
                    && is_int($room['port'])
                    ? $room['port']
                    : null,
                'snapshot_date' =>
                isset($room['date'])
                    && (
                        is_int($room['date'])
                        || is_float($room['date'])
                    )
                    ? (int)$room['date']
                    : null,
                'room_source' =>
                isset($room['room_source'])
                    && is_string($room['room_source'])
                    ? $room['room_source']
                    : null,
            ],
            'overall_status' => 'INCOMPLETE',
            'sideA' => [
                'player' => normalizePlayer($room, 'A'),
                'evaluation' => null,
            ],
            'sideB' => [
                'player' => normalizePlayer($room, 'B'),
                'evaluation' => null,
            ],
        ]);
    }

    try {
        $evaluationA = evaluateSingleDeck(
            $db,
            $deckA,
            $baseCostMap,
            $rulesetCostMap,
            $weaponCostMap,
            $eventCostMap
        );

        $evaluationB = evaluateSingleDeck(
            $db,
            $deckB,
            $baseCostMap,
            $rulesetCostMap,
            $weaponCostMap,
            $eventCostMap
        );

        $overallStatus = getRoomOverallStatus(
            $evaluationA,
            $evaluationB
        );
    } catch (Throwable $exception) {
        respondJson(500, [
            'ok' => false,
            'mode' => 'WATCHER_ROOM',
            'game_room_id' => trim($gameRoomId),
            'error' => [
                'code' =>
                'WATCHER_ROOM_COST_EVALUATION_FAILED',
                'message' => $exception->getMessage(),
            ],
        ]);
    }

    respondJson(200, [
        'ok' => true,
        'mode' => 'WATCHER_ROOM',
        'ruleset' => buildRulesetResponse(),
        'source' => [
            'lookup_status' =>
            $readerResult['lookup_status'],
            'data_status' =>
            $readerResult['data_status'],
            'freshness' =>
            $readerResult['freshness'],
            'warnings' =>
            $readerResult['warnings'],
        ],
        'room' => [
            'room_id' =>
            $readerResult['room_id'],
            'name' =>
            isset($room['name'])
                && is_string($room['name'])
                ? $room['name']
                : null,
            'region' =>
            isset($room['region'])
                && is_string($room['region'])
                ? $room['region']
                : null,
            'port' =>
            isset($room['port'])
                && is_int($room['port'])
                ? $room['port']
                : null,
            'snapshot_date' =>
            isset($room['date'])
                && (
                    is_int($room['date'])
                    || is_float($room['date'])
                )
                ? (int)$room['date']
                : null,
            'room_source' =>
            isset($room['room_source'])
                && is_string($room['room_source'])
                ? $room['room_source']
                : null,
        ],
        'overall_status' => $overallStatus,
        'sideA' => [
            'player' => normalizePlayer($room, 'A'),
            'evaluation' => $evaluationA,
        ],
        'sideB' => [
            'player' => normalizePlayer($room, 'B'),
            'evaluation' => $evaluationB,
        ],
    ]);
}
/*
 * 單 Deck 模式
 *
 * 支援：
 * {
 *   "deck": {
 *     "chara": [],
 *     "charaIndex": [],
 *     "cost": 0
 *   }
 * }
 *
 * 也支援直接傳入 deck 本體。
 */
if (isSingleDeckPayload($payload) && !isRoomPayload($payload)) {
    $deck = extractSingleDeck($payload);

    try {
        $evaluation = evaluateSingleDeck(
            $db,
            $deck,
            $baseCostMap,
            $rulesetCostMap,
            $weaponCostMap,
            $eventCostMap
        );
    } catch (Throwable $exception) {
        respondJson(500, [
            'ok' => false,
            'mode' => 'SINGLE_DECK',
            'error' => [
                'code' => 'COST_EVALUATION_FAILED',
                'message' => $exception->getMessage(),
            ],
        ]);
    }

    respondJson(200, [
        'ok' => true,
        'mode' => 'SINGLE_DECK',
        'ruleset' => buildRulesetResponse(),
        'evaluation' => $evaluation,
    ]);
}

/*
 * ROOM 模式
 *
 * 支援完整 Watcher room：
 * {
 *   "room_id": "...",
 *   "playerA": {},
 *   "playerB": {},
 *   "deckA": {},
 *   "deckB": {}
 * }
 */
if (isRoomPayload($payload)) {
    $roomErrors = validateRoomPayload($payload);

    if ($roomErrors !== []) {
        respondJson(400, [
            'ok' => false,
            'mode' => 'ROOM',
            'error' => [
                'code' => 'INVALID_ROOM_PAYLOAD',
                'message' => 'Room payload is incomplete or invalid.',
                'details' => $roomErrors,
            ],
        ]);
    }

    try {
        $evaluationA = evaluateSingleDeck(
            $db,
            $payload['deckA'],
            $baseCostMap,
            $rulesetCostMap,
            $weaponCostMap,
            $eventCostMap
        );

        $evaluationB = evaluateSingleDeck(
            $db,
            $payload['deckB'],
            $baseCostMap,
            $rulesetCostMap,
            $weaponCostMap,
            $eventCostMap
        );

        $overallStatus = getRoomOverallStatus(
            $evaluationA,
            $evaluationB
        );
    } catch (Throwable $exception) {
        respondJson(500, [
            'ok' => false,
            'mode' => 'ROOM',
            'error' => [
                'code' => 'ROOM_COST_EVALUATION_FAILED',
                'message' => $exception->getMessage(),
            ],
        ]);
    }

    respondJson(200, [
        'ok' => true,
        'mode' => 'ROOM',
        'ruleset' => buildRulesetResponse(),
        'room' => [
            'room_id' => trim($payload['room_id']),
            'name' => isset($payload['name'])
                && is_string($payload['name'])
                ? $payload['name']
                : null,
            'region' => isset($payload['region'])
                && is_string($payload['region'])
                ? $payload['region']
                : null,
            'port' => isset($payload['port'])
                && is_int($payload['port'])
                ? $payload['port']
                : null,
            'snapshot_date' => isset($payload['date'])
                && is_int($payload['date'])
                ? $payload['date']
                : null,
        ],
        'overall_status' => $overallStatus,
        'sideA' => [
            'player' => normalizePlayer($payload, 'A'),
            'evaluation' => $evaluationA,
        ],
        'sideB' => [
            'player' => normalizePlayer($payload, 'B'),
            'evaluation' => $evaluationB,
        ],
    ]);
}

respondJson(400, [
    'ok' => false,
    'error' => [
        'code' => 'UNRECOGNIZED_PAYLOAD_MODE',
        'message' => 'Payload must contain game_room_id, a single deck, or a complete room snapshot.',
    ],
]);
