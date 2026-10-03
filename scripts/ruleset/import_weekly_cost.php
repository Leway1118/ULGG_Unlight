<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../api/get_steam_unlight_news.php';

const PARSER_VERSION = 'steam-cost-v1';

function parseWeeklyCostNews(array $news): array
{
    $contents = html_entity_decode(
        strip_tags((string)($news['contents'] ?? '')),
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

$newsItems = getUnlightSteamNews(10);

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
        source_title = VALUES(source_title),
        source_url = VALUES(source_url),
        source_published_at = VALUES(source_published_at),
        updated_at = CURRENT_TIMESTAMP
";

$stmt = $db->prepare($insertSql);
$imported = 0;

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

    /*
     * 只有同時完整解析 Alexandre 與 Fornheil，
     * 才自動核准。
     */
    $isComplete =
        isset($profiles['ALEXANDRE'])
        && isset($profiles['FORNHEIL'])
        && count($profiles) === 2;

    $status = $isComplete
        ? 'APPROVED'
        : 'DETECTED';

    $effectiveDate = extractEffectiveDate($news);
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

        $imported++;

        echo sprintf(
            "%s %s %d/%d/%d/%d %s\n",
            $effectiveDate,
            $zoneCode,
            $costs['cost1'],
            $costs['cost2'],
            $costs['cost3'],
            $costs['cost4'],
            $status
        );
    }
}

echo "Imported or refreshed {$imported} rows.\n";