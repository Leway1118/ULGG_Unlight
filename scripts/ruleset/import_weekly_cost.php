<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../api/get_steam_unlight_news.php';

const PARSER_VERSION = 'steam-cost-v2';

function parseWeeklyCostNews(array $news): array
{
    $contents = html_entity_decode(
        strip_tags(preg_replace('/<[^>]+>|\[\/?(?:p|br|b|i|u|h[1-6]|list|olist|\*)\]/i', ' ', (string)($news['contents'] ?? ''))),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $patterns = [
        'ALEXANDRE' => '/《Alexandre》\s*Category\s*1:\s*COST\s*(\d+)\s*Category\s*2:\s*COST\s*(\d+)\s*Category\s*3:\s*COST\s*(\d+)\s*Category\s*4:\s*COST\s*(\d+)\+/iu',
        'FORNHEIL' => '/《Fornheil》\s*Category\s*1:\s*COST\s*(\d+)\s*Category\s*2:\s*COST\s*(\d+)\s*Category\s*3:\s*COST\s*(\d+)\s*Category\s*4:\s*COST\s*(\d+)\+/iu',
    ];

    $result = [];

    foreach ($patterns as $zoneCode => $pattern) {
        if (preg_match($pattern, $contents, $matches) !== 1) {
            continue;
        }

        $result[$zoneCode] = [
            'cost1' => (int)$matches[1],
            'cost2' => (int)$matches[2],
            'cost3' => (int)$matches[3],
            'cost4' => (int)$matches[4],
        ];
    }

    return $result;
}

function extractEffectiveDate(array $news): string
{
    $title = (string)($news['title'] ?? '');

    if (
        preg_match(
            '/\((\d{4})\/(\d{2})\/(\d{2})\)/',
            $title,
            $matches
        ) === 1
    ) {
        return sprintf(
            '%04d-%02d-%02d',
            (int)$matches[1],
            (int)$matches[2],
            (int)$matches[3]
        );
    }

    $timestamp = (int)($news['date'] ?? 0);

    if ($timestamp <= 0) {
        throw new RuntimeException(
            'Unable to determine effective date.'
        );
    }

    return date('Y-m-d', $timestamp);
}

$newsItems = getUnlightSteamNews(10, 0);

if ($newsItems === []) {
    fwrite(STDERR, "No Steam news was returned.\n");
    exit(1);
}

$insertSql = "
    INSERT INTO quickmatch_weekly_cost (
        ladder_zone_code,
        effective_date,
        cost1,
        cost2,
        cost3,
        cost4,
        cost4_operator,
        source_gid,
        source_title,
        source_url,
        source_published_at,
        import_status,
        matchmaking_enabled,
        parser_version
    ) VALUES (
        :ladder_zone_code,
        :effective_date,
        :cost1,
        :cost2,
        :cost3,
        :cost4,
        'MIN',
        :source_gid,
        :source_title,
        :source_url,
        :source_published_at,
        :import_status,
        :matchmaking_enabled,
        :parser_version
    )
    ON DUPLICATE KEY UPDATE
        import_status = IF(import_status = 'DETECTED'
            AND VALUES(import_status) = 'APPROVED'
            AND cost1 = VALUES(cost1) AND cost2 = VALUES(cost2)
            AND cost3 = VALUES(cost3) AND cost4 = VALUES(cost4)
            AND cost4_operator = VALUES(cost4_operator)
            AND effective_date = VALUES(effective_date)
            AND ladder_zone_code = VALUES(ladder_zone_code)
            AND source_gid = VALUES(source_gid), 'APPROVED', import_status),
        parser_version = IF(import_status = 'APPROVED'
            AND VALUES(import_status) = 'APPROVED'
            AND cost1 = VALUES(cost1) AND cost2 = VALUES(cost2)
            AND cost3 = VALUES(cost3) AND cost4 = VALUES(cost4)
            AND cost4_operator = VALUES(cost4_operator)
            AND effective_date = VALUES(effective_date)
            AND ladder_zone_code = VALUES(ladder_zone_code)
            AND source_gid = VALUES(source_gid), VALUES(parser_version), parser_version),
        source_title = IF(source_gid = VALUES(source_gid) AND cost1 = VALUES(cost1) AND cost2 = VALUES(cost2) AND cost3 = VALUES(cost3) AND cost4 = VALUES(cost4), VALUES(source_title), source_title),
        source_url = IF(source_gid = VALUES(source_gid) AND cost1 = VALUES(cost1) AND cost2 = VALUES(cost2) AND cost3 = VALUES(cost3) AND cost4 = VALUES(cost4), VALUES(source_url), source_url),
        source_published_at = IF(source_gid = VALUES(source_gid) AND cost1 = VALUES(cost1) AND cost2 = VALUES(cost2) AND cost3 = VALUES(cost3) AND cost4 = VALUES(cost4), VALUES(source_published_at), source_published_at),
        updated_at = CURRENT_TIMESTAMP
";

$stmt = $db->prepare($insertSql);
// Read back persisted records by both natural identifiers; rowCount alone
// cannot distinguish accepted values from a duplicate-key conflict.
$readBack = $db->prepare("SELECT id, ladder_zone_code, effective_date,
    cost1, cost2, cost3, cost4, cost4_operator, source_gid,
    import_status, matchmaking_enabled
    FROM quickmatch_weekly_cost
    WHERE ladder_zone_code = :zone
      AND (effective_date = :effective OR source_gid = :gid)
    ORDER BY id");
$verified = 0;
$conflicts = 0;
$unverified = 0;

foreach ($newsItems as $news) {
    $contents = (string)($news['contents'] ?? '');

    if (
        preg_match(
            '/COST|ＣＯＳＴ|コスト/u',
            $contents
        ) !== 1
    ) {
        continue;
    }

    $profiles = parseWeeklyCostNews($news);

    if ($profiles === []) {
        continue;
    }

    $effectiveDate = extractEffectiveDate($news);
    if (!checkdate((int)substr($effectiveDate,5,2), (int)substr($effectiveDate,8,2), (int)substr($effectiveDate,0,4))) continue;
    $title = (string)($news['title'] ?? '');
    $isConfirmed = preg_match('/^Maintenance Information\s*\(\d{4}\/\d{2}\/\d{2}\)/i', $title) === 1;
    // Post-closure, Alexandre alone is sufficient. Historical dual-zone policy remains.
    if (!$isConfirmed) continue;
    $isComplete = isset($profiles['ALEXANDRE'])
        && ($effectiveDate >= '2026-09-22' || isset($profiles['FORNHEIL']));
    if ($effectiveDate >= '2026-09-22') {
        unset($profiles['FORNHEIL']);
    }

    $sourceGid = (string)($news['gid'] ?? '');

    if ($sourceGid === '') {
        fwrite(
            STDERR,
            "Skipped news without gid: "
            . ($news['title'] ?? 'unknown')
            . "\n"
        );
        continue;
    }

    foreach ($profiles as $zoneCode => $costs) {
        $valid = min($costs) > 0 && count(array_unique($costs)) === 4
            && $costs['cost4'] > max($costs['cost1'], $costs['cost2'], $costs['cost3']);
        $status = $isComplete && $valid ? 'APPROVED' : 'DETECTED';
        $stmt->execute([
            ':ladder_zone_code' => $zoneCode,
            ':effective_date' => $effectiveDate,
            ':cost1' => $costs['cost1'],
            ':cost2' => $costs['cost2'],
            ':cost3' => $costs['cost3'],
            ':cost4' => $costs['cost4'],
            ':source_gid' => $sourceGid,
            ':source_title' => (string)($news['title'] ?? ''),
            ':source_url' => (string)($news['url'] ?? ''),
            ':source_published_at' => date(
                'Y-m-d H:i:s',
                (int)($news['date'] ?? time())
            ),
            ':import_status' => $status,
            ':matchmaking_enabled' =>
                $zoneCode === 'ALEXANDRE' ? 1 : 0,
            ':parser_version' => PARSER_VERSION,
        ]);

        $readBack->execute([
            ':zone' => $zoneCode,
            ':effective' => $effectiveDate,
            ':gid' => $sourceGid,
        ]);
        $storedRows = $readBack->fetchAll(PDO::FETCH_ASSOC);
        $prefix = sprintf('%s %s requested=%d/%d/%d/%d requested_status=%s',
            $effectiveDate, $zoneCode, $costs['cost1'], $costs['cost2'],
            $costs['cost3'], $costs['cost4'], $status);
        if (count($storedRows) !== 1) {
            $unverified++;
            echo $prefix . ' outcome=UNVERIFIED matches=' . count($storedRows) . PHP_EOL;
            continue;
        }
        $stored = $storedRows[0];
        $sameRule = (string)$stored['effective_date'] === $effectiveDate
            && (string)$stored['ladder_zone_code'] === $zoneCode
            && (string)$stored['source_gid'] === $sourceGid
            && (string)$stored['cost4_operator'] === 'MIN'
            && (int)$stored['matchmaking_enabled'] === ($zoneCode === 'ALEXANDRE' ? 1 : 0);
        foreach ($costs as $key => $value) {
            $sameRule = $sameRule && (int)$stored[$key] === $value;
        }
        $sameStatus = (string)$stored['import_status'] === $status;
        if ($sameRule && $sameStatus) {
            $verified++;
            $outcome = 'VERIFIED';
        } else {
            $conflicts++;
            $outcome = 'CONFLICT';
        }
        echo sprintf('%s outcome=%s stored_id=%d stored_date=%s stored_gid=%s stored=%d/%d/%d/%d stored_operator=%s stored_enabled=%d stored_status=%s',
            $prefix, $outcome, (int)$stored['id'], $stored['effective_date'],
            $stored['source_gid'], $stored['cost1'], $stored['cost2'],
            $stored['cost3'], $stored['cost4'], $stored['cost4_operator'],
            $stored['matchmaking_enabled'], $stored['import_status']) . PHP_EOL;
    }
}

echo "Verified {$verified} rows; conflicts {$conflicts}; unverified {$unverified}.\n";