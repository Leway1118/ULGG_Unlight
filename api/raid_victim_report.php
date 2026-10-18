<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/*
 * RAID_VICTIM_ASSOCIATION_API_V1
 *
 * Member-self-only.
 * No arbitrary player/founder parameter is accepted.
 */

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');


function victimJson(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}


function victimNormBoss(
    string $value
): string {
    $value = trim($value);

    return trim(
        (string)preg_replace(
            '/^M\d+\s*/u',
            '',
            $value
        )
    );
}


function victimNormTier(
    string $value
): string {
    return str_replace(
        '涡',
        '渦',
        trim($value)
    );
}


function victimSemanticItems(
    mixed $items
): array {
    if (!is_array($items)) {
        return [];
    }

    $rows = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim(
            (string)($item['itemName'] ?? '')
        );

        $qty = (int)(
            $item['quantity'] ?? 0
        );

        if ($name === '' || $qty <= 0) {
            continue;
        }

        $rows[] = [
            'item_name' => $name,
            'quantity' => $qty,
        ];
    }

    usort(
        $rows,
        static fn(
            array $a,
            array $b
        ): int =>
            strcmp(
                $a['item_name'],
                $b['item_name']
            )
            ?: (
                $a['quantity']
                <=>
                $b['quantity']
            )
    );

    return $rows;
}


function victimRewardIndex(): array {
    $path =
        __DIR__
        . '/../tool/raid-bot/data/'
        . 'raid_reward_cache.json';

    if (!is_file($path)) {
        return [];
    }

    $raw = json_decode(
        (string)file_get_contents($path),
        true
    );

    $rewards =
        is_array($raw)
        && is_array($raw['rewards'] ?? null)
            ? $raw['rewards']
            : [];

    $index = [];

    foreach ($rewards as $key => $row) {
        if (!is_array($row)) {
            continue;
        }

        $boss = victimNormBoss(
            (string)(
                $row['bossDisplayName']
                ?? ''
            )
        );

        $tier = victimNormTier(
            (string)(
                $row['whirlpoolTier']
                ?? ''
            )
        );

        $rarity = (int)(
            $row['rarity'] ?? 0
        );

        if (
            $boss === ''
            || $tier === ''
            || $rarity <= 0
        ) {
            continue;
        }

        $tl = (int)(
            $row['treasureLevel']
            ?? $key
        );

        $index[
            $boss
            . '|'
            . $tier
            . '|'
            . $rarity
        ][$tl] = $row;
    }

    return $index;
}


function victimResolveRewards(
    array $raid,
    array $rewardIndex
): array {
    $boss = victimNormBoss(
        (string)($raid['boss'] ?? '')
    );

    $tier = victimNormTier(
        (string)(
            $raid['whirlpool_tier']
            ?? ''
        )
    );

    $rarity = (int)(
        $raid['rarity'] ?? 0
    );

    $tl = (int)(
        $raid['treasure_level']
        ?? 0
    );

    $key =
        $boss
        . '|'
        . $tier
        . '|'
        . $rarity;

    $records =
        $rewardIndex[$key]
        ?? [];

    $source = 'unavailable';

    if (
        $tl > 0
        && isset($records[$tl])
    ) {
        $records = [
            $tl => $records[$tl],
        ];

        $source =
            'exact_treasure_level';

    } elseif ($records) {
        $source =
            'common_across_matching_tl';
    }

    $resolved = [];

    foreach (
        [
            'discovery',
            'participation',
            'defeat',
        ] as $group
    ) {
        $common = null;
        $consistent = !empty($records);

        foreach ($records as $record) {
            $semantic =
                victimSemanticItems(
                    $record[$group]
                    ?? []
                );

            if ($common === null) {
                $common = $semantic;
                continue;
            }

            if ($common !== $semantic) {
                $consistent = false;
                break;
            }
        }

        $resolved[$group] =
            $consistent
                ? ($common ?? [])
                : null;
    }

    return [
        'source' => $source,

        'matching_tl_count' =>
            count($records),

        'discovery' =>
            $resolved['discovery'],

        'participation' =>
            $resolved['participation'],

        'defeat' =>
            $resolved['defeat'],

        'ranking' => null,

        'ranking_note' =>
            '渦主未列入玩家紀錄，'
            . '無可靠 Rank/TL 可精確還原排名獎勵',
    ];
}


function victimAddTotals(
    array &$totals,
    string $group,
    ?array $items
): void {
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        $name = (string)(
            $item['item_name']
            ?? ''
        );

        $qty = (int)(
            $item['quantity']
            ?? 0
        );

        if (
            $name === ''
            || $qty <= 0
        ) {
            continue;
        }

        $totals[$group][$name] =
            (
                $totals[$group][$name]
                ?? 0
            )
            + $qty;
    }
}


$username = trim(
    (string)(
        $_SESSION['username']
        ?? ''
    )
);

$userId = (int)(
    $_SESSION['user_id']
    ?? 0
);


/*
 * Privacy boundary:
 * Must be a real logged-in member session.
 */
if (
    $userId <= 0
    || $username === ''
) {
    victimJson(
        [
            'ok' => false,
            'error' =>
                'member_login_required',
        ],
        401
    );
}


try {
    $stmt = $db->prepare(
        '
        SELECT COUNT(*)
        FROM raid_history
        WHERE founder = ?
        '
    );

    $stmt->execute([
        $username,
    ]);

    $opened =
        (int)$stmt->fetchColumn();


    $stmt = $db->prepare(
        '
        SELECT
            r.first_seen_at,
            r.last_seen_at,
            r.ended_at,
            r.boss,
            r.whirlpool_tier,
            r.rarity,
            r.raid_id,
            r.treasure_level,
            r.lifecycle_status,
            r.defeat_method,
            r.participant_count

        FROM raid_history r

        WHERE r.founder = ?

          AND NOT EXISTS (
              SELECT 1
              FROM raid_player_history p

              WHERE p.raid_id = r.raid_id
                AND p.player_name = r.founder
          )

        ORDER BY
            COALESCE(
                r.ended_at,
                r.last_seen_at,
                r.first_seen_at
            ),
            r.raid_id
        '
    );

    $stmt->execute([
        $username,
    ]);

    $missingRows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

} catch (Throwable $error) {
    victimJson(
        [
            'ok' => false,
            'error' =>
                'report_query_failed',
        ],
        500
    );
}


$rewardIndex =
    victimRewardIndex();

$confirmed = [];
$insufficient = [];

$totals = [
    'discovery' => [],
    'participation' => [],
    'defeat' => [],
];


foreach ($missingRows as $row) {

    $isConfirmed =
        (
            (string)(
                $row['lifecycle_status']
                ?? ''
            )
            === 'defeated'
        )
        &&
        (
            (string)(
                $row['defeat_method']
                ?? ''
            )
            === 'hp_zero'
        );


    $item = [
        'time' =>
            $row['ended_at']
            ?: (
                $row['last_seen_at']
                ?: $row['first_seen_at']
            ),

        'first_seen_at' =>
            $row['first_seen_at'],

        'ended_at' =>
            $row['ended_at'],

        'boss' =>
            $row['boss'],

        'whirlpool_tier' =>
            victimNormTier(
                (string)(
                    $row['whirlpool_tier']
                    ?? ''
                )
            ),

        'rarity' =>
            $row['rarity'] !== null
                ? (int)$row['rarity']
                : null,

        'raid_id' =>
            $row['raid_id'],

        'treasure_level' =>
            $row['treasure_level'] !== null
                ? (int)$row['treasure_level']
                : null,

        'participant_count' =>
            (int)(
                $row['participant_count']
                ?? 0
            ),

        'lifecycle_status' =>
            $row['lifecycle_status'],

        'defeat_method' =>
            $row['defeat_method'],
    ];


    if ($isConfirmed) {

        $item['evidence'] =
            'HP=0 確認擊破'
            . '｜渦主未列入本站玩家紀錄';

        $item['reward'] =
            victimResolveRewards(
                $row,
                $rewardIndex
            );

        victimAddTotals(
            $totals,
            'discovery',
            $item['reward']['discovery']
        );

        victimAddTotals(
            $totals,
            'participation',
            $item['reward']['participation']
        );

        victimAddTotals(
            $totals,
            'defeat',
            $item['reward']['defeat']
        );

        $confirmed[] = $item;

    } else {

        $item['evidence'] =
            '渦主未列入本站玩家紀錄'
            . '｜缺少 HP=0 擊破證據';

        $insufficient[] = $item;
    }
}


$missing =
    count($missingRows);

$recorded =
    max(
        0,
        $opened - $missing
    );

$confirmedCount =
    count($confirmed);


$reportPayload = [
    'ok' => true,

    'report_version' => 1,

    'privacy' =>
        'member_self_only',

    'founder' =>
        $username,

    'generated_at' =>
        date(DATE_ATOM),

    'summary' => [
        'opened' =>
            $opened,

        'founder_recorded' =>
            $recorded,

        'founder_missing' =>
            $missing,

        'confirmed_anomaly' =>
            $confirmedCount,

        'insufficient_evidence' =>
            count($insufficient),

        'confirmed_rate_percent' =>
            $opened > 0
                ? round(
                    $confirmedCount
                    * 100
                    / $opened,
                    1
                )
                : 0.0,
    ],

    'reward_summary' => [
        'discovery' =>
            $totals['discovery'],

        'participation' =>
            $totals['participation'],

        'defeat' =>
            $totals['defeat'],

        'ranking' =>
            null,

        'ranking_note' =>
            '排名獎勵無法精確還原，'
            . '不納入合計',

        'wording' =>
            '可能受影響獎勵配置；'
            . '本站無法驗證官方帳號'
            . '實際入帳結果',
    ],

    'confirmed' =>
        $confirmed,

    'insufficient' =>
        $insufficient,
];


// RAID_VICTIM_XLSX_EXPORT_V1
if (
    (string)($_GET['format'] ?? '')
    === 'xlsx'
) {
    $generator =
        __DIR__
        . '/../tool/raid_victim_xlsx.py';

    $python =
        '/usr/bin/python3.11';


    if (
        !is_file($generator)
        || !is_executable($python)
        || !function_exists('proc_open')
    ) {
        victimJson(
            [
                'ok' => false,
                'error' =>
                    'xlsx_generator_unavailable',
            ],
            500
        );
    }


    $json = json_encode(
        $reportPayload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );


    if (!is_string($json)) {
        victimJson(
            [
                'ok' => false,
                'error' =>
                    'xlsx_report_encode_failed',
            ],
            500
        );
    }


    $pipes = [];

    $process = proc_open(
        [
            $python,
            $generator,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );


    if (!is_resource($process)) {
        victimJson(
            [
                'ok' => false,
                'error' =>
                    'xlsx_generator_start_failed',
            ],
            500
        );
    }


    fwrite(
        $pipes[0],
        $json
    );

    fclose($pipes[0]);


    $xlsx =
        stream_get_contents(
            $pipes[1]
        );

    fclose($pipes[1]);


    $stderr =
        stream_get_contents(
            $pipes[2]
        );

    fclose($pipes[2]);


    $status =
        proc_close($process);


    if (
        $status !== 0
        || !is_string($xlsx)
        || strlen($xlsx) < 100
        || substr($xlsx, 0, 2) !== 'PK'
    ) {
        error_log(
            '[RAID VICTIM XLSX] '
            . trim((string)$stderr)
        );

        victimJson(
            [
                'ok' => false,
                'error' =>
                    'xlsx_generation_failed',
            ],
            500
        );
    }


    $safeFounder =
        preg_replace(
            '/[\\\\\/:*?"<>|]+/u',
            '_',
            $username
        );

    if (
        !is_string($safeFounder)
        || $safeFounder === ''
    ) {
        $safeFounder =
            'player';
    }


    $date =
        date('Ymd');

    $downloadName =
        'ULGG_受害者協會_'
        . $safeFounder
        . '_'
        . $date
        . '.xlsx';

    $asciiName =
        'ULGG_victim_report_'
        . $date
        . '.xlsx';


    header_remove(
        'Content-Type'
    );

    header(
        'Content-Type: '
        . 'application/vnd.openxmlformats-officedocument.'
        . 'spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; '
        . 'filename="'
        . $asciiName
        . '"; '
        . "filename*=UTF-8''"
        . rawurlencode(
            $downloadName
        )
    );

    header(
        'Content-Length: '
        . strlen($xlsx)
    );

    header(
        'X-Content-Type-Options: nosniff'
    );

    echo $xlsx;
    exit;
}


victimJson(
    $reportPayload
);

