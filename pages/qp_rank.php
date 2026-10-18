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

  .qp-compare-note {
    margin: 0 0 12px 0;
    padding: 9px 12px;
    border-radius: 10px;
    background: rgba(37, 99, 235, .12);
    border: 1px solid rgba(96, 165, 250, .25);
    color: #bfdbfe;
    font-size: 12px;
    line-height: 1.6;
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

  /* =========================
     STEAM_REWARD_TIERS_20260930
     Steam 主榜季末獎勵分水嶺：Top 5 / Top 30
  ========================= */
  #example1 tbody tr.reward-tier-top5 > td {
    background: rgba(52, 211, 153, .032) !important;
  }

  #example1 tbody tr.reward-tier-top30 > td {
    background: rgba(96, 165, 250, .020) !important;
  }

  #example1 tbody tr.reward-tier-top5 > td:first-child {
    box-shadow: inset 3px 0 0 rgba(52, 211, 153, .82);
    color: #a7f3d0;
    font-weight: 900;
  }

  #example1 tbody tr.reward-tier-top30 > td:first-child {
    box-shadow: inset 3px 0 0 rgba(96, 165, 250, .66);
  }

  #example1 tbody tr.reward-boundary-top5 > td {
    border-bottom: 2px solid rgba(52, 211, 153, .48) !important;
  }

  #example1 tbody tr.reward-boundary-top30 > td {
    border-bottom: 2px solid rgba(96, 165, 250, .34) !important;
  }

  .rank-reward-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    padding: 8px 10px 3px;
    color: #94a3b8;
    font-size: 12px;
  }

  .rank-reward-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, .10);
  }

  .rank-reward-chip::before {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 999px;
  }

  .rank-reward-chip.top5::before {
    background: rgba(52, 211, 153, .75);
  }

  .rank-reward-chip.top30::before {
    background: rgba(96, 165, 250, .72);
  }

  .dmm-archive-details {
    margin-top: 14px;
    margin-bottom: 14px;
    background: #0f172a;
    border: 1px solid rgba(148, 163, 184, .20);
    border-radius: 14px;
    overflow: hidden;
  }

  .dmm-archive-summary {
    cursor: pointer;
    list-style: none;
    padding: 13px 16px;
    color: #cbd5e1;
    font-weight: 800;
    background: rgba(148, 163, 184, .055);
  }

  .dmm-archive-summary::-webkit-details-marker {
    display: none;
  }

  .dmm-archive-summary::after {
    content: "＋";
    float: right;
    color: #94a3b8;
  }

  .dmm-archive-details[open] .dmm-archive-summary::after {
    content: "－";
  }

  .dmm-archive-body {
    padding: 10px;
  }


  /* =========================================================
     RANK_DUAL_PLAYER_LINKS_20260930
     玩家名稱 -> Fight / 小膠囊 -> Rank Player Detail
  ========================================================= */
  .rank-player-nav {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    max-width: 100%;
    vertical-align: middle;
  }

  .rank-player-fight-link {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .rank-player-detail-link {
    flex: 0 0 auto;
    min-width: 28px;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;

    padding: 1px 6px;
    border-radius: 999px;

    font-size: 10px;
    line-height: 1.5;
    font-weight: 900;

    text-decoration: none !important;
    vertical-align: middle;
    max-width: none !important;

    transition:
      background .15s ease,
      border-color .15s ease,
      color .15s ease;
  }

  .rank-player-detail-link-bp {
    color: #c4b5fd !important;
    background: rgba(124, 58, 237, .08);
    border: 1px solid rgba(167, 139, 250, .28);
  }

  .rank-player-detail-link-bp:hover {
    color: #fff !important;
    background: rgba(124, 58, 237, .28);
    border-color: rgba(196, 181, 253, .65);
  }

  .rank-player-detail-link-qp {
    color: #bfdbfe !important;
    background: rgba(37, 99, 235, .08);
    border: 1px solid rgba(96, 165, 250, .28);
  }

  .rank-player-detail-link-qp:hover {
    color: #fff !important;
    background: rgba(37, 99, 235, .27);
    border-color: rgba(147, 197, 253, .65);
  }

  @media (max-width: 768px) {
    .rank-player-nav {
      gap: 4px;
    }

    .rank-player-detail-link {
      padding: 1px 5px;
      font-size: 9px;
    }
  }

</style>

<?php
/* 放入你的 PHP程式    */

//$ack = $_SESSION["ack"] ?? 0;
$permission = $_SESSION["ack"] ?? 0;

/* QP_ACTIVITY_ADMIN_DEBUG_V1 */
$qpActivityErrors = [];

function qpActivityRecordError(Throwable $e, string $sql, array $params = []): void
{
  global $qpActivityErrors;

  $qpActivityErrors[] = [
    'message' => $e->getMessage(),
    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
    'params' => $params,
  ];
}


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
    qpActivityRecordError($e, $sql, $params);
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
    qpActivityRecordError($e, $sql, $params);
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
    qpActivityRecordError($e, $sql, $params);
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
/* QP_SEASON_RESET_WINDOW_V1
 * QP snapshot rules:
 * - TW reset boundary: first Tuesday of each month, 10:00 Taiwan time.
 * - JP reset boundary: first Thursday of each month, 10:00 Taiwan time (= 11:00 JST).
 * - after reset boundary, comparison NEVER crosses into the old season.
 * - QP importer may pad an incomplete ranking to 100 rows with NULL objects,
 *   so validity is based on real name + qp rows, not COUNT(*).
 */
function qpSeasonRule(string $server): array
{
  $rules = [
    'TW' => [
      'weekday' => 2,
      'reset_time' => '10:00:00',
    ],
    'JP' => [
      'weekday' => 4,
      'reset_time' => '10:00:00',
    ],
  ];

  if (!isset($rules[$server])) {
    throw new InvalidArgumentException("Unknown QP server: {$server}");
  }

  return $rules[$server];
}

function qpResetBoundaryForDate(string $server, string $date): ?string
{
  if ($server === 'TW') {
    $markerPath = dirname(__DIR__) . '/watcher/ranking_qp_season_started_TW.txt';
    if (is_file($markerPath)) {
      $markerTs = trim((string)@file_get_contents($markerPath));
      if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $markerTs)
          && substr($markerTs, 0, 10) === $date) {
        return $markerTs;
      }
    }
  }

  $rule = qpSeasonRule($server);
  $tz = new DateTimeZone('Asia/Taipei');

  $day = new DateTimeImmutable($date . ' 00:00:00', $tz);
  $first = new DateTimeImmutable($day->format('Y-m-01 00:00:00'), $tz);

  $firstWeekday = (int)$first->format('N');
  $offset = ((int)$rule['weekday'] - $firstWeekday + 7) % 7;
  $resetDate = $first->modify("+{$offset} days")->format('Y-m-d');

  if ($date !== $resetDate) {
    return null;
  }

  return $resetDate . ' ' . $rule['reset_time'];
}

function qpValidSnapshotHavingSql(): string
{
  return "SUM(CASE
    WHEN name IS NOT NULL
     AND TRIM(name) <> ''
     AND qp IS NOT NULL
    THEN 1 ELSE 0 END) >= 1";
}

function buildQpActivity(PDO $db, string $server): array
{
  $table = "ranking_qp_{$server}";
  $validHaving = qpValidSnapshotHavingSql();

  $latestTs = fetchQpCol($db, "
    SELECT ts
    FROM `$table`
    GROUP BY ts
    HAVING {$validHaving}
    ORDER BY ts DESC
    LIMIT 1
  ");

  $resetBoundary = null;
  $isPostResetWindow = false;
  $windowStart = null;
  $windowBaseTs = null;
  $prevTs = null;
  $compareTs = null;
  $compareModeText = '前次快照';

  if ($latestTs) {
    $latestDate = substr((string)$latestTs, 0, 10);
    $resetBoundary = qpResetBoundaryForDate($server, $latestDate);

    // 10:00 本身保留給舊季截止快照；新季 baseline 必須嚴格晚於 10:00。
    $isPostResetWindow = $resetBoundary !== null && strcmp((string)$latestTs, $resetBoundary) > 0;
    $windowStart = $isPostResetWindow
      ? $resetBoundary
      : $latestDate . ' 00:00:00';

    $windowOp = $isPostResetWindow ? '>' : '>=';

    $windowBaseTs = fetchQpCol($db, "
      SELECT ts
      FROM `$table`
      WHERE ts {$windowOp} ?
        AND ts <= ?
      GROUP BY ts
      HAVING {$validHaving}
      ORDER BY ts ASC
      LIMIT 1
    ", [$windowStart, $latestTs]);

    if ($isPostResetWindow) {
      $prevTs = fetchQpCol($db, "
        SELECT ts
        FROM `$table`
        WHERE ts < ?
          AND ts > ?
        GROUP BY ts
        HAVING {$validHaving}
        ORDER BY ts DESC
        LIMIT 1
      ", [$latestTs, $resetBoundary]);
    } else {
      $prevTs = fetchQpCol($db, "
        SELECT ts
        FROM `$table`
        WHERE ts < ?
        GROUP BY ts
        HAVING {$validHaving}
        ORDER BY ts DESC
        LIMIT 1
      ", [$latestTs]);
    }

    if ($windowBaseTs && $windowBaseTs !== $latestTs) {
      $compareTs = $windowBaseTs;
    } elseif ($prevTs) {
      $compareTs = $prevTs;
    }

    if ($isPostResetWindow) {
      $compareModeText = '新賽季';
    } elseif ($windowBaseTs && $windowBaseTs !== $latestTs) {
      $compareModeText = '今日';
    }
  }

  $data = [
    'server' => $server,
    'latestTs' => $latestTs,
    'prevTs' => $prevTs,
    'compareTs' => $compareTs,
    'compareModeText' => $compareModeText,
    'resetBoundary' => $resetBoundary,
    'isPostResetWindow' => $isPostResetWindow,
    'windowStart' => $windowStart,
    'windowBaseTs' => $windowBaseTs,
    'comparisonReady' => (bool)$compareTs,
    'summary' => [
      'current_players' => 0,
      'new_players' => 0,
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
      AND name IS NOT NULL
      AND TRIM(name) <> ''
      AND qp IS NOT NULL
    ORDER BY rank_num ASC
  ", [$latestTs]);

  $data['summary']['current_players'] = count($currentRows);

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
    $realCurrent = "
      c.name IS NOT NULL
      AND TRIM(c.name) <> ''
      AND c.qp IS NOT NULL
    ";

    $data['growthTop'] = fetchQpRows($db, "
      SELECT c.rank_num AS now_rank, p.rank_num AS old_rank, c.name, c.level,
        p.qp AS old_qp, c.qp AS now_qp, c.qp - p.qp AS qp_gain,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.qp IS NOT NULL
        AND c.qp > p.qp
      ORDER BY qp_gain DESC, c.rank_num ASC
      LIMIT 20
    ", [$compareTs, $latestTs]);

    $data['riseTop'] = fetchQpRows($db, "
      SELECT c.rank_num AS now_rank, p.rank_num AS old_rank, c.name, c.level,
        p.qp AS old_qp, c.qp AS now_qp, c.qp - p.qp AS qp_gain,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.qp IS NOT NULL
        AND CAST(p.rank_num AS SIGNED) > CAST(c.rank_num AS SIGNED)
      ORDER BY rank_rise DESC, qp_gain DESC
      LIMIT 20
    ", [$compareTs, $latestTs]);

    $data['newRows'] = fetchQpRows($db, "
      SELECT c.rank_num, c.name, c.level, c.qp
      FROM `$table` c
      LEFT JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.name IS NULL
      ORDER BY c.rank_num ASC
    ", [$compareTs, $latestTs]);

    $data['summary']['new_players'] = count($data['newRows']);

    $stat = fetchQpOne($db, "
      SELECT COUNT(*) AS active_players,
        COALESCE(SUM(c.qp - p.qp), 0) AS total_qp_gain,
        COALESCE(MAX(c.qp - p.qp), 0) AS max_qp_gain,
        ROUND(AVG(c.qp - p.qp), 1) AS avg_qp_gain
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.qp IS NOT NULL
        AND c.qp > p.qp
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

/* DMM_RETIRE_QP_20260930_V1
 * JP/DMM 保留歷史查詢，但不再視為即時排行。
 */
$qpActivityTW = buildQpActivity($db, 'TW');
$qpActivityJP = buildQpActivity($db, 'JP');

function renderQpActivityPanel(array $data, string $label): void
{
  $summary = $data['summary'];
  $growthFocus = $data['growthTop'][0] ?? null;
  $riseFocus = $data['riseTop'][0] ?? null;
  $maxDist = !empty($data['distribution']) ? max($data['distribution']) : 0;

  // TW_QP_NO_LEVEL_20260930_V1
  // Protocol 1.4 TW ranking only supplies player_name + point.
  // JP historical data may still have level, so only hide it for TW.
  $showLevel = (($data['server'] ?? '') !== 'TW');
?>
  <div class="qp-activity-panel <?= $data['server'] === 'TW' ? 'active' : '' ?>" id="qp-activity-<?= qpSafe($data['server']) ?>">
    <?php if (($data['server'] ?? '') === 'JP'): ?>
      <div class="qp-compare-note">
        📦 DMM / JP 已停止追蹤；以下僅為最後封存快照與歷史資料，不再代表即時排行。
      </div>
    <?php endif; ?>
    <div class="qp-compare-note">
      <?php if (!empty($data['isPostResetWindow'])): ?>
        賽季重置日：只比較重置後的新賽季快照｜
        <?php if (!empty($data['compareTs'])): ?>
          基準 <?= qpSafe($data['compareTs']) ?> → 最新 <?= qpSafe($data['latestTs']) ?>
        <?php else: ?>
          目前為新賽季第一份有效快照，等待下一份快照後開始計算變化
        <?php endif; ?>
      <?php elseif (!empty($data['compareTs'])): ?>
        比較基準：<?= qpSafe($data['compareTs']) ?> → <?= qpSafe($data['latestTs']) ?>
      <?php else: ?>
        目前沒有可比較的前次快照
      <?php endif; ?>
    </div>
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
        <div class="qp-stat-value"><?= $data['comparisonReady'] ? qpFmt($summary['active_players']) : '—' ?></div>
        <div class="qp-stat-note"><?= $data['comparisonReady'] ? 'QP 有增加的人數' : '等待下一份有效快照' ?></div>
      </div>
      <div class="qp-stat-card">
        <div class="qp-stat-label"><?= qpSafe($data['compareModeText']) ?> QP 總增加</div>
        <div class="qp-stat-value"><?= $data['comparisonReady'] ? qpFmt($summary['total_qp_gain']) : '—' ?></div>
        <div class="qp-stat-note"><?= $data['comparisonReady'] ? '平均 +' . qpSafe($summary['avg_qp_gain']) : '等待下一份有效快照' ?></div>
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
                    <?php if ($showLevel): ?><th>Lv</th><?php endif; ?>
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
                      <?php if ($showLevel): ?><td data-label="Lv"><?= (int)$r['level'] ?></td><?php endif; ?>
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
                    <?php if ($showLevel): ?><th>Lv</th><?php endif; ?>
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
                      <?php if ($showLevel): ?><td data-label="Lv"><?= (int)$r['level'] ?></td><?php endif; ?>
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
                    <?php if ($showLevel): ?><th>Lv</th><?php endif; ?>
                    <th>QP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($data['newRows'], 0, 15) as $r): ?>
                    <tr>
                      <td data-label="排名">#<?= (int)$r['rank_num'] ?></td>
                      <td data-label="玩家" class="qp-player-name"><?= qpPlayerLink($data['server'], $r['name'], 'qp-player-link') ?></td>
                      <?php if ($showLevel): ?><td data-label="Lv"><?= (int)$r['level'] ?></td><?php endif; ?>
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


// 主排行直接沿用 QP Activity 的有效快照與比較基準。
$rankLatestTsTW = $qpActivityTW['latestTs'] ?? null;
$rankBaseTsTW = $qpActivityTW['compareTs'] ?? null;

$prevRank = $prevBp = [];
if ($rankBaseTsTW) {
  $stmtPrev = $db->prepare("SELECT name, rank_num, qp
    FROM ranking_qp_TW
    WHERE ts = :base_ts
      AND name IS NOT NULL
      AND TRIM(name) <> ''
      AND qp IS NOT NULL");
  $stmtPrev->execute([':base_ts' => $rankBaseTsTW]);
  $prevSnapshot = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);

  foreach ($prevSnapshot as $p) {
    $prevRank[$p['name']] = (int)$p['rank_num'];
    $prevBp[$p['name']] = (int)$p['qp'];
  }
}
$rankCompareReadyTW = !empty($rankBaseTsTW);


// 1. 準備與執行查詢
// 主榜只讀最新「有效快照」中的真實玩家，不讀 NULL padding。
$lastUpdate = $rankLatestTsTW;

$sql = "SELECT
    rank_num,
    ts,
    name,
    level,
    qp
  FROM ranking_qp_TW
  WHERE ts = :latest_ts
    AND name IS NOT NULL
    AND TRIM(name) <> ''
    AND qp IS NOT NULL
  ORDER BY rank_num ASC";

$stmt = $db->prepare($sql);
$stmt->execute([':latest_ts' => $lastUpdate]);

// RANK_MAINTENANCE_GATE_V1
$rankingMaintenanceFlag = dirname(__DIR__) . '/watcher/ranking_qp_maintenance.flag';
$rankingMaintenance = is_file($rankingMaintenanceFlag);

?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">

      <?php if ($rankingMaintenance): ?>
        <div style="margin:14px 0 18px;padding:18px;border-radius:14px;border:1px solid rgba(250,204,21,.55);background:#241f0d;color:#fde68a;">
          <div style="font-size:18px;font-weight:900;margin-bottom:6px;">🛠️ Steam 排行榜維護中</div>
          <div style="line-height:1.7;color:#fef3c7;">
            官方正在進行賽季切換，維修期間排行榜快照可能不完整或異常，因此暫停公開即時 QP 排行與活躍分析。
            系統偵測到 QP 榜單完成清零、確認新賽季開始後，會自動恢復公開。
          </div>
        </div>
      <?php else: ?>
      <div class="qp-activity-wrap">
        <div class="qp-activity-hero">
          <div class="qp-activity-title">📘 QP 活躍分析</div>
          <div class="qp-activity-desc">
            QP 主要觀察玩家近期投入度、活躍度與遊玩量。
          </div>
          <div class="qp-activity-tabs">
            <button type="button" class="qp-activity-tab-btn active" data-server="TW">Steam / TW</button>
            <button type="button" class="qp-activity-tab-btn" data-server="JP">DMM / JP（歷史封存）</button>
          </div>
        </div>

        <?php if ((int)$permission >= 2): ?>
          <div style="
            margin-bottom:14px;
            padding:14px;
            background:#101827;
            border:1px solid rgba(96,165,250,.55);
            border-radius:12px;
            color:#dbeafe;
          ">
            <div style="font-size:16px;font-weight:900;margin-bottom:8px;">
              🛠 QP DEBUG（僅管理員可見）
            </div>

            <div style="font-size:13px;line-height:1.8;margin-bottom:10px;">
              <strong>TW</strong>
              ｜latest: <?= qpSafe($qpActivityTW['latestTs'] ?? 'NULL') ?>
              ｜compare: <?= qpSafe($qpActivityTW['compareTs'] ?? 'NULL') ?>
              ｜current players: <?= (int)($qpActivityTW['summary']['current_players'] ?? 0) ?>
              ｜new: <?= (int)($qpActivityTW['summary']['new_players'] ?? 0) ?>
              ｜reset: <?= qpSafe($qpActivityTW['resetBoundary'] ?? 'N/A') ?>
              ｜baseline: <?= qpSafe($qpActivityTW['windowBaseTs'] ?? 'NULL') ?>
              <br>

              <strong>JP</strong>
              ｜latest: <?= qpSafe($qpActivityJP['latestTs'] ?? 'NULL') ?>
              ｜compare: <?= qpSafe($qpActivityJP['compareTs'] ?? 'NULL') ?>
              ｜current players: <?= (int)($qpActivityJP['summary']['current_players'] ?? 0) ?>
              ｜new: <?= (int)($qpActivityJP['summary']['new_players'] ?? 0) ?>
              ｜reset: <?= qpSafe($qpActivityJP['resetBoundary'] ?? 'N/A') ?>
              ｜baseline: <?= qpSafe($qpActivityJP['windowBaseTs'] ?? 'NULL') ?>
            </div>

            <?php if (empty($qpActivityErrors)): ?>
              <div style="background:#102018;color:#86efac;padding:10px;border-radius:8px;">
                ✓ QP Activity 查詢沒有捕捉到 SQL Exception
              </div>
            <?php else: ?>
              <div style="margin-bottom:8px;font-weight:800;color:#fca5a5;">
                發現 <?= count($qpActivityErrors) ?> 個 SQL 錯誤
              </div>

              <?php foreach ($qpActivityErrors as $idx => $err): ?>
                <details style="margin:8px 0;padding:10px;background:#0b0c10;border-radius:8px;">
                  <summary style="cursor:pointer;font-weight:800;color:#fca5a5;">
                    ERROR #<?= $idx + 1 ?> <?= qpSafe($err['message'] ?? 'Unknown error') ?>
                  </summary>

                  <div style="margin-top:10px;color:#fda4af;font-weight:800;">SQL</div>
                  <pre style="white-space:pre-wrap;word-break:break-word;background:#111827;color:#e5e7eb;padding:10px;border-radius:6px;"><?= qpSafe($err['sql'] ?? '') ?></pre>

                  <div style="color:#fda4af;font-weight:800;">Params</div>
                  <pre style="white-space:pre-wrap;word-break:break-word;background:#111827;color:#e5e7eb;padding:10px;border-radius:6px;"><?= qpSafe(json_encode(
                      $err['params'] ?? [],
                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )) ?></pre>
                </details>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php renderQpActivityPanel($qpActivityTW, 'Steam / TW'); ?>
        <?php renderQpActivityPanel($qpActivityJP, 'DMM / JP（歷史封存）'); ?>
      </div>
      <?php endif; ?>

      <div class="row">
        <!-- Steam main column -->
        <div class="col-md-12">
          <?php if (!$rankingMaintenance): ?>
          <div class="card-dark" style="padding: 5px;">
            <div class="card-gradient-header">本季Steam QP排行</div>
            <div class="rank-reward-legend" aria-label="季末獎勵分區">
              <span>季末獎勵區：</span>
              <span class="rank-reward-chip top5">#1–5</span>
              <span class="rank-reward-chip top30">#6–30</span>
              <span>#31+</span>
            </div>
            <table id="example1" class="table datatable-init-hide">
              <thead>
                <tr>
                  <th>排名</th>
                  <th>玩家</th>
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
                  $qp        = (int)$row['qp'];
                  $ts        = $row['ts'];

                  // 把時間字串取出時:分
                  $timePart = date('H:i', strtotime($ts));

                  if (!$rankCompareReadyTW) {
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




                  // 季末獎勵分區：Top 5 / Top 30
                  $rewardClass = $rank_num <= 5
                    ? 'reward-tier-top5'
                    : ($rank_num <= 30 ? 'reward-tier-top30' : '');
                  if ($rank_num === 5) {
                    $rewardClass .= ' reward-boundary-top5';
                  } elseif ($rank_num === 30) {
                    $rewardClass .= ' reward-boundary-top30';
                  }
                ?>
                  <tr class="<?= htmlspecialchars(trim($rewardClass), ENT_QUOTES) ?>">
                    <td><?= $rank_num ?></td>
                    <td>
                      <span class="rank-player-nav">
                        <a
                          class="rank-player-detail-link rank-player-detail-link-qp"
                          href="<?= qpSafe(qpPlayerUrl('TW', $rawName)) ?>"
                          title="查看 QP 玩家詳情">QP</a>
                        <a
                          class="qp-player-link rank-player-fight-link"
                          href="/pages/fight.php?player_name=<?= urlencode($rawName) ?>"
                          title="查看對戰紀錄"><?= $name ?></a>
                      </span> <?= $rankChange ?>
                    </td>
                    <td><?= $qp ?> <?= $qpChange ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
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
        <!-- DMM / JP archived column -->
        <div class="col-md-12">
          <details class="dmm-archive-details">
            <summary class="dmm-archive-summary">📦 DMM / JP 排行歷史封存（已退役，點擊展開）</summary>
            <div class="dmm-archive-body">
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


          // DMM 主排行直接沿用 QP Activity 的有效快照與比較基準。
          $rankLatestTsJP = $qpActivityJP['latestTs'] ?? null;
          $rankBaseTsJP = $qpActivityJP['compareTs'] ?? null;

          $prevRank = $prevBp = [];
          if ($rankBaseTsJP) {
            $stmtPrev = $db->prepare("SELECT name, rank_num, qp
              FROM ranking_qp_JP
              WHERE ts = :base_ts
                AND name IS NOT NULL
                AND TRIM(name) <> ''
                AND qp IS NOT NULL");
            $stmtPrev->execute([':base_ts' => $rankBaseTsJP]);
            $prevSnapshot = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);

            foreach ($prevSnapshot as $p) {
              $prevRank[$p['name']] = (int)$p['rank_num'];
              $prevBp[$p['name']] = (int)$p['qp'];
            }
          }
          $rankCompareReadyJP = !empty($rankBaseTsJP);


          // 1. 準備與執行查詢
          // DMM 主榜固定讀同一個最新有效快照，避免不同 timestamp 混入同一張表。
          $lastUpdate_JP = $rankLatestTsJP;

          $sql = "SELECT
              rank_num,
              ts,
              name,
              level,
              qp
            FROM ranking_qp_JP
            WHERE ts = :latest_ts
              AND name IS NOT NULL
              AND TRIM(name) <> ''
              AND qp IS NOT NULL
            ORDER BY rank_num ASC";

          $stmt = $db->prepare($sql);
          $stmt->execute([':latest_ts' => $lastUpdate_JP]);

          ?>
          <div class="card-dark" style="padding: 5px;">
            <div class="card-gradient-header">DMM QP排行（歷史封存）</div>
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
                  if (!$rankCompareReadyJP) {
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
                      <span class="rank-player-nav">
                        <a
                          class="rank-player-detail-link rank-player-detail-link-qp"
                          href="<?= qpSafe(qpPlayerUrl('JP', $rawName)) ?>"
                          title="查看 QP 玩家詳情">QP</a>
                        <a
                          class="qp-player-link rank-player-fight-link"
                          href="/pages/fight.php?player_name=<?= urlencode($rawName) ?>"
                          title="查看對戰紀錄"><?= $name ?></a>
                      </span> <?= $rankChange ?>
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
          </details>
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
            排行有效快照：${formatted}
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
    function initQPRankingTable(selector, defaultPageLength) {

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
        pageLength: defaultPageLength,
        lengthMenu: [
          [10, 30, 50, 100, -1],
          ['10 列', '30 列', '50 列', '100 列', '全部']
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
    initQPRankingTable('#example1', 30);
    initQPRankingTable('#exampleJP', 10);

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