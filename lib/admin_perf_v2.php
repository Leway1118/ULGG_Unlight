<?php
declare(strict_types=1);

/**
 * UL.GG Admin Dashboard Performance V2.
 * Read-only, rolling 15/30-minute metrics from visitors. No schema changes.
 * 'Human candidate' is not verified-human identity.
 */
if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

function ulggPerfV2NewGroup(): array
{
    return [
        'requests' => 0,
        'human_candidates' => 0,
        'ua_bots' => 0,
        'suspicious' => 0,
        'googlebot_ua' => 0,
        'latencies' => [],
    ];
}

function ulggPerfV2Record(array &$group, array $row): void
{
    ++$group['requests'];
    $ua = (string)($row['user_agent'] ?? '');
    $isBot = (int)($row['is_bot'] ?? 0) === 1;
    $page = (string)($row['page'] ?? '');
    $suspicious = !$isBot
        && $page === '/pages/team_analysis.php'
        && str_contains($ua, 'Chrome/124.0.6367.207');

    if (stripos($ua, 'Googlebot') !== false) {
        ++$group['googlebot_ua'];
    }
    if ($isBot) {
        ++$group['ua_bots'];
    } elseif ($suspicious) {
        ++$group['suspicious'];
    } else {
        ++$group['human_candidates'];
        $ms = $row['response_ms'] ?? null;
        if (is_numeric($ms) && (float)$ms >= 0) {
            $group['latencies'][] = (float)$ms;
        }
    }
}

function ulggPerfV2FinishGroup(array $group): array
{
    $values = $group['latencies'];
    $count = count($values);
    sort($values, SORT_NUMERIC);
    $slow1 = 0;
    $slow3 = 0;
    foreach ($values as $ms) {
        if ($ms >= 1000) {
            ++$slow1;
        }
        if ($ms >= 3000) {
            ++$slow3;
        }
    }

    unset($group['latencies']);
    $group['measured'] = $count;
    $group['avg_ms'] = $count ? (int)round(array_sum($values) / $count) : null;
    $group['p95_ms'] = $count ? (int)ceil($values[max(0, (int)ceil($count * 0.95) - 1)]) : null;
    $group['max_ms'] = $count ? (int)ceil($values[$count - 1]) : null;
    $group['slow_1000'] = $slow1;
    $group['slow_3000'] = $slow3;
    $group['slow_1000_rate'] = $count ? round($slow1 / $count * 100, 1) : null;
    $group['slow_3000_rate'] = $count ? round($slow3 / $count * 100, 1) : null;
    return $group;
}

function ulggPerfV2Aggregate(array $rows, int $nowTimestamp, int $limit = 10000): array
{
    $truncated = count($rows) > $limit;
    if ($truncated) {
        $rows = array_slice($rows, 0, $limit);
    }
    $windows = [15 => ulggPerfV2NewGroup(), 30 => ulggPerfV2NewGroup()];
    $pages = [];
    $cutoff30 = $nowTimestamp - 1800;
    $cutoff15 = $nowTimestamp - 900;

    foreach ($rows as $row) {
        $visitedAt = strtotime((string)($row['visited_at'] ?? ''));
        if ($visitedAt === false || $visitedAt < $cutoff30 || $visitedAt > $nowTimestamp + 60) {
            continue;
        }
        ulggPerfV2Record($windows[30], $row);
        if ($visitedAt >= $cutoff15) {
            ulggPerfV2Record($windows[15], $row);
        }
        $page = (string)($row['page'] ?? '') ?: '(unknown)';
        if (!isset($pages[$page])) {
            $pages[$page] = ulggPerfV2NewGroup();
        }
        ulggPerfV2Record($pages[$page], $row);
    }
    $pageRows = [];
    foreach ($pages as $page => $group) {
        $finished = ulggPerfV2FinishGroup($group);
        $finished['page'] = $page;
        $pageRows[] = $finished;
    }
    usort($pageRows, static fn(array $a, array $b): int =>
        ($b['requests'] <=> $a['requests']) ?: strcmp($a['page'], $b['page'])
    );
    return [
        'created_at' => date('Y-m-d H:i:s', $nowTimestamp),
        'truncated' => $truncated,
        'limit' => $limit,
        'windows' => [
            '15' => ulggPerfV2FinishGroup($windows[15]),
            '30' => ulggPerfV2FinishGroup($windows[30]),
        ],
        'pages_30m' => array_slice($pageRows, 0, 16),
        'page_count' => count($pageRows),
    ];
}

function ulggPerfV2Fetch(PDO $pdo, int $limit = 10000): array
{
    $limit = max(100, min(10000, $limit));
    $now = time();
    $since = date('Y-m-d H:i:s', $now - 1800);
    // database/schema.sql declares INDEX visited_at. Always verify live index
    // before deployment. Hard limit guards memory during spikes.
    $statement = $pdo->prepare(
        'SELECT visited_at, page, is_bot, user_agent, response_ms
         FROM visitors
         WHERE visited_at >= :since
         ORDER BY visited_at DESC
         LIMIT ' . ($limit + 1)
    );
    $statement->execute([':since' => $since]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return ulggPerfV2Aggregate($rows, $now, $limit);
}

function ulggPerfV2Rate(array $metric, string $key): string
{
    return ($metric[$key] ?? null) === null ? '—' : number_format((float)$metric[$key], 1) . '%';
}
