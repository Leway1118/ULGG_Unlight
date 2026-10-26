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
$carry = ['sort' => $sort, 'dir' => $dir, 'q' => $q];

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

/** Sort V2 values deterministically; unqualified rows always appear last. */
function qpub_v2_sort_rows(array $rows, string $dir): array
{
    usort($rows, static function (array $a, array $b) use ($dir): int {
        $aScore = $a['_composite_v2_points'] ?? null;
        $bScore = $b['_composite_v2_points'] ?? null;
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
    qpub_render_search('index.php', $q, ['sort' => $sort, 'dir' => $dir], '搜尋地域名稱');
    qpub_render_reset('index.php');
    qpub_render_copy_button();
  ?>
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
      <span class="qpub-card__value"><?= $row === null ? '—' : qpub_num($row[$leader['metric']] ?? null, $leader['metric'] === 'eligible_samples' ? 0 : 2) ?></span>
      <span class="qpub-leader-name"><?= $row === null ? '尚無足夠樣本' : qpub_text($row['zone_name'] ?? null) ?></span>
      <span class="qpub-leader-meta"><?= qpub_h($leader['unit']) ?> · <?= $row === null ? '—' : '樣本 ' . qpub_int($row['eligible_samples'] ?? null) ?>
      </span>
    </div>
  <?php endforeach; ?>
</div>
<!-- QUEST_COMPOSITE_V2A_20261027 -->
<!-- QUEST_TOTAL_CARD_SPECIAL_TABLE_20261027 -->
<p class="qpub-hint">總樣本數依全期間公開來源紀錄計算，與表格的「有效地域樣本 n」定義不同。最高 QP/VAL 效率卡片需至少 10 筆對應樣本；最高綜合分 V2 需至少 30 筆有效及 QP/VAL 樣本。最高效率卡片僅計一般地圖、不受搜尋影響。</p>
<p class="qpub-hint">綜合分 V2-A = QP/h 百分位 × 50% + VAL/h 百分位 × 30% + QP/AP 百分位 × 10% + VAL/AP 百分位 × 10%。排名母體僅包含資料完整的一般地圖；同值用平均名次、樣本不足顯示「—」，特殊地圖不評分。</p>
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
            $low = qpub_low_sample($row);
            ?>
        <tr class="<?= $low ? 'is-low-sample' : '' ?>">
          <td class="qpub-col-rank"><?= qpub_int($index + 1) ?></td>
          <td class="qpub-col-name">
            <span><?= qpub_text($row['zone_name'] ?? null) ?></span>
            
            <?php if ($low): ?> <?= qpub_badge('低樣本', 'warn') ?><?php endif; ?>
          </td>
          <td class="qpub-col-num"><?= qpub_int($row['eligible_samples'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-strong"><?= qpub_num($row['qph'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_num($row['valh'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_num($row['average_qp'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_num($row['average_value'] ?? null) ?></td>
          <td><?= qpub_text($row['confidence'] ?? null) ?></td>
          
          
          <td class="qpub-col-num qpub-col-optional"><?= qpub_text($row['median_text'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['qp_per_ap'] ?? null, 4) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['value_per_ap'] ?? null, 4) ?></td>
          
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['composite_score_v2'] ?? null) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php } ?>
<div class="qpub-panel" id="regions">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title">地域排行</h2>
    <p class="qpub-panel__note">一般地圖 <?= qpub_int(count($ordinaryRows)) ?> 個。表格可點擊欄名排序；低於 5 筆的地圖標示低樣本。</p>
  </div>
  <?php if (!$ordinaryRows): ?>
    <?= qpub_empty_state('沒有符合條件的一般地域', '請調整搜尋文字，或查看下方特殊地圖。') ?>
  <?php else: qpub_render_region_list($ordinaryRows, $carry, $sort, $dir); endif; ?>
</div>
<div class="qpub-panel" id="special-maps">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title">特殊地圖</h2>
    <p class="qpub-panel__note">特殊地圖 <?= qpub_int(count($specialRows)) ?> 個（ExQuest／Event、道具與 R1 記憶任務）。不參與一般地域的綜合分 V2 評分。</p>
  </div>
  <?php if (!$specialRows): ?>
    <?= qpub_empty_state('沒有符合搜尋條件的特殊地圖', '請調整搜尋文字。') ?>
  <?php else: qpub_render_region_list($specialRows, $carry, $sort, $dir); endif; ?>
</div>
<?php require __DIR__ . '/inc/stars_section.php'; ?>
<?php qpub_render_foot(); ?>
