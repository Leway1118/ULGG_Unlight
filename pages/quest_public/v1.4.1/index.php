<?php
/**
 * Tab 1 - 地域排行 (Regions).
 *
 * All four time windows are supported. Statistics are read straight from
 * windows/{window}/regions.json; the page never recomputes a ratio.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$window = qpub_param_window();
if (!qpub_data_available()) {
    qpub_abort_no_data('regions', '地域排行');
}

$sortMap = [
    'qph' => 'qph',
    'valh' => 'valh',
    'samples' => 'eligible_samples',
    'name' => 'zone_name',
    'composite' => 'composite_score',
    'ap' => 'qp_per_ap',
];
$sort = qpub_param_sort(array_keys($sortMap), 'qph');
$dir = qpub_param_dir('desc');
$q = qpub_param_q();

$rows = qpub_window_rows($window, 'regions');
if ($q !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
        $haystack = (string)($row['zone_name'] ?? '') . ' ' . (string)($row['zone_key'] ?? '');
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        return str_contains($haystack, $needle);
    }));
}
$rows = qpub_rows_sort($rows, $sort, $dir, $sortMap);

$carry = ['window' => $window, 'sort' => $sort, 'dir' => $dir, 'q' => $q];
$overview = qpub_overview();
$ov = is_array($overview['by_window'][$window] ?? null) ? $overview['by_window'][$window] : [];

qpub_render_head('regions', '地域排行', ['canonical' => qpub_canonical_url('index.php')]);
qpub_render_tabs('regions', $carry);
qpub_render_notices();
?>
<div class="qpub-toolbar">
  <?php
    $windowOptions = [];
    foreach (QPUB_WINDOWS as $key) {
        $windowOptions[$key] = qpub_window_label($key);
    }
    qpub_render_select('index.php', 'window', '時間範圍', $windowOptions, $window, ['sort' => $sort, 'dir' => $dir, 'q' => $q]);
    qpub_render_search('index.php', $q, ['window' => $window, 'sort' => $sort, 'dir' => $dir], '搜尋地域名稱或 zone key');
    qpub_render_reset('index.php');
    qpub_render_copy_button();
    ?>
</div>

<?php qpub_render_meta($window); ?>

<div class="qpub-cards">
  <div class="qpub-card">
    <span class="qpub-card__label">有效樣本</span>
    <span class="qpub-card__value"><?= qpub_int($ov['eligible_samples'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">QP 覆蓋率</span>
    <span class="qpub-card__value"><?= qpub_pct($ov['qp_coverage_percent'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">VAL 覆蓋率</span>
    <span class="qpub-card__value"><?= qpub_pct($ov['value_coverage_percent'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">可信度</span>
    <span class="qpub-card__value"><?= qpub_text($ov['confidence'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">總 QP</span>
    <span class="qpub-card__value"><?= qpub_num($ov['total_qp'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">總 VAL</span>
    <span class="qpub-card__value"><?= qpub_num($ov['total_value'] ?? null) ?></span>
  </div>
</div>

<div class="qpub-panel">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title">地域排行（<?= qpub_h(qpub_window_label($window)) ?>）</h2>
    <p class="qpub-panel__note">
      共 <?= qpub_int(count($rows)) ?> 個地域。Quest 整體不限樣本數，低樣本以「低樣本」標記並顯示可信度。
      點擊地域可鑽取至該地域的 Quest 效率。
    </p>
  </div>

  <?php if ($rows === []): ?>
  <?= qpub_empty_state(
      '此時間範圍沒有可顯示的地域資料',
      $window === 'all'
          ? '目前沒有任何已發布的地域樣本。'
          : '此時間範圍沒有樣本；可切換至「全期間」查看完整資料。'
  ) ?>
  <?php else: ?>
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
            qpub_th_plain('QP n');
            qpub_th_plain('VAL n');
            qpub_th_plain('可信度');
            qpub_th_plain('QP 覆蓋');
            qpub_th_plain('VAL 覆蓋', 'qpub-col-optional');
            qpub_th_plain('中位時間', 'qpub-col-optional');
            qpub_th('index.php', $carry, 'ap', 'QP/AP', $sort, $dir, 'qpub-col-optional');
            qpub_th_plain('VAL/AP', 'qpub-col-optional');
            qpub_th_plain('丟棄率', 'qpub-col-optional');
            qpub_th('index.php', $carry, 'composite', '綜合分', $sort, $dir, 'qpub-col-optional');
            ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $index => $row): ?>
        <?php
            $zoneKey = (string)($row['zone_key'] ?? '');
            $drillUrl = qpub_url('quest.php', ['window' => $window, 'region' => $zoneKey]);
            $low = qpub_low_sample($row);
            ?>
        <tr class="<?= $low ? 'is-low-sample' : '' ?>">
          <td class="qpub-col-rank"><?= qpub_int($index + 1) ?></td>
          <td class="qpub-col-name">
            <a class="qpub-link" href="<?= qpub_hu($drillUrl) ?>"><?= qpub_text($row['zone_name'] ?? null) ?></a>
            <span class="qpub-sub"><?= qpub_h($zoneKey) ?></span>
            <?php if ($low): ?> <?= qpub_badge('低樣本', 'warn') ?><?php endif; ?>
          </td>
          <td class="qpub-col-num"><?= qpub_int($row['eligible_samples'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-strong"><?= qpub_num($row['qph'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_num($row['valh'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-dim"><?= qpub_int($row['qp_samples'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-dim"><?= qpub_int($row['valh_samples'] ?? null) ?></td>
          <td><?= qpub_text($row['confidence'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_pct($row['coverage_percent'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_pct($row['value_coverage_percent'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_text($row['median_text'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['qp_per_ap'] ?? null, 4) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['value_per_ap'] ?? null, 4) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_pct($row['discard_rate_percent'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['composite_score'] ?? null) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php qpub_render_foot(); ?>
