<?php
/**
 * Region Quest detail page.
 *
 * Linked from the region ranking table (index.php). Shows every known Quest
 * for one zone_key, including never-observed ones. Identity is quest_key
 * (zone_key#quest_id); the Quest name is a label only.
 *
 * Unobserved efficiency is null and rendered as "—", never 0.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$window = qpub_param_window();
if (!qpub_data_available()) {
    qpub_abort_no_data('regions', '地域排行');
}

$region = qpub_param_catalog_region();
if ($region === '') {
    qpub_render_head('regions', '地域任務', ['canonical' => qpub_canonical_url('index.php')]);
    qpub_render_notices();
    ?>
  <div class="qpub-panel">
    <div class="qpub-panel__head">
      <h2 class="qpub-panel__title">未指定地域</h2>
      <p class="qpub-panel__note">請從<a href="<?= qpub_hu(qpub_url('index.php', ['window' => $window])) ?>">地域排行</a>點擊地域名稱進入。</p>
    </div>
    <?= qpub_empty_state('缺少 region 參數', '請由地域排行點選地域。') ?>
  </div>
    <?php
    qpub_render_foot();
    exit;
}

$q = qpub_param_q();
$sortMap = [
    'name' => 'quest_name',
    'star' => 'star',
    'samples' => 'occurrences',
    'completion' => 'completed_count',
    'qp' => 'qp',
    'qph' => 'qph',
    'valh' => 'valh',
];
$sort = qpub_param_sort(array_keys($sortMap), 'qph');
$dir = qpub_param_dir('desc');

$allRows = array_values(array_filter(
    qpub_quest_view($window, 'all'),
    static fn(array $row): bool => (string)($row['zone_key'] ?? '') === $region
));

if ($q !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $allRows = array_values(array_filter($allRows, static function (array $row) use ($needle): bool {
        $haystack = (string)($row['quest_name'] ?? '') . ' '
            . (string)($row['quest_type'] ?? '') . ' '
            . (string)($row['quest_key'] ?? '');
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
    'window' => $window, 'region' => $region, 'q' => $q,
    'sort' => $sort, 'dir' => $dir, 'page' => $page,
];

$regionName = '';
foreach (qpub_catalog_rows() as $cat) {
    if ((string)($cat['zone_key'] ?? '') === $region) {
        $regionName = trim((string)($cat['region_name'] ?? ''));
        break;
    }
}
$title = $regionName !== '' ? $regionName . '（' . $region . '）' : $region;

qpub_render_head('regions', $title . ' 任務一覽', ['canonical' => qpub_canonical_url('quest.php')]);
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
    ]);
    qpub_render_search('quest.php', $q, [
        'window' => $window, 'region' => $region, 'sort' => $sort, 'dir' => $dir,
    ]);
    qpub_render_reset('quest.php', '重設篩選');
  ?>
  <a class="report-btn" href="<?= qpub_hu(qpub_url('index.php', ['window' => $window])) ?>">← 返回地域排行</a>
</div>

<div class="qpub-panel">
  <div class="qpub-panel__head">
    <h2 class="qpub-panel__title"><?= qpub_h($title) ?> 任務一覽</h2>
    <p class="qpub-panel__note">
      顯示該地域全部 <?= qpub_int($total) ?> 筆已知 Quest（第 <?= qpub_int($page) ?> / <?= qpub_int($pages) ?> 頁）。
      數值取自已發布 JSON；未觀測效率顯示為「—」。Quest 名稱僅為標籤，識別以 <code>zone_key#quest_id</code> 為準。
    </p>
  </div>

  <?php if ($total === 0): ?>
  <?= qpub_empty_state('沒有符合條件的 Quest', '請調整搜尋文字或切換時間範圍。') ?>
  <?php else: ?>
  <div class="qpub-table-wrap">
    <table class="qpub-table">
      <thead>
        <tr>
          <th scope="col" class="qpub-col-rank">#</th>
          <?php
            qpub_th('quest.php', $carry, 'name', 'Quest', $sort, $dir);
            qpub_th_plain('類型');
            qpub_th('quest.php', $carry, 'star', '★', $sort, $dir);
            qpub_th('quest.php', $carry, 'samples', '樣本', $sort, $dir);
            qpub_th('quest.php', $carry, 'completion', '完成場數', $sort, $dir, 'qpub-col-optional');
            qpub_th('quest.php', $carry, 'qp', 'QP', $sort, $dir, 'qpub-col-optional');
            qpub_th('quest.php', $carry, 'qph', 'QP/h', $sort, $dir);
            qpub_th('quest.php', $carry, 'valh', 'VAL/h', $sort, $dir);
            qpub_th_plain('平均 VAL', 'qpub-col-optional');
            qpub_th_plain('搜尋 AP', 'qpub-col-optional');
            qpub_th_plain('執行 AP', 'qpub-col-optional');
            qpub_th_plain('中位時間', 'qpub-col-optional');
          ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $index => $row): ?>
        <?php
            $low = !empty($row['low_sample']) || qpub_low_sample($row);
            $questKey = (string)($row['quest_key'] ?? '');
            $questName = (string)($row['quest_name'] ?? '');
            $questId = $row['quest_id'] ?? null;
            $ambiguous = !empty($row['name_ambiguous']);
        ?>
        <tr class="<?= $low && !empty($row['observed']) ? 'is-low-sample' : '' ?>">
          <td class="qpub-col-rank"><?= qpub_int($offset + $index + 1) ?></td>
          <td class="qpub-col-name">
            <?= qpub_text($row['quest_name'] ?? null) ?>
            <?php if (qpub_is_number($questId)): ?>
            <span class="qpub-sub">#<?= qpub_int($questId) ?></span>
            <?php endif; ?>
            <?php if ($ambiguous): ?>
            <?= qpub_badge('同名多 ID', 'info') ?>
            <?php endif; ?>
          </td>
          <td><?= qpub_text($row['quest_type'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_int($row['star'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_int($row['occurrences'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int($row['completed_count'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int($row['qp'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-strong"><?= qpub_num($row['qph'] ?? null) ?></td>
          <td class="qpub-col-num"><?= qpub_num($row['valh'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_num($row['average_value'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int($row['search_ap'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_int($row['execute_ap'] ?? null) ?></td>
          <td class="qpub-col-num qpub-col-optional"><?= qpub_text($row['median_text'] ?? null) ?></td>
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
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php
qpub_render_foot();
