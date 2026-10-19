<?php
/**
 * Tab 2 - Quest 效率 (Quests).
 *
 * Shows the COMPLETE reliably identified Quest catalog (quest_catalog.json)
 * joined with the selected window (windows/{window}/quests.json). The same name
 * can exist under several Quest IDs in one region, so identity is the published
 * `quest_key` (zone_key#quest_id); the name is a label only.
 *
 * Observation status is explicit and three-way:
 *   - 本期間已觀測        (observed in this window)
 *   - 本期間未觀測（曾觀測） (observed in the all-period window, not here)
 *   - 從未觀測            (never observed)
 * Unobserved efficiency is null ("—"), never zero.
 *
 * Duration: the Phase 2B contract publishes per-duration cells
 * (windows/{window}/quests_duration.json) that already meet the sample
 * thresholds, so the control is enabled here. No ratio is recomputed in JS and
 * a below-threshold cell is never shown.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$window = qpub_param_window();
if (!qpub_data_available()) {
    qpub_abort_no_data('quests', 'Quest 效率');
}

$region = qpub_param_catalog_region();
$questFilter = qpub_param_quest($window);
$q = qpub_param_q();

/* Duration: enabled when the tree carries the per-duration document. */
$durationAvailable = qpub_duration_contract_available($window);
$duration = qpub_param_duration();
$durationRejected = false;
if ($duration !== 'all' && !$durationAvailable) {
    qpub_reject('duration', $duration);
    $duration = 'all';
    $durationRejected = true;
}

$sortMap = [
    'qph' => 'qph',
    'valh' => 'valh',
    'samples' => 'occurrences',
    'name' => 'quest_name',
    'qp' => 'qp',
    'star' => 'star',
    'completion' => 'completed_count',
    'region' => 'region_name',
    'status' => 'status',
];
$sort = qpub_param_sort(array_keys($sortMap), 'qph');
$dir = qpub_param_dir('desc');

$allRows = qpub_quest_view($window, $duration);
if ($region !== '') {
    $allRows = array_values(array_filter(
        $allRows,
        static fn(array $row): bool => (string)($row['zone_key'] ?? '') === $region
    ));
}
if ($questFilter !== '') {
    $allRows = array_values(array_filter($allRows, static function (array $row) use ($questFilter): bool {
        return (string)($row['quest_key'] ?? '') === $questFilter
            || (string)($row['quest_name'] ?? '') === $questFilter;
    }));
}
if ($q !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $allRows = array_values(array_filter($allRows, static function (array $row) use ($needle): bool {
        $haystack = (string)($row['quest_name'] ?? '') . ' '
            . (string)($row['quest_type'] ?? '') . ' '
            . (string)($row['region_name'] ?? '') . ' '
            . (string)($row['zone_key'] ?? '');
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        return str_contains($haystack, $needle);
    }));
}
$allRows = qpub_rows_sort($allRows, $sort, $dir, $sortMap);

$total = count($allRows);
$pageSize = QPUB_QUEST_PAGE_SIZE;
$pages = max(1, (int)ceil($total / $pageSize));
$page = qpub_param_page();
if ($page > $pages) {
    qpub_reject('page', (string)$page);
    $page = $pages;
}
$offset = ($page - 1) * $pageSize;
$rows = array_slice($allRows, $offset, $pageSize);

$carry = [
    'window' => $window, 'region' => $region, 'quest' => $questFilter, 'q' => $q,
    'duration' => $duration, 'sort' => $sort, 'dir' => $dir, 'page' => $page,
];

/* Region options: every catalog zone (not only zones observed in this window). */
$regionOptions = ['' => '全部地域'];
$regionSeen = [];
foreach (qpub_catalog_rows() as $cat) {
    $zoneKey = (string)($cat['zone_key'] ?? '');
    if ($zoneKey === '' || isset($regionSeen[$zoneKey])) {
        continue;
    }
    $regionSeen[$zoneKey] = true;
    $name = trim((string)($cat['region_name'] ?? ''));
    $regionOptions[$zoneKey] = $name === '' ? $zoneKey : $name . ' (' . $zoneKey . ')';
}

$durationOptions = ['all' => '全部 duration'];
foreach (QPUB_DURATIONS as $index) {
    $durationOptions[(string)$index] = '僅 ' . qpub_duration_label($index) . '（duration ' . $index . '）';
}

$catalogCount = count(qpub_catalog_rows());
$minDuration = qpub_duration_min_samples($window);

qpub_render_head('quests', 'Quest 效率', ['canonical' => qpub_current_url('quest.php', $carry)]);
qpub_render_tabs('quests', $carry);
qpub_render_notices();
?>
<div class="qpub-toolbar">
  <?php
    $windowOptions = [];
    foreach (QPUB_WINDOWS as $key) {
        $windowOptions[$key] = qpub_window_label($key);
    }
    qpub_render_select('quest.php', 'window', '時間範圍', $windowOptions, $window, [
        'region' => $region, 'q' => $q, 'sort' => $sort, 'dir' => $dir,
        'duration' => $duration, 'quest' => $questFilter,
    ]);
    qpub_render_select('quest.php', 'region', '地域', $regionOptions, $region, [
        'window' => $window, 'q' => $q, 'sort' => $sort, 'dir' => $dir,
        'duration' => $duration, 'quest' => $questFilter,
    ]);
    if ($durationAvailable) {
        qpub_render_select('quest.php', 'duration', 'Duration', $durationOptions, $duration, [
            'window' => $window, 'region' => $region, 'q' => $q, 'sort' => $sort,
            'dir' => $dir, 'quest' => $questFilter,
        ]);
    } else {
        ?>
  <div class="qpub-field qpub-field--disabled">
    <span class="qpub-field__label" id="qpub-duration-label">Duration</span>
    <select class="qpub-select" name="duration" disabled aria-describedby="qpub-duration-reason">
      <option value="all">全部 duration（無法使用）</option>
    </select>
  </div>
  <?php
    }
    qpub_render_search('quest.php', $q, [
        'window' => $window, 'region' => $region, 'sort' => $sort, 'dir' => $dir,
        'duration' => $duration, 'quest' => $questFilter,
    ]);
    qpub_render_reset('quest.php');
    qpub_render_copy_button();
    ?>
</div>

<?php if (!$durationAvailable): ?>
<p class="qpub-hint" id="qpub-duration-reason">
  Duration 篩選在本頁停用：目前載入的公開契約沒有逐 duration 的 Quest 文件
  （windows/*/quests_duration.json），依規範不得在前端重新計算比率。
</p>
<?php elseif ($durationRejected): ?>
<p class="qpub-hint">已忽略無效的 duration 參數（此時間範圍沒有可用的逐 duration 資料）。</p>
<?php endif; ?>

<?php if ($duration !== 'all'): ?>
<div class="qpub-alert qpub-alert--scope" role="status">
  <strong>Duration <?= qpub_h(qpub_duration_label((int)$duration)) ?> 檢視</strong>
  僅列出已發布且同時滿足 <code>QP n ≥ <?= qpub_int($minDuration['qp']) ?></code> 與
  <code>VAL n ≥ <?= qpub_int($minDuration['valh']) ?></code> 的 Quest 時距彙總。
  未達門檻的時距不發布，本站也不顯示其數量或由全期間差值推回。
</div>
<?php endif; ?>

<?php qpub_render_meta($window); ?>

<div class="qpub-panel">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title">
      Quest 效率（<?= qpub_h(qpub_window_label($window)) ?><?= $region !== '' ? '・' . qpub_h((string)($regionOptions[$region] ?? $region)) : '' ?>）
    </h2>
    <p class="qpub-panel__note">
      顯示全部 <?= qpub_int($catalogCount) ?> 筆已知 Quest 圖鑑與本期間狀態，共
      <?= qpub_int($total) ?> 筆符合篩選（第 <?= qpub_int($page) ?> / <?= qpub_int($pages) ?> 頁）。
      數值全部取自已發布 JSON，不在前端重新計算比率。未觀測效率為「—」。
      Quest 名稱僅為標籤，識別以 <code>zone_key#quest_id</code> 為準（同名不同 ID 會標記）。
      點擊 Quest 名稱可跳至隊伍比較並帶入精確的地域 / Quest 篩選。
    </p>
  </div>

  <?php if ($total === 0): ?>
  <?= qpub_empty_state(
      $duration !== 'all'
          ? '此 duration / 條件沒有已發布的 Quest 時距資料'
          : '沒有符合條件的 Quest 資料',
      $duration !== 'all'
          ? '逐 duration 僅發布達門檻的儲存格；請切換回「全部 duration」或調整條件。'
          : '請調整時間範圍、地域或搜尋關鍵字；或切換至「全期間」。'
  ) ?>
  <?php else: ?>
  <div class="qpub-table-wrap">
    <table class="qpub-table">
      <thead>
        <tr>
          <th scope="col" class="qpub-col-rank">#</th>
          <?php
            qpub_th('quest.php', $carry, 'region', '地域', $sort, $dir);
            qpub_th('quest.php', $carry, 'name', 'Quest', $sort, $dir);
            qpub_th_plain('類型');
            qpub_th('quest.php', $carry, 'star', '★', $sort, $dir);
            qpub_th_plain('狀態');
            qpub_th('quest.php', $carry, 'samples', '出現', $sort, $dir);
            qpub_th('quest.php', $carry, 'completion', '完成', $sort, $dir, 'qpub-col-optional');
            qpub_th('quest.php', $carry, 'qp', 'QP', $sort, $dir, 'qpub-col-optional');
            qpub_th('quest.php', $carry, 'qph', 'QP/h', $sort, $dir);
            qpub_th('quest.php', $carry, 'valh', 'VAL/h', $sort, $dir);
            qpub_th_plain('QP n');
            qpub_th_plain('VAL n');
            qpub_th_plain('搜尋AP', 'qpub-col-optional');
            qpub_th_plain('執行AP', 'qpub-col-optional');
            qpub_th_plain('中位時間', 'qpub-col-optional');
            qpub_th_plain('標記');
            ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $index => $row): ?>
        <?php
            $low = !empty($row['low_sample']) || qpub_low_sample($row);
            $zoneKey = (string)($row['zone_key'] ?? '');
            $questKey = (string)($row['quest_key'] ?? '');
            $questName = (string)($row['quest_name'] ?? '');
            $questId = $row['quest_id'] ?? null;
            $ambiguous = !empty($row['name_ambiguous']);
            $status = (string)($row['status'] ?? 'never');
            $statusMap = [
                'observed' => ['本期間已觀測', 'ok'],
                'not_in_window' => ['本期間未觀測', 'warn'],
                'never' => ['從未觀測', 'muted'],
            ];
            [$statusText, $statusKind] = $statusMap[$status] ?? $statusMap['never'];
            $teamParams = ['region' => $zoneKey];
            if ($questKey !== '') {
                $teamParams['quest'] = $questKey;
            } elseif ($questName !== '') {
                $teamParams['from_quest'] = $questName;
            }
            $questUrl = qpub_url('teams.php', $teamParams);
            $regionUrl = qpub_url('quest.php', ['window' => $window, 'region' => $zoneKey, 'duration' => $duration]);
            ?>
        <tr class="<?= $low && !empty($row['observed']) ? 'is-low-sample' : '' ?>">
          <td class="qpub-col-rank"><?= qpub_int($offset + $index + 1) ?></td>
          <td class="qpub-col-name">
            <a class="qpub-link qpub-link--dim" href="<?= qpub_hu($regionUrl) ?>">
              <?= qpub_text($row['region_name'] ?? null) ?>
            </a>
          </td>
          <td class="qpub-col-name">
            <?php if ($questKey !== ''): ?>
            <a class="qpub-link" href="<?= qpub_hu($questUrl) ?>"
              title="<?= qpub_h($questKey) ?>"><?= qpub_text($row['quest_name'] ?? null) ?></a>
            <?php else: ?>
            <span class="qpub-link"><?= qpub_text($row['quest_name'] ?? null) ?></span>
            <?php endif; ?>
            <?php if (qpub_is_number($questId)): ?>
            <span class="qpub-sub">#<?= qpub_int($questId) ?></span>
            <?php endif; ?>
            <?php if ($ambiguous): ?>
            <?= qpub_badge('同名多 ID', 'info') ?>
            <?php endif; ?>
          </td>
          <td><?= qpub_text($row['quest_type'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_int($row['star'] ?? null) ?></td>
          <td><?= qpub_badge($statusText, $statusKind) ?></td>
          <td class="qpub-col-num"><?= qpub_int($row['occurrences'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int($row['completed_count'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['qp'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-strong"><?= qpub_num($row['qph'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_num($row['valh'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-dim"><?= qpub_int($row['qp_samples'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-dim"><?= qpub_int($row['value_samples'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int($row['search_ap'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int($row['execute_ap'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_text($row['median_text'] ?? null) ?></td>
          <td>
            <?php if (empty($row['observed'])): ?>
            <?= qpub_badge('無資料', 'muted') ?>
            <?php else: ?>
            <?= $low ? qpub_badge('低樣本', 'warn') : qpub_badge('OK', 'ok') ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="qpub-pager" aria-label="分頁">
    <?php
        $prev = max(1, $page - 1);
        $next = min($pages, $page + 1);
        $first = max(1, $page - 2);
        $last = min($pages, $page + 2);
        ?>
    <a class="qpub-pager__link <?= $page <= 1 ? 'is-disabled' : '' ?>"
      href="<?= qpub_hu(qpub_url('quest.php', array_merge($carry, ['page' => $prev]))) ?>"
      <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>上一頁</a>
    <?php for ($p = $first; $p <= $last; $p++): ?>
    <a class="qpub-pager__link <?= $p === $page ? 'is-active' : '' ?>"
      href="<?= qpub_hu(qpub_url('quest.php', array_merge($carry, ['page' => $p]))) ?>"
      <?= $p === $page ? 'aria-current="page"' : '' ?>><?= qpub_int($p) ?></a>
    <?php endfor; ?>
    <a class="qpub-pager__link <?= $page >= $pages ? 'is-disabled' : '' ?>"
      href="<?= qpub_hu(qpub_url('quest.php', array_merge($carry, ['page' => $next]))) ?>"
      <?= $page >= $pages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>下一頁</a>
    <span class="qpub-pager__info"><?= qpub_int($total) ?> 筆 / <?= qpub_int($pages) ?> 頁</span>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php qpub_render_foot(); ?>
