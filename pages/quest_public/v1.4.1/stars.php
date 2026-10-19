<?php
/**
 * Tab 3 - 星數機率 (Stars).
 *
 * The 11x10 matrix (duration 0..10 x star 1..10) is copied verbatim from the
 * published `star_matrix.json` rows: estimated_percent, ci_low/high_percent,
 * observed_percent, counts and samples. The Bayesian model is never touched and
 * no percentage is recomputed here.
 *
 * Time policy: the approved contract serves ONE global aggregate
 * (`matrix_scope: "global_aggregate"`, `window_filtered: false`). The page says
 * so explicitly, and never labels the all-period aggregate as recent data.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$window = qpub_param_window();
if (!qpub_data_available()) {
    qpub_abort_no_data('stars', '星數機率');
}
$duration = qpub_param_duration();

$matrix = qpub_star_matrix($window);
$rows = $matrix['rows'];
$meta = $matrix['meta'];
$stars = [];
for ($star = 1; $star <= 10; $star++) {
    $stars[] = $star;
}
$windowFiltered = !empty($meta['window_filtered']);
$matrixScope = (string)($meta['matrix_scope'] ?? 'unavailable');

$visible = $rows;
if ($duration !== 'all') {
    $visible = array_values(array_filter(
        $rows,
        static fn(array $row): bool => (int)($row['duration_index'] ?? -1) === (int)$duration
    ));
}

/* Colour scale bounds from the values actually rendered (presentation only). */
$maxPercent = 0.0;
foreach ($visible as $row) {
    foreach (($row['estimated_percent'] ?? []) as $value) {
        if (qpub_is_number($value) && (float)$value > $maxPercent) {
            $maxPercent = (float)$value;
        }
    }
}
if ($maxPercent <= 0.0) {
    $maxPercent = 1.0;
}

$carry = ['window' => $window, 'duration' => $duration];
qpub_render_head('stars', '星數機率', ['canonical' => qpub_canonical_url('stars.php')]);
qpub_render_tabs('stars', $carry);
qpub_render_notices();
?>
<div class="qpub-toolbar">
  <?php
    $windowOptions = [];
    foreach (QPUB_WINDOWS as $key) {
        $windowOptions[$key] = qpub_window_label($key);
    }
    $durationOptions = ['all' => '全部 duration（0m–3d）'];
    foreach (QPUB_DURATIONS as $index) {
        $durationOptions[(string)$index] = '僅 ' . qpub_duration_label($index)
            . '（duration ' . $index . '）';
    }
    qpub_render_select('stars.php', 'window', '時間範圍', $windowOptions, $window, ['duration' => $duration]);
    qpub_render_select('stars.php', 'duration', 'Duration', $durationOptions, $duration, ['window' => $window]);
    qpub_render_reset('stars.php');
    qpub_render_copy_button();
    ?>
</div>

<?php qpub_render_meta($window); ?>

<div class="qpub-alert <?= $windowFiltered ? 'qpub-alert--note' : 'qpub-alert--scope' ?>" role="status">
  <strong>矩陣時間政策</strong>
  <?php if ($matrixScope === 'unavailable'): ?>
  本次發布沒有可用的星數矩陣。
  <?php elseif ($windowFiltered): ?>
  此矩陣依所選時間範圍（<?= qpub_h(qpub_window_label($window)) ?>）計算，來源 <code><?= qpub_h((string)($meta['source'] ?? '—')) ?></code>。
  <?php else: ?>
  星數矩陣為<strong>全域貝氏彙總</strong>（來源 <code><?= qpub_h((string)($meta['source'] ?? '—')) ?></code>，
  <code>window_filtered=false</code>），依契約不隨時間範圍變動。
  因此切換上方時間範圍<strong>不會</strong>讓矩陣變成近期資料，本站也不以全期間彙總冒充近期結果；
  時間範圍僅影響「地域排行」與「Quest 效率」兩頁。
  <?php endif; ?>
</div>

<div class="qpub-cards qpub-cards--tight">
  <div class="qpub-card">
    <span class="qpub-card__label">模型</span>
    <span class="qpub-card__value qpub-card__value--sm"><?= qpub_text($meta['model'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">先驗強度 / 基準 duration</span>
    <span class="qpub-card__value qpub-card__value--sm"><?= qpub_num($meta['prior_strength'] ?? null, 1) ?>
      / <?= qpub_text($meta['prior_duration_index'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">已解析樣本</span>
    <span class="qpub-card__value"><?= qpub_int($meta['resolved'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">基準總數</span>
    <span class="qpub-card__value"><?= qpub_int($meta['baseline_total'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">未知星數樣本</span>
    <span class="qpub-card__value"><?= qpub_int($meta['hidden_unknown'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">顯示列數</span>
    <span class="qpub-card__value"><?= qpub_int(count($visible)) ?></span>
  </div>
</div>

<div class="qpub-panel">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title">
      星數機率矩陣（★1–★10 × duration <?= $duration === 'all' ? '0m–3d' : qpub_h(qpub_duration_label((int)$duration)) ?>）
    </h2>
    <p class="qpub-panel__note">
      表格數值為貝氏估計百分比（estimated_percent）。顏色深淺僅為呈現輔助（越深＝比例越高）。
      點選單一 duration 可檢視可信區間與觀測值。
    </p>
  </div>

  <?php if ($visible === []): ?>
  <?= qpub_empty_state('此條件沒有星數矩陣資料', '請切換 duration 或時間範圍。') ?>
  <?php else: ?>
  <div class="qpub-scale" aria-hidden="true">
    <span class="qpub-scale__label">淺</span>
    <span class="qpub-scale__bar"></span>
    <span class="qpub-scale__label">深</span>
    <span class="qpub-scale__max">最高 <?= qpub_h(number_format($maxPercent, 1)) ?>%</span>
  </div>

  <div class="qpub-table-wrap">
    <table class="qpub-table qpub-heatmap">
      <caption class="qpub-caption">
        列＝duration（樣本數），欄＝星數；格內為估計百分比。滑鼠停留或聚焦可看觀測值、樣本數與可信區間。
      </caption>
      <thead>
        <tr>
          <th scope="col" class="qpub-heatmap__corner">duration</th>
          <?php foreach ($stars as $star): ?>
          <th scope="col">★<?= qpub_h((string)$star) ?></th>
          <?php endforeach; ?>
          <th scope="col" class="qpub-col-optional">樣本 n</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($visible as $row): ?>
        <?php
            $index = (int)($row['duration_index'] ?? -1);
            $estimated = is_array($row['estimated_percent'] ?? null) ? $row['estimated_percent'] : [];
            $low = is_array($row['ci_low_percent'] ?? null) ? $row['ci_low_percent'] : [];
            $high = is_array($row['ci_high_percent'] ?? null) ? $row['ci_high_percent'] : [];
            $observed = is_array($row['observed_percent'] ?? null) ? $row['observed_percent'] : [];
            $counts = is_array($row['counts'] ?? null) ? $row['counts'] : [];
            ?>
        <tr class="<?= !empty($row['is_prior_base']) ? 'is-prior-base' : '' ?>">
          <th scope="row" class="qpub-heatmap__row-head">
            <?= qpub_h(qpub_duration_label($index)) ?>
            <span class="qpub-sub">d<?= qpub_int($index) ?></span>
            <?php if (!empty($row['is_prior_base'])): ?><?= qpub_badge('先驗基準', 'muted') ?><?php endif; ?>
          </th>
          <?php foreach ($stars as $position => $star): ?>
          <?php
                $value = $estimated[$position] ?? null;
                $alpha = qpub_is_number($value)
                    ? min(0.85, max(0.04, 0.04 + 0.75 * ((float)$value / $maxPercent)))
                    : 0.0;
                $title = '★' . $star . '：估計 ' . qpub_num($value, 1) . '%'
                    . '｜觀測 ' . qpub_num($observed[$position] ?? null, 1) . '%'
                    . '｜CI ' . qpub_num($low[$position] ?? null, 1) . '–'
                    . qpub_num($high[$position] ?? null, 1) . '%'
                    . '｜樣本 ' . qpub_int($counts[(string)$star] ?? null);
                ?>
          <td class="qpub-heat" style="background-color: rgba(130, 130, 255, <?= qpub_h(number_format($alpha, 3, '.', '')) ?>);"
            tabindex="0" title="<?= qpub_h($title) ?>" data-qpub-star="<?= qpub_h((string)$star) ?>"
            data-qpub-estimated="<?= qpub_h((string)($value ?? '')) ?>"
            data-qpub-ci-low="<?= qpub_h((string)($low[$position] ?? '')) ?>"
            data-qpub-ci-high="<?= qpub_h((string)($high[$position] ?? '')) ?>"
            data-qpub-observed="<?= qpub_h((string)($observed[$position] ?? '')) ?>"
            data-qpub-count="<?= qpub_h((string)($counts[(string)$star] ?? '')) ?>">
            <span class="qpub-heat__value"><?= qpub_h(number_format((float)($value ?? 0), 1)) ?></span>
          </td>
          <?php endforeach; ?>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int($row['samples'] ?? null) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($duration !== 'all'): ?>
  <?php $detail = $visible[0]; ?>
  <div class="qpub-panel__sub">
    <h3 class="qpub-panel__title qpub-panel__title--sm">
      duration <?= qpub_h(qpub_duration_label((int)$duration)) ?>
      （<?= qpub_int($detail['samples'] ?? null) ?> 筆樣本）明細
    </h3>
    <div class="qpub-table-wrap">
      <table class="qpub-table">
        <thead>
          <tr>
            <th scope="col">★</th>
            <th scope="col">估計 %</th>
            <th scope="col">可信區間</th>
            <th scope="col">觀測 %</th>
            <th scope="col">樣本 n</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stars as $position => $star): ?>
          <tr>
            <td>★<?= qpub_h((string)$star) ?></td>
            <td class="qpub-col-num qpub-col-strong">
              <?= qpub_num(($detail['estimated_percent'] ?? [])[$position] ?? null, 2) ?>
            </td>
            <td class="qpub-col-num">
              <?= qpub_num(($detail['ci_low_percent'] ?? [])[$position] ?? null, 1) ?> –
              <?= qpub_num(($detail['ci_high_percent'] ?? [])[$position] ?? null, 1) ?>
            </td>
            <td class="qpub-col-num">
              <?= qpub_num(($detail['observed_percent'] ?? [])[$position] ?? null, 1) ?>
            </td>
            <td class="qpub-col-num">
              <?= qpub_int(($detail['counts'] ?? [])[(string)$star] ?? null) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php qpub_render_foot(); ?>
