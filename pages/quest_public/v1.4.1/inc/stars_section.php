<?php
/**
 * Embedded Stars matrix, read from the existing anonymous JSON export.
 * No DB or session; the all-period matrix must not be described as recent data.
 */
if (!defined('QPUB_VERSION')) {
    http_response_code(404);
    exit;
}
$starDuration = qpub_param_duration();
$starMatrix = qpub_star_matrix($window);
$starRows = $starMatrix['rows'];
$starMeta = $starMatrix['meta'];
$starValues = range(1, 10);
$starWindowFiltered = !empty($starMeta['window_filtered']);
$starMatrixScope = (string)($starMeta['matrix_scope'] ?? 'unavailable');
$starVisible = $starRows;
if ($starDuration !== 'all') {
    $starVisible = array_values(array_filter($starRows,
        static fn(array $row): bool => (int)($row['duration_index'] ?? -1) === (int)$starDuration
    ));
}
$starMaxPercent = 0.0;
foreach ($starVisible as $starRow) {
    foreach (($starRow['estimated_percent'] ?? []) as $value) {
        if (qpub_is_number($value) && (float)$value > $starMaxPercent) {
            $starMaxPercent = (float)$value;
        }
    }
}
if ($starMaxPercent <= 0) { $starMaxPercent = 1.0; }
$starDurationOptions = ['all' => '全部 duration（0m–3d）'];
foreach (QPUB_DURATIONS as $index) {
    $starDurationOptions[(string)$index] = '僅 ' . qpub_duration_label($index);
}
?>
<section id="stars" class="qpub-stars-section" aria-labelledby="qpub-stars-title">
  <div class="qpub-panel">
    <div class="qpub-panel__head">
      <h2 class="qpub-panel__title" id="qpub-stars-title">星數機率</h2>
      <p class="qpub-panel__note">依公開匿名紀錄的貝氏估計；下方 duration 可單獨篩選。</p>
    </div>
    <div class="qpub-toolbar">
      <form class="qpub-field" method="get" action="<?= qpub_hu(qpub_url('index.php', [])) ?>#stars">
        <input type="hidden" name="window" value="<?= qpub_h($window) ?>">
        <label class="qpub-field__label" for="qpub-stars-duration">Duration</label>
        <select class="qpub-select" name="duration" id="qpub-stars-duration" data-qpub-autosubmit="1">
          <?php foreach ($starDurationOptions as $value => $label): ?>
          <option value="<?= qpub_h((string)$value) ?>" <?= (string)$value === $starDuration ? 'selected' : '' ?>><?= qpub_h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="qpub-btn" type="submit">套用</button></noscript>
      </form>
    </div>
    <div class="qpub-alert <?= $starWindowFiltered ? 'qpub-alert--note' : 'qpub-alert--scope' ?>" role="status">
      <?php if ($starMatrixScope === 'unavailable'): ?>
        本次發布沒有可用的星數矩陣。
      <?php elseif ($starWindowFiltered): ?>
        此矩陣依所選時間範圍（<?= qpub_h(qpub_window_label($window)) ?>）計算。
      <?php else: ?>
        此矩陣為<strong>全期間貝氏彙總</strong>（<?= qpub_h((string)($starMeta['source'] ?? '—')) ?>），
        <strong>使用完整已發布期間的樣本</strong>。
      <?php endif; ?>
    </div>
  </div>
<div class="qpub-cards qpub-cards--tight">
  <div class="qpub-card">
    <span class="qpub-card__label">模型</span>
    <span class="qpub-card__value qpub-card__value--sm"><?= qpub_text($starMeta['model'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">先驗強度 / 基準 duration</span>
    <span class="qpub-card__value qpub-card__value--sm"><?= qpub_num($starMeta['prior_strength'] ?? null, 1) ?>
      / <?= qpub_text($starMeta['prior_duration_index'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">已解析樣本</span>
    <span class="qpub-card__value"><?= qpub_int($starMeta['resolved'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">基準總數</span>
    <span class="qpub-card__value"><?= qpub_int($starMeta['baseline_total'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">未知星數樣本</span>
    <span class="qpub-card__value"><?= qpub_int($starMeta['hidden_unknown'] ?? null) ?></span>
  </div>
  <div class="qpub-card">
    <span class="qpub-card__label">顯示列數</span>
    <span class="qpub-card__value"><?= qpub_int(count($starVisible)) ?></span>
  </div>
</div>

<div class="qpub-panel">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title">
      星數機率矩陣（★1–★10 × duration <?= $starDuration === 'all' ? '0m–3d' : qpub_h(qpub_duration_label((int)$starDuration)) ?>）
    </h2>
    <p class="qpub-panel__note">
      表格數值為貝氏估計百分比（estimated_percent）。顏色深淺僅為呈現輔助（越深＝比例越高）。
      點選單一 duration 可檢視可信區間與觀測值。
    </p>
  </div>

  <?php if ($starVisible === []): ?>
  <?= qpub_empty_state('此條件沒有星數矩陣資料', '請切換 duration。') ?>
  <?php else: ?>
  <div class="qpub-scale" aria-hidden="true">
    <span class="qpub-scale__label">淺</span>
    <span class="qpub-scale__bar"></span>
    <span class="qpub-scale__label">深</span>
    <span class="qpub-scale__max">最高 <?= qpub_h(number_format($starMaxPercent, 1)) ?>%</span>
  </div>

  <div class="qpub-table-wrap">
    <table class="qpub-table qpub-heatmap">
      <caption class="qpub-caption">
        列＝duration（樣本數），欄＝星數；格內為估計百分比。滑鼠停留或聚焦可看觀測值、樣本數與可信區間。
      </caption>
      <thead>
        <tr>
          <th scope="col" class="qpub-heatmap__corner">duration</th>
          <?php foreach ($starValues as $star): ?>
          <th scope="col">★<?= qpub_h((string)$star) ?></th>
          <?php endforeach; ?>
          <th scope="col" class="qpub-col-optional">樣本 n</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($starVisible as $row): ?>
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
          <?php foreach ($starValues as $position => $star): ?>
          <?php
                $value = $estimated[$position] ?? null;
                $alpha = qpub_is_number($value)
                    ? min(0.85, max(0.04, 0.04 + 0.75 * ((float)$value / $starMaxPercent)))
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

  <?php if ($starDuration !== 'all'): ?>
  <?php $detail = $starVisible[0]; ?>
  <div class="qpub-panel__sub">
    <h3 class="qpub-panel__title qpub-panel__title--sm">
      duration <?= qpub_h(qpub_duration_label((int)$starDuration)) ?>
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
          <?php foreach ($starValues as $position => $star): ?>
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

</section>
