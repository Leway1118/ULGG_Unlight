<?php
/** ULGG G2 complete missing values: static map route + historical group models.
 * Existing observed source fields and official ranking remain authoritative.
 */
declare(strict_types=1);
if (!defined('QPUB_VERSION')) { http_response_code(404); exit; }

function g2_route_candidate_path(): string
{
    $test = (string)getenv('ULGG_G2_STAGE_PATH');
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)
        && $test !== ''
        && str_starts_with($test, '/root/ulgg-g1/g2-private/')
    ) return $test;
    return '/var/lib/ulgg/quest-g2/route_valh.json';
}
function g2_route_load(): array
{
    $path = g2_route_candidate_path();
    if (!is_file($path) || !is_readable($path)
        || !hash_equals('90593c6e7de3d9fc2e8753f8a22e4a9a68e5d1535fe14c06fa8e09572b09216f', (string)hash_file('sha256', $path))) return [];
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 800000) return [];
    $doc = json_decode($raw, true);
    if (!is_array($doc)
        || ($doc['schema'] ?? '') !== 'ULGG_G2_FULL_CATALOG_SIMULATION_V3'
        || ($doc['policy'] ?? '') !== 'model_simulation_only_missing_no_observed_override') return [];
    // Static model: no date-based expiry; pinned SHA and source hashes remain mandatory.
    $root = qpub_data_dir();
    $refs = [
        'quest_catalog.json' => $root . '/quest_catalog.json',
        'windows/all/quests.json' => $root . '/windows/all/quests.json',
    ];
    foreach ($refs as $key => $file) {
        $expected = $doc['source_sha256'][$key] ?? null;
        if (!is_string($expected) || !preg_match('/^[0-9a-f]{64}$/', $expected)
            || !is_file($file) || !hash_equals($expected, (string)hash_file('sha256', $file))) return [];
    }
    // The public metric JSON contains only 806 Quest; Catalog contains all 1515.
    // Never infer non-membership from the published metric JSON alone.
    $catalog = json_decode((string)file_get_contents($refs['quest_catalog.json']), true);
    $published = json_decode((string)file_get_contents($refs['windows/all/quests.json']), true);
    if (!is_array($catalog) || !is_array($catalog['rows'] ?? null)
        || !is_array($published) || !is_array($published['rows'] ?? null)
        || count($catalog['rows']) !== 1515) return [];
    $byPublished = [];
    foreach ($published['rows'] as $r) {
        if (!is_array($r)) return [];
        $key = $r['quest_key'] ?? null;
        if (!is_string($key) || $key === '' || isset($byPublished[$key])) return [];
        $byPublished[$key] = $r;
    }
    $allKeys = [];
    $missing = [];
    $regions = [];
    $observedCount = 0;
    foreach ($catalog['rows'] as $cat) {
        if (!is_array($cat)) return [];
        $key = $cat['quest_key'] ?? null;
        $zone = $cat['zone_key'] ?? null;
        if (!is_string($key) || $key === '' || isset($allKeys[$key])
            || !is_string($zone) || $zone === '') return [];
        $allKeys[$key] = true;
        $regions[$zone] = true;
        $r = $byPublished[$key] ?? null;
        if ($r !== null && ($r['observed'] ?? null) === true) {
            ++$observedCount;
            continue; // Observed source always wins, including numeric zero.
        }
        if ($r !== null && (
            ($r['qp'] ?? null) !== null || ($r['qph'] ?? null) !== null
            || ($r['valh'] ?? null) !== null || ($r['median_sec'] ?? null) !== null
        )) return [];
        $missing[$key] = true;
    }
    foreach ($byPublished as $key => $r) if (!isset($allKeys[$key])) return [];
    if (count($regions) !== 53 || $observedCount !== 473
        || count($missing) !== 1042) return [];
    $entries = $doc['estimates'] ?? null;
    if (!is_array($entries) || count($entries) !== count($missing)) return [];
    $index = [];
    foreach ($entries as $e) {
        if (!is_array($e)) return [];
        $key = $e['quest_key'] ?? null;
        if (!is_string($key) || !isset($missing[$key]) || isset($index[$key])
            || ($e['model'] ?? '') !== 'QUEST_ROUTE_MAP_DIFFICULTY_QP_V4') return [];
        foreach (['estimated_sec','estimated_qp','estimated_qph','estimated_valh'] as $field) {
            if (!is_numeric($e[$field] ?? null) || !is_finite((float)$e[$field])
                || (float)$e[$field] < 0) return [];
        }
        $t = (float)$e['estimated_sec'];
        $qp = (float)$e['estimated_qp'];
        $v = $e['known_value'] ?? null;
        $difficulty = $e['map_difficulty_qp'] ?? null;
        $ap = $e['ap_cost'] ?? null;
        if (!is_int($difficulty) || $difficulty < 0 || $difficulty > 200
            || !is_int($ap) || $ap < 0 || $ap > 100
            || $qp !== (float)$difficulty
            || ($ap > 0
               && (abs((float)($e['estimated_qp_ap'] ?? -1) - $qp/$ap) > 0.00001
                   || abs((float)($e['estimated_val_ap'] ?? -1) - $v/$ap) > 0.00001))
            || ($ap === 0 && (($e['estimated_qp_ap'] ?? null) !== null
                             || ($e['estimated_val_ap'] ?? null) !== null))
            || $t < 5 || $t > 3600 || !is_int($v) || $v < 0 || $v > 1000000
            || abs((float)$e['estimated_valh'] - $v*3600/$t) > 0.1
            || abs((float)$e['estimated_qph'] - $qp*3600/$t) > 0.1
            || !is_int($e['hops'] ?? null) || $e['hops'] < 1 || $e['hops'] > 5
            || !is_int($e['battles'] ?? null) || $e['battles'] < 0 || $e['battles'] > $e['hops']
            || !is_array($e['route'] ?? null) || count($e['route']) !== $e['hops']
            || !is_int($e['unknown_rewards'] ?? null) || $e['unknown_rewards'] < 0
            || !is_int($e['time_reference_n'] ?? null) || $e['time_reference_n'] < 0
            || !is_int($e['qp_reference_n'] ?? null) || $e['qp_reference_n'] < 0) return [];
        $index[$key] = $e;
    }
    return count($index) === count($missing) ? $index : [];
}
function g2_route_rows(array $rows, array $model, bool $visible): array
{
    foreach ($rows as &$r) {
        $r['g2_sort_qp'] = $r['qp'] ?? null;
        $r['g2_sort_qph'] = $r['qph'] ?? null;
        $r['g2_sort_valh'] = $r['valh'] ?? null;
        if (!$visible || !$model || !array_key_exists('observed', $r)
            || $r['observed'] !== false
            || ($r['qp'] ?? null) !== null || ($r['qph'] ?? null) !== null
            || ($r['valh'] ?? null) !== null || ($r['median_sec'] ?? null) !== null) continue;
        $key = (string)($r['quest_key'] ?? '');
        if (!isset($model[$key])) continue;
        $e = $model[$key];
        $r['g2_estimate'] = $e;
        $r['g2_sort_qp'] = (float)$e['estimated_qp'];
        $r['g2_sort_qph'] = !empty($e['time_unverified']) ? null : (float)$e['estimated_qph'];
        $r['g2_sort_valh'] = !empty($e['reward_unpriced']) ? null : (float)$e['estimated_valh'];
    }
    unset($r);
    return $rows;
}
function g2_route_cell(array $row, string $type): string
{
    $e = $row['g2_estimate'] ?? null;
    if (!is_array($e)) {
        return match ($type) {
          'qp' => qpub_int($row['qp'] ?? null),
          'qph' => qpub_num($row['qph'] ?? null),
          'valh' => qpub_num($row['valh'] ?? null),
          'value' => qpub_num($row['average_value'] ?? null),
          'time' => qpub_text($row['median_text'] ?? null),
          default => '—',
        };
    }
    $unknown = (int)$e['unknown_rewards'];
    if (!empty($e['reward_unpriced']) && in_array($type, ['qph', 'valh', 'value', 'time'], true)) {
        $why = 'R1 記憶任務是可領取角色卡的特殊任務；其獎勵尚無可核對的 VALUE 計價。'
             . '缺少同類任務的充分通關時間實測，因此不顯示估算效率、時間或 VALUE。'
             . 'QP 仍以原始地圖 difficulty 為準。';
        return '<span class="p4c-estimate g2-estimate" title="'.qpub_h($why).'">'
            .'—<sup aria-label="模型資料不足" style="font-size:.7em;margin-left:1px">*</sup></span>';
    }
    $tip = '模型模擬（不是實測）；Quest '.$e['quest_key'].'；地圖路線 '
        .$e['hops'].' 格、'.$e['battles'].' 戰；'
        .'已知路線 VALUE '.$e['known_value'].'；未定價獎勵 '.$unknown.' 個暫按 0；'
        .'QP '.$e['map_difficulty_qp'].' 取自原始地圖 difficulty（以三人隊伍為基準）；'
        .'總 AP '.$e['ap_cost'].'；時間參照 Quest '.$e['time_reference_n'].' 張。'
        .'QP 為原始地圖固定值；時間、效率與路線 VALUE 仍屬模型模擬。';
    if (($e['supplement_source'] ?? '') === 'wiki_hexrealm_v5') {
        $tip .= '戰鬥格依 Unlight 繁中 Wiki 逐階任務表補正；預測時間不是人狼家園實測。';
    }
    $value = match ($type) {
        'qp' => number_format((float)$e['estimated_qp'], 1, '.', ','),
        'qph' => number_format((float)$e['estimated_qph'], 1, '.', ','),
        'valh' => number_format((float)$e['estimated_valh'], 0, '.', ','),
        'value' => number_format((float)$e['known_value'], 0, '.', ','),
        'time' => intdiv((int)round((float)$e['estimated_sec']), 60).'m '
                  .((int)round((float)$e['estimated_sec']) % 60).'s',
        default => '—',
    };
    // Compact cell; a single explanatory legend above the table owns the label.
    return '<span class="p4c-estimate g2-estimate" style="white-space:nowrap" title="'.qpub_h($tip).'">'
        .qpub_h($value).'<sup aria-label="模型模擬" style="font-size:0.7em;margin-left:1px">*</sup></span>';
}
