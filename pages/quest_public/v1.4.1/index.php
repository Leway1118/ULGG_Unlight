<?php
/** UL.GG Quest Public region dashboard (anonymous JSON only). */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';

// The time selector is retired: this page consistently uses all published history.
$window = 'all';
if (!qpub_data_available()) {
    qpub_abort_no_data('regions', '地域排行');
}
$sortMap = [
    'qph' => 'qph',
    'valh' => 'valh',
    'samples' => 'eligible_samples',
    'name' => 'zone_name',
    'composite' => 'composite_score_v2',
    'ap' => 'qp_per_ap',
];
$sort = qpub_param_sort(array_keys($sortMap), 'qph');
$dir = qpub_param_dir('desc');
$q = qpub_param_q();
$showSpecialRaw = qpub_raw('show_special', 4);
if ($showSpecialRaw !== '' && $showSpecialRaw !== '1') {
    qpub_reject('show_special', $showSpecialRaw);
}
$showSpecial = $showSpecialRaw === '1';
$carry = ['sort' => $sort, 'dir' => $dir, 'q' => $q, 'show_special' => $showSpecial ? '1' : null];

/**
 * Only classify zones with explicit metadata, an ExQuest/Event world marker,
 * or an Ex/Event label. Low samples are NEVER treated as special.
 * If a site's export lacks category metadata, operators may add verified
 * zone_key values to the exact-key override below; do not guess IDs.
 */
function qpub_special_zone_keys(): array
{
    // Confirmed by site owner: item/pass-access Ex worlds and R1 bookmark quests.
    // Keys are world:region IDs in the anonymous published region dataset.
    $special = [
        '7:0' => true, // 影之大陸 (Ex / passage)
        '7:1' => true, // 月之大陸 (Ex / passage)
        '7:2' => true, // 安妮莫娜 (Ex / passage)
        '7:3' => true, // 天使大陸 (Ex / passage)
        '8:0' => true, // R1 記憶任務 (bookmark)
    ];
    // Verified world families from game import_quests.json:
    // 7=ExQuest, 8=rarecard memory, 9=event, 10=NamelessLand.
    foreach ([7 => 4, 8 => 2, 9 => 4, 10 => 8] as $world => $regions) {
        for ($i = 0; $i < $regions; ++$i) $special[$world . ':' . $i] = true;
    }
    foreach (qpub_catalog_rows() as $entry) {
        $zoneKey = (string)($entry['zone_key'] ?? '');
        if ($zoneKey !== '' && qpub_special_region_flag($entry)) {
            $special[$zoneKey] = true;
        }
    }
    return $special;
}
function qpub_special_region_flag(array $row): bool
{
    if (in_array($row['is_special'] ?? null, [true, 1, '1'], true)
        || in_array($row['requires_item'] ?? null, [true, 1, '1'], true)
        || in_array($row['item_required'] ?? null, [true, 1, '1'], true)) {
        return true;
    }
    $worldKey = trim((string)($row['world_key'] ?? ''));
    if (in_array(strtolower($worldKey), ['exquest', 'event'], true)) {
        return true;
    }
    $worldName = trim((string)($row['world_name'] ?? ''));
    if (preg_match('/^(?:ExQuest|Event|EX[\s_\-]?|活動)/iu', $worldName)) {
        return true;
    }
    $zoneName = trim((string)($row['zone_name'] ?? ''));
    return (bool)preg_match('/^(?:Ex[\s_\-]|Ex(?=\p{Han})|Event[\s_\-]|活動限定|特殊地圖)/iu', $zoneName);
}
function qpub_region_is_special(array $row, array $specialKeys): bool
{
    $key = (string)($row['zone_key'] ?? '');
    return isset($specialKeys[$key]) || qpub_special_region_flag($row);
}

/**
 * V2-A: rank-normalized QP-first composite, excluding special regions.
 * Only ordinary regions with >=30 eligible/QP/VAL samples and complete
 * finite, nonnegative metric values participate in the reference universe.
 * The anonymous source JSON and its legacy composite_score are untouched.
 *
 * Average ranks for ties: rank2 = 2*rank = 2 + 2*above + (ties-1).
 * Integer weighted rank points avoid float tie noise before presentation.
 * Weights: QP/h 5, VAL/h 3, QP/AP 1, VAL/AP 1 (out of 10).
 */
function qpub_v2_score_regions(array $ordinary): array
{
    $metrics = ['qph' => 5, 'valh' => 3, 'qp_per_ap' => 1, 'value_per_ap' => 1];
    $eligible = [];
    foreach ($ordinary as $i => $row) {
        if ((int)($row['eligible_samples'] ?? 0) < 30
            || (int)($row['qph_samples'] ?? 0) < 30
            || (int)($row['valh_samples'] ?? 0) < 30) {
            continue;
        }
        $good = true;
        foreach ($metrics as $field => $_weight) {
            $value = $row[$field] ?? null;
            if (!qpub_is_number($value) || !is_finite((float)$value) || (float)$value < 0) {
                $good = false;
                break;
            }
        }
        if ($good) {
            $eligible[$i] = $row;
        }
    }
    $n = count($eligible);
    foreach ($ordinary as $i => &$row) {
        // Explicit null: insufficient data is NOT the same as score zero.
        $row['composite_score_v2'] = null;
        $row['_composite_v2_points'] = null;
        if (!isset($eligible[$i]) || $n < 2) {
            continue;
        }
        $points = 0;
        foreach ($metrics as $field => $weight) {
            $value = (float)$row[$field];
            $above = 0;
            $ties = 0;
            foreach ($eligible as $other) {
                $otherValue = (float)$other[$field];
                if ($otherValue > $value) {
                    $above++;
                } elseif ($otherValue === $value) {
                    $ties++;
                }
            }
            $rank2 = 2 + 2 * $above + $ties - 1;
            $points += $weight * (2 * $n - $rank2);
        }
        $row['_composite_v2_points'] = $points;
        $row['composite_score_v2'] = 100.0 * $points / (20 * ($n - 1));
    }
    unset($row);
    return $ordinary;
}

/**
 * Reference V2 score for unobserved model regions and measured-but-low
 * sample ordinary regions. Use ONLY eligible measured ordinary regions as
 * the fixed comparison reference. Never rescore official measured rows.
 * Percentiles use the V2 midrank formula; unseen between-rank observations
 * use midpoint rank, clipped at [0,100]. Null metric = no score.
 * Special maps are excluded, even when the toggle reveals their rows.
 */
function qpub_v2_score_model_regions(array $rows, array $measuredOrdinary): array
{
    $weights = ['qph' => 5, 'valh' => 3, 'qp_per_ap' => 1, 'value_per_ap' => 1];
    $reference = [];
    foreach ($measuredOrdinary as $ref) {
        if (($ref['_composite_v2_points'] ?? null) !== null) {
            $reference[] = $ref;
        }
    }
    $n = count($reference);
    if ($n < 2) return $rows;
    foreach ($rows as &$row) {
        if (!empty($row['_special_map'])) continue;
        $model = !empty($row['simulated_region']);
        $provisionalMeasured = !$model && ($row['composite_score_v2'] ?? null) === null
            && (int)($row['eligible_samples'] ?? 0) > 0;
        if (!$model && !$provisionalMeasured) continue;
        $weighted = 0.0;
        $valid = true;
        foreach ($weights as $metric => $weight) {
            $value = $row[$metric] ?? null;
            if (!qpub_is_number($value) || !is_finite((float)$value)
                || (float)$value < 0) {
                $valid = false;
                break;
            }
            $above = 0; $ties = 0;
            foreach ($reference as $ref) {
                $other = (float)$ref[$metric];
                if ($other > (float)$value) ++$above;
                elseif ($other === (float)$value) ++$ties;
            }
            $midrank = $above + ($ties > 0 ? ($ties + 1) / 2.0 : 0.5);
            $percentile = max(0.0, min(1.0, ($n - $midrank) / ($n - 1)));
            $weighted += $weight * $percentile;
        }
        if ($valid) {
            $row['composite_score_v2'] = 10.0 * $weighted;
            if ($model) {
                $row['_composite_model_basis_n'] = $n;
            } else {
                $row['_composite_low_sample_reference'] = true;
                $row['_composite_reference_n'] = $n;
            }
        }
    }
    unset($row);
    return $rows;
}

/**
 * Stable region-column formatting. Display precision is fixed per unit:
 * rate/average QP/AP/composite 1 decimal; VAL/h & average VAL 0 decimals.
 * Sort always uses original raw numeric values, never formatted strings.
 */
function qpub_region_display_number($value, int $decimals): string
{
    if (!qpub_is_number($value) || !is_finite((float)$value)) return '—';
    return number_format((float)$value, $decimals, '.', ',');
}

/** Sort V2 values deterministically; unqualified rows always appear last. */
function qpub_v2_sort_rows(array $rows, string $dir): array
{
    usort($rows, static function (array $a, array $b) use ($dir): int {
        $aScore = $a['composite_score_v2'] ?? null;
        $bScore = $b['composite_score_v2'] ?? null;
        if ($aScore === null || $bScore === null) {
            if ($aScore === null && $bScore === null) {
                return strcmp((string)($a['zone_key'] ?? ''), (string)($b['zone_key'] ?? ''));
            }
            return $aScore === null ? 1 : -1;
        }
        $cmp = $aScore <=> $bScore;
        if ($cmp !== 0) {
            return $dir === 'asc' ? $cmp : -$cmp;
        }
        // Tie-break policy: QP/h, VAL/h, eligible samples (all descending).
        foreach (['qph', 'valh', 'eligible_samples'] as $field) {
            $tie = (float)($b[$field] ?? 0) <=> (float)($a[$field] ?? 0);
            if ($tie !== 0) {
                return $tie;
            }
        }
        return strcmp((string)($a['zone_key'] ?? ''), (string)($b['zone_key'] ?? ''));
    });
    return $rows;
}

/** A rank card should not be won by one lucky or sparsely sampled run. */
function qpub_region_leader(array $rows, string $metric, int $minSamples = 10): ?array
{
    $best = null;
    foreach ($rows as $row) {
        $value = $row[$metric] ?? null;
        if (!qpub_is_number($value) || (float)$value < 0) {
            continue;
        }
        if ((int)($row['eligible_samples'] ?? 0) < $minSamples) {
            continue;
        }
        if ($metric === 'qph' && (int)($row['qph_samples'] ?? 0) < $minSamples) {
            continue;
        }
        if ($metric === 'valh' && (int)($row['valh_samples'] ?? 0) < $minSamples) {
            continue;
        }
        if ($metric === 'composite_score_v2'
            && ((int)($row['qph_samples'] ?? 0) < $minSamples
                || (int)($row['valh_samples'] ?? 0) < $minSamples)) {
            continue;
        }
        if ($best === null || (float)$value > (float)$best[$metric]
            || ((float)$value === (float)$best[$metric]
                && (int)($row['eligible_samples'] ?? 0) > (int)($best['eligible_samples'] ?? 0))) {
            $best = $row;
        }
    }
    return $best;
}

// Cards use the full (unsearched) ordinary-region universe; search only filters tables.
$allRows = qpub_window_rows($window, 'regions');
$specialKeys = qpub_special_zone_keys();
$ordinaryAll = [];
$specialAll = [];
foreach ($allRows as $row) {
    if (qpub_region_is_special($row, $specialKeys)) {
        $specialAll[] = $row;
    } else {
        $ordinaryAll[] = $row;
    }
}
$ordinaryAll = qpub_v2_score_regions($ordinaryAll);
// Total input records from the published quality/manifest metadata. This is
// NOT the sum of eligible_samples in displayed region rows, which is a subset.
$rawRecordCount = qpub_meta()['records'] ?? null;
$totalSamplesText = (is_numeric($rawRecordCount) && (float)$rawRecordCount >= 0)
    ? number_format((int)$rawRecordCount)
    : '—';
$leaders = [
    ['label' => '最高 QP 效率', 'metric' => 'qph', 'unit' => 'QP/h', 'row' => qpub_region_leader($ordinaryAll, 'qph')],
    ['label' => '最高 VAL 效率', 'metric' => 'valh', 'unit' => 'VAL/h', 'row' => qpub_region_leader($ordinaryAll, 'valh')],
    ['label' => '最高綜合分 V2', 'metric' => 'composite_score_v2', 'unit' => '分', 'row' => qpub_region_leader($ordinaryAll, 'composite_score_v2', 30)],
];
$filter = static function (array $rows) use ($q): array {
    if ($q === '') { return $rows; }
    $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    return array_values(array_filter($rows, static function (array $row) use ($needle): bool {
        $haystack = (string)($row['zone_name'] ?? '') . ' ' . (string)($row['zone_key'] ?? '');
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        return str_contains($haystack, $needle);
    }));
};
$ordinaryRows = $filter($ordinaryAll);
$ordinaryRows = $sort === 'composite'
    ? qpub_v2_sort_rows($ordinaryRows, $dir)
    : qpub_rows_sort($ordinaryRows, $sort, $dir, $sortMap);
// Special maps are intentionally NOT scored in the ordinary-region V2 universe.
$specialRows = qpub_rows_sort($filter($specialAll), $sort === 'composite' ? 'qph' : $sort, $dir, $sortMap);
// Catalog-only zones have no published region metric row, so never add
// them to observed ranking/leaderboards/V2 score or star matrix.
$rankedZoneKeys = [];
foreach ($allRows as $rankedRegion) {
    $rankedZoneKeys[(string)($rankedRegion['zone_key'] ?? '')] = true;
}
$catalogOnlyZones = [];
foreach (qpub_catalog_rows() as $cat) {
    $z = (string)($cat['zone_key'] ?? '');
    if ($z === '' || isset($rankedZoneKeys[$z])) continue;
    if (!isset($catalogOnlyZones[$z])) {
        $catalogOnlyZones[$z] = [
            'name' => trim((string)($cat['region_name'] ?? $z)),
            'count' => 0,
        ];
    }
    ++$catalogOnlyZones[$z]['count'];
}
uksort($catalogOnlyZones, 'strnatcmp');
$catalogOnlyTotal = count($catalogOnlyZones);
$catalogOnlyFiltered = [];
foreach ($catalogOnlyZones as $z => $entry) {
    $haystack = $entry['name'] . ' ' . $z;
    $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
    if ($needle === '' || str_contains($haystack, $needle)) {
        $catalogOnlyFiltered[$z] = $entry;
    }
}
// Display all 53 zones in ONE table. Leader cards and composite math above
// remain based solely on observed eligible ordinary regions.
$mergedRegionRows = array_merge($ordinaryAll, $specialAll);
if (g2_public_enabled() && $catalogOnlyZones) {
    require_once __DIR__ . '/inc/g2_route_valh.php';
    $g2ByQuest = g2_route_load(); // Guarded by candidate and published-source hashes.
    if ($g2ByQuest) {
        // Fixed cost per Quest comes from the same Catalog used by the site.
        $apByQuest = [];
        foreach (qpub_catalog_rows() as $catalogQuest) {
            $questKey = (string)($catalogQuest['quest_key'] ?? '');
            $search = $catalogQuest['search_ap'] ?? null;
            $execute = $catalogQuest['execute_ap'] ?? null;
            if ($questKey !== '' && is_numeric($search) && is_numeric($execute)
                && (float)$search >= 0 && (float)$execute >= 0) {
                $apByQuest[$questKey] = (float)$search + (float)$execute;
            }
        }
        $byRegion = [];
        foreach ($g2ByQuest as $key => $estimate) {
            $parts = explode('#', (string)$key, 2);
            if (count($parts) !== 2) continue;
            $byRegion[$parts[0]][] = $estimate;
        }
        foreach ($catalogOnlyZones as $key => $meta) {
            $estimates = $byRegion[$key] ?? [];
            if (count($estimates) !== (int)$meta['count'] || !$estimates) continue;
            $sumSec = 0.0; $sumQp = 0.0; $sumValue = 0.0; $sumAp = 0.0; $times = [];
            $unpricedGroup = false;
            foreach ($estimates as $estimate) {
                $seconds = (float)($estimate['estimated_sec'] ?? 0);
                $questKey = (string)($estimate['quest_key'] ?? '');
                $ap = $apByQuest[$questKey] ?? null;
                if ($seconds <= 0 || !is_finite($seconds) || $ap === null
                    || abs((float)($estimate['ap_cost'] ?? -1) - $ap) > 0.001) continue 2;
                $unpricedGroup = $unpricedGroup || !empty($estimate['reward_unpriced'])
                    || !empty($estimate['time_unverified']);
                $sumSec += $seconds;
                $sumQp += (float)$estimate['estimated_qp'];
                $sumValue += (float)$estimate['known_value'];
                $sumAp += $ap;
                $times[] = $seconds;
            }
            if ($sumSec <= 0 || count($times) !== count($estimates)) continue;
            sort($times, SORT_NUMERIC);
            $n = count($times);
            $middle = $n % 2
                ? $times[intdiv($n, 2)]
                : ($times[intdiv($n, 2) - 1] + $times[intdiv($n, 2)]) / 2;
            $secondsRounded = (int)round($middle);
            $medianText = intdiv($secondsRounded, 3600) > 0
                ? intdiv($secondsRounded, 3600).'h '.intdiv($secondsRounded % 3600, 60).'m '.($secondsRounded % 60).'s'
                : intdiv($secondsRounded, 60).'m '.($secondsRounded % 60).'s';
            $mergedRegionRows[] = [
                'zone_key' => $key,
                'zone_name' => $meta['name'] ?: $key,
                'eligible_samples' => 0,
                'qph' => $unpricedGroup ? null : $sumQp * 3600 / $sumSec,
                'valh' => $unpricedGroup ? null : $sumValue * 3600 / $sumSec,
                'average_qp' => $sumQp / $n,
                'average_value' => $unpricedGroup ? null : $sumValue / $n,
                'confidence' => '模型模擬',
                'median_text' => $unpricedGroup ? null : $medianText,
                'qp_per_ap' => $sumAp > 0 ? $sumQp / $sumAp : null,
                'value_per_ap' => !$unpricedGroup && $sumAp > 0 ? $sumValue / $sumAp : null,
                'composite_score_v2' => null,
                '_composite_v2_points' => null,
                'simulated_region' => true,
                'simulated_quest_count' => $n,
            ];
        }
    }
}
$allCatalogZoneKeys = [];
foreach (qpub_catalog_rows() as $cat) {
    $key = (string)($cat['zone_key'] ?? '');
    if ($key !== '') $allCatalogZoneKeys[$key] = true;
}
$specialRegionCount = count(array_intersect_key($specialKeys, $allCatalogZoneKeys));
foreach ($mergedRegionRows as &$regionRow) {
    $key = (string)($regionRow['zone_key'] ?? '');
    $regionRow['zone_name'] = qpub_region_source_name($key, (string)($regionRow['zone_name'] ?? ''));
    $regionRow['_special_map'] = qpub_region_is_special($regionRow, $specialKeys);
}
unset($regionRow);
// Score simulated ordinary regions against the 14 measured V2 references.
$mergedRegionRows = qpub_v2_score_model_regions($mergedRegionRows, $ordinaryAll);
if (!$showSpecial) {
    $mergedRegionRows = array_values(array_filter(
        $mergedRegionRows,
        static fn(array $row): bool => empty($row['_special_map'])
    ));
}
$mergedRegionRows = $filter($mergedRegionRows);
$mergedRegionRows = $sort === 'composite'
    ? qpub_v2_sort_rows($mergedRegionRows, $dir)
    : qpub_rows_sort($mergedRegionRows, $sort, $dir, $sortMap);
qpub_render_head('regions', '地域排行', ['canonical' => qpub_canonical_url('index.php')]);
qpub_render_notices();
?>
<style>
  .qpub-leader-card { min-width:0; }
  .qpub-leader-card .qpub-card__value { overflow-wrap:anywhere; }
  .qpub-leader-name { display:block; font-size:13px; color:#d5daee; margin:6px 0 4px; overflow-wrap:anywhere; }
  .qpub-leader-meta { display:block; font-size:11px; color:#aab2c9; }
</style>
<div class="qpub-toolbar">
  <?php
    qpub_render_search('index.php', $q, [
        'sort' => $sort, 'dir' => $dir,
        'show_special' => $showSpecial ? '1' : null,
    ], '搜尋地域名稱');
    qpub_render_reset('index.php');
    qpub_render_copy_button();
  ?>
  <a class="report-btn" id="toggle-special-maps"
     href="<?= qpub_hu(qpub_url('index.php', [
         'q' => $q, 'sort' => $sort, 'dir' => $dir,
         'show_special' => $showSpecial ? null : '1',
     ])) ?>"
     aria-expanded="<?= $showSpecial ? 'true' : 'false' ?>">
    <?= $showSpecial ? '隱藏特殊地圖' : '顯示特殊地圖' ?>（<?= qpub_int($specialRegionCount) ?>）
  </a>
</div>
<div class="qpub-cards qpub-featured-cards" aria-label="推薦排行摘要">
  <div class="qpub-card qpub-leader-card">
    <span class="qpub-card__label">總樣本數</span>
    <span class="qpub-card__value"><?= qpub_h($totalSamplesText) ?></span>
    <span class="qpub-leader-name">全期間匿名紀錄</span>
    <span class="qpub-leader-meta">所有來源紀錄，包含特殊地圖及未達排行門檻者</span>
  </div>
  <?php foreach ($leaders as $leader): $row = $leader['row']; ?>
    <div class="qpub-card qpub-leader-card">
      <span class="qpub-card__label"><?= qpub_h($leader['label']) ?></span>
      <span class="qpub-card__value"><?= $row === null ? '—' : qpub_region_display_number($row[$leader['metric']] ?? null, $leader['metric'] === 'valh' || $leader['metric'] === 'eligible_samples' ? 0 : 1) ?></span>
      <span class="qpub-leader-name"><?= $row === null ? '尚無足夠樣本' : qpub_text($row['zone_name'] ?? null) ?></span>
      <span class="qpub-leader-meta"><?= qpub_h($leader['unit']) ?> · <?= $row === null ? '—' : '樣本 ' . qpub_int($row['eligible_samples'] ?? null) ?>
      </span>
    </div>
  <?php endforeach; ?>
</div>
<!-- QUEST_COMPOSITE_V2A_20261027 -->
<!-- QUEST_TOTAL_CARD_SPECIAL_TABLE_20261027 -->
<p class="qpub-hint">總樣本數依全期間公開來源紀錄計算，與表格的「有效地域樣本 n」定義不同。最高 QP/VAL 效率卡片需至少 10 筆對應樣本；最高綜合分 V2 需至少 30 筆有效及 QP/VAL 樣本。最高效率卡片僅計一般地圖、不受搜尋影響。</p>
<p class="qpub-hint">綜合分 V2-A = QP/h 百分位 × 50% + VAL/h 百分位 × 30% + QP/AP 百分位 × 10% + VAL/AP 百分位 × 10%。合格實測使用正式分數；* 為模型參考分；† 為未達 30 筆門檻的低樣本實測參考分。兩種參考分均以合格實測一般地域為固定基準，不參與最高分卡片；特殊地圖不評分。</p>
<?php
/** Render the identical region table for either ordinary or explicitly revealed special maps. */
function qpub_render_region_list(array $rows, array $carry, string $sort, string $dir): void
{ ?>
  <div class="qpub-table-wrap">
    <table class="qpub-table">
      <thead>
        <tr>
          <th scope="col" class="qpub-col-rank">#</th>
          <?php
            qpub_th('index.php', $carry, 'name', '地域', $sort, $dir);
            qpub_th('index.php', $carry, 'samples', '樣本 n', $sort, $dir);
            qpub_th('index.php', $carry, 'qph', 'QP/h', $sort, $dir, '', '實際有效 QP / 耗時彙總');
            qpub_th('index.php', $carry, 'valh', 'VAL/h', $sort, $dir, '', '估值屬性（estimate）');
            qpub_th_plain('平均 QP');
            qpub_th_plain('平均 VAL');
            qpub_th_plain('可信度');
            
            
            qpub_th_plain('中位時間', 'qpub-col-optional');
            qpub_th('index.php', $carry, 'ap', 'QP/AP', $sort, $dir, 'qpub-col-optional');
            qpub_th_plain('VAL/AP', 'qpub-col-optional');
            
            qpub_th('index.php', $carry, 'composite', '綜合分 V2', $sort, $dir, 'qpub-col-optional', 'QP/h 50%、VAL/h 30%、QP/AP 10%、VAL/AP 10%（各指標以百分位計算）');
            ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $index => $row): ?>
        <?php
            $simulated = !empty($row['simulated_region']);
            $low = !$simulated && qpub_low_sample($row);
            ?>
        <tr class="<?= !empty($row['simulated_region']) ? 'is-model-simulated' : ($low ? 'is-low-sample' : '') ?>">
          <td class="qpub-col-rank"><?= qpub_int($index + 1) ?></td>
          <td class="qpub-col-name">
            <a class="qpub-link" href="<?= qpub_hu(qpub_url('quest.php', ['region' => (string)($row['zone_key'] ?? '')])) ?>">
              <?= qpub_text($row['zone_name'] ?? null) ?>
            </a>
            <?php if ($simulated): ?> <span class="qpub-model-mark" title="本地域暫無實測資料，依 <?= qpub_int($row['simulated_quest_count'] ?? null) ?> 個子任務的模型模擬彙總">* </span><?php endif; ?>
            <?php if (!empty($row['_special_map'])): ?> <small class="qpub-sub">特殊</small><?php endif; ?>
          </td>
          <td class="qpub-col-num"><?= qpub_int($row['eligible_samples'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-strong"><?= qpub_region_display_number($row['qph'] ?? null, 1) ?><?= $simulated ? '<sup title="模型模擬">*</sup>' : '' ?></td>
          <td class="qpub-col-num"><?= qpub_region_display_number($row['valh'] ?? null, 0) ?><?= $simulated ? '<sup title="模型模擬">*</sup>' : '' ?></td>
          <td class="qpub-col-num"><?= qpub_region_display_number($row['average_qp'] ?? null, 1) ?><?= $simulated ? '<sup title="模型模擬">*</sup>' : '' ?></td>
          <td class="qpub-col-num"><?= qpub_region_display_number($row['average_value'] ?? null, 0) ?><?= $simulated ? '<sup title="模型模擬">*</sup>' : '' ?></td>
          <td><?= qpub_text($row['confidence'] ?? null) ?></td>
          
          
          <td class="qpub-col-num qpub-col-optional"><?= qpub_text($row['median_text'] ?? null) ?><?= $simulated ? '<sup title="模型模擬">*</sup>' : '' ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_region_display_number($row['qp_per_ap'] ?? null, 1) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_region_display_number($row['value_per_ap'] ?? null, 1) ?></td>
          
          <td class="qpub-col-num qpub-col-optional"><?= qpub_region_display_number($row['composite_score_v2'] ?? null, 1) ?><?php if ($simulated && ($row['composite_score_v2'] ?? null) !== null): ?><sup title="依合格實測地域百分位計算的模型參考綜合分">*</sup><?php elseif (!empty($row['_composite_low_sample_reference'])): ?><sup title="未達 30 筆有效樣本門檻；依合格實測地域百分位計算的低樣本參考綜合分">†</sup><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php } ?>
<div class="qpub-panel" id="regions">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title">地域排行</h2>
    <p class="qpub-panel__note">目前顯示 <?= qpub_int(count($mergedRegionRows)) ?> 個地域，同表搜尋與排序。* 代表模型模擬，† 代表低樣本實測參考分；兩者以合格實測一般地域為固定參照計算；特殊地圖預設隱藏，可用上方按鈕切換。</p>
  </div>
  <?php if (!$mergedRegionRows): ?>
    <?= qpub_empty_state('沒有符合搜尋條件的地域', '請調整搜尋文字，或顯示特殊地圖。') ?>
  <?php endif; ?>
  <?php qpub_render_region_list($mergedRegionRows, $carry, $sort, $dir); ?>
</div>
<?php require __DIR__ . '/inc/stars_section.php'; ?>
<?php qpub_render_foot(); ?>
