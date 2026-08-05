<?php
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定


$seoTitle = 'QP 排行 Rank | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "qp_rank";
$pageTitleFull = 'QP 排行 Rank | UL.GG 戰績網'; //桌機
$pageTitleText = 'QP 排行 Rank'; //手機

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* =========================
   Rank Badges
========================= */
  .badge-up {
    background: #28a745;
  }

  .badge-new {
    background: #17a2b8;
  }

  .badge-top {
    background: #ffc107;
    color: #212529;
  }

  .badge-down {
    background: #dc3545;
  }

  .text-sm {
    font-size: .85rem;
  }

  /* =========================
   Blacklist Player — Prison Style
========================= */
  .blacklist-name {
    background: linear-gradient(180deg, #2b2b2b, #0f0f0f);
    color: #ffeb3b !important;
    /* 警示黃 */
    padding: 0px 7px;
    border-radius: 4px;
    font-weight: 700;
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;

    border: 1px solid rgba(255, 235, 59, 0.6);
    box-shadow:
      inset 0 0 6px rgba(0, 0, 0, 0.8),
      0 0 6px rgba(255, 235, 59, 0.35);

    text-decoration: none;
    display: inline-block;
  }


  /* =========================
   Milestone Table (Dark)
========================= */
  .milestone-table {
    background: #0b0c10;
  }

  .milestone-table th {
    background: #1f2937;
    color: #e5e7eb;
  }

  .milestone-table td {
    color: #e5e7eb;
  }

  .milestone-table tbody tr {
    background-color: #111827 !important;
  }

  .milestone-table tbody tr:nth-child(odd) {
    background-color: #0f172a !important;
  }


  /* =========================
   Milestone Table Border
========================= */
  .milestone-table {
    border: 1px solid rgba(255, 255, 255, 0.22);
  }

  .milestone-table th,
  .milestone-table td {
    border: 1px solid rgba(255, 255, 255, 0.18) !important;
  }

  /* 表頭再加一條亮線 */
  .milestone-table thead th {
    border-bottom: 1px solid rgba(120, 120, 255, 0.5) !important;
  }

  .datatable-update-time {
    text-align: right;
    margin-bottom: 8px;
    font-size: 0.85rem;
    color: #9fa8da;
  }

  /* =========================
   QP Activity Analysis
========================= */
  .qp-activity-wrap {
    margin-bottom: 18px;
  }

  .qp-activity-hero {
    background: linear-gradient(135deg, #111827, #24395f);
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 16px;
    padding: 18px;
    color: #fff;
    margin-bottom: 14px;
    box-shadow: 0 8px 22px rgba(0, 0, 0, .22);
  }

  .qp-activity-title {
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .qp-activity-desc {
    color: rgba(255, 255, 255, .76);
    font-size: 13px;
    line-height: 1.7;
  }

  .qp-activity-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0;
  }

  .qp-activity-tab-btn {
    border: 1px solid rgba(255, 255, 255, .18);
    background: #1f2937;
    color: #dbeafe;
    border-radius: 999px;
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
  }

  .qp-activity-tab-btn.active {
    background: #2563eb;
    color: #fff;
    border-color: #60a5fa;
  }

  .qp-activity-panel {
    display: none;
  }

  .qp-activity-panel.active {
    display: block;
  }

  .qp-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
  }

  .qp-stat-card {
    background: #111827;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 12px;
    padding: 12px;
    color: #fff;
  }

  .qp-stat-label {
    color: #9ca3af;
    font-size: 12px;
    margin-bottom: 6px;
  }

  .qp-stat-value {
    font-size: 21px;
    font-weight: 800;
    line-height: 1.1;
  }

  .qp-stat-note {
    color: #94a3b8;
    font-size: 11px;
    margin-top: 6px;
  }

  .qp-focus-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
  }

  .qp-focus-card {
    background: linear-gradient(135deg, #0f172a, #1e293b);
    border: 1px solid rgba(96, 165, 250, .28);
    border-radius: 14px;
    padding: 14px;
    color: #e5e7eb;
  }

  .qp-focus-label {
    color: #93c5fd;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 6px;
  }

  .qp-focus-name {
    font-size: 20px;
    font-weight: 800;
    color: #fff;
    margin-bottom: 4px;
  }

  .qp-focus-main {
    font-size: 24px;
    font-weight: 900;
    color: #86efac;
    margin-bottom: 5px;
  }

  .qp-focus-sub {
    color: #cbd5e1;
    font-size: 12px;
  }

  .qp-analysis-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(280px, .55fr);
    gap: 16px;
    align-items: start;
  }

  .qp-analysis-card {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 14px;
    padding: 14px;
    color: #e5e7eb;
    margin-bottom: 12px;
  }

  .qp-analysis-title {
    font-weight: 800;
    font-size: 16px;
    color: #fff;
    margin-bottom: 10px;
  }

  .qp-mini-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-bottom: 10px;
  }

  .qp-mini-tab-btn {
    border: 1px solid rgba(255, 255, 255, .14);
    background: #1f2937;
    color: #cbd5e1;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
  }

  .qp-mini-tab-btn.active {
    background: #2563eb;
    color: #fff;
  }

  .qp-mini-panel {
    display: none;
  }

  .qp-mini-panel.active {
    display: block;
  }

  .qp-analysis-table {
    width: 100%;
    border-collapse: collapse;
  }

  .qp-analysis-table th {
    background: #1f2937;
    color: #cbd5e1;
    font-size: 12px;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    white-space: nowrap;
  }

  .qp-analysis-table td {
    color: #e5e7eb;
    font-size: 12px;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    white-space: nowrap;
  }

  .qp-player-name {
    font-weight: 800;
    color: #fff;
  }

  .qp-player-link {
    color: inherit;
    text-decoration: none;
    border-bottom: 1px dashed rgba(147, 197, 253, .65);
  }

  .qp-player-link:hover {
    color: #93c5fd;
    text-decoration: none;
  }

  .qp-focus-card-link {
    display: block;
    text-decoration: none;
    transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
  }

  .qp-focus-card-link:hover {
    color: #e5e7eb;
    text-decoration: none;
    transform: translateY(-2px);
    border-color: rgba(147, 197, 253, .65);
    box-shadow: 0 8px 22px rgba(37, 99, 235, .22);
  }

  .qp-gain {
    color: #93c5fd;
    font-weight: 800;
  }

  .qp-rise {
    color: #86efac;
    font-weight: 800;
  }

  .qp-empty {
    padding: 18px;
    text-align: center;
    color: #94a3b8;
    background: rgba(255, 255, 255, .04);
    border-radius: 10px;
  }

  .qp-dist-row {
    display: grid;
    grid-template-columns: 90px 1fr 38px;
    gap: 8px;
    align-items: center;
    margin-bottom: 10px;
    font-size: 12px;
    color: #cbd5e1;
  }

  .qp-dist-bar {
    height: 10px;
    background: #1f2937;
    border-radius: 999px;
    overflow: hidden;
  }

  .qp-dist-bar span {
    display: block;
    height: 100%;
    background: linear-gradient(90deg, #2563eb, #22c55e);
    border-radius: 999px;
  }

  @media (max-width: 1199px) {
    .qp-stat-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .qp-analysis-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 991px) {
    .qp-analysis-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 767px) {

    .qp-stat-grid,
    .qp-focus-grid {
      grid-template-columns: 1fr;
    }

    .qp-analysis-table-wrap {
      overflow-x: auto;
    }

    .qp-analysis-table {
      min-width: 650px;
    }
  }

  .qp-mini-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0 14px;
  }

  .qp-mini-tab-btn {
    border: 1px solid rgba(148, 163, 184, .35);
    background: rgba(15, 23, 42, .55);
    color: #cbd5e1;
    border-radius: 999px;
    padding: 8px 13px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
  }

  .qp-mini-tab-btn.active {
    background: #2563eb;
    border-color: #60a5fa;
    color: #fff;
  }


  @media (max-width: 575px) {
    .qp-analysis-card {
      padding: 14px;
      border-radius: 16px;
    }

    .qp-analysis-title {
      font-size: 16px;
      margin-bottom: 10px;
    }

    .qp-empty {
      padding: 16px;
      font-size: 13px;
    }

    .qp-analysis-table td[data-label]:nth-child(n+3)::before {
      content: attr(data-label) "：";
      color: #94a3b8;
      font-weight: 700;
      margin-right: 2px;
    }

    .qp-mini-tabs {
      display: grid;
      grid-template-columns: 1fr;
      gap: 8px;
    }

    .qp-mini-tab-btn {
      width: 100%;
      padding: 10px 12px;
      text-align: center;
    }

    .qp-analysis-table-wrap {
      overflow: visible;
    }

    .qp-analysis-table {
      min-width: 0;
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 10px;
    }

    .qp-analysis-table thead {
      display: none;
    }

    .qp-analysis-table,
    .qp-analysis-table tbody,
    .qp-analysis-table tr,
    .qp-analysis-table td {
      display: block;
      width: 100%;
    }

    .qp-analysis-table tr {
      background: rgba(15, 23, 42, .72);
      border: 1px solid rgba(148, 163, 184, .18);
      border-radius: 14px;
      padding: 12px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, .16);
    }

    .qp-analysis-table td {
      border: 0 !important;
      padding: 4px 0;
      color: #cbd5e1;
      white-space: normal;
    }

    .qp-analysis-table td:first-child {
      color: #93c5fd;
      font-size: 12px;
      font-weight: 900;
      margin-bottom: 4px;
    }

    .qp-analysis-table td:nth-child(2) {
      font-size: 17px;
      font-weight: 900;
      color: #fff;
      margin-bottom: 6px;
    }

    .qp-analysis-table td:nth-child(n+3) {
      display: inline-flex;
      width: auto;
      margin-right: 10px;
      margin-top: 4px;
      font-size: 13px;
    }

    .qp-analysis-table .qp-player-link {
      color: #fff;
      text-decoration: none;
    }

    .qp-analysis-table .qp-player-link:hover {
      color: #93c5fd;
    }

    .qp-dist-row {
      display: grid;
      grid-template-columns: 90px minmax(0, 1fr) 42px;
      gap: 10px;
      align-items: center;
      margin-bottom: 12px;
    }

    .qp-dist-bar {
      height: 11px;
      background: rgba(148, 163, 184, .16);
      border-radius: 999px;
      overflow: hidden;
    }

    .qp-dist-bar span {
      display: block;
      height: 100%;
      border-radius: 999px;
      background: linear-gradient(90deg, #38bdf8, #2563eb);
    }

    @media (max-width: 575px) {
      .qp-dist-row {
        grid-template-columns: 78px minmax(0, 1fr) 34px;
        gap: 8px;
        font-size: 12px;
      }

      .qp-dist-bar {
        height: 9px;
      }
    }
  }
</style>

<?php
/* 放入你的 PHP程式    */

//$ack = $_SESSION["ack"] ?? 0;
$permission = $_SESSION["ack"] ?? 0;


function qpFmt($v)
{
  return number_format((int)$v);
}
function qpSafe($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function qpPlayerUrl(string $server, string $name): string
{
  return '/pages/qp_player.php?server=' . rawurlencode($server) . '&name=' . rawurlencode($name);
}
function qpPlayerLink(string $server, string $name, ?string $class = null): string
{
  $classAttr = $class ? ' class="' . qpSafe($class) . '"' : '';
  return '<a' . $classAttr . ' href="' . qpSafe(qpPlayerUrl($server, $name)) . '">' . qpSafe($name) . '</a>';
}
function fetchQpRows(PDO $db, string $sql, array $params = []): array
{
  try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}
function fetchQpOne(PDO $db, string $sql, array $params = []): ?array
{
  try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}
function fetchQpCol(PDO $db, string $sql, array $params = [])
{
  try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
  } catch (Throwable $e) {
    return null;
  }
}
function qpMedian(array $nums): int
{
  $nums = array_values(array_filter(array_map('intval', $nums), fn($v) => $v >= 0));
  sort($nums);
  $count = count($nums);
  if ($count === 0) return 0;
  $mid = intdiv($count, 2);
  return $count % 2 ? $nums[$mid] : (int)round(($nums[$mid - 1] + $nums[$mid]) / 2);
}
function buildQpActivity(PDO $db, string $server): array
{
  $table = "ranking_qp_{$server}";
  $latestTs = fetchQpCol($db, "SELECT MAX(ts) FROM `$table`");

  // ranking_qp_* 是日常快照；history 是月結資料，不能用來算今日活躍
  $todayBaseTs = $latestTs ? fetchQpCol($db, "
    SELECT MIN(ts)
    FROM `$table`
    WHERE DATE(ts) = DATE(?)
  ", [$latestTs]) : null;

  $prevTs = $latestTs ? fetchQpCol($db, "
    SELECT MAX(ts)
    FROM `$table`
    WHERE ts < ?
  ", [$latestTs]) : null;

  $compareTs = ($todayBaseTs && $todayBaseTs !== $latestTs) ? $todayBaseTs : $prevTs;
  $compareModeText = ($todayBaseTs && $todayBaseTs !== $latestTs) ? '今日' : '前次快照';

  $data = [
    'server' => $server,
    'latestTs' => $latestTs,
    'prevTs' => $prevTs,
    'compareTs' => $compareTs,
    'compareModeText' => $compareModeText,
    'summary' => [
      'max_qp' => 0,
      'avg_qp' => 0,
      'median_qp' => 0,
      'active_players' => 0,
      'total_qp_gain' => 0,
      'max_qp_gain' => 0,
      'avg_qp_gain' => 0,
      'max_rank_rise' => 0
    ],
    'growthTop' => [],
    'riseTop' => [],
    'newRows' => [],
    'distribution' => []
  ];
  if (!$latestTs) return $data;

  $currentRows = fetchQpRows($db, "
    SELECT rank_num, name, level, qp, ts
    FROM `$table`
    WHERE ts = ?
    ORDER BY rank_num ASC
  ", [$latestTs]);

  $qpList = array_column($currentRows, 'qp');
  $data['summary']['max_qp'] = $qpList ? max(array_map('intval', $qpList)) : 0;
  $data['summary']['avg_qp'] = $qpList ? (int)round(array_sum(array_map('intval', $qpList)) / count($qpList)) : 0;
  $data['summary']['median_qp'] = qpMedian($qpList);

  $buckets = [
    '2000-2999' => [2000, 2999],
    '3000-3999' => [3000, 3999],
    '4000-4999' => [4000, 4999],
    '5000-6999' => [5000, 6999],
    '7000-9999' => [7000, 9999],
    '10000-19999' => [10000, 19999],
    '20000+' => [20000, PHP_INT_MAX]
  ];
  foreach ($buckets as $label => [$min, $max]) {
    $data['distribution'][$label] = 0;
    foreach ($currentRows as $r) {
      $qp = (int)$r['qp'];
      if ($qp >= $min && $qp <= $max) $data['distribution'][$label]++;
    }
  }

  if ($compareTs) {
    $joinName = "p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci";
    $data['growthTop'] = fetchQpRows($db, "
      SELECT c.rank_num AS now_rank, p.rank_num AS old_rank, c.name, c.level,
        p.qp AS old_qp, c.qp AS now_qp, c.qp - p.qp AS qp_gain,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ? AND c.qp > p.qp
      ORDER BY qp_gain DESC
      LIMIT 20
    ", [$compareTs, $latestTs]);

    $data['riseTop'] = fetchQpRows($db, "
      SELECT c.rank_num AS now_rank, p.rank_num AS old_rank, c.name, c.level,
        p.qp AS old_qp, c.qp AS now_qp, c.qp - p.qp AS qp_gain,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ? AND CAST(p.rank_num AS SIGNED) > CAST(c.rank_num AS SIGNED)
      ORDER BY rank_rise DESC, qp_gain DESC
      LIMIT 20
    ", [$compareTs, $latestTs]);

    $data['newRows'] = fetchQpRows($db, "
      SELECT c.rank_num, c.name, c.level, c.qp
      FROM `$table` c
      LEFT JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ? AND p.name IS NULL
      ORDER BY c.rank_num ASC
      LIMIT 50
    ", [$compareTs, $latestTs]);

    $stat = fetchQpOne($db, "
      SELECT COUNT(*) AS active_players,
        COALESCE(SUM(c.qp - p.qp), 0) AS total_qp_gain,
        COALESCE(MAX(c.qp - p.qp), 0) AS max_qp_gain,
        ROUND(AVG(c.qp - p.qp), 1) AS avg_qp_gain
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ? AND c.qp > p.qp
    ", [$compareTs, $latestTs]);

    if ($stat) {
      $data['summary']['active_players'] = (int)$stat['active_players'];
      $data['summary']['total_qp_gain'] = (int)$stat['total_qp_gain'];
      $data['summary']['max_qp_gain'] = (int)$stat['max_qp_gain'];
      $data['summary']['avg_qp_gain'] = (float)$stat['avg_qp_gain'];
    }
    $data['summary']['max_rank_rise'] = !empty($data['riseTop']) ? (int)$data['riseTop'][0]['rank_rise'] : 0;
  }
  return $data;
}
$qpActivityTW = buildQpActivity($db, 'TW');
$qpActivityJP = buildQpActivity($db, 'JP');

function renderQpActivityPanel(array $data, string $label): void
{
  $summary = $data['summary'];
  $growthFocus = $data['growthTop'][0] ?? null;
  $riseFocus = $data['riseTop'][0] ?? null;
  $maxDist = !empty($data['distribution']) ? max($data['distribution']) : 0;
?>
  <div class="qp-activity-panel <?= $data['server'] === 'TW' ? 'active' : '' ?>" id="qp-activity-<?= qpSafe($data['server']) ?>">
    <div class="qp-stat-grid">
      <div class="qp-stat-card">
        <div class="qp-stat-label">最高 QP</div>
        <div class="qp-stat-value"><?= qpFmt($summary['max_qp']) ?></div>
        <div class="qp-stat-note">目前榜首分數</div>
      </div>
      <div class="qp-stat-card">
        <div class="qp-stat-label">平均 / 中位 QP</div>
        <div class="qp-stat-value" style="font-size:18px;"><?= qpFmt($summary['avg_qp']) ?> / <?= qpFmt($summary['median_qp']) ?></div>
        <div class="qp-stat-note">觀察整體投入度</div>
      </div>
      <div class="qp-stat-card">
        <div class="qp-stat-label"><?= qpSafe($data['compareModeText']) ?>活躍玩家</div>
        <div class="qp-stat-value"><?= qpFmt($summary['active_players']) ?></div>
        <div class="qp-stat-note">QP 有增加的人數</div>
      </div>
      <div class="qp-stat-card">
        <div class="qp-stat-label"><?= qpSafe($data['compareModeText']) ?> QP 總增加</div>
        <div class="qp-stat-value"><?= qpFmt($summary['total_qp_gain']) ?></div>
        <div class="qp-stat-note">平均 +<?= qpSafe($summary['avg_qp_gain']) ?></div>
      </div>
    </div>

    <div class="qp-focus-grid">
      <?php if ($growthFocus): ?>
        <a class="qp-focus-card qp-focus-card-link" href="<?= qpSafe(qpPlayerUrl($data['server'], $growthFocus['name'])) ?>">
          <div class="qp-focus-label"><?= qpSafe($data['compareModeText']) ?>最大成長</div>
          <div class="qp-focus-name"><?= qpSafe($growthFocus['name']) ?></div>
          <div class="qp-focus-main">+<?= qpFmt($growthFocus['qp_gain']) ?> QP</div>
          <div class="qp-focus-sub">#<?= (int)$growthFocus['old_rank'] ?> → #<?= (int)$growthFocus['now_rank'] ?>｜目前 QP <?= qpFmt($growthFocus['now_qp']) ?>｜點擊查看詳情</div>
        </a>
      <?php else: ?>
        <div class="qp-focus-card">
          <div class="qp-focus-label"><?= qpSafe($data['compareModeText']) ?>最大成長</div>
          <div class="qp-empty">目前沒有可比較的成長資料</div>
        </div>
      <?php endif; ?>
      <?php if ($riseFocus): ?>
        <a class="qp-focus-card qp-focus-card-link" href="<?= qpSafe(qpPlayerUrl($data['server'], $riseFocus['name'])) ?>">
          <div class="qp-focus-label"><?= qpSafe($data['compareModeText']) ?>最大躍升</div>
          <div class="qp-focus-name"><?= qpSafe($riseFocus['name']) ?></div>
          <div class="qp-focus-main">▲<?= qpFmt($riseFocus['rank_rise']) ?></div>
          <div class="qp-focus-sub">#<?= (int)$riseFocus['old_rank'] ?> → #<?= (int)$riseFocus['now_rank'] ?>｜QP +<?= qpFmt($riseFocus['qp_gain']) ?>｜點擊查看詳情</div>
        </a>
      <?php else: ?>
        <div class="qp-focus-card">
          <div class="qp-focus-label"><?= qpSafe($data['compareModeText']) ?>最大躍升</div>
          <div class="qp-empty">目前沒有可比較的躍升資料</div>
        </div>
      <?php endif; ?>
    </div>

    <div class="qp-analysis-grid">
      <div class="qp-analysis-card">
        <div class="qp-analysis-title"><?= qpSafe($label) ?> 活躍排行</div>
        <div class="qp-mini-tabs" data-scope="<?= qpSafe($data['server']) ?>">
          <button type="button" class="qp-mini-tab-btn active" data-tab="growth"><?= qpSafe($data['compareModeText']) ?> QP 成長</button>
          <button type="button" class="qp-mini-tab-btn" data-tab="rise"><?= qpSafe($data['compareModeText']) ?> QP 躍升</button>
          <button type="button" class="qp-mini-tab-btn" data-tab="new"><?= qpSafe($data['compareModeText']) ?>新進榜</button>
        </div>

        <div class="qp-mini-panel active" id="qp-mini-<?= qpSafe($data['server']) ?>-growth">
          <?php if (empty($data['growthTop'])): ?>
            <div class="qp-empty">目前沒有 QP 成長資料</div>
          <?php else: ?>
            <div class="qp-analysis-table-wrap">
              <table class="qp-analysis-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>玩家</th>
                    <th>Lv</th>
                    <th>QP 增加</th>
                    <th>排名變化</th>
                    <th>目前 QP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($data['growthTop'], 0, 10) as $idx => $r): ?>
                    <tr>
                      <td data-label="排行"><?= $idx + 1 ?></td>
                      <td data-label="玩家" class="qp-player-name"><?= qpPlayerLink($data['server'], $r['name'], 'qp-player-link') ?></td>
                      <td data-label="Lv"><?= (int)$r['level'] ?></td>
                      <td data-label="QP 增加" class="qp-gain">+<?= qpFmt($r['qp_gain']) ?></td>
                      <td data-label="排名變化">#<?= (int)$r['old_rank'] ?> → #<?= (int)$r['now_rank'] ?></td>
                      <td data-label="目前 QP"><?= qpFmt($r['now_qp']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="qp-mini-panel" id="qp-mini-<?= qpSafe($data['server']) ?>-rise">
          <?php if (empty($data['riseTop'])): ?>
            <div class="qp-empty">目前沒有 QP 躍升資料</div>
          <?php else: ?>
            <div class="qp-analysis-table-wrap">
              <table class="qp-analysis-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>玩家</th>
                    <th>Lv</th>
                    <th>躍升</th>
                    <th>排名變化</th>
                    <th>QP 增加</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($data['riseTop'], 0, 10) as $idx => $r): ?>
                    <tr>
                      <td data-label="排行"><?= $idx + 1 ?></td>
                      <td data-label="玩家" class="qp-player-name"><?= qpPlayerLink($data['server'], $r['name'], 'qp-player-link') ?></td>
                      <td data-label="Lv"><?= (int)$r['level'] ?></td>
                      <td data-label="躍升" class="qp-rise">▲<?= qpFmt($r['rank_rise']) ?></td>
                      <td data-label="排名變化">#<?= (int)$r['old_rank'] ?> → #<?= (int)$r['now_rank'] ?></td>
                      <td data-label="QP 增加" class="qp-gain">+<?= qpFmt($r['qp_gain']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="qp-mini-panel" id="qp-mini-<?= qpSafe($data['server']) ?>-new">
          <?php if (empty($data['newRows'])): ?>
            <div class="qp-empty">目前沒有新進榜玩家</div>
          <?php else: ?>
            <div class="qp-analysis-table-wrap">
              <table class="qp-analysis-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>玩家</th>
                    <th>Lv</th>
                    <th>QP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($data['newRows'], 0, 15) as $r): ?>
                    <tr>
                      <td data-label="排名">#<?= (int)$r['rank_num'] ?></td>
                      <td data-label="玩家" class="qp-player-name"><?= qpPlayerLink($data['server'], $r['name'], 'qp-player-link') ?></td>
                      <td data-label="Lv"><?= (int)$r['level'] ?></td>
                      <td data-label="QP"><?= qpFmt($r['qp']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="qp-analysis-card">
        <div class="qp-analysis-title"><?= qpSafe($label) ?> QP 高分層分布</div>
        <?php foreach ($data['distribution'] as $range => $count): ?>
          <?php $width = $maxDist > 0 ? max(4, round($count / $maxDist * 100)) : 0; ?>
          <div class="qp-dist-row">
            <div><?= qpSafe($range) ?></div>
            <div class="qp-dist-bar"><span style="width:<?= (int)$width ?>%;"></span></div>
            <div><?= (int)$count ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php
}



$sql2 = "WITH latest_snapshot AS (
            -- 取最新一次的月結快照
            SELECT *
            FROM ranking_qp_TW_history
            WHERE ts = (
              SELECT MAX(ts)
              FROM ranking_qp_TW_history
            )
          ),
          ranked AS (
            -- 給最新快照裡每位玩家，依 qp 排序編上名次
            SELECT
              ts,
              name,
              level,
              qp,
              ROW_NUMBER() OVER (ORDER BY qp DESC) AS rn
            FROM latest_snapshot
          )
          -- 篩出第 5、30、100 名
          SELECT
            rn    AS `rn`,
            ts    AS `ts`,
            name  AS `name`,
            level AS `level`,
            qp    AS `qp`
          FROM ranked
          WHERE rn IN (5, 30, 100)
          ORDER BY rn ASC;
        ";
$stmt2 = $db->query($sql2);

if (!$stmt2) {
  $err = $db->errorInfo();
  die("查詢失敗：{$err[2]}");
}
// 一定要先初始化
$ms_rank = [];
$ms_qp   = [];
$ms_ts   = [];

// 三種底線樣式：紅／藍／綠
$ms_style = [
  'style="background-color: #ffaeae;"',
  'style="background-color: #c3c3ff;"',
  'style="background-color: #c1ffc1;"',
];
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
  $ms_rank[] = (int)$row['rn'];
  $ms_qp[]   = (int)$row['qp'];
  $ms_ts[]   = $row['ts'];
}


// 以最新快照當天的第一筆作為比較基準；若當天只有一筆，則不顯示變化
$rankLatestTsTW = fetchQpCol($db, "SELECT MAX(ts) FROM ranking_qp_TW");
$rankBaseTsTW = $rankLatestTsTW ? fetchQpCol($db, "
  SELECT MIN(ts)
  FROM ranking_qp_TW
  WHERE DATE(ts) = DATE(?)
", [$rankLatestTsTW]) : null;

$prevRank = $prevBp = [];
if ($rankBaseTsTW && $rankLatestTsTW && $rankBaseTsTW !== $rankLatestTsTW) {
  $stmtPrev = $db->prepare("SELECT name, rank_num, qp
              FROM ranking_qp_TW
            WHERE ts = :base_ts");
  $stmtPrev->execute([':base_ts' => $rankBaseTsTW]);
  $prevSnapshot = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
  foreach ($prevSnapshot as $p) {
    $prevRank[$p['name']] = (int)$p['rank_num'];
    $prevBp[$p['name']] = (int)$p['qp'];
  }
}


// 1. 準備與執行查詢
// 取前 100 名（或依需求調整）
try {
  $stmt = $db->query("SELECT MAX(`ts`) FROM `ranking_qp_TW`");
  $lastUpdate = $stmt->fetchColumn();
} catch (PDOException $e) {
  $lastUpdate = null;
}

/* $sql = "SELECT 
            `rank_num`,
            `ts`,
            `name`, 
            `level`,
            `qp`
        FROM `ranking_qp_TW`
        WHERE `ts` = (
            SELECT MAX(`ts`) FROM `ranking_qp_TW`
        )
        ORDER BY `rank_num` DESC"; */
$sql = "SELECT 
    `rank_num`,
    `ts`,
    `name`, 
    `level`,
    `qp`
FROM `ranking_qp_TW`
WHERE `ts` = (
    SELECT MAX(`ts`) FROM `ranking_qp_TW`
)
ORDER BY `rank_num` ASC;";

$stmt = $db->query($sql);

if (!$stmt) {
  $err = $db->errorInfo();
  die("查詢失敗：{$err[2]}");
}

?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">

      <div class="qp-activity-wrap">
        <div class="qp-activity-hero">
          <div class="qp-activity-title">📘 QP 活躍分析</div>
          <div class="qp-activity-desc">
            QP 主要觀察玩家近期投入度、活躍度與遊玩量。
          </div>
          <div class="qp-activity-tabs">
            <button type="button" class="qp-activity-tab-btn active" data-server="TW">Steam / TW</button>
            <button type="button" class="qp-activity-tab-btn" data-server="JP">DMM / JP</button>
          </div>
        </div>
        <?php renderQpActivityPanel($qpActivityTW, 'Steam / TW'); ?>
        <?php renderQpActivityPanel($qpActivityJP, 'DMM / JP'); ?>
      </div>

      <div class="row">
        <!-- left column -->
        <div class="col-md-6">
          <div class="card-dark" style="padding: 5px;">
            <div class="card-gradient-header">本季Steam QP排行</div>
            <table id="example1" class="table datatable-init-hide">
              <thead>
                <tr>
                  <th>排名</th>
                  <th>玩家</th>
                  <th>等級</th>
                  <th>QP</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $i = 0;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                  $rank_num  = (int)$row['rank_num'];
                  $rawName   = (string)$row['name'];
                  $name      = htmlspecialchars($rawName, ENT_QUOTES);
                  $level     = (int)$row['level'];
                  $qp        = (int)$row['qp'];
                  $ts        = $row['ts'];

                  // 把時間字串取出時:分
                  $timePart = date('H:i', strtotime($ts));

                  if ($timePart === '00:00') {
                    // 00:00 這筆快照不做比較
                    $rankChange = '';
                    $qpChange   = '';
                  } else {
                    // --- 名次變化 Badge ---
                    $beforeRank = $prevRank[$rawName] ?? null;
                    if ($beforeRank === null) {
                      $rankChange = '<span class="badge badge-new text-sm">新進榜</span>';
                    } elseif ($rank_num === 1 && $beforeRank > 1) {
                      $rankChange = '<span class="badge badge-top text-sm">登頂</span>';
                    } elseif ($rank_num < $beforeRank) {
                      $delta = $beforeRank - $rank_num;
                      $rankChange = "<span class=\"badge badge-up text-sm\">↑{$delta}</span>";
                    } elseif ($rank_num > $beforeRank) {
                      $delta = $rank_num - $beforeRank;
                      $rankChange = "<span class=\"badge badge-down text-sm\">↓{$delta}</span>";
                    } else {
                      $rankChange = '<span class="text-sm">—</span>';
                    }

                    // --- QP 差值 ---
                    $beforeQp = $prevBp[$rawName] ?? null;
                    if ($beforeQp !== null) {
                      $diff  = $qp - $beforeQp;
                      $sign  = $diff >= 0 ? '+' : '';
                      $qpChange = "<small class=\"text-sm\">{$sign}{$diff}</small>";
                    } else {
                      $qpChange = '';
                    }
                  }




                  // --- 里程碑底色 ---
                  $style = '';
                  if (isset($ms_qp[$i]) && $qp < $ms_qp[$i]) {
                    $style = $ms_style[$i];
                    $i++;
                  }
                ?>
                  <tr <?= $style ?>>
                    <td><?= $rank_num ?></td>
                    <td>
                      <a class="qp-player-link" href="<?= qpSafe(qpPlayerUrl('TW', $rawName)) ?>"><?= $name ?></a> <?= $rankChange ?>
                    </td>
                    <td><?= $level ?></td>
                    <td><?= $qp ?> <?= $qpChange ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          <!-- /.card style="display: none;"-->
          <div class="card-dark">
            <div class="box-header">
              <h3 class="box-title">上季Steam QP里程碑:</h3>
            </div>
            <!-- /.box-header -->
            <div class="box-body">
              <table class="table table-bordered table-striped milestone-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <!-- <th>名稱</th> -->
                    <th>QP</th>
                    <th>更新時間</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = 0; $i < count($ms_rank); $i++): ?>
                    <tr <?= $ms_style[$i] ?>>
                      <td><?= htmlspecialchars($ms_rank[$i]) ?></td>
                      <td><?= htmlspecialchars($ms_qp[$i]) ?></td>
                      <td><?= htmlspecialchars($ms_ts[$i]) ?></td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>

            <!-- /.box-body -->
          </div>
          <!-- /.box -->
        </div>
        <!-- /.col -->
        <!-- right column -->
        <div class="col-md-6">
          <?php
          $sql2 = "WITH latest_snapshot AS (
            -- 取最新一次的月結快照
            SELECT *
            FROM ranking_qp_JP_history
            WHERE ts = (
              SELECT MAX(ts)
              FROM ranking_qp_JP_history
            )
          ),
          ranked AS (
            -- 給最新快照裡每位玩家，依 qp 排序編上名次
            SELECT
              ts,
              name,
              level,
              qp,
              ROW_NUMBER() OVER (ORDER BY qp DESC) AS rn
            FROM latest_snapshot
          )
          -- 篩出第 5、30、100 名
          SELECT
            rn    AS `rn`,
            ts    AS `ts`,
            name  AS `name`,
            level AS `level`,
            qp    AS `qp`
          FROM ranked
          WHERE rn IN (5, 30, 100)
          ORDER BY rn ASC;
        ";
          $stmt2 = $db->query($sql2);

          if (!$stmt2) {
            $err = $db->errorInfo();
            die("查詢失敗：{$err[2]}");
          }
          // 一定要先初始化
          $ms_rank_JP = [];
          $ms_qp_JP   = [];
          $ms_ts_JP   = [];

          // 三種底線樣式：紅／藍／綠
          $ms_style_JP = [
            'style="background-color: #ffaeae;"',
            'style="background-color: #c3c3ff;"',
            'style="background-color: #c1ffc1;"',
          ];
          while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $ms_rank_JP[] = (int)$row['rn'];
            $ms_qp_JP[]   = (int)$row['qp'];
            $ms_ts_JP[]   = $row['ts'];
          }


          // 以最新快照當天的第一筆作為比較基準；若當天只有一筆，則不顯示變化
          $rankLatestTsJP = fetchQpCol($db, "SELECT MAX(ts) FROM ranking_qp_JP");
          $rankBaseTsJP = $rankLatestTsJP ? fetchQpCol($db, "
            SELECT MIN(ts)
            FROM ranking_qp_JP
            WHERE DATE(ts) = DATE(?)
          ", [$rankLatestTsJP]) : null;

          $prevRank = $prevBp = [];
          if ($rankBaseTsJP && $rankLatestTsJP && $rankBaseTsJP !== $rankLatestTsJP) {
            $stmtPrev = $db->prepare("SELECT name, rank_num, qp
              FROM ranking_qp_JP
            WHERE ts = :base_ts");
            $stmtPrev->execute([':base_ts' => $rankBaseTsJP]);
            $prevSnapshot = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
            foreach ($prevSnapshot as $p) {
              $prevRank[$p['name']] = (int)$p['rank_num'];
              $prevBp[$p['name']] = (int)$p['qp'];
            }
          }


          // 1. 準備與執行查詢
          // 取前 100 名（或依需求調整）
          try {
            $stmt = $db->query("SELECT MAX(`ts`) FROM `ranking_qp_JP`");
            $lastUpdate_JP = $stmt->fetchColumn();
          } catch (PDOException $e) {
            $lastUpdate_JP = null;
          }

          $sql = "SELECT 
          `rank_num`,
          `ts`,
          `name`, 
          `level`,
          `qp`
      FROM `ranking_qp_JP`
      ORDER BY `ts` DESC, `rank_num` ASC LIMIT 100;";

          $stmt = $db->query($sql);

          if (!$stmt) {
            $err = $db->errorInfo();
            die("查詢失敗：{$err[2]}");
          }
          ?>
          <div class="card-dark" style="padding: 5px;">
            <div class="card-gradient-header">DMM QP排行</div>
            <table id="exampleJP" class="table datatable-init-hide">
              <thead>
                <tr>
                  <th>排名</th>
                  <th>玩家</th>
                  <th>等級</th>
                  <th>QP</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $i = 0;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                  $rank_num  = (int)$row['rank_num'];
                  $rawName   = (string)$row['name'];
                  $name      = htmlspecialchars($rawName, ENT_QUOTES);
                  $level     = (int)$row['level'];
                  $qp        = (int)$row['qp'];
                  $ts        = $row['ts'];

                  // 把時間字串取出時:分

                  $timePart = date('H:i', strtotime($ts));
                  if ($timePart === '00:00') {
                    // 00:00 這筆快照不做比較
                    $rankChange = '';
                    $qpChange   = '';
                    //print $timePart;
                  } else {
                    // --- 名次變化 Badge ---
                    $beforeRank = $prevRank[$rawName] ?? null;
                    if ($beforeRank === null) {
                      $rankChange = '<span class="badge badge-new text-sm">新進榜</span>';
                    } elseif ($rank_num === 1 && $beforeRank > 1) {
                      $rankChange = '<span class="badge badge-top text-sm">登頂</span>';
                    } elseif ($rank_num < $beforeRank) {
                      $delta = $beforeRank - $rank_num;
                      $rankChange = "<span class=\"badge badge-up text-sm\">↑{$delta}</span>";
                    } elseif ($rank_num > $beforeRank) {
                      $delta = $rank_num - $beforeRank;
                      $rankChange = "<span class=\"badge badge-down text-sm\">↓{$delta}</span>";
                    } else {
                      $rankChange = '<span class="text-sm">—</span>';
                    }


                    // --- QP 差值 ---
                    $beforeQp = $prevBp[$rawName] ?? null;
                    if ($beforeQp !== null) {
                      $diff  = $qp - $beforeQp;
                      $sign  = $diff >= 0 ? '+' : '';
                      $qpChange = "<small class=\"text-sm\">{$sign}{$diff}</small>";
                    } else {
                      $qpChange = '';
                    }
                  }



                  // --- 里程碑底色 ---
                  $style = '';
                  if (isset($ms_qp_JP[$i]) && $qp < $ms_qp_JP[$i]) {
                    $style = $ms_style_JP[$i];
                    $i++;
                  }
                ?>
                  <tr <?= $style ?>>
                    <td><?= $rank_num ?></td>
                    <td>
                      <a class="qp-player-link" href="<?= qpSafe(qpPlayerUrl('JP', $rawName)) ?>"><?= $name ?></a> <?= $rankChange ?>
                    </td>
                    <td><?= $level ?></td>
                    <td><?= $qp ?> <?= $qpChange ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          <!-- /.card style="display: none;"-->
          <div class="card-dark">
            <div class="box-header">
              <h3 class="box-title">上季DMM QP里程碑:</h3>
            </div>
            <!-- /.box-header -->
            <div class="box-body">
              <table class="table table-bordered table-striped milestone-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <!-- <th>名稱</th> -->
                    <th>QP</th>
                    <!-- <th>上季-勝</th>
                  <th>上季-敗</th>
                  <th>上季-總計</th>
                  <th>勝率</th> -->
                    <th>更新時間</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = 0; $i < count($ms_rank_JP); $i++): ?>
                    <tr <?= $ms_style_JP[$i] ?>>
                      <td><?= htmlspecialchars($ms_rank_JP[$i]) ?></td>
                      <td><?= htmlspecialchars($ms_qp_JP[$i]) ?></td>
                      <td><?= htmlspecialchars($ms_ts_JP[$i]) ?></td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
            <!-- /.box-body -->
          </div>
          <!-- /.box -->
        </div>
        <!-- /.col -->
      </div>
      <!-- /.row -->
    </div>
    <!-- /.container -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<!-- DataTables -->
<link rel="stylesheet" href="../assets/bower_components/datatables.net-bs/css/dataTables.bootstrap.min.css">
<script src="../assets/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="../assets/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>

<!-- page script -->
<script>
  const lastUpdateTW = <?= $lastUpdate ? json_encode($lastUpdate) : 'null' ?>;
  const lastUpdateJP = <?= $lastUpdate_JP ? json_encode($lastUpdate_JP) : 'null' ?>;
</script>

<script>
  $(function() {


    /* ===============================
       QP 活躍分析 Tab
    =============================== */
    $('.qp-activity-tab-btn').on('click', function() {
      const server = $(this).data('server');
      $('.qp-activity-tab-btn').removeClass('active');
      $(this).addClass('active');
      $('.qp-activity-panel').removeClass('active');
      $('#qp-activity-' + server).addClass('active');
    });

    $('.qp-mini-tab-btn').on('click', function() {
      const scope = $(this).closest('.qp-mini-tabs').data('scope');
      const tab = $(this).data('tab');
      $(this).closest('.qp-mini-tabs').find('.qp-mini-tab-btn').removeClass('active');
      $(this).addClass('active');
      $('#qp-activity-' + scope).find('.qp-mini-panel').removeClass('active');
      $('#qp-mini-' + scope + '-' + tab).addClass('active');
    });

    /* ===============================
       插入更新時間（共用）
    =============================== */
    function injectUpdateTime(tableSelector, lastUpdate) {
      if (!lastUpdate) return;

      const formatted = new Date(lastUpdate.replace(' ', 'T'))
        .toLocaleString('zh-TW', {
          hour12: false
        });

      const wrapper = $(tableSelector).closest('.dataTables_wrapper');

      if (wrapper.find('.datatable-update-time').length === 0) {
        wrapper.prepend(`
          <div class="datatable-update-time">
            最後更新：${formatted}
          </div>
        `);
      }
    }

    /* ===============================
       建立兩排工具列（共用）
    =============================== */
    function buildTopToolbar(dtApi) {
      const $wrapper = $(dtApi.table().container()).closest('.dataTables_wrapper');

      // 防止重複插入
      if ($wrapper.find('.dt-top-toolbar').length) return;

      const buttons = $wrapper.find('.dt-buttons').get(0);
      const length = $wrapper.find('.dataTables_length').get(0);
      const filter = $wrapper.find('.dataTables_filter').get(0);

      if (!buttons || !filter) return;

      const toolbar = document.createElement('div');
      toolbar.className = 'dt-top-toolbar';

      const rowTop = document.createElement('div');
      rowTop.className = 'dt-toolbar-row';

      const rowSearch = document.createElement('div');
      rowSearch.className = 'dt-toolbar-search';

      rowTop.appendChild(buttons);
      if (length) rowTop.appendChild(length);
      rowSearch.appendChild(filter);

      toolbar.appendChild(rowTop);
      toolbar.appendChild(rowSearch);

      $wrapper.prepend(toolbar);
    }

    /* ===============================
       QP Ranking Table 初始化
    =============================== */
    function initQPRankingTable(selector) {

      if ($.fn.DataTable.isDataTable(selector)) {
        $(selector).DataTable().destroy();
      }

      const table = $(selector).DataTable({
        dom: 'Blfrtip', // ⭐ 和 BP 一致（有 length）

        buttons: [{
            extend: 'excelHtml5',
            text: 'Excel',
            className: 'btn btn-default'
          },
          {
            text: '匯出截圖',
            className: 'btn btn-default',
            action: function(e, dt) {
              const wrapper = dt.table().container().parentNode;
              html2canvas(wrapper).then(canvas => {
                canvas.toBlob(blob => {
                  const a = document.createElement('a');
                  a.href = URL.createObjectURL(blob);
                  a.download = 'datatable.png';
                  a.click();
                });
              });
            }
          }
        ],

        paging: true,
        lengthChange: true,
        pageLength: 10,
        lengthMenu: [
          [10, 25, 50, 100, -1],
          ['10 列', '25 列', '50 列', '100 列', '全部']
        ],

        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        deferRender: true,

        order: [
          [0, 'asc']
        ],

        language: {
          lengthMenu: '顯示 _MENU_',
          paginate: {
            previous: '‹',
            next: '›'
          },
          info: '顯示第 _START_ 到 _END_ 筆，共 _TOTAL_ 筆'
        },

        /* ⭐ 關鍵：初始化完成後再處理工具列 */
        initComplete: function() {
          const api = this.api();
          buildTopToolbar(api);

          // 只調整自己這張表的搜尋框
          $(api.table().container())
            .closest('.dataTables_wrapper')
            .find('.dataTables_filter input')
            .css('width', '220px');
        }
      });

      return table;
    }

    /* ===============================
       啟動（QP）
    =============================== */
    initQPRankingTable('#example1');
    initQPRankingTable('#exampleJP');

    injectUpdateTime('#example1', lastUpdateTW);
    injectUpdateTime('#exampleJP', lastUpdateJP);

    /* 顯示表格（避免白閃） */
    $('#example1, #exampleJP')
      .removeClass('datatable-init-hide');
  });
</script>





<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>