<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function observedRaidsRespond(int $status, array $payload): never
{
    http_response_code($status);

    // RAID_OBSERVER_SERVER_CLOCK_V2
    $payload['server_now_ms'] =
        (int) round(microtime(true) * 1000);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function observedRaidsUnavailable(string $code): never
{
    observedRaidsRespond(503, [
        'ok' => false,
        'source' => 'websocket',
        'connected' => false,
        'updated_at' => null,
        'active_count' => 0,
        'recent_ended_count' => 0,
        'raids' => [],
        'error' => [
            'code' => $code,
            'message' => 'Raid 觀測服務暫時無法連線。',
        ],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    observedRaidsRespond(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => '僅支援 GET 請求。',
        ],
    ]);
}

if (!function_exists('curl_init')) {
    error_log('[ULGG observed raids] PHP cURL extension is unavailable.');
    observedRaidsUnavailable('PROXY_UNAVAILABLE');
}

$handle = curl_init('http://127.0.0.1:8766/api/observed-raids');
if ($handle === false) {
    observedRaidsUnavailable('PROXY_UNAVAILABLE');
}

try {
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT_MS => 1500,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_NOPROXY => '*',
    ]);

    $response = curl_exec($handle);
    $curlError = curl_errno($handle);
    $httpStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
} catch (Throwable $error) {
    error_log(
        '[ULGG observed raids] Proxy request raised '
        . get_class($error)
        . '.'
    );
    observedRaidsUnavailable('UPSTREAM_UNAVAILABLE');
} finally {
    curl_close($handle);
}

if (
    $response === false
    || $curlError !== 0
    || $httpStatus < 200
    || $httpStatus >= 300
) {
    error_log(
        '[ULGG observed raids] Upstream request failed: curl_errno='
        . $curlError
        . ' http_status='
        . $httpStatus
    );
    observedRaidsUnavailable('UPSTREAM_UNAVAILABLE');
}

$payload = json_decode($response, true);
if (
    !is_array($payload)
    || ($payload['ok'] ?? null) !== true
    || !isset($payload['raids'])
    || !is_array($payload['raids'])
) {
    error_log('[ULGG observed raids] Upstream returned an invalid payload.');
    observedRaidsUnavailable('INVALID_UPSTREAM_RESPONSE');
}


/*
 * RAID_OBSERVER_ACTIVE_DPM_V1
 *
 * Observation Window Active DPM
 *
 * DPM =
 *   SUM(damage_delta) * 60
 *   / SUM(window_seconds)
 *
 * Formal live_snapshot data only.
 * support_backfill is intentionally excluded.
 */
try {
    if (
        isset($db)
        && $db instanceof PDO
        && isset($payload['raids'])
        && is_array($payload['raids'])
    ) {
        $raidIds = [];

        foreach ($payload['raids'] as $raid) {
            if (!is_array($raid)) {
                continue;
            }

            $raidId = trim(
                (string)($raid['raid_id'] ?? '')
            );

            if ($raidId !== '') {
                $raidIds[$raidId] = true;
            }
        }

        $raidIds = array_keys($raidIds);

        $dpmMap = [];

        if ($raidIds) {
            $placeholders = implode(
                ',',
                array_fill(
                    0,
                    count($raidIds),
                    '?'
                )
            );

            $sql = "
                SELECT
                    raid_id,
                    player_name,

                    COUNT(*) AS dpm_observations,

                    SUM(damage_delta)
                        AS dpm_damage,

                    SUM(window_seconds)
                        AS dpm_seconds,

                    ROUND(
                        SUM(damage_delta)
                        * 60.0
                        / NULLIF(
                            SUM(window_seconds),
                            0
                        ),
                        2
                    ) AS active_dpm

                FROM raid_player_damage_events

                WHERE
                    raid_id IN ({$placeholders})

                    AND source = 'live_snapshot'
                    AND event_kind = 'damage_change'
                    AND damage_delta > 0
                    AND window_seconds > 0

                GROUP BY
                    raid_id,
                    player_name
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute($raidIds);

            while (
                $row = $stmt->fetch(
                    PDO::FETCH_ASSOC
                )
            ) {
                $raidId = (string)$row['raid_id'];
                $playerName =
                    (string)$row['player_name'];

                if (!isset($dpmMap[$raidId])) {
                    $dpmMap[$raidId] = [];
                }

                $dpmMap[$raidId][$playerName] = [
                    'active_dpm' =>
                        $row['active_dpm'] === null
                            ? null
                            : (float)$row['active_dpm'],

                    'dpm_observations' =>
                        (int)$row['dpm_observations'],

                    'dpm_damage' =>
                        (int)$row['dpm_damage'],

                    'dpm_seconds' =>
                        (float)$row['dpm_seconds'],
                ];
            }
        }


        foreach ($payload['raids'] as &$raid) {
            if (!is_array($raid)) {
                continue;
            }

            $raidId = trim(
                (string)($raid['raid_id'] ?? '')
            );

            if (
                !isset($raid['players'])
                || !is_array($raid['players'])
            ) {
                continue;
            }

            foreach (
                $raid['players']
                as &$player
            ) {
                if (!is_array($player)) {
                    continue;
                }

                $playerName = trim(
                    (string)($player['name'] ?? '')
                );

                $metric =
                    $dpmMap[$raidId][$playerName]
                    ?? null;

                /*
                 * Explicit defaults are important:
                 *
                 * null = no DPM sample yet
                 * 0    = zero observations
                 *
                 * This also proves enrichment actually ran.
                 */
                $player['active_dpm'] =
                    is_array($metric)
                        ? $metric['active_dpm']
                        : null;

                $player['dpm_observations'] =
                    is_array($metric)
                        ? $metric['dpm_observations']
                        : 0;

                $player['dpm_damage'] =
                    is_array($metric)
                        ? $metric['dpm_damage']
                        : 0;

                $player['dpm_seconds'] =
                    is_array($metric)
                        ? $metric['dpm_seconds']
                        : 0.0;
            }

            unset($player);
        }

        unset($raid);
    }

} catch (Throwable $error) {
    /*
     * Analytics must never break Raid Observer.
     */
    error_log(
        '[ULGG Raid Observer DPM] '
        . get_class($error)
        . ': '
        . $error->getMessage()
    );
}


/*
 * RAID_OBSERVER_CAST_TOP1_V2
 *
 * Observer「施術」：
 * 顯示玩家歷史高可信上狀態傾向 Top1。
 *
 * 不含：
 * - refreshed / 延長
 * - curse
 *
 * 注意：
 * 這是活動關聯傾向，不宣稱已證實施術者。
 */
try {

    if (
        isset($db)
        && $db instanceof PDO
        && isset($payload['raids'])
        && is_array($payload['raids'])
    ) {

        $observerPlayers = [];

        foreach ($payload['raids'] as $raid) {

            if (
                !is_array($raid)
                || !isset($raid['players'])
                || !is_array($raid['players'])
            ) {
                continue;
            }

            foreach ($raid['players'] as $player) {

                if (!is_array($player)) {
                    continue;
                }

                $name = trim(
                    (string)($player['name'] ?? '')
                );

                if ($name !== '') {
                    $observerPlayers[$name] = true;
                }
            }
        }


        $observerPlayers =
            array_keys($observerPlayers);

        $topStatusMap = [];


        if ($observerPlayers) {

            $placeholders = implode(
                ',',
                array_fill(
                    0,
                    count($observerPlayers),
                    '?'
                )
            );


            /*
             * candidate_count：
             * 同一 Raid + observed_at 的可觀察 candidates 數。
             *
             * 不依賴 events table 有 candidate_count 欄位。
             */
            $sql = "
                SELECT
                    c.player_name,
                    e.raid_id,
                    e.observed_at,
                    e.status_base,

                    CASE
                      WHEN
                        COALESCE(e.new_level, 0) > 0
                        AND e.status_base IN (
                            'atkB',
                            'atkD',
                            'defB',
                            'defD',
                            'movB',
                            'movD'
                        )
                      THEN COALESCE(e.new_level, 0)
                      ELSE 0
                    END AS status_level,

                    MAX(
                        COALESCE(c.damage_delta, 0)
                    ) AS damage_delta,

                    MAX(
                        ac.candidate_count
                    ) AS candidate_count

                FROM raid_support_candidates c

                JOIN raid_support_events e
                  ON e.id = c.event_id

                JOIN (
                    SELECT
                        e2.raid_id,
                        e2.observed_at,
                        COUNT(
                            DISTINCT c2.player_name
                        ) AS candidate_count

                    FROM raid_support_candidates c2

                    JOIN raid_support_events e2
                      ON e2.id = c2.event_id

                    WHERE
                        c2.point_before IS NOT NULL
                        AND e2.status_base <> 'curse'

                    GROUP BY
                        e2.raid_id,
                        e2.observed_at
                ) ac
                  ON ac.raid_id = e.raid_id
                 AND ac.observed_at = e.observed_at

                WHERE
                    c.point_before IS NOT NULL

                    AND c.player_name IN (
                        {$placeholders}
                    )

                    AND e.status_base IS NOT NULL
                    AND e.status_base <> ''
                    AND e.status_base <> 'curse'

                    AND e.event_type IN (
                        'added',
                        'upgraded'
                    )

                GROUP BY
                    c.player_name,
                    e.raid_id,
                    e.observed_at,
                    e.status_base,

                    CASE
                      WHEN
                        COALESCE(e.new_level, 0) > 0
                        AND e.status_base IN (
                            'atkB',
                            'atkD',
                            'defB',
                            'defD',
                            'movB',
                            'movD'
                        )
                      THEN COALESCE(e.new_level, 0)
                      ELSE 0
                    END

                HAVING
                    MAX(
                        COALESCE(c.point_delta, 0)
                    ) > 0
            ";


            $stmt = $db->prepare($sql);

            $stmt->execute(
                $observerPlayers
            );


            /*
             * rawAgg:
             *
             * player
             * -> status base+level
             * -> fractional / raids / bundles / anchors
             */
            $rawAgg = [];


            while (
                $row = $stmt->fetch(PDO::FETCH_ASSOC)
            ) {

                $playerName = trim(
                    (string)($row['player_name'] ?? '')
                );

                $raidId = trim(
                    (string)($row['raid_id'] ?? '')
                );

                $observedAt = trim(
                    (string)($row['observed_at'] ?? '')
                );

                $statusBase = trim(
                    (string)($row['status_base'] ?? '')
                );

                $statusLevel = (int)(
                    $row['status_level'] ?? 0
                );

                $candidateCount = max(
                    1,
                    (int)(
                        $row['candidate_count']
                        ?? 1
                    )
                );

                $damageDelta = (int)(
                    $row['damage_delta']
                    ?? 0
                );


                if (
                    $playerName === ''
                    || $raidId === ''
                    || $observedAt === ''
                    || $statusBase === ''
                ) {
                    continue;
                }


                $statusKey =
                    $statusBase
                    . "\x1E"
                    . $statusLevel;

                $bundleKey =
                    $raidId
                    . "\x1F"
                    . $observedAt;


                if (
                    !isset(
                        $rawAgg[
                            $playerName
                        ][
                            $statusKey
                        ]
                    )
                ) {

                    $rawAgg[
                        $playerName
                    ][
                        $statusKey
                    ] = [
                        'status_base' =>
                            $statusBase,

                        'status_level' =>
                            $statusLevel,

                        'fractional' =>
                            0.0,

                        'bundles' =>
                            [],

                        'raids' =>
                            [],

                        'damage0_raids' =>
                            [],

                        'solo_anchor' =>
                            false,
                    ];
                }


                $entry =&
                    $rawAgg[
                        $playerName
                    ][
                        $statusKey
                    ];


                if (
                    !isset(
                        $entry['bundles'][$bundleKey]
                    )
                ) {

                    $entry['fractional'] +=
                        1.0 / $candidateCount;

                    $entry[
                        'bundles'
                    ][
                        $bundleKey
                    ] = true;
                }


                $entry[
                    'raids'
                ][
                    $raidId
                ] = true;


                if ($damageDelta === 0) {

                    $entry[
                        'damage0_raids'
                    ][
                        $raidId
                    ] = true;
                }


                if ($candidateCount === 1) {

                    $entry[
                        'solo_anchor'
                    ] = true;
                }


                unset($entry);
            }


            /*
             * Direct qualification:
             *
             * Raid >= 2
             * fractional >= 0.75
             *
             * strong anchor：
             * - solo bundle
             * OR
             * - damage=0 evidence across >=2 Raid
             */
            $directQualified = [];


            foreach (
                $rawAgg as
                $playerName => $statuses
            ) {

                foreach (
                    $statuses as
                    $statusKey => $entry
                ) {

                    $raidCount =
                        count(
                            $entry['raids']
                        );

                    $damage0RaidCount =
                        count(
                            $entry[
                                'damage0_raids'
                            ]
                        );

                    $fractional =
                        (float)(
                            $entry[
                                'fractional'
                            ]
                            ?? 0.0
                        );

                    $strongAnchor =
                        !empty(
                            $entry[
                                'solo_anchor'
                            ]
                        )
                        ||
                        $damage0RaidCount >= 2;


                    if (
                        $raidCount >= 2
                        && $fractional >= 0.75
                        && $strongAnchor
                    ) {

                        $directQualified[
                            $playerName
                        ][
                            $statusKey
                        ] = true;
                    }
                }
            }


            /*
             * 已確認技能家族。
             */
            $families = [

                [
                    "atkD\x1E9",
                    "defD\x1E9",
                    "movD\x1E9",
                ],

                [
                    "atkD\x1E7",
                    "defD\x1E7",
                    "jikai\x1E0",
                ],
            ];


            $finalQualified = [];


            foreach (
                $rawAgg as
                $playerName => $statuses
            ) {

                foreach (
                    array_keys(
                        $directQualified[
                            $playerName
                        ] ?? []
                    )
                    as $statusKey
                ) {

                    $finalQualified[
                        $playerName
                    ][
                        $statusKey
                    ] = true;
                }


                foreach (
                    $families as
                    $familyMembers
                ) {

                    $triggered = false;


                    foreach (
                        $familyMembers
                        as $statusKey
                    ) {

                        if (
                            !empty(
                                $directQualified[
                                    $playerName
                                ][
                                    $statusKey
                                ]
                            )
                        ) {

                            $triggered = true;
                            break;
                        }
                    }


                    if (!$triggered) {
                        continue;
                    }


                    foreach (
                        $familyMembers
                        as $statusKey
                    ) {

                        /*
                         * 只 propagation 已觀測過的 family member，
                         * 不憑空製造 status。
                         */
                        if (
                            isset(
                                $statuses[
                                    $statusKey
                                ]
                            )
                        ) {

                            $finalQualified[
                                $playerName
                            ][
                                $statusKey
                            ] = true;
                        }
                    }
                }
            }


            /*
             * Top1：
             *
             * score DESC
             * Raid DESC
             * bundle DESC
             * status key
             */
            foreach (
                $finalQualified as
                $playerName => $statusKeys
            ) {

                $candidates = [];


                foreach (
                    array_keys($statusKeys)
                    as $statusKey
                ) {

                    $entry =
                        $rawAgg[
                            $playerName
                        ][
                            $statusKey
                        ]
                        ?? null;


                    if (!is_array($entry)) {
                        continue;
                    }


                    $candidates[] = [

                        'status_base' =>
                            (string)(
                                $entry[
                                    'status_base'
                                ]
                                ?? ''
                            ),

                        'status_level' =>
                            (int)(
                                $entry[
                                    'status_level'
                                ]
                                ?? 0
                            ),

                        'status_score' =>
                            (float)(
                                $entry[
                                    'fractional'
                                ]
                                ?? 0.0
                            )
                            * 10.0,

                        'observed_raids' =>
                            count(
                                $entry[
                                    'raids'
                                ]
                                ?? []
                            ),

                        'observed_bundles' =>
                            count(
                                $entry[
                                    'bundles'
                                ]
                                ?? []
                            ),

                        '_key' =>
                            $statusKey,
                    ];
                }


                usort(
                    $candidates,

                    static function (
                        array $a,
                        array $b
                    ): int {

                        $cmp =
                            (float)$b[
                                'status_score'
                            ]
                            <=>
                            (float)$a[
                                'status_score'
                            ];

                        if ($cmp !== 0) {
                            return $cmp;
                        }


                        $cmp =
                            (int)$b[
                                'observed_raids'
                            ]
                            <=>
                            (int)$a[
                                'observed_raids'
                            ];

                        if ($cmp !== 0) {
                            return $cmp;
                        }


                        $cmp =
                            (int)$b[
                                'observed_bundles'
                            ]
                            <=>
                            (int)$a[
                                'observed_bundles'
                            ];

                        if ($cmp !== 0) {
                            return $cmp;
                        }


                        return strcmp(
                            (string)$a['_key'],
                            (string)$b['_key']
                        );
                    }
                );


                if ($candidates) {

                    $topStatusMap[
                        $playerName
                    ] = $candidates[0];
                }
            }
        }


        /*
         * Enrich payload.
         */
        foreach (
            $payload['raids']
            as &$raid
        ) {

            if (
                !is_array($raid)
                || !isset($raid['players'])
                || !is_array($raid['players'])
            ) {
                continue;
            }


            foreach (
                $raid['players']
                as &$player
            ) {

                if (!is_array($player)) {
                    continue;
                }


                $name = trim(
                    (string)(
                        $player['name']
                        ?? ''
                    )
                );


                $top =
                    $topStatusMap[
                        $name
                    ]
                    ?? null;


                $player[
                    'support_top_status_base'
                ] =
                    is_array($top)
                    ? (string)$top[
                        'status_base'
                    ]
                    : null;


                $player[
                    'support_top_status_level'
                ] =
                    is_array($top)
                    ? (int)$top[
                        'status_level'
                    ]
                    : null;


                $player[
                    'support_top_status_score'
                ] =
                    is_array($top)
                    ? round(
                        (float)$top[
                            'status_score'
                        ],
                        4
                    )
                    : null;


                $player[
                    'support_top_status_raids'
                ] =
                    is_array($top)
                    ? (int)$top[
                        'observed_raids'
                    ]
                    : 0;
            }

            unset($player);
        }

        unset($raid);
    }

} catch (Throwable $error) {

    error_log(
        '[ULGG Raid Observer Cast Top1 V2] '
        . get_class($error)
        . ': '
        . $error->getMessage()
    );
}


/*
 * RAID_OBSERVER_CAST_MAINTENANCE_V3
 *
 * 「施術」Top1 也包含延長。
 *
 * Raid Stats V7 maintenance semantics:
 *
 * refreshed bundle:
 *   same raid_id + observed_at = ONE maintenance event
 *
 * evidence:
 *   1 / observable candidate count
 *
 * qualification:
 *   >= 3 distinct Raid
 *   fractional evidence >= 1.5
 *
 * score:
 *   fractional evidence * 10
 *
 * curse-only refresh does not participate.
 */
try {

    if (
        isset($db)
        && $db instanceof PDO
        && isset($payload['raids'])
        && is_array($payload['raids'])
    ) {

        $observerPlayers = [];

        foreach ($payload['raids'] as $raid) {

            if (
                !is_array($raid)
                || !isset($raid['players'])
                || !is_array($raid['players'])
            ) {
                continue;
            }

            foreach ($raid['players'] as $player) {

                if (!is_array($player)) {
                    continue;
                }

                $name = trim(
                    (string)($player['name'] ?? '')
                );

                if ($name !== '') {
                    $observerPlayers[$name] = true;
                }
            }
        }

        $observerPlayers = array_keys(
            $observerPlayers
        );

        $maintenanceMap = [];


        if ($observerPlayers) {

            $ph = implode(
                ',',
                array_fill(
                    0,
                    count($observerPlayers),
                    '?'
                )
            );


            /*
             * Get one candidate row per:
             *
             * player + raid + observed_at
             *
             * and derive the total candidates in that
             * refreshed bundle.
             */
            $sql = "
                SELECT
                    c.player_name,
                    e.raid_id,
                    e.observed_at,
                    ac.candidate_count

                FROM raid_support_candidates c

                JOIN raid_support_events e
                  ON e.id = c.event_id

                JOIN (
                    SELECT
                        z.raid_id,
                        z.observed_at,
                        COUNT(
                            DISTINCT z.player_name
                        ) AS candidate_count

                    FROM (
                        SELECT DISTINCT
                            e2.raid_id,
                            e2.observed_at,
                            c2.player_name

                        FROM raid_support_candidates c2

                        JOIN raid_support_events e2
                          ON e2.id = c2.event_id

                        WHERE
                            c2.point_before IS NOT NULL

                            AND e2.event_type = 'refreshed'

                            AND EXISTS (
                                SELECT 1
                                FROM raid_support_events e3
                                WHERE
                                    e3.raid_id =
                                        e2.raid_id

                                    AND e3.observed_at =
                                        e2.observed_at

                                    AND e3.event_type =
                                        'refreshed'

                                    AND e3.status_base
                                        <> 'curse'
                            )
                    ) z

                    GROUP BY
                        z.raid_id,
                        z.observed_at
                ) ac
                  ON ac.raid_id = e.raid_id
                 AND ac.observed_at =
                     e.observed_at

                WHERE
                    c.point_before IS NOT NULL

                    AND c.player_name IN (
                        {$ph}
                    )

                    AND e.event_type =
                        'refreshed'

                    AND EXISTS (
                        SELECT 1
                        FROM raid_support_events ex

                        WHERE
                            ex.raid_id =
                                e.raid_id

                            AND ex.observed_at =
                                e.observed_at

                            AND ex.event_type =
                                'refreshed'

                            AND ex.status_base
                                <> 'curse'
                    )

                GROUP BY
                    c.player_name,
                    e.raid_id,
                    e.observed_at,
                    ac.candidate_count
            ";


            $stmt = $db->prepare($sql);
            $stmt->execute($observerPlayers);


            $agg = [];


            while (
                $row = $stmt->fetch(
                    PDO::FETCH_ASSOC
                )
            ) {

                $name = trim(
                    (string)(
                        $row['player_name']
                        ?? ''
                    )
                );

                $raidId = trim(
                    (string)(
                        $row['raid_id']
                        ?? ''
                    )
                );

                $observedAt = trim(
                    (string)(
                        $row['observed_at']
                        ?? ''
                    )
                );

                $candidateCount = max(
                    1,
                    (int)(
                        $row['candidate_count']
                        ?? 1
                    )
                );


                if (
                    $name === ''
                    || $raidId === ''
                    || $observedAt === ''
                ) {
                    continue;
                }


                $bundleKey =
                    $raidId
                    . "\x1F"
                    . $observedAt;


                if (!isset($agg[$name])) {

                    $agg[$name] = [
                        'fractional' => 0.0,
                        'bundles' => [],
                        'raids' => [],
                    ];
                }


                /*
                 * One player gets evidence only once
                 * for one refreshed bundle.
                 */
                if (
                    !isset(
                        $agg[$name]['bundles'][
                            $bundleKey
                        ]
                    )
                ) {

                    $agg[$name][
                        'fractional'
                    ] +=
                        1.0
                        / $candidateCount;

                    $agg[$name]['bundles'][
                        $bundleKey
                    ] = true;
                }


                $agg[$name]['raids'][
                    $raidId
                ] = true;
            }


            foreach (
                $agg as
                $name => $entry
            ) {

                $fractional = (float)(
                    $entry['fractional']
                    ?? 0.0
                );

                $raidCount = count(
                    $entry['raids']
                    ?? []
                );


                if (
                    $raidCount < 3
                    || $fractional < 1.5
                ) {
                    continue;
                }


                $maintenanceMap[$name] = [
                    'score' =>
                        $fractional * 10.0,

                    'raids' =>
                        $raidCount,

                    'bundles' =>
                        count(
                            $entry['bundles']
                            ?? []
                        ),
                ];
            }
        }


        /*
         * Compare qualified maintenance against
         * current Build Top1.
         *
         * Strictly greater only:
         * exact tie keeps the concrete status
         * rather than generic maintenance.
         */
        foreach (
            $payload['raids']
            as &$raid
        ) {

            if (
                !is_array($raid)
                || !isset($raid['players'])
                || !is_array($raid['players'])
            ) {
                continue;
            }


            foreach (
                $raid['players']
                as &$player
            ) {

                if (!is_array($player)) {
                    continue;
                }


                $name = trim(
                    (string)(
                        $player['name']
                        ?? ''
                    )
                );


                $maintenance =
                    $maintenanceMap[$name]
                    ?? null;


                /*
                 * RAID_OBSERVER_HIDE_MAINTENANCE_FROM_CAST_V4
                 *
                 * Maintenance / 延長 remains available as
                 * historical BETA evidence, but MUST NOT be
                 * presented as Observer「施術」caster Top1.
                 *
                 * Ground-truth validation showed false-positive
                 * maintenance attribution for players who did
                 * not actually cast an extension.
                 *
                 * Keep CAST_TOP1_V2 concrete added/upgraded
                 * status result instead.
                 */
                $maintenance = null;


                if (!is_array($maintenance)) {
                    continue;
                }


                $maintenanceScore =
                    (float)$maintenance['score'];


                $currentScore =
                    $player[
                        'support_top_status_score'
                    ] === null

                    ? -1.0

                    : (float)$player[
                        'support_top_status_score'
                    ];


                if (
                    $maintenanceScore
                    <= $currentScore
                ) {
                    continue;
                }


                $player[
                    'support_top_status_base'
                ] = '__maintenance__';

                $player[
                    'support_top_status_level'
                ] = 0;

                $player[
                    'support_top_status_score'
                ] = round(
                    $maintenanceScore,
                    4
                );

                $player[
                    'support_top_status_raids'
                ] = (int)(
                    $maintenance['raids']
                );

                $player[
                    'support_top_status_bundles'
                ] = (int)(
                    $maintenance['bundles']
                );
            }

            unset($player);
        }

        unset($raid);
    }

} catch (Throwable $error) {

    error_log(
        '[ULGG Raid Observer Cast Maintenance V3] '
        . get_class($error)
        . ': '
        . $error->getMessage()
    );
}


/*
 * RAID_OBSERVER_SINGLE_CHANGE_DPM_V2
 *
 * Controlled one-turn validation:
 *
 * When a player has exactly ONE formal timed positive
 * damage_change in the Raid, and that change starts from
 * damage_before = 0, do NOT scale the damage by the Raid
 * Observer polling interval.
 *
 * Example:
 *
 *   0 -> 26
 *   one damage_change
 *   observed window = 44 sec
 *
 * Old:
 *   26 * 60 / 44 = 35.45
 *
 * New:
 *   DPM = 26
 *
 * Multi-change players continue using the existing
 * Observation Window DPM model.
 *
 * Baseline-only damage is NOT converted into DPM.
 */
try {

    if (
        isset($db)
        && $db instanceof PDO
        && isset($payload['raids'])
        && is_array($payload['raids'])
    ) {

        /*
         * Only inspect Raid IDs currently being returned.
         */
        $singleChangeRaidIds = [];

        foreach ($payload['raids'] as $raid) {

            if (!is_array($raid)) {
                continue;
            }

            $raidId = trim(
                (string)(
                    $raid['raid_id']
                    ?? ''
                )
            );

            if ($raidId !== '') {
                $singleChangeRaidIds[
                    $raidId
                ] = true;
            }
        }

        $singleChangeRaidIds =
            array_keys(
                $singleChangeRaidIds
            );


        $singleChangeDpmMap = [];


        if ($singleChangeRaidIds) {

            $ph = implode(
                ',',
                array_fill(
                    0,
                    count($singleChangeRaidIds),
                    '?'
                )
            );


            /*
             * Count ONLY formal timed positive damage changes.
             *
             * support_backfill is explicitly excluded.
             */
            $sql = "
                SELECT
                    raid_id,
                    player_name,

                    COUNT(*) AS change_count,

                    MIN(damage_before)
                        AS first_damage_before,

                    MAX(damage_before)
                        AS max_damage_before,

                    SUM(damage_delta)
                        AS damage_delta

                FROM raid_player_damage_events

                WHERE
                    raid_id IN ({$ph})

                    AND source =
                        'live_snapshot'

                    AND event_kind =
                        'damage_change'

                    AND damage_delta > 0

                    AND window_seconds > 0

                GROUP BY
                    raid_id,
                    player_name

                HAVING
                    COUNT(*) = 1

                    AND MIN(damage_before) = 0

                    AND MAX(damage_before) = 0
            ";


            $stmt = $db->prepare(
                $sql
            );

            $stmt->execute(
                $singleChangeRaidIds
            );


            while (
                $row = $stmt->fetch(
                    PDO::FETCH_ASSOC
                )
            ) {

                $raidId = trim(
                    (string)(
                        $row['raid_id']
                        ?? ''
                    )
                );

                $playerName = trim(
                    (string)(
                        $row['player_name']
                        ?? ''
                    )
                );

                $damageDelta = (float)(
                    $row['damage_delta']
                    ?? 0
                );


                if (
                    $raidId === ''
                    || $playerName === ''
                    || $damageDelta <= 0
                ) {
                    continue;
                }


                $key =
                    $raidId
                    . "\x1F"
                    . $playerName;


                $singleChangeDpmMap[
                    $key
                ] = $damageDelta;
            }
        }


        /*
         * Apply after ACTIVE_DPM_V1 enrichment.
         *
         * Keep the original calculated value internally
         * for debugging / model comparison.
         */
        foreach (
            $payload['raids']
            as &$raid
        ) {

            if (
                !is_array($raid)
                || !isset($raid['players'])
                || !is_array($raid['players'])
            ) {
                continue;
            }


            $raidId = trim(
                (string)(
                    $raid['raid_id']
                    ?? ''
                )
            );


            if ($raidId === '') {
                continue;
            }


            foreach (
                $raid['players']
                as &$player
            ) {

                if (!is_array($player)) {
                    continue;
                }


                $playerName = trim(
                    (string)(
                        $player['name']
                        ?? ''
                    )
                );


                if ($playerName === '') {
                    continue;
                }


                $key =
                    $raidId
                    . "\x1F"
                    . $playerName;


                if (
                    !array_key_exists(
                        $key,
                        $singleChangeDpmMap
                    )
                ) {
                    continue;
                }


                /*
                 * Preserve raw Observation Window result.
                 */
                $player[
                    'active_dpm_observation_raw'
                ] =
                    $player[
                        'active_dpm'
                    ]
                    ?? null;


                /*
                 * One known positive damage change from 0.
                 *
                 * Treat its damage as one-turn-scale DPM
                 * instead of scaling by the polling window.
                 */
                $player[
                    'active_dpm'
                ] = round(
                    (float)$singleChangeDpmMap[
                        $key
                    ],
                    2
                );


                $player[
                    'active_dpm_method'
                ] =
                    'single_change_damage';
            }

            unset($player);
        }

        unset($raid);
    }

} catch (Throwable $error) {

    /*
     * DPM refinement is optional.
     * Never break Raid Observer if this layer fails.
     */
    error_log(
        '[ULGG Raid Observer Single Change DPM V2] '
        . get_class($error)
        . ': '
        . $error->getMessage()
    );
}

observedRaidsRespond(200, $payload);
