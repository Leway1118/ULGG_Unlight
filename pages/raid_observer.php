<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/*
 * RAID_LOOKUP_HISTORY_REPORT_V1
 *
 * Lookup contract:
 * - numeric TL => fixed reward lookup only
 * - public Raid code => exact historical Raid report + that TL's fixed rewards
 *
 * Raid history source of truth:
 * - raid_history.participant_count = highest simultaneously observed participant count
 * - raid_player_history            = cumulative players observed in this Raid
 */
$pdo = $db;

function raidLookupHistoryRaid(PDO $pdo, string $raidId): ?array
{
    $stmt = $pdo->prepare(
        "
        SELECT
            r.*,
            (
                SELECT COUNT(*)
                FROM raid_player_history p
                WHERE p.raid_id = r.raid_id
            ) AS observed_player_count,
            (
                SELECT COALESCE(SUM(p.damage), 0)
                FROM raid_player_history p
                WHERE p.raid_id = r.raid_id
            ) AS observed_total_damage
        FROM raid_history r
        WHERE r.raid_id = :raid_id
        LIMIT 1
        "
    );

    $stmt->execute([
        ':raid_id' => $raidId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function raidLookupHistoryPlayers(PDO $pdo, string $raidId): array
{
    $stmt = $pdo->prepare(
        "
        SELECT
            player_name,
            rank_no,
            point,
            damage,
            first_seen_at,
            last_seen_at
        FROM raid_player_history
        WHERE raid_id = :raid_id
        ORDER BY
            CASE WHEN rank_no IS NULL THEN 1 ELSE 0 END,
            rank_no ASC,
            point DESC,
            player_name ASC
        "
    );

    $stmt->execute([
        ':raid_id' => $raidId,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function raidLookupHistoryDate($value): string
{
    $text = trim((string)$value);

    if ($text === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($text))->format('Y-m-d H:i:s');
    } catch (Throwable $error) {
        return $text;
    }
}

function raidLookupHistoryDuration($firstSeen, $lastSeen): string
{
    try {
        $first = new DateTimeImmutable((string)$firstSeen);
        $last = new DateTimeImmutable((string)$lastSeen);
    } catch (Throwable $error) {
        return '—';
    }

    $seconds = max(0, $last->getTimestamp() - $first->getTimestamp());

    if ($seconds < 60) {
        return '約 ' . $seconds . ' 秒';
    }

    $minutes = intdiv($seconds, 60);

    if ($minutes < 60) {
        return '約 ' . $minutes . ' 分';
    }

    $hours = intdiv($minutes, 60);
    $remainMinutes = $minutes % 60;

    return $remainMinutes > 0
        ? "約 {$hours} 小時 {$remainMinutes} 分"
        : "約 {$hours} 小時";
}


/*
 * RAID_REWARD_API_FALLBACK_V1
 *
 * raid_reward_cache.json = first choice
 * cache miss             = read-only Reward API fallback
 *
 * IMPORTANT:
 * This PHP page NEVER writes raid_reward_cache.json.
 * Raid Bot remains the only owner of RewardCache writes.
 */
function raidLookupFetchRewardApi(int $treasureLevel): ?array
{
    if ($treasureLevel <= 0) {
        return null;
    }

    $url = 'https://www.ulrmap.wiki/api/raid-rewards/query';

    $payload = json_encode(
        [
            'levels' => [$treasureLevel],
            'locale' => 'zh-TW',
        ],
        JSON_UNESCAPED_UNICODE
    );

    if (!is_string($payload)) {
        return null;
    }

    $body = null;

    /*
     * Prefer PHP cURL.
     */
    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        if ($ch !== false) {
            curl_setopt_array(
                $ch,
                [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 6,
                ]
            );

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

            curl_close($ch);

            if (
                is_string($response)
                && $response !== ''
                && $httpCode >= 200
                && $httpCode < 300
            ) {
                $body = $response;
            }
        }
    }

    /*
     * Fallback when PHP cURL extension is unavailable.
     */
    if ($body === null) {
        $context = stream_context_create(
            [
                'http' => [
                    'method' => 'POST',
                    'header' =>
                        "Content-Type: application/json\r\n"
                        . "Accept: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 6,
                    'ignore_errors' => true,
                ],
            ]
        );

        $response = @file_get_contents(
            $url,
            false,
            $context
        );

        if (
            is_string($response)
            && $response !== ''
        ) {
            $body = $response;
        }
    }

    if ($body === null) {
        return null;
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        return null;
    }

    foreach ($decoded as $row) {
        if (
            is_array($row)
            && (int)($row['treasureLevel'] ?? 0)
                === $treasureLevel
        ) {
            return $row;
        }
    }

    return null;
}

$raidLookupQuery = trim((string)($_GET['raid_lookup'] ?? ''));
$raidLookupResult = null;
$raidLookupError = null;
$raidLookupHistoryReport = null;

if ($raidLookupQuery !== '') {
    // /pages and /tool are sibling directories under the same web root.
    $rewardCachePath = __DIR__ . '/../tool/raid-bot/data/raid_reward_cache.json';

    $lookupTl = null;
    $historyRow = null;

    if (ctype_digit($raidLookupQuery)) {
        /*
         * Numeric TL is a fixed reward template.
         * Never silently choose one historical Raid from many Raids
         * that happened to share the same TL.
         */
        $lookupTl = (int)$raidLookupQuery;
    } else {
        try {
            $historyRow = raidLookupHistoryRaid(
                $pdo,
                $raidLookupQuery
            );

            if ($historyRow === null) {
                $raidLookupError = '找不到這個渦碼的單場歷史紀錄。';
            } else {
                if (
                    isset($historyRow['treasure_level'])
                    && is_numeric($historyRow['treasure_level'])
                ) {
                    $lookupTl = (int)$historyRow['treasure_level'];
                }

                $historyPlayers = raidLookupHistoryPlayers(
                    $pdo,
                    $raidLookupQuery
                );

                $raidLookupHistoryReport = [
                    'raid' => $historyRow,
                    'players' => $historyPlayers,
                ];
            }
        } catch (Throwable $error) {
            $raidLookupError = '單場 Raid 歷史資料目前無法讀取。';
            error_log(
                '[ULGG raid observer history lookup] '
                . get_class($error)
                . ': '
                . $error->getMessage()
            );
        }
    }

    if ($lookupTl !== null) {
        $reward = null;

        if (
            is_file($rewardCachePath)
            && is_readable($rewardCachePath)
        ) {
            $rewardData = json_decode(
                (string)file_get_contents($rewardCachePath),
                true
            );

            $rewards = is_array($rewardData)
                ? ($rewardData['rewards'] ?? [])
                : [];

            $reward = is_array($rewards)
                ? ($rewards[(string)$lookupTl] ?? null)
                : null;
        }

        /*
         * Cache miss:
         * fetch Reward API read-only.
         * TL rewards are fixed, so this remains independent
         * from which historical Raid was looked up.
         */
        if (!is_array($reward)) {
            $reward = raidLookupFetchRewardApi(
                $lookupTl
            );
        }

        $raidLookupResult = [
            'treasure_level' => $lookupTl,
            'history' => $historyRow,
            'reward' => is_array($reward)
                ? $reward
                : null,
        ];

        if (
            $raidLookupResult['reward'] === null
            && $raidLookupError === null
        ) {
            $raidLookupError =
                "已找到 TL {$lookupTl}，但獎勵資料目前無法取得。";
        }
    } elseif (
        $raidLookupQuery !== ''
        && !ctype_digit($raidLookupQuery)
        && $raidLookupError === null
    ) {
        $raidLookupError =
            '已找到這場 Raid，但沒有可用的 TL，無法顯示固定獎勵。';
    }
}

$pageTitleText = '觀察渦';
$seoTitle = '觀察渦 | UL.GG Unlight 公開 Raid 即時狀態';
$pageTitleFull = '觀察渦 | UL.GG';
$activeMenu = 'raid_observer';

// ADMIN_RAID_FOUNDER_STATS_V1
$isRaidStatsAdmin =
    (int)($_SESSION['permission'] ?? 0) >= 2
    ||
    (int)($_SESSION['ack'] ?? 0) >= 2
    ||
    (int)($_SESSION['check_ack'] ?? 0) >= 2;

ob_start();
?>

<style>
  /* RAID_OBSERVER_ACTIVE_DPM_V1 */
  .raid-observer-page {
    --raid-panel: rgba(31, 34, 45, .96);
    --raid-panel-soft: rgba(42, 46, 60, .9);
    --raid-border: rgba(184, 190, 255, .2);
    --raid-text: #edf0fa;
    --raid-muted: #aeb5c9;
    --raid-accent: #b8beff;
    --raid-green: #4dd599;
    --raid-yellow: #e6c77a;
    --raid-red: #ff6b6b;
    --raid-blue: #6fa8ff;
    color: var(--raid-text);
    padding: 24px 12px 48px;
  }

  .raid-observer__hero,
  .raid-observer__section {
    background: var(--raid-panel);
    border: 1px solid var(--raid-border);
    border-radius: 14px;
    box-shadow: 0 14px 34px rgba(0, 0, 0, .2);
  }

  .raid-observer__hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 22px;
    margin-bottom: 16px;
  }

  .raid-observer__eyebrow {
    color: var(--raid-accent);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .12em;
    margin: 0 0 4px;
  }

  .raid-observer__title {
    color: #fff;
    font-size: clamp(26px, 4vw, 38px);
    font-weight: 700;
    margin: 0;
  }

  .raid-observer__summary {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 10px;
  }

  .raid-stat {
    min-width: 106px;
    padding: 10px 14px;
    background: var(--raid-panel-soft);
    border: 1px solid rgba(255, 255, 255, .07);
    border-radius: 10px;
  }

  .raid-stat__label {
    color: var(--raid-muted);
    display: block;
    font-size: 12px;
  }

  .raid-stat__value {
    color: #fff;
    display: block;
    font-size: 18px;
    font-weight: 700;
    margin-top: 2px;
  }

  .raid-connection {
    align-items: center;
    border-radius: 999px;
    display: inline-flex;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .06em;
    padding: 7px 11px;
  }

  .raid-connection.is-live {
    background: rgba(77, 213, 153, .14);
    color: var(--raid-green);
  }

  .raid-connection.is-stale {
    background: rgba(230, 199, 122, .14);
    color: var(--raid-yellow);
  }

  .raid-stale-notice {
    background: rgba(230, 199, 122, .11);
    border: 1px solid rgba(230, 199, 122, .35);
    border-radius: 10px;
    color: #f2dba4;
    margin: 0 0 16px;
    padding: 11px 14px;
  }

  .raid-observer__section {
    padding: 18px;
    margin-top: 16px;
  }

  .raid-observer__section-heading {
    align-items: baseline;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .raid-observer__section-heading h2 {
    color: #fff;
    font-size: 20px;
    font-weight: 700;
    margin: 0;
  }

  .raid-observer__section-heading span {
    color: var(--raid-muted);
    font-size: 13px;
  }

  .raid-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
  }

  .raid-card {
    background: var(--raid-panel-soft);
    border: 1px solid rgba(255, 255, 255, .08);
    border-left: 3px solid var(--raid-blue);
    border-radius: 11px;
    padding: 15px;
    transition: border-color .2s ease, background .2s ease;
  }

  .raid-card.is-critical { border-left-color: var(--raid-red); }
  .raid-card.is-defeated { border-left-color: var(--raid-green); opacity: .88; }
  .raid-card.is-expired,
  .raid-card.is-ended { border-left-color: #7b8296; opacity: .78; }

  .raid-card__header,
  .raid-card__hp-row {
    align-items: flex-start;
    display: flex;
    justify-content: space-between;
    gap: 12px;
  }

  .raid-card__boss {
    color: #fff;
    display: block;
    font-size: 19px;
    font-weight: 700;
    overflow-wrap: anywhere;
  }

  .raid-card__founder {
    color: var(--raid-muted);
    display: block;
    font-size: 13px;
    margin-top: 3px;
    overflow-wrap: anywhere;
  }

  .raid-card__badge {
    background: rgba(111, 168, 255, .14);
    border-radius: 999px;
    color: #a9caff;
    flex: 0 0 auto;
    font-size: 12px;
    font-weight: 700;
    padding: 5px 9px;
  }

  .is-critical .raid-card__badge {
    background: rgba(255, 107, 107, .16);
    color: #ff9c9c;
  }

  .is-defeated .raid-card__badge {
    background: rgba(77, 213, 153, .14);
    color: var(--raid-green);
  }

  .is-expired .raid-card__badge,
  .is-ended .raid-card__badge {
    background: rgba(174, 181, 201, .12);
    color: var(--raid-muted);
  }

  .raid-card__hp-row {
    align-items: baseline;
    margin-top: 15px;
  }

  .raid-card__hp {
    color: #fff;
    font-size: 16px;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
  }

  .raid-card__hp-percent {
    color: var(--raid-muted);
    font-size: 13px;
  }

  .raid-card__bar {
    background: rgba(255, 255, 255, .08);
    border-radius: 999px;
    height: 7px;
    margin-top: 7px;
    overflow: hidden;
  }

  .raid-card__bar span {
    background: linear-gradient(90deg, #6298e7, #7db8ff);
    border-radius: inherit;
    display: block;
    height: 100%;
    transition: width .25s ease;
  }

  .is-critical .raid-card__bar span {
    background: linear-gradient(90deg, #e05252, #ff7979);
  }

  .raid-card__meta {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px 14px;
    margin-top: 14px;
  }

  .raid-card__meta-item:first-child {
    grid-column: 1 / -1;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .07);
  }

  .raid-card__meta-item:first-child .raid-card__meta-label {
    color: var(--raid-accent);
    font-weight: 700;
  }

  .raid-card__meta-item:first-child .raid-card__meta-value {
    font-size: 14px;
    line-height: 1.55;
    word-break: break-word;
  }

  .raid-card__meta-item {
    min-width: 0;
  }

  .raid-card__meta-label,
  .raid-card__meta-value {
    display: block;
  }

  .raid-card__meta-label {
    color: var(--raid-muted);
    font-size: 11px;
  }

  .raid-card__meta-value {
    color: var(--raid-text);
    font-size: 13px;
    margin-top: 2px;
    overflow-wrap: anywhere;
  }

  .raid-empty {
    color: var(--raid-muted);
    grid-column: 1 / -1;
    padding: 28px 12px;
    text-align: center;
  }

  .raid-lookup__form {
    display: flex;
    gap: 10px;
    margin-top: 12px;
  }

  .raid-lookup__input {
    background: rgba(255, 255, 255, .06);
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 9px;
    color: #fff;
    flex: 1 1 auto;
    font-size: 15px;
    min-width: 0;
    padding: 10px 12px;
  }

  .raid-lookup__input:focus {
    border-color: var(--raid-accent);
    outline: none;
  }

  .raid-lookup__button {
    background: rgba(184, 190, 255, .16);
    border: 1px solid rgba(184, 190, 255, .35);
    border-radius: 9px;
    color: #fff;
    cursor: pointer;
    font-weight: 700;
    padding: 10px 16px;
  }

  .raid-lookup__button:hover {
    background: rgba(184, 190, 255, .24);
  }

  .raid-lookup__result,
  .raid-lookup__error {
    border-radius: 10px;
    margin-top: 12px;
    padding: 12px 14px;
  }

  .raid-lookup__result {
    background: rgba(77, 213, 153, .08);
    border: 1px solid rgba(77, 213, 153, .24);
  }

  .raid-lookup__error {
    background: rgba(255, 107, 107, .09);
    border: 1px solid rgba(255, 107, 107, .25);
    color: #ffb2b2;
  }

  .raid-lookup__headline {
    color: #fff;
    font-size: 17px;
    font-weight: 700;
    margin-bottom: 8px;
  }

  .raid-lookup__meta {
    color: var(--raid-muted);
    font-size: 13px;
    margin-bottom: 10px;
  }

  .raid-lookup__reward-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .raid-lookup__reward-box {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 9px;
    padding: 10px;
  }

  .raid-lookup__reward-title {
    color: var(--raid-accent);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 6px;
  }

  .raid-lookup__reward-item {
    color: var(--raid-text);
    font-size: 13px;
    line-height: 1.55;
  }

  .raid-status-list {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 5px;
  }

  .raid-status-chip {
    align-items: center;
    background: rgba(255, 255, 255, .055);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 8px;
    display: inline-flex;
    gap: 6px;
    min-height: 30px;
    padding: 3px 8px 3px 4px;
  }

  .raid-status-chip.has-no-icon {
    padding-left: 8px;
  }

  .raid-status-icon {
    display: block;
    flex: 0 0 24px;
    height: 24px;
    image-rendering: auto;
    object-fit: contain;
    width: 24px;
  }

  .raid-status-text {
    color: var(--raid-text);
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
  }

  .raid-participants-toggle {
    background: transparent;
    border: 0;
    color: var(--raid-text);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    margin: 2px 0 0;
    padding: 0;
    text-align: left;
  }

  .raid-participants-toggle:hover {
    color: #fff;
    text-decoration: underline;
  }

  .raid-card__participants.is-expanded {
    grid-column: 1 / -1;
  }

  .raid-player-list {
    background: rgba(0, 0, 0, .14);
    border: 1px solid rgba(255, 255, 255, .07);
    border-radius: 9px;
    margin-top: 8px;
    max-height: 240px;
    overflow-y: auto;
    padding: 4px 8px;
  }

  .raid-player-row {
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, .05);
    display: grid;
    gap: 8px;
    grid-template-columns: 44px minmax(160px, 1fr) 115px 105px 120px 70px;
    min-height: 32px;
  }

  .raid-player-row:last-child {
    border-bottom: 0;
  }

  .raid-player-rank {
    color: var(--raid-muted);
    font-size: 11px;
  }

  .raid-player-dpm {
    font-weight: 700;
    white-space: nowrap;
  }

  .raid-player-percent {
    text-align: right;
  }

  .raid-player-name {
    color: var(--raid-text);
    font-size: 13px;
    overflow-wrap: anywhere;
  }

  .raid-player-point,
  .raid-player-damage,
  .raid-player-dpm,
  .raid-player-percent {
    color: var(--raid-muted);
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }


  .raid-rewards {
    border-top: 1px solid rgba(255, 255, 255, .07);
    margin-top: 15px;
    padding-top: 13px;
  }

  .raid-rewards__header {
    align-items: center;
    display: flex;
    gap: 8px;
    margin-bottom: 9px;
  }

  .raid-rewards__title {
    color: var(--raid-muted);
    font-size: 11px;
    font-weight: 700;
  }

  .raid-rewards__tier {
    background: rgba(184, 190, 255, .1);
    border: 1px solid rgba(184, 190, 255, .22);
    border-radius: 999px;
    color: var(--raid-accent);
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
  }

  .raid-reward-group {
    align-items: flex-start;
    display: grid;
    gap: 8px;
    grid-template-columns: 44px minmax(0, 1fr);
    margin-top: 7px;
  }

  .raid-reward-group:first-of-type {
    margin-top: 0;
  }

  .raid-reward-group__label {
    color: var(--raid-muted);
    font-size: 11px;
    line-height: 27px;
    white-space: nowrap;
  }

  .raid-reward-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    min-width: 0;
  }

  .raid-reward-chip {
    --reward-color: #aeb5c9;

    align-items: center;
    background:
      color-mix(
        in srgb,
        var(--reward-color) 10%,
        transparent
      );
    border: 1px solid
      color-mix(
        in srgb,
        var(--reward-color) 72%,
        transparent
      );
    border-radius: 999px;
    color: var(--reward-color);
    display: inline-flex;
    font-size: 12px;
    line-height: 1.25;
    min-height: 27px;
    padding: 4px 9px;
    white-space: normal;
  }

  .raid-reward-chip.is-bold {
    font-weight: 800;
  }

  .raid-rewards__empty {
    color: var(--raid-muted);
    display: block;
    font-size: 12px;
  }


  .raid-stats-section {
    background: rgba(14, 17, 28, .82);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    margin-top: 18px;
    padding: 16px;
  }

  .raid-stats-head {
    align-items: flex-end;
    display: flex;
    gap: 14px;
    justify-content: space-between;
    margin-bottom: 14px;
  }

  .raid-stats-title {
    color: var(--raid-text);
    font-size: 18px;
    margin: 0 0 4px;
  }

  .raid-stats-summary {
    color: var(--raid-muted);
    font-size: 12px;
  }

  .raid-stats-controls {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
  }

  .raid-stats-ranges {
    display: flex;
    gap: 5px;
  }

  .raid-stats-ranges button {
    background: rgba(255, 255, 255, .04);
    border: 1px solid rgba(255, 255, 255, .1);
    border-radius: 7px;
    color: var(--raid-muted);
    cursor: pointer;
    font: inherit;
    font-size: 12px;
    padding: 6px 10px;
  }

  .raid-stats-ranges button.is-active {
    background: rgba(184, 190, 255, .14);
    border-color: rgba(184, 190, 255, .4);
    color: var(--raid-text);
  }

  .raid-stats-search {
    background: rgba(0, 0, 0, .2);
    border: 1px solid rgba(255, 255, 255, .1);
    border-radius: 7px;
    color: var(--raid-text);
    min-width: 160px;
    outline: none;
    padding: 7px 9px;
  }

  .raid-stats-search:focus {
    border-color: rgba(184, 190, 255, .55);
  }

  .raid-stats-table-wrap {
    overflow-x: auto;
    width: 100%;
  }

  .raid-stats-table {
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed;
    width: 100%;
  }

  .raid-stats-table th,
  .raid-stats-table td {
    border-bottom: 1px solid rgba(255, 255, 255, .06);
    padding: 9px 6px;
  }

  .raid-stats-table th {
    line-height: 1.2;
    white-space: normal;
  }

  .raid-stats-table td {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* 玩家名稱稍寬，其餘統計欄平均壓縮 */
  .raid-stats-table th:nth-child(1),
  .raid-stats-table td:nth-child(1) {
    width: 21%;
  }

  .raid-stats-table th:nth-child(2),
  .raid-stats-table td:nth-child(2) {
    width: 8%;
  }

  .raid-stats-table th:nth-child(3),
  .raid-stats-table td:nth-child(3) {
    width: 9%;
  }

  .raid-stats-table th:nth-child(4),
  .raid-stats-table td:nth-child(4) {
    width: 10%;
  }

  .raid-stats-table th:nth-child(5),
  .raid-stats-table td:nth-child(5) {
    width: 10%;
  }

  .raid-stats-table th:nth-child(6),
  .raid-stats-table td:nth-child(6) {
    width: 10%;
  }

  .raid-stats-table th:nth-child(7),
  .raid-stats-table td:nth-child(7) {
    width: 12%;
  }

  .raid-stats-table th:nth-child(8),
  .raid-stats-table td:nth-child(8) {
    width: 10%;
  }

  .raid-stats-table th:nth-child(9),
  .raid-stats-table td:nth-child(9) {
    width: 10%;
  }

  .raid-stats-table th {
    color: var(--raid-muted);
    cursor: pointer;
    font-size: 11px;
    font-weight: 700;
    text-align: left;
    user-select: none;
  }

  .raid-stats-table th:not(:first-child),
  .raid-stats-table td.is-number {
    text-align: right;
  }

  .raid-stats-table th.is-sorted {
    color: var(--raid-text);
  }

  .raid-stats-table th.is-sorted::after {
    content: ' ▼';
    font-size: 9px;
  }

  .raid-stats-table th.is-sorted[data-direction="asc"]::after {
    content: ' ▲';
  }

  .raid-stats-table tbody tr:hover {
    background: rgba(255, 255, 255, .035);
  }

  .raid-stats-table tbody tr.is-owner {
    background: rgba(184, 190, 255, .07);
    font-weight: 700;
  }

  .raid-stats-table tbody tr.is-owner td:first-child::before {
    content: '★ ';
    color: var(--raid-accent);
  }


  /* RAID_PLAYER_STATS_PAGINATION_V1 */
  .raid-stats-pagination {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px 14px;
    justify-content: space-between;
    margin-top: 12px;
  }

  .raid-stats-page-summary {
    color: var(--raid-muted);
    font-size: 12px;
  }

  .raid-stats-page-buttons {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
  }

  .raid-stats-page-button {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 7px;
    color: var(--raid-muted);
    cursor: pointer;
    font: inherit;
    font-size: 12px;
    min-width: 34px;
    padding: 6px 9px;
  }

  .raid-stats-page-button:hover:not(:disabled) {
    background: rgba(184,190,255,.11);
    color: var(--raid-text);
  }

  .raid-stats-page-button.is-active {
    background: rgba(184,190,255,.18);
    border-color: rgba(184,190,255,.45);
    color: #fff;
    font-weight: 800;
  }

  .raid-stats-page-button:disabled {
    cursor: default;
    opacity: .35;
  }

  .raid-stats-page-dots {
    color: var(--raid-muted);
    min-width: 20px;
    text-align: center;
  }


  /* ADMIN_RAID_FOUNDER_CONTROLS_THEME_V1
   * Keep admin founder search + pagination in the same UL.GG Raid palette.
   */
  .raid-stats-controls > button,
  .raid-stats-controls button[data-action*="search"],
  .raid-stats-controls button[class*="search"] {
    background: rgba(184, 190, 255, .12);
    border: 1px solid rgba(184, 190, 255, .34);
    border-radius: 7px;
    color: var(--raid-accent);
    cursor: pointer;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    padding: 7px 12px;
    transition:
      background .16s ease,
      border-color .16s ease,
      color .16s ease,
      box-shadow .16s ease;
  }

  .raid-stats-controls > button:hover:not(:disabled),
  .raid-stats-controls button[data-action*="search"]:hover:not(:disabled),
  .raid-stats-controls button[class*="search"]:hover:not(:disabled) {
    background: rgba(184, 190, 255, .20);
    border-color: rgba(184, 190, 255, .55);
    color: #fff;
    box-shadow: 0 0 0 2px rgba(184, 190, 255, .07);
  }

  .raid-stats-controls > button:focus-visible,
  .raid-stats-controls button[data-action*="search"]:focus-visible,
  .raid-stats-controls button[class*="search"]:focus-visible,
  .raid-stats-page-button:focus-visible {
    outline: 2px solid rgba(184, 190, 255, .60);
    outline-offset: 2px;
  }

  .raid-stats-page-button {
    background: rgba(184, 190, 255, .075);
    border-color: rgba(184, 190, 255, .22);
    color: #cbd0ff;
    transition:
      background .16s ease,
      border-color .16s ease,
      color .16s ease,
      box-shadow .16s ease;
  }

  .raid-stats-page-button:hover:not(:disabled) {
    background: rgba(184, 190, 255, .15);
    border-color: rgba(184, 190, 255, .42);
    color: #fff;
    box-shadow: 0 0 0 2px rgba(184, 190, 255, .055);
  }

  .raid-stats-page-button.is-active {
    background: rgba(184, 190, 255, .22);
    border-color: rgba(184, 190, 255, .58);
    color: #fff;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .035);
  }

  .raid-stats-page-button:disabled {
    background: rgba(255, 255, 255, .025);
    border-color: rgba(255, 255, 255, .07);
    color: var(--raid-muted);
    opacity: .38;
  }


  /* RAID_STATS_BUTTON_THEME_V2
   * Force every button inside Raid stats panels to use the Raid dark/accent theme.
   * This intentionally overrides Bootstrap/AdminLTE button backgrounds.
   */
  .raid-observer-page .raid-stats-section button {
    background: rgba(184, 190, 255, .08) !important;
    border: 1px solid rgba(184, 190, 255, .22) !important;
    border-radius: 7px !important;
    color: #cbd0ff !important;
    box-shadow: none !important;
    cursor: pointer;
    transition:
      background .16s ease,
      border-color .16s ease,
      color .16s ease,
      box-shadow .16s ease;
  }

  .raid-observer-page .raid-stats-section button:hover:not(:disabled) {
    background: rgba(184, 190, 255, .16) !important;
    border-color: rgba(184, 190, 255, .42) !important;
    color: #ffffff !important;
    box-shadow: 0 0 0 2px rgba(184, 190, 255, .055) !important;
  }

  .raid-observer-page .raid-stats-section button.is-active,
  .raid-observer-page .raid-stats-section button.active,
  .raid-observer-page .raid-stats-section button[aria-current="page"] {
    background: rgba(184, 190, 255, .22) !important;
    border-color: rgba(184, 190, 255, .56) !important;
    color: #ffffff !important;
    font-weight: 800;
  }

  .raid-observer-page .raid-stats-section button:disabled {
    background: rgba(255, 255, 255, .025) !important;
    border-color: rgba(255, 255, 255, .07) !important;
    color: var(--raid-muted) !important;
    opacity: .38 !important;
    cursor: default;
  }

  .raid-observer-page .raid-stats-section button:focus-visible {
    outline: 2px solid rgba(184, 190, 255, .62) !important;
    outline-offset: 2px;
  }

  @media (max-width: 767px) {
    .raid-stats-pagination {
      align-items: stretch;
      flex-direction: column;
    }

    .raid-stats-page-buttons {
      justify-content: center;
    }

    .raid-stats-page-summary {
      text-align: center;
    }

    .raid-stats-head {
      align-items: stretch;
      flex-direction: column;
    }

    .raid-stats-controls {
      align-items: stretch;
      flex-direction: column;
    }

    .raid-stats-search {
      width: 100%;
    }

    /* Mobile stats table: keep only the four core columns.
       1 玩家名稱 / 2 參加渦 / 4 總傷害 / 5 平均傷害 */
    .raid-stats-table-wrap {
      overflow-x: hidden;
    }

    .raid-stats-table {
      table-layout: fixed;
      width: 100%;
    }

    .raid-stats-table th:nth-child(3),
    .raid-stats-table td:nth-child(3),
    .raid-stats-table th:nth-child(6),
    .raid-stats-table td:nth-child(6),
    .raid-stats-table th:nth-child(7),
    .raid-stats-table td:nth-child(7),
    .raid-stats-table th:nth-child(8),
    .raid-stats-table td:nth-child(8),
    .raid-stats-table th:nth-child(9),
    .raid-stats-table td:nth-child(9) {
      display: none;
    }

    .raid-stats-table th:nth-child(1),
    .raid-stats-table td:nth-child(1) {
      width: 43%;
    }

    .raid-stats-table th:nth-child(2),
    .raid-stats-table td:nth-child(2) {
      width: 16%;
    }

    .raid-stats-table th:nth-child(4),
    .raid-stats-table td:nth-child(4) {
      width: 23%;
    }

    .raid-stats-table th:nth-child(5),
    .raid-stats-table td:nth-child(5) {
      width: 18%;
    }

    .raid-stats-table th,
    .raid-stats-table td {
      padding: 9px 4px;
    }

    .raid-stats-table th {
      font-size: 10px;
    }

    .raid-stats-table td {
      font-size: 12px;
    }

    .raid-player-row {
      gap: 3px 8px;
      grid-template-columns: 34px minmax(0, 1fr) auto auto;
      padding: 5px 0;
    }

    .raid-player-rank {
      align-self: center;
      grid-column: 1;
      grid-row: 1 / span 3;
    }

    .raid-player-name {
      grid-column: 2 / -1;
      grid-row: 1;
    }

    .raid-player-point {
      grid-column: 2;
      grid-row: 2;
    }

    .raid-player-damage {
      grid-column: 3;
      grid-row: 2;
    }

    .raid-player-percent {
      grid-column: 4;
      grid-row: 2;
    }

    .raid-player-dpm {
      grid-column: 2 / -1;
      grid-row: 3;
    }

    .raid-reward-group {
      grid-template-columns: 38px minmax(0, 1fr);
    }

    .raid-reward-chip {
      font-size: 11px;
      padding: 4px 7px;
    }

    .raid-observer-page { padding: 14px 2px 36px; }
    .raid-observer__hero { align-items: stretch; flex-direction: column; padding: 18px; }
    .raid-observer__summary { justify-content: flex-start; }
    .raid-stat { flex: 1 1 100px; }
    .raid-grid { grid-template-columns: minmax(0, 1fr); }
    .raid-card__meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .raid-card__meta-item:first-child { grid-column: 1 / -1; }
    .raid-lookup__form { flex-direction: column; }
    .raid-lookup__reward-grid { grid-template-columns: minmax(0, 1fr); }
  }

  /* RAID_OBSERVER_PLAYER_REAL_TABLE_CSS_V2 */

  .raid-player-list {
    overflow-x: auto;
    padding: 0;
  }


  .raid-player-table {
    border-collapse: collapse;
    table-layout: fixed;
    width: 100%;

    min-width: 560px;
  }


  .raid-player-table th,
  .raid-player-table td {
    border-bottom:
      1px solid rgba(255,255,255,.055);

    padding: 6px 6px;

    vertical-align: middle;
  }


  .raid-player-table thead th {
    background:
      rgba(15, 18, 28, .96);

    color:
      var(--raid-muted);

    font-size: 10px;
    font-weight: 800;

    position: sticky;
    top: 0;
    z-index: 2;

    white-space: nowrap;
  }


  .raid-player-table tbody tr:last-child td {
    border-bottom: 0;
  }


  .raid-player-table tbody tr:hover {
    background:
      rgba(255,255,255,.035);
  }


  .raid-player-th-rank,
  .raid-player-rank {
    width: 38px;
  }


  .raid-player-th-name {
    width: auto;
  }


  .raid-player-th-point {
    width: 84px;
  }


  .raid-player-th-damage {
    width: 62px;
  }


  .raid-player-th-dpm {
    width: 60px;
  }


  .raid-player-th-cast {
    width: 50px;
  }


  .raid-player-th-percent {
    width: 60px;
  }


  .raid-player-th-point,
  .raid-player-th-damage,
  .raid-player-th-dpm,
  .raid-player-th-percent,

  .raid-player-point,
  .raid-player-damage,
  .raid-player-dpm,
  .raid-player-percent {
    text-align: right;
  }


  .raid-player-th-cast,
  .raid-player-cast {
    text-align: center;
  }


  .raid-player-rank {
    color:
      var(--raid-muted);

    font-size: 11px;
    white-space: nowrap;
  }


  .raid-player-name {
    color:
      var(--raid-text);

    font-size: 12px;

    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }


  .raid-player-point,
  .raid-player-damage,
  .raid-player-dpm,
  .raid-player-percent {
    color:
      var(--raid-muted);

    font-size: 12px;

    font-variant-numeric:
      tabular-nums;

    white-space: nowrap;
  }


  .raid-player-dpm {
    color:
      var(--raid-accent);

    font-weight: 700;
  }


  .raid-player-cast {
    height: 32px;
  }


  .raid-player-cast-icon {
    display: inline-block;

    height: 22px;
    width: 22px;

    object-fit: contain;

    vertical-align: middle;
  }


  .raid-player-cast-maintenance {
    display: inline-block;

    font-size: 19px;
    line-height: 22px;

    vertical-align: middle;
  }


  /*
   * Old grid/mobile rules no longer own
   * the player rows, because they are now
   * real TR/TD elements.
   */
  .raid-player-table .raid-player-row {
    display: table-row;

    min-height: 0;
  }


  @media (max-width: 767px) {

    .raid-player-table {
      min-width: 500px;
    }


    .raid-player-table th,
    .raid-player-table td {
      padding:
        5px 4px;
    }


    .raid-player-table thead th {
      font-size: 9px;
    }


    .raid-player-name,
    .raid-player-point,
    .raid-player-damage,
    .raid-player-dpm,
    .raid-player-percent {
      font-size: 11px;
    }


    .raid-player-th-rank {
      width: 32px;
    }


    .raid-player-th-point {
      width: 70px;
    }


    .raid-player-th-damage {
      width: 52px;
    }


    .raid-player-th-dpm {
      width: 52px;
    }


    .raid-player-th-cast {
      width: 42px;
    }


    .raid-player-th-percent {
      width: 50px;
    }


    .raid-player-cast-icon {
      height: 20px;
      width: 20px;
    }


    .raid-player-cast-maintenance {
      font-size: 17px;
    }
  }



  /* RAID_OBSERVER_REMOVE_CAST_COLUMN_CSS_V3
   *
   * Player table:
   * # / 玩家 / 分數 / 傷害 / DPM / 占比
   *
   * Old cast styles are intentionally harmless/unused.
   */

  .raid-player-table {
    min-width: 510px;
  }

  @media (max-width: 767px) {
    .raid-player-table {
      min-width: 455px;
    }
  }



  /* RAID_OBSERVER_REMOVE_DPM_COLUMN_CSS_V4
   *
   * Final Observer player table:
   * # / 玩家 / 分數 / 傷害 / 占比
   *
   * DPM backend analytics remain available but are no
   * longer presented in the public player table.
   */

  .raid-player-table {
    min-width: 440px;
  }

  @media (max-width: 767px) {
    .raid-player-table {
      min-width: 400px;
    }
  }



  /* RAID_OBSERVER_COMPACT_DECISION_UI_V1
   *
   * Default Raid card = decision information only:
   *
   * Boss / rarity
   * HP
   * abnormal statuses
   * participants
   * remaining time
   * founder
   *
   * Participants detail + Rewards stay collapsed.
   */

  .raid-observer__hero {
    padding: 15px 18px;
    margin-bottom: 11px;
  }

  .raid-observer__title {
    font-size: clamp(24px, 3vw, 32px);
  }

  .raid-stat {
    padding: 7px 11px;
  }

  .raid-stat__value {
    font-size: 16px;
  }

  .raid-observer__section {
    padding: 14px;
    margin-top: 11px;
  }

  .raid-observer__section-heading {
    margin-bottom: 10px;
  }

  .raid-grid {
    gap: 9px;
  }

  .raid-card {
    padding: 11px 12px;
  }

  .raid-card__boss {
    font-size: 17px;
  }

  /*
   * Founder/code are no longer rendered in the header
   * by JS V1, but keep this defensive rule for stale cache.
   */
  .raid-card__header
  .raid-card__founder,
  .raid-card__header
  .raid-card__code {
    display: none;
  }

  .raid-card__badge {
    font-size: 11px;
    padding: 4px 8px;
  }

  .raid-card__hp-row {
    margin-top: 9px;
  }

  .raid-card__hp {
    font-size: 15px;
  }

  .raid-card__bar {
    height: 6px;
    margin-top: 5px;
  }

  .raid-card__meta {
    grid-template-columns:
      minmax(0, 1.35fr)
      minmax(0, .8fr)
      minmax(0, .8fr);

    gap: 7px 10px;
    margin-top: 9px;
  }

  .raid-card__meta-item:first-child {
    grid-column: 1 / -1;
    padding-bottom: 6px;
  }

  .raid-card__meta-label {
    font-size: 10px;
  }

  .raid-card__meta-value {
    font-size: 12px;
  }

  .raid-status-list {
    gap: 4px;
    margin-top: 4px;
  }

  .raid-status-chip {
    gap: 4px;
    min-height: 25px;
    padding: 2px 6px 2px 3px;
  }

  .raid-status-chip.has-no-icon {
    padding-left: 6px;
  }

  .raid-status-icon {
    flex-basis: 20px;
    width: 20px;
    height: 20px;
  }

  .raid-status-text {
    font-size: 11px;
  }

  /*
   * Participants/rewards are secondary information.
   */
  .raid-participants-toggle,
  .raid-card__detail-toggle {
    background: rgba(255,255,255,.035);
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 7px;
    color: var(--raid-text);
    cursor: pointer;
    font: inherit;
    font-size: 11px;
    font-weight: 700;
    padding: 5px 8px;
    text-align: left;
  }

  .raid-participants-toggle:hover,
  .raid-card__detail-toggle:hover {
    background: rgba(184,190,255,.10);
    border-color: rgba(184,190,255,.24);
    color: #fff;
    text-decoration: none;
  }

  .raid-card__participants.is-expanded {
    grid-column: 1 / -1;
  }

  .raid-player-list {
    margin-top: 6px;
  }

  .raid-rewards {
    border-top: 1px solid rgba(255,255,255,.06);
    margin-top: 8px;
    padding-top: 7px;
  }

  .raid-rewards__body {
    padding-top: 8px;
  }

  .raid-rewards__header {
    margin-bottom: 7px;
  }

  /*
   * Desktop decision view:
   * two Raids per row.
   */
  @media (min-width: 1100px) {
    .raid-grid {
      grid-template-columns:
        repeat(2, minmax(0, 1fr));
    }
  }

  /*
   * Mobile remains single-column.
   */
  @media (max-width: 767px) {
    .raid-observer__hero {
      padding: 13px;
    }

    .raid-observer__section {
      padding: 11px;
    }

    .raid-card {
      padding: 10px;
    }

    .raid-card__meta {
      grid-template-columns:
        repeat(2, minmax(0, 1fr));
    }

    .raid-card__meta-item:first-child {
      grid-column: 1 / -1;
    }
  }



  /* RAID_OBSERVER_ENDED_COLLAPSE_V1 */

  .raid-ended-section.is-collapsed {
    padding-bottom: 12px;
  }

  .raid-ended-section.is-collapsed
  .raid-observer__section-heading {
    margin-bottom: 0;
  }

  .raid-ended-toggle {
    align-items: center;
    background: transparent;
    border: 0;
    color: var(--raid-text);
    cursor: pointer;
    display: inline-flex;
    gap: 7px;
    font: inherit;
    margin: 0;
    padding: 2px 0;
    text-align: left;
  }

  .raid-ended-toggle:hover {
    color: #fff;
  }

  .raid-ended-toggle__icon {
    color: var(--raid-accent);
    display: inline-block;
    font-size: 13px;
    width: 14px;
  }

  .raid-ended-toggle__title {
    color: inherit;
    font-size: 20px;
    font-weight: 700;
  }

  .raid-ended-toggle__count {
    align-items: center;
    background: rgba(184,190,255,.11);
    border: 1px solid rgba(184,190,255,.20);
    border-radius: 999px;
    color: var(--raid-accent);
    display: inline-flex;
    font-size: 11px;
    font-weight: 800;
    justify-content: center;
    min-width: 26px;
    padding: 2px 7px;
  }

  .raid-ended-toggle:focus-visible {
    outline: 2px solid rgba(184,190,255,.55);
    outline-offset: 4px;
    border-radius: 4px;
  }



  /* RAID_LOOKUP_HISTORY_REPORT_V1 */
  .raid-history-report {
    background: rgba(15, 18, 28, .72);
    border: 1px solid rgba(184, 190, 255, .18);
    border-radius: 12px;
    margin-top: 14px;
    overflow: hidden;
  }

  .raid-history-report__head {
    align-items: flex-start;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex;
    gap: 12px;
    justify-content: space-between;
    padding: 13px 14px;
  }

  .raid-history-report__title {
    color: #fff;
    font-size: 17px;
    font-weight: 800;
  }

  .raid-history-report__code {
    color: var(--raid-muted);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    margin-top: 3px;
    overflow-wrap: anywhere;
  }

  .raid-history-report__status {
    background: rgba(77,213,153,.11);
    border: 1px solid rgba(77,213,153,.24);
    border-radius: 999px;
    color: var(--raid-green);
    flex: 0 0 auto;
    font-size: 11px;
    font-weight: 800;
    padding: 4px 8px;
  }

  .raid-history-report__facts {
    display: grid;
    gap: 7px 14px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    padding: 12px 14px;
  }

  .raid-history-report__fact {
    display: grid;
    gap: 8px;
    grid-template-columns: 112px minmax(0, 1fr);
  }

  .raid-history-report__label {
    color: var(--raid-muted);
    font-size: 12px;
  }

  .raid-history-report__value {
    color: var(--raid-text);
    font-size: 13px;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
  }

  .raid-history-report__players {
    border-top: 1px solid rgba(255,255,255,.07);
    padding: 12px 14px 14px;
  }

  .raid-history-report__players-title {
    color: #fff;
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 8px;
  }

  .raid-player-pagination {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    justify-content: space-between;
    margin-top: 9px;
  }

  .raid-player-page-summary {
    color: var(--raid-muted);
    font-size: 11px;
  }

  .raid-player-page-buttons {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .raid-player-page-button {
    background: rgba(184,190,255,.075);
    border: 1px solid rgba(184,190,255,.22);
    border-radius: 7px;
    color: #cbd0ff;
    cursor: pointer;
    font: inherit;
    font-size: 11px;
    min-width: 30px;
    padding: 5px 8px;
  }

  .raid-player-page-button:hover:not(:disabled) {
    background: rgba(184,190,255,.15);
    border-color: rgba(184,190,255,.42);
    color: #fff;
  }

  .raid-player-page-button.is-active {
    background: rgba(184,190,255,.22);
    border-color: rgba(184,190,255,.58);
    color: #fff;
    font-weight: 800;
  }

  .raid-player-page-button:disabled {
    cursor: default;
    opacity: .35;
  }

  @media (max-width: 767px) {
    .raid-history-report__facts {
      grid-template-columns: minmax(0, 1fr);
    }

    .raid-history-report__fact {
      grid-template-columns: 104px minmax(0, 1fr);
    }

    .raid-player-pagination {
      align-items: stretch;
      flex-direction: column;
    }

    .raid-player-page-summary {
      text-align: center;
    }

    .raid-player-page-buttons {
      justify-content: center;
    }
  }


  /* RAID_OBSERVER_TWO_ROW_COMPACT_UI_V2 */
  /* RAID_OBSERVER_COMPACT_HP_BAR_V2
   * Restore the original Raid HP progress bar between compact row 1 and row 2.
   */
  .raid-card__compact-shell > .raid-card__bar {
    display: block;
    height: 7px;
    margin: 0 7px;
    border-radius: 999px;
  }

  .raid-card.has-two-row-compact {
    overflow: hidden;
    padding: 0;
  }

  .raid-card__compact-primary,
  .raid-card__compact-secondary {
    align-items: center;
    display: flex;
    min-width: 0;
  }

  .raid-card__compact-primary {
    background: linear-gradient(180deg, rgba(150,153,158,.34), rgba(104,108,116,.28));
    border-bottom: 1px solid rgba(0,0,0,.28);
    min-height: 31px;
    overflow-x: auto;
    scrollbar-width: thin;
  }

  .raid-card__compact-cell {
    align-items: center;
    align-self: stretch;
    border-right: 1px solid rgba(0,0,0,.24);
    color: #f5f6fa;
    display: inline-flex;
    flex: 0 0 auto;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    gap: 4px;
    padding: 5px 9px;
    white-space: nowrap;
  }

  .raid-card__compact-cell:last-child {
    border-right: 0;
  }

  .raid-card__compact-boss {
    color: #fff;
    flex: 1 1 150px;
    font-size: 13px;
    font-weight: 850;
    min-width: 110px;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .raid-card__compact-timer {
    min-width: 84px;
  }

  .raid-card__compact-hp {
    min-width: 122px;
  }

  .raid-card__compact-label {
    color: rgba(255,255,255,.68);
    font-size: 10px;
    font-weight: 800;
  }

  .raid-card__compact-rarity {
    color: #ffe070;
  }

  .raid-card__compact-tier {
    color: #dfe3ff;
  }

  .raid-card__compact-secondary {
    background: rgba(16,19,28,.64);
    gap: 7px;
    min-height: 34px;
    padding: 5px 7px;
  }

  .raid-card__compact-state {
    align-items: center;
    display: flex;
    flex: 1 1 auto;
    gap: 5px;
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: thin;
    white-space: nowrap;
  }

  .raid-card__compact-status-badge {
    border: 1px solid rgba(111,168,255,.24);
    border-radius: 999px;
    color: #bcd6ff;
    flex: 0 0 auto;
    font-size: 10px;
    font-weight: 850;
    padding: 3px 7px;
  }

  .is-critical .raid-card__compact-status-badge {
    border-color: rgba(255,107,107,.34);
    color: #ffabab;
  }

  .is-defeated .raid-card__compact-status-badge {
    border-color: rgba(77,213,153,.34);
    color: #8ee9be;
  }

  .is-ended .raid-card__compact-status-badge,
  .is-expired .raid-card__compact-status-badge {
    border-color: rgba(174,181,201,.25);
    color: #b9c0d3;
  }

  .raid-card__compact-state-label {
    color: var(--raid-muted);
    flex: 0 0 auto;
    font-size: 10px;
    font-weight: 800;
  }

  .raid-card__compact-state .raid-status-chip {
    flex: 0 0 auto;
    gap: 3px;
    min-height: 23px;
    padding: 1px 5px 1px 2px;
  }

  .raid-card__compact-state .raid-status-chip.has-no-icon {
    padding-left: 5px;
  }

  .raid-card__compact-state .raid-status-icon {
    flex-basis: 18px;
    height: 18px;
    width: 18px;
  }

  .raid-card__compact-state .raid-status-text {
    font-size: 10px;
  }

  .raid-card__compact-secondary-meta {
    color: #d9ddea;
    flex: 0 0 auto;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  .raid-card__compact-founder {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .raid-card__compact-more {
    background: rgba(184,190,255,.09);
    border: 1px solid rgba(184,190,255,.20);
    border-radius: 7px;
    color: #e9ebff;
    cursor: pointer;
    flex: 0 0 auto;
    font: inherit;
    font-size: 10px;
    font-weight: 850;
    padding: 4px 7px;
    white-space: nowrap;
  }

  .raid-card__compact-more:hover {
    background: rgba(184,190,255,.17);
    border-color: rgba(184,190,255,.38);
    color: #fff;
  }

  .raid-card__compact-details {
    border-top: 1px solid rgba(255,255,255,.06);
    padding: 8px 10px 10px;
  }

  .raid-card__compact-details > .raid-card__header,
  .raid-card__compact-details > .raid-card__hp-row,
  .raid-card__compact-details > .raid-card__bar {
    display: none;
  }

  .raid-card__compact-details
  > .raid-card__meta
  > .raid-card__meta-item:not(.raid-card__participants) {
    display: none;
  }

  .raid-card__compact-details > .raid-card__meta {
    display: block;
    margin-top: 0;
  }

  .raid-card__compact-details .raid-card__participants {
    margin-top: 0;
  }

  .raid-card__compact-details .raid-rewards {
    margin-top: 8px;
  }

  @media (max-width: 767px) {
    .raid-card.has-two-row-compact {
      padding: 0;
    }

    .raid-card__compact-cell {
      padding-left: 7px;
      padding-right: 7px;
    }

    .raid-card__compact-boss {
      flex-basis: 125px;
      min-width: 100px;
    }

    .raid-card__compact-secondary {
      gap: 5px;
      padding-left: 6px;
      padding-right: 6px;
    }

    .raid-card__compact-founder {
      max-width: 92px;
    }
  }


  /* RAID_OBSERVER_HP_BAR_ULGG_RED_V1
   * Muted UL.GG rose-red HP bar.
   * Normal HP stays restrained; critical HP is only one step brighter.
   */
  .raid-card__bar {
    background: rgba(103, 45, 58, .20);
  }

  .raid-card__bar span {
    background: linear-gradient(
      90deg,
      #93485a 0%,
      #b75b69 55%,
      #c96b76 100%
    );
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,.08);
  }

  .is-critical .raid-card__bar span {
    background: linear-gradient(
      90deg,
      #a64c59 0%,
      #c85d68 55%,
      #dc7079 100%
    );
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,.10),
      0 0 6px rgba(200,93,104,.12);
  }


  /* RAID_OBSERVER_STATUS_ICON_ONLY_OVER_6_V1 */
  .raid-card__compact-state.is-icon-only {
    gap: 2px;
    overflow-x: hidden;
  }

  .raid-card__compact-state.is-icon-only .raid-status-chip {
    border-radius: 5px;
    gap: 0;
    min-height: 19px;
    padding: 1px;
  }

  .raid-card__compact-state.is-icon-only .raid-status-icon {
    flex-basis: 40px;
    height: 20px;
    width: 20px;
  }

  .raid-card__compact-state.is-icon-only .raid-status-text {
    display: none;
  }

  @media (max-width: 767px) {
    .raid-card__compact-state.is-icon-only {
      gap: 1px;
    }

    .raid-card__compact-state.is-icon-only .raid-status-chip {
      min-height: 17px;
      padding: 0;
    }

    .raid-card__compact-state.is-icon-only .raid-status-icon {
      flex-basis: 15px;
      height: 15px;
      width: 15px;
    }
  }

</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container raid-observer-page"
      data-raid-observer
      data-endpoint="/api/observed_raids.php"
      data-raid-stats-admin="<?= $isRaidStatsAdmin ? '1' : '0' ?>">
      <header class="raid-observer__hero">
        <div>
          <p class="raid-observer__eyebrow">PUBLIC RAID MONITOR</p>
          <h1 class="raid-observer__title">觀察渦</h1>
        </div>
        <div class="raid-observer__summary" aria-label="Raid 觀測摘要">
          <span class="raid-connection is-stale" data-role="connection">連線中</span>
          <div class="raid-stat">
            <span class="raid-stat__label">觀測中</span>
            <strong class="raid-stat__value" data-role="active-count">—</strong>
          </div>
          <div class="raid-stat">
            <span class="raid-stat__label">最近結束</span>
            <strong class="raid-stat__value" data-role="ended-count">—</strong>
          </div>
          <div class="raid-stat">
            <span class="raid-stat__label">最後更新</span>
            <strong class="raid-stat__value" data-role="updated-age">尚未更新</strong>
          </div>
        </div>
      </header>

      <p class="raid-stale-notice" data-role="stale-notice" role="status" hidden>
        資料可能已過期；目前保留最後一次成功取得的狀態，不會將 Raid 判定為結束。
      </p>

      <section class="raid-observer__section" aria-labelledby="active-raids-title">
        <div class="raid-observer__section-heading">
          <h2 id="active-raids-title">目前狀態</h2>
          <span>每 30 秒更新</span>
        </div>
        <div class="raid-grid" data-role="active-grid" aria-live="polite">
          <div class="raid-empty">正在取得 Raid 狀態…</div>
        </div>
      </section>

      <section class="raid-observer__section raid-lookup" aria-labelledby="raid-lookup-title">
        <div class="raid-observer__section-heading">
          <h2 id="raid-lookup-title">TL / 渦碼查詢</h2>
          <span>可輸入 TL 或歷史公開渦碼</span>
        </div>

        <form class="raid-lookup__form" method="get" action="">
          <input
            class="raid-lookup__input"
            type="text"
            name="raid_lookup"
            value="<?= htmlspecialchars($raidLookupQuery, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="例如：2088 或 G2ZNkfZSOhKf"
            autocomplete="off"
          >
          <button class="raid-lookup__button" type="submit">查詢</button>
        </form>

        <?php if ($raidLookupError !== null): ?>
          <div class="raid-lookup__error">
            <?= htmlspecialchars($raidLookupError, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <?php if ($raidLookupResult !== null && $raidLookupResult['reward'] !== null): ?>
          <?php
            $lookupHistory = $raidLookupResult['history'];
            $lookupReward = $raidLookupResult['reward'];
            $lookupTl = (int)$raidLookupResult['treasure_level'];

            $rewardSections = [
                '發現獎勵' => $lookupReward['discovery'] ?? [],
                '參加獎勵' => $lookupReward['participation'] ?? [],
                '排名獎勵' => $lookupReward['ranking'] ?? [],
                '擊破獎勵' => $lookupReward['defeat'] ?? [],
            ];
          ?>
          <div class="raid-lookup__result">
            <div class="raid-lookup__headline">
              TL <?= $lookupTl ?> ·
              <?= htmlspecialchars((string)($lookupReward['bossDisplayName'] ?? '未知 Boss'), ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="raid-lookup__meta">
              星級 <?= htmlspecialchars((string)($lookupReward['rarity'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
              ｜Map Level <?= htmlspecialchars((string)($lookupReward['mapLevel'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
              ｜<?= htmlspecialchars(
    str_replace('涡', '渦', (string)($lookupReward['whirlpoolTier'] ?? '未知渦階')),
    ENT_QUOTES,
    'UTF-8'
) ?>
              <?php if (is_array($lookupHistory)): ?>
                ｜渦主 <?= htmlspecialchars((string)($lookupHistory['founder'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
              <?php endif; ?>
            </div>

            <div class="raid-lookup__reward-grid">
              <?php foreach ($rewardSections as $sectionTitle => $items): ?>
                <div class="raid-lookup__reward-box">
                  <div class="raid-lookup__reward-title">
                    <?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?>
                  </div>

                  <?php if (is_array($items) && $items): ?>
                    <?php foreach ($items as $item): ?>
                      <?php
                        $itemName = (string)($item['itemName'] ?? '未知獎勵');
                        $quantity = $item['quantity'] ?? null;
                        $rankMin = $item['rankMin'] ?? null;
                        $rankMax = $item['rankMax'] ?? null;

                        $suffix = '';
                        if ($quantity !== null) {
                            $suffix .= ' ×' . (string)$quantity;
                        }
                        if ($rankMin !== null || $rankMax !== null) {
                            $suffix .= '（排名 ' .
                                (string)($rankMin ?? '?') . '–' .
                                (string)($rankMax ?? '?') . '）';
                        }
                      ?>
                      <div class="raid-lookup__reward-item">
                        <?= htmlspecialchars($itemName . $suffix, ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="raid-lookup__reward-item">—</div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (is_array($raidLookupHistoryReport)): ?>
          <?php
            $reportRaid = $raidLookupHistoryReport['raid'];
            $reportPlayers = $raidLookupHistoryReport['players'];

            // RAID_OBSERVER_HISTORY_POINT_MAIN_V1
            $reportPlayerCount = count($reportPlayers);

            $reportTotalPoint = 0;
            foreach ($reportPlayers as $reportPlayer) {
                $reportTotalPoint += max(
                    0,
                    (int)($reportPlayer['point'] ?? 0)
                );
            }
            // RAID_OBSERVER_LEGACY_DAMAGE_GATE_V1
            $reportDamageCutoff =
                '2026-09-22 10:00:00';

            $reportLastSeenKey = substr(
                str_replace(
                    'T',
                    ' ',
                    trim(
                        (string)(
                            $reportRaid['last_seen_at']
                            ?? ''
                        )
                    )
                ),
                0,
                19
            );

            $reportLegacyDamageAvailable =
                $reportLastSeenKey !== ''
                && $reportLastSeenKey
                    < $reportDamageCutoff;

            $reportLegacyTotalDamage = 0;

            if ($reportLegacyDamageAvailable) {
                foreach ($reportPlayers as $reportPlayer) {
                    $reportLegacyTotalDamage += max(
                        0,
                        (int)(
                            $reportPlayer['damage']
                            ?? 0
                        )
                    );
                }
            }

            $reportRarity = isset($reportRaid['rarity'])
                ? (int)$reportRaid['rarity']
                : null;

            $reportTier = str_replace(
                '涡',
                '渦',
                (string)($reportRaid['whirlpool_tier'] ?? '—')
            );

            $reportEnded = trim((string)($reportRaid['ended_at'] ?? '')) !== '';

            $reportBoss = trim((string)($reportRaid['boss'] ?? ''));
            if ($reportBoss === '') {
                $reportBoss = '未知 Boss';
            }

            $reportFounder = trim((string)($reportRaid['founder'] ?? ''));
            if ($reportFounder === '') {
                $reportFounder = '—';
            }
          ?>

          <div
            class="raid-history-report"
            data-history-raid-report
          >
            <div class="raid-history-report__head">
              <div>
                <div class="raid-history-report__title">
                  📜 單場戰報 ·
                  <?= htmlspecialchars($reportBoss, ENT_QUOTES, 'UTF-8') ?>
                  <?php if ($reportRarity !== null): ?>
                    ⭐<?= $reportRarity ?>
                  <?php endif; ?>
                </div>

                <div class="raid-history-report__code">
                  <?= htmlspecialchars((string)$reportRaid['raid_id'], ENT_QUOTES, 'UTF-8') ?>
                </div>
              </div>

              <span class="raid-history-report__status">
                <?= $reportEnded ? '已結束' : '歷史觀測中' ?>
              </span>
            </div>

            <div class="raid-history-report__facts">
              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">渦主</span>
                <span class="raid-history-report__value">
                  <?= htmlspecialchars($reportFounder, ENT_QUOTES, 'UTF-8') ?>
                </span>
              </div>

              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">渦階</span>
                <span class="raid-history-report__value">
                  <?= htmlspecialchars($reportTier, ENT_QUOTES, 'UTF-8') ?>
                </span>
              </div>

              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">HP 上限</span>
                <span class="raid-history-report__value">
                  <?= number_format((int)($reportRaid['hp_max'] ?? 0)) ?>
                </span>
              </div>

              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">最高同時參戰</span>
                <span class="raid-history-report__value">
                  <?= number_format((int)($reportRaid['participant_count'] ?? 0)) ?>
                  /
                  <?= number_format((int)($reportRaid['member_limit'] ?? 0)) ?>
                </span>
              </div>

              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">累積觀測玩家</span>
                <span class="raid-history-report__value">
                  <?= number_format($reportPlayerCount) ?>
                </span>
              </div>

              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">觀測時間</span>
                <span class="raid-history-report__value">
                  <?= htmlspecialchars(
                      raidLookupHistoryDuration(
                          $reportRaid['first_seen_at'] ?? null,
                          $reportRaid['last_seen_at'] ?? null
                      ),
                      ENT_QUOTES,
                      'UTF-8'
                  ) ?>
                </span>
              </div>

              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">首次觀測</span>
                <span class="raid-history-report__value">
                  <?= htmlspecialchars(
                      raidLookupHistoryDate($reportRaid['first_seen_at'] ?? null),
                      ENT_QUOTES,
                      'UTF-8'
                  ) ?>
                </span>
              </div>

              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">最後觀測</span>
                <span class="raid-history-report__value">
                  <?= htmlspecialchars(
                      raidLookupHistoryDate($reportRaid['last_seen_at'] ?? null),
                      ENT_QUOTES,
                      'UTF-8'
                  ) ?>
                </span>
              </div>

              <div class="raid-history-report__fact">
                <span class="raid-history-report__label">結束記錄</span>
                <span class="raid-history-report__value">
                  <?= htmlspecialchars(
                      raidLookupHistoryDate($reportRaid['ended_at'] ?? null),
                      ENT_QUOTES,
                      'UTF-8'
                  ) ?>
                </span>
              </div>
            </div>

            <div class="raid-history-report__players">
              <div class="raid-history-report__players-title">
                👥 玩家 POINT
                <?= number_format($reportPlayerCount) ?>
                人 ▲
              </div>

              <?php if ($reportPlayerCount > 0): ?>
                <div class="raid-player-list">
                  <table class="raid-player-table">
                    <thead>
                      <tr>
                        <th class="raid-player-th raid-player-th-rank">#</th>
                        <th class="raid-player-th raid-player-th-name">玩家</th>
                        <th class="raid-player-th raid-player-th-point">POINT</th>
                        <th class="raid-player-th raid-player-th-percent">POINT占比</th>
                      </tr>
                    </thead>

                    <tbody>
                      <?php foreach ($reportPlayers as $index => $player): ?>
                        <?php
                          $rank = isset($player['rank_no']) && is_numeric($player['rank_no'])
                              ? (int)$player['rank_no']
                              : $index + 1;

                          $point = max(
                              0,
                              (int)($player['point'] ?? 0)
                          );

                          $pointPercent = $reportTotalPoint > 0
                              ? ($point / $reportTotalPoint) * 100
                              : 0.0;
                        ?>
                        <tr
                          class="raid-player-row"
                          data-history-player-row
                          <?= $index >= 20 ? 'hidden' : '' ?>
                        >
                          <td class="raid-player-rank">
                            #<?= $rank ?>
                          </td>
                          <td class="raid-player-name">
                            <?= htmlspecialchars(
                                (string)($player['player_name'] ?? '未知玩家'),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                          </td>
                          <td class="raid-player-point">
                            <?= number_format($point) ?>
                          </td>
                          <td class="raid-player-percent">
                            <?= number_format($pointPercent, 1) ?>%
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="raid-player-pagination">
                  <span
                    class="raid-player-page-summary"
                    data-history-player-page-summary
                  >
                    共<?= number_format($reportPlayerCount) ?>人
                    ｜第1/<?= max(1, (int)ceil($reportPlayerCount / 20)) ?>頁
                    ｜顯示1–<?= min(20, $reportPlayerCount) ?>
                  </span>

                  <div
                    class="raid-player-page-buttons"
                    data-history-player-page-buttons
                  ></div>
                </div>

                <!-- RAID_OBSERVER_LEGACY_DAMAGE_UI_V1 -->

                <div
                  class="raid-stats-summary"
                  style="
                    margin-top:10px;
                    line-height:1.6;
                  "
                >
                  2026/09/22 10:00 維修後官方不再提供 Damage，
                  現行戰報改以 POINT 顯示。
                </div>

                <?php if ($reportLegacyDamageAvailable): ?>

                  <details
                    class="raid-history-damage-archive"
                    style="
                      margin-top:14px;
                      padding-top:12px;
                      border-top:
                        1px solid
                        rgba(255,255,255,.08);
                    "
                  >
                    <summary
                      style="
                        cursor:pointer;
                        font-weight:700;
                      "
                    >
                      舊版傷害紀錄
                    </summary>

                    <div
                      class="raid-stats-summary"
                      style="
                        margin:8px 0 10px;
                        line-height:1.6;
                      "
                    >
                      2026/09/22 10:00 前官方 Damage
                    </div>

                    <div class="raid-player-list">
                      <table class="raid-player-table">
                        <thead>
                          <tr>
                            <th class="raid-player-th raid-player-th-rank">
                              #
                            </th>

                            <th class="raid-player-th raid-player-th-name">
                              玩家
                            </th>

                            <th class="raid-player-th raid-player-th-damage">
                              Damage
                            </th>

                            <th class="raid-player-th raid-player-th-percent">
                              傷害占比
                            </th>
                          </tr>
                        </thead>

                        <tbody>
                          <?php foreach (
                              $reportPlayers
                              as $index => $player
                          ): ?>
                            <?php
                              $legacyRank =
                                  isset($player['rank_no'])
                                  && is_numeric($player['rank_no'])
                                  ? (int)$player['rank_no']
                                  : $index + 1;

                              $legacyDamage = max(
                                  0,
                                  (int)(
                                      $player['damage']
                                      ?? 0
                                  )
                              );

                              $legacyPercent =
                                  $reportLegacyTotalDamage > 0
                                  ? (
                                      $legacyDamage
                                      / $reportLegacyTotalDamage
                                    ) * 100
                                  : 0.0;
                            ?>

                            <tr class="raid-player-row">
                              <td class="raid-player-rank">
                                #<?= $legacyRank ?>
                              </td>

                              <td class="raid-player-name">
                                <?= htmlspecialchars(
                                    (string)(
                                        $player['player_name']
                                        ?? '未知玩家'
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                              </td>

                              <td class="raid-player-damage">
                                <?= number_format($legacyDamage) ?>
                              </td>

                              <td class="raid-player-percent">
                                <?= number_format(
                                    $legacyPercent,
                                    1
                                ) ?>%
                              </td>
                            </tr>

                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </details>

                <?php endif; ?>

              <?php else: ?>
                <div class="raid-empty">
                  這場 Raid 尚無玩家歷史資料。
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      </section>

      <section
        class="raid-observer__section raid-ended-section is-collapsed"
        aria-labelledby="ended-raids-title"
        data-role="ended-section"
      >
        <div class="raid-observer__section-heading">

          <button
            type="button"
            class="raid-ended-toggle"
            data-role="ended-toggle"
            aria-expanded="false"
            aria-controls="raid-ended-body"
          >
            <span
              class="raid-ended-toggle__icon"
              data-role="ended-toggle-icon"
              aria-hidden="true"
            >▶</span>

            <span
              class="raid-ended-toggle__title"
              id="ended-raids-title"
            >
              最近結束
            </span>

            <span
              class="raid-ended-toggle__count"
              data-role="ended-toggle-count"
            >—</span>
          </button>

          <span>點擊查看近期結束 Raid</span>
        </div>

        <div
          id="raid-ended-body"
          data-role="ended-body"
          hidden
        >
          <div
            class="raid-grid"
            data-role="ended-grid"
            aria-live="polite"
          >
            <div class="raid-empty">
              正在取得最近結束紀錄…
            </div>
          </div>
        </div>
      </section>
    </div>
  </section>
</div>

<script
  src="/assets/js/raid_status_icons.js?v=<?= filemtime(__DIR__ . '/../assets/js/raid_status_icons.js') ?>"
  defer
></script>
<script
  src="/assets/js/raid_observer.js?v=<?= filemtime(__DIR__ . '/../assets/js/raid_observer.js') ?>"
  defer
></script>

<!-- RAID_VICTIM_ASSOCIATION_UI_V1 -->
<script
  src="/assets/js/raid_victim_association.js?v=<?= filemtime(__DIR__ . '/../assets/js/raid_victim_association.js') ?>"
  defer
></script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
