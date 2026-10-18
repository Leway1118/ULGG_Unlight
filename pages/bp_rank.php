<?php
/* session_start(); */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定

$seoTitle = 'BP 排行 Rank | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "bp_rank";
$pageTitleFull = 'BP 排行 Rank | UL.GG 戰績網'; //桌機
$pageTitleText = 'BP 排行 Rank'; //手機

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

  /* 表頭不換行 */
  table.dataTable thead th {
    white-space: nowrap;
  }

  /* 角色 / 名稱欄位不斷行 */
  td.character-col {
    white-space: nowrap;
    max-width: 180px;
    /* 建議加，避免過長 */
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* 表格寬度計算穩定 */
  table.dataTable {
    table-layout: fixed;
  }

  /* 外層撐滿 card */
  .table-full {
    width: 100%;
    overflow-x: auto;
    /* 只有真的超寬才會出現 */
  }

  /* 表格一定吃滿 */
  .table-full table.dataTable {
    width: 100% !important;
    table-layout: auto;
    /* ⭐ 不要 fixed */
  }

  /* =========================
   BP Rank 手機縮排
========================= */
  @media (max-width: 768px) {

    /* 隱藏次要欄位 */
    .hide-mobile {
      display: none !important;
    }

    /* 整體字體略縮 */
    #bpTable {
      font-size: 13px;
    }

    /* Rank */
    .col-rank {
      width: 36px;
      text-align: center;
      color: #aaa;
    }

    /* 名稱：單行省略 */
    .col-name {
      max-width: 140px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .col-name a {
      display: inline-block;
      max-width: 100%;
      vertical-align: middle;
    }

    /* BP */
    .col-bp {
      width: 72px;
      text-align: right;
      font-weight: bold;
    }

    /* 勝率 */
    .col-rate {
      width: 64px;
      text-align: right;
    }

    .bp-change {
      display: none !important;
    }
  }

  .h3 {
    color: #dfdfdf;
  }

  /* =========================
   BP player detail links
========================= */
  .bp-player-link {
    font-weight: 700;
    text-decoration: none;
  }

  .bp-player-link:hover {
    text-decoration: underline;
  }

  .bp-delta {
    margin-left: 4px;
    font-weight: 700;
  }

  .bp-delta.up {
    color: #22c55e;
  }

  .bp-delta.down {
    color: #ef4444;
  }

  .bp-delta.same {
    color: #94a3b8;
  }

  /* =========================
   BP Activity Analysis
========================= */
  .bp-activity-wrap {
    margin-bottom: 18px;
  }

  .bp-activity-hero {
    background: linear-gradient(135deg, #111827, #312e81);
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 16px;
    padding: 18px;
    color: #fff;
    margin-bottom: 14px;
    box-shadow: 0 8px 22px rgba(0, 0, 0, .22);
  }

  .bp-activity-title {
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .bp-activity-desc {
    color: rgba(255, 255, 255, .76);
    font-size: 13px;
    line-height: 1.7;
  }

  .bp-activity-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0;
  }

  .bp-activity-tab-btn {
    border: 1px solid rgba(255, 255, 255, .18);
    background: #1f2937;
    color: #ddd6fe;
    border-radius: 999px;
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
  }

  .bp-activity-tab-btn.active {
    background: #7c3aed;
    color: #fff;
    border-color: #a78bfa;
  }

  .bp-activity-panel {
    display: none;
  }

  .bp-activity-panel.active {
    display: block;
  }

  .bp-compare-note {
    margin: 0 0 12px 0;
    padding: 9px 12px;
    border-radius: 10px;
    background: rgba(124, 58, 237, .12);
    border: 1px solid rgba(167, 139, 250, .24);
    color: #c4b5fd;
    font-size: 12px;
    line-height: 1.6;
  }

  .bp-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
  }

  .bp-stat-card {
    background: #111827;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 12px;
    padding: 12px;
    color: #fff;
  }

  .bp-stat-label {
    color: #9ca3af;
    font-size: 12px;
    margin-bottom: 6px;
  }

  .bp-stat-value {
    font-size: 21px;
    font-weight: 800;
    line-height: 1.1;
  }

  .bp-stat-note {
    color: #a5b4fc;
    font-size: 11px;
    margin-top: 6px;
  }

  .bp-focus-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
  }

  .bp-focus-card {
    background: linear-gradient(135deg, #0f172a, #1e1b4b);
    border: 1px solid rgba(167, 139, 250, .32);
    border-radius: 14px;
    padding: 14px;
    color: #e5e7eb;
  }

  .bp-focus-label {
    color: #c4b5fd;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 6px;
  }

  .bp-focus-name {
    font-size: 20px;
    font-weight: 800;
    color: #fff;
    margin-bottom: 4px;
  }

  .bp-focus-main {
    font-size: 24px;
    font-weight: 900;
    color: #facc15;
    margin-bottom: 5px;
  }

  .bp-focus-sub {
    color: #cbd5e1;
    font-size: 12px;
  }

  .bp-analysis-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(280px, .55fr);
    gap: 16px;
    align-items: start;
  }

  .bp-analysis-card {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 14px;
    padding: 14px;
    color: #e5e7eb;
    margin-bottom: 12px;
  }

  .bp-analysis-title {
    font-weight: 800;
    font-size: 16px;
    color: #fff;
    margin-bottom: 10px;
  }

  .bp-mini-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0 14px;
  }

  .bp-mini-tab-btn {
    border: 1px solid rgba(148, 163, 184, .35);
    background: rgba(15, 23, 42, .55);
    color: #cbd5e1;
    border-radius: 999px;
    padding: 8px 13px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
  }

  .bp-mini-tab-btn.active {
    background: #7c3aed;
    border-color: #a78bfa;
    color: #fff;
  }

  .bp-mini-panel {
    display: none;
  }

  .bp-mini-panel.active {
    display: block;
  }

  .bp-analysis-table {
    width: 100%;
    border-collapse: collapse;
  }

  .bp-analysis-table th {
    background: #1f2937;
    color: #cbd5e1;
    font-size: 12px;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    white-space: nowrap;
  }

  .bp-analysis-table td {
    color: #e5e7eb;
    font-size: 12px;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    white-space: nowrap;
  }

  .bp-player-name {
    font-weight: 800;
    color: #fff;
  }

  .bp-player-link {
    color: inherit;
    text-decoration: none;
    border-bottom: 1px dashed rgba(196, 181, 253, .65);
  }

  .bp-player-link:hover {
    color: #c4b5fd;
    text-decoration: none;
  }

  .bp-focus-card-link {
    display: block;
    text-decoration: none;
    transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
  }

  .bp-focus-card-link:hover {
    color: #e5e7eb;
    text-decoration: none;
    transform: translateY(-2px);
    border-color: rgba(196, 181, 253, .72);
    box-shadow: 0 8px 22px rgba(124, 58, 237, .24);
  }

  .bp-gain {
    color: #86efac;
    font-weight: 800;
  }

  .bp-rise {
    color: #facc15;
    font-weight: 800;
  }

  .bp-loss {
    color: #f87171;
    font-weight: 800;
  }

  .bp-same {
    color: #94a3b8;
    font-weight: 800;
  }

  .bp-empty {
    padding: 18px;
    text-align: center;
    color: #94a3b8;
    background: rgba(255, 255, 255, .04);
    border-radius: 10px;
  }

  .bp-dist-row {
    display: grid;
    grid-template-columns: 90px 1fr 38px;
    gap: 8px;
    align-items: center;
    margin-bottom: 10px;
    font-size: 12px;
    color: #cbd5e1;
  }

  .bp-dist-bar {
    height: 10px;
    background: #1f2937;
    border-radius: 999px;
    overflow: hidden;
  }

  .bp-dist-bar span {
    display: block;
    height: 100%;
    background: linear-gradient(90deg, #7c3aed, #facc15);
    border-radius: 999px;
  }

  @media (max-width: 1199px) {
    .bp-stat-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .bp-analysis-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 991px) {
    .bp-analysis-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 767px) {

    .bp-stat-grid,
    .bp-focus-grid {
      grid-template-columns: 1fr;
    }

    .bp-analysis-table-wrap {
      overflow-x: auto;
    }

    .bp-analysis-table {
      min-width: 650px;
    }
  }

  @media (max-width: 575px) {
    .bp-analysis-card {
      padding: 14px;
      border-radius: 16px;
    }

    .bp-analysis-title {
      font-size: 16px;
      margin-bottom: 10px;
    }

    .bp-empty {
      padding: 16px;
      font-size: 13px;
    }

    .bp-mini-tabs {
      display: grid;
      grid-template-columns: 1fr;
      gap: 8px;
    }

    .bp-mini-tab-btn {
      width: 100%;
      padding: 10px 12px;
      text-align: center;
    }

    .bp-analysis-table-wrap {
      overflow: visible;
    }

    .bp-analysis-table {
      min-width: 0;
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 10px;
    }

    .bp-analysis-table thead {
      display: none;
    }

    .bp-analysis-table,
    .bp-analysis-table tbody,
    .bp-analysis-table tr,
    .bp-analysis-table td {
      display: block;
      width: 100%;
    }

    .bp-analysis-table tr {
      background: rgba(15, 23, 42, .72);
      border: 1px solid rgba(148, 163, 184, .18);
      border-radius: 14px;
      padding: 12px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, .16);
    }

    .bp-analysis-table td {
      border: 0 !important;
      padding: 4px 0;
      color: #cbd5e1;
      white-space: normal;
    }

    .bp-analysis-table td:first-child {
      color: #c4b5fd;
      font-size: 12px;
      font-weight: 900;
      margin-bottom: 4px;
    }

    .bp-analysis-table td:nth-child(2) {
      font-size: 17px;
      font-weight: 900;
      color: #fff;
      margin-bottom: 6px;
    }

    .bp-analysis-table td:nth-child(n+3) {
      display: inline-flex;
      width: auto;
      margin-right: 10px;
      margin-top: 4px;
      font-size: 13px;
    }

    .bp-analysis-table td[data-label]:nth-child(n+3)::before {
      content: attr(data-label) "：";
      color: #94a3b8;
      font-weight: 700;
      margin-right: 2px;
    }

    .bp-analysis-table .bp-player-link {
      color: #fff;
      text-decoration: none;
    }

    .bp-analysis-table .bp-player-link:hover {
      color: #c4b5fd;
    }

    .bp-dist-row {
      grid-template-columns: 78px minmax(0, 1fr) 34px;
      gap: 8px;
      font-size: 12px;
    }

    .bp-dist-bar {
      height: 9px;
      background: rgba(148, 163, 184, .16);
    }

    .bp-dist-bar span {
      background: linear-gradient(90deg, #a78bfa, #facc15);
    }
  }


  /* =========================
     BP_ONLY_UI_20260926
     2026/09 game update no longer exposes reliable W/L/D counters.
     Keep the page focused on rank + BP snapshot deltas.
  ========================= */
  .bp-snapshot-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 7px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
    background: rgba(124, 58, 237, .14);
    border: 1px solid rgba(167, 139, 250, .28);
    color: #c4b5fd;
  }

  .bp-rank-delta-cell {
    white-space: nowrap;
    font-weight: 800;
  }

  .bp-rank-delta-up { color: #86efac; }
  .bp-rank-delta-down { color: #f87171; }
  .bp-rank-delta-same { color: #94a3b8; }

  .bp-rank-change-inline { display: none; }

  @media (max-width: 768px) {
    .bp-rank-change-inline { display: inline; margin-left: 4px; }
    .col-rank-delta { display: none !important; }
    .col-bp { width: 92px; }
    .col-rate { display: none !important; }
  }


  /* =========================
     STEAM_REWARD_TIERS_20260930
     Steam 主榜季末獎勵分水嶺：Top 5 / Top 30
  ========================= */
  #example1 tbody tr.reward-tier-top5 > td {
    background: rgba(250, 204, 21, .035) !important;
  }

  #example1 tbody tr.reward-tier-top30 > td {
    background: rgba(167, 139, 250, .022) !important;
  }

  #example1 tbody tr.reward-tier-top5 > td:first-child {
    box-shadow: inset 3px 0 0 rgba(250, 204, 21, .82);
    color: #fde68a;
    font-weight: 900;
  }

  #example1 tbody tr.reward-tier-top30 > td:first-child {
    box-shadow: inset 3px 0 0 rgba(167, 139, 250, .66);
  }

  #example1 tbody tr.reward-boundary-top5 > td {
    border-bottom: 2px solid rgba(250, 204, 21, .48) !important;
  }

  #example1 tbody tr.reward-boundary-top30 > td {
    border-bottom: 2px solid rgba(167, 139, 250, .34) !important;
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
    background: rgba(250, 204, 21, .75);
  }

  .rank-reward-chip.top30::before {
    background: rgba(167, 139, 250, .72);
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
/* BP_ACTIVITY_ADMIN_DEBUG_V1 */
/* BP_ONLY_UI_20260926: ranking UI no longer depends on W/L/D counters. */
$bpActivityErrors = [];

function bpActivityRecordError(Throwable $e, string $sql, array $params = []): void
{
  global $bpActivityErrors;

  $bpActivityErrors[] = [
    'message' => $e->getMessage(),
    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
    'params' => $params,
  ];
}

function bpFmt($v)
{
  return number_format((int)$v);
}

function bpSafe($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function bpPlayerUrl(string $server, string $name): string
{
  return '/pages/bp_player.php?server=' . rawurlencode($server) . '&name=' . rawurlencode($name);
}

function bpPlayerLink(string $server, string $name, ?string $class = null): string
{
  $classAttr = $class ? ' class="' . bpSafe($class) . '"' : '';
  return '<a' . $classAttr . ' href="' . bpSafe(bpPlayerUrl($server, $name)) . '">' . bpSafe($name) . '</a>';
}

function fetchBpRows(PDO $db, string $sql, array $params = []): array
{
  try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    bpActivityRecordError($e, $sql, $params);
    return [];
  }
}

function fetchBpOne(PDO $db, string $sql, array $params = []): ?array
{
  try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    bpActivityRecordError($e, $sql, $params);
    return null;
  }
}

function fetchBpCol(PDO $db, string $sql, array $params = [])
{
  try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
  } catch (Throwable $e) {
    bpActivityRecordError($e, $sql, $params);
    return null;
  }
}

function bpMedian(array $nums): int
{
  $nums = array_values(array_filter(array_map('intval', $nums), fn($v) => $v >= 0));
  sort($nums);
  $count = count($nums);
  if ($count === 0) return 0;
  $mid = intdiv($count, 2);
  return $count % 2 ? $nums[$mid] : (int)round(($nums[$mid - 1] + $nums[$mid]) / 2);
}

/* BP_SEASON_RESET_WINDOW_V1_1
 * Snapshot rules:
 * - heartbeat rows (blank name / NULL bp) are NOT valid ranking snapshots.
 * - a snapshot with >=1 real player is valid, even if the new season currently has <50 players.
 * - TW season boundary: first Tuesday of the month, 10:00 Taiwan time.
 * - JP season boundary: first Thursday of the month, 10:00 Taiwan time
 *   (= 11:00 JST maintenance/ranking cutoff).
 * - after the season boundary, never compare against a pre-reset snapshot.
 */
function bpSeasonRule(string $server): array
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
    throw new InvalidArgumentException("Unknown BP server: {$server}");
  }

  return $rules[$server];
}

function bpResetBoundaryForDate(string $server, string $date): ?string
{
  if ($server === 'TW') {
    $markerPath = dirname(__DIR__) . '/watcher/ranking_bp_season_started_TW.txt';
    if (is_file($markerPath)) {
      $markerTs = trim((string)@file_get_contents($markerPath));
      if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $markerTs)
          && substr($markerTs, 0, 10) === $date) {
        return $markerTs;
      }
    }
  }

  $rule = bpSeasonRule($server);
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

function bpValidSnapshotHavingSql(): string
{
  return "SUM(CASE
    WHEN name IS NOT NULL
     AND TRIM(name) <> ''
     AND bp IS NOT NULL
    THEN 1 ELSE 0 END) >= 1";
}

function buildBpActivity(PDO $db, string $server): array
{
  $table = "ranking_bp_{$server}";
  $validHaving = bpValidSnapshotHavingSql();

  $latestTs = fetchBpCol($db, "
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
    $resetBoundary = bpResetBoundaryForDate($server, $latestDate);

    $isPostResetWindow = $resetBoundary !== null && strcmp((string)$latestTs, $resetBoundary) > 0;
    $windowStart = $isPostResetWindow
      ? $resetBoundary
      : $latestDate . ' 00:00:00';

    $windowOp = $isPostResetWindow ? '>' : '>=';

    $windowBaseTs = fetchBpCol($db, "
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
      $prevTs = fetchBpCol($db, "
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
      $prevTs = fetchBpCol($db, "
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
      'max_bp' => 0,
      'avg_bp' => 0,
      'median_bp' => 0,
      'up_players' => 0,
      'down_players' => 0,
      'same_players' => 0,
      'new_players' => 0,
      'total_bp_gain' => 0,
      'total_bp_loss' => 0,
      'max_bp_gain' => 0,
      'max_bp_loss' => 0,
      'avg_bp_diff' => 0,
      'max_rank_rise' => 0
    ],
    'growthTop' => [],
    'riseTop' => [],
    'lossTop' => [],
    'newRows' => [],
    'distribution' => []
  ];

  if (!$latestTs) return $data;

  $currentRows = fetchBpRows($db, "
    SELECT rank_num, name, level, bp, ts
    FROM `$table`
    WHERE ts = ?
      AND name IS NOT NULL
      AND TRIM(name) <> ''
      AND bp IS NOT NULL
    ORDER BY rank_num ASC
  ", [$latestTs]);

  $bpList = array_column($currentRows, 'bp');
  $data['summary']['max_bp'] = $bpList ? max(array_map('intval', $bpList)) : 0;
  $data['summary']['avg_bp'] = $bpList ? (int)round(array_sum(array_map('intval', $bpList)) / count($bpList)) : 0;
  $data['summary']['median_bp'] = bpMedian($bpList);

  $buckets = [
    '<1500' => [PHP_INT_MIN, 1499],
    '1500-1549' => [1500, 1549],
    '1550-1599' => [1550, 1599],
    '1600-1649' => [1600, 1649],
    '1650-1699' => [1650, 1699],
    '1700-1799' => [1700, 1799],
    '1800+' => [1800, PHP_INT_MAX]
  ];

  foreach ($buckets as $label => [$min, $max]) {
    $data['distribution'][$label] = 0;
    foreach ($currentRows as $r) {
      $bp = (int)$r['bp'];
      if ($bp >= $min && $bp <= $max) $data['distribution'][$label]++;
    }
  }

  if ($compareTs) {
    $joinName = "p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci";

    $baseSelect = "
      c.rank_num AS now_rank,
      p.rank_num AS old_rank,
      c.name,
      c.level,
      p.bp AS old_bp,
      c.bp AS now_bp,
      c.bp - p.bp AS bp_diff,
      CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
    ";

    $realCurrent = "
      c.name IS NOT NULL
      AND TRIM(c.name) <> ''
      AND c.bp IS NOT NULL
    ";

    $data['growthTop'] = fetchBpRows($db, "
      SELECT {$baseSelect}
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.bp IS NOT NULL
        AND c.bp > p.bp
      ORDER BY bp_diff DESC, c.rank_num ASC
      LIMIT 20
    ", [$compareTs, $latestTs]);

    $data['riseTop'] = fetchBpRows($db, "
      SELECT {$baseSelect}
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.bp IS NOT NULL
        AND CAST(p.rank_num AS SIGNED) > CAST(c.rank_num AS SIGNED)
      ORDER BY rank_rise DESC, bp_diff DESC
      LIMIT 20
    ", [$compareTs, $latestTs]);

    $data['lossTop'] = fetchBpRows($db, "
      SELECT {$baseSelect}
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.bp IS NOT NULL
        AND c.bp < p.bp
      ORDER BY bp_diff ASC, c.rank_num ASC
      LIMIT 20
    ", [$compareTs, $latestTs]);

    $data['newRows'] = fetchBpRows($db, "
      SELECT c.rank_num, c.name, c.level, c.bp
      FROM `$table` c
      LEFT JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.name IS NULL
      ORDER BY c.rank_num ASC
    ", [$compareTs, $latestTs]);

    $data['summary']['new_players'] = count($data['newRows']);

    $stat = fetchBpOne($db, "
      SELECT
        COUNT(*) AS compared_players,
        SUM(CASE WHEN c.bp > p.bp THEN 1 ELSE 0 END) AS up_players,
        SUM(CASE WHEN c.bp < p.bp THEN 1 ELSE 0 END) AS down_players,
        SUM(CASE WHEN c.bp = p.bp THEN 1 ELSE 0 END) AS same_players,
        COALESCE(SUM(CASE WHEN c.bp > p.bp THEN c.bp - p.bp ELSE 0 END), 0) AS total_bp_gain,
        COALESCE(SUM(CASE WHEN c.bp < p.bp THEN p.bp - c.bp ELSE 0 END), 0) AS total_bp_loss,
        COALESCE(MAX(c.bp - p.bp), 0) AS max_bp_gain,
        COALESCE(MIN(c.bp - p.bp), 0) AS max_bp_loss,
        ROUND(AVG(c.bp - p.bp), 1) AS avg_bp_diff
      FROM `$table` c
      JOIN `$table` p ON {$joinName} AND p.ts = ?
      WHERE c.ts = ?
        AND {$realCurrent}
        AND p.bp IS NOT NULL
    ", [$compareTs, $latestTs]);

    if ($stat) {
      $data['summary']['up_players'] = (int)$stat['up_players'];
      $data['summary']['down_players'] = (int)$stat['down_players'];
      $data['summary']['same_players'] = (int)$stat['same_players'];
      $data['summary']['total_bp_gain'] = (int)$stat['total_bp_gain'];
      $data['summary']['total_bp_loss'] = (int)$stat['total_bp_loss'];
      $data['summary']['max_bp_gain'] = (int)$stat['max_bp_gain'];
      $data['summary']['max_bp_loss'] = (int)$stat['max_bp_loss'];
      $data['summary']['avg_bp_diff'] = (float)$stat['avg_bp_diff'];
    }

    $data['summary']['max_rank_rise'] = !empty($data['riseTop']) ? (int)$data['riseTop'][0]['rank_rise'] : 0;
  }

  return $data;
}

/* DMM_RETIRE_BP_20260930_V1
 * JP/DMM 保留歷史查詢，但不再視為即時排行。
 */
$bpActivityTW = buildBpActivity($db, 'TW');
$bpActivityJP = buildBpActivity($db, 'JP');

// -----------------------------------------------------------
//  TW / JP 每月榜單匯入工具：初始化變數
// -----------------------------------------------------------
$dataPath = dirname(__DIR__) . "/watcher";

// 安全初始化
$tw_updated_bp  = "";
$tw_updated_qp  = "";
$tw_last_import = "";

// --- 將 UTC 時間字串轉換成本地台灣時間 ---
function formatTWTime($utcString)
{
  if ($utcString === null || $utcString === '') return "（無資料）";

  try {
    if (is_numeric($utcString)) {
      $n = (float)$utcString;
      if (abs($n) >= 10000000000) $n /= 1000.0;
      $dt = (new DateTime('@' . (string)$n));
      $dt->setTimezone(new DateTimeZone("Asia/Taipei"));
      return $dt->format("Y-m-d H:i:s");
    }
    $dt = new DateTime((string)$utcString, new DateTimeZone("UTC"));
    $dt->setTimezone(new DateTimeZone("Asia/Taipei"));
    return $dt->format("Y-m-d H:i:s");
  } catch (Exception $e) {
    return "（格式錯誤）";
  }
}

// -----------------------------------------------------------
// 讀取 TW JSON 檔 updatedAt（⚠ 必須在 formatTWTime() 之前）
// -----------------------------------------------------------

$tw_bp_file = "$dataPath/ranking_bp_TW.json";
$tw_qp_file = "$dataPath/ranking_qp_TW.json";

if (file_exists($tw_bp_file)) {
  $json = json_decode(file_get_contents($tw_bp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $tw_updated_bp = $json["update_at"] ?? $json["updatedAt"] ?? "";
  }
}

if (file_exists($tw_qp_file)) {
  $json = json_decode(file_get_contents($tw_qp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $tw_updated_qp = $json["update_at"] ?? $json["updatedAt"] ?? "";
  }
}

// -----------------------------------------------------------
// 這裡才轉換成台灣時間（順序不能錯）
// -----------------------------------------------------------
$tw_bp_local = formatTWTime($tw_updated_bp);
$tw_qp_local = formatTWTime($tw_updated_qp);

// -----------------------------------------------------------
// 讀取 TW 最後匯入記錄
// -----------------------------------------------------------
$tw_last_import_file = "$dataPath/tw_last_import.txt";
if (file_exists($tw_last_import_file)) {
  $tw_last_import = trim(file_get_contents($tw_last_import_file));
}



// -----------------------------------------------------------
// JP 每月榜單匯入工具（重構後版本）
// -----------------------------------------------------------

// 安全初始化
$jp_updated_bp  = "";
$jp_updated_qp  = "";
$jp_last_import = "";

// JP JSON 檔案路徑
$jp_bp_file = "$dataPath/ranking_bp_JP.json";
$jp_qp_file = "$dataPath/ranking_qp_JP.json";

// 讀取 JP BP JSON
if (file_exists($jp_bp_file)) {
  $json = json_decode(file_get_contents($jp_bp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $jp_updated_bp = $json["updatedAt"] ?? "";
  }
}

// 讀取 JP QP JSON
if (file_exists($jp_qp_file)) {
  $json = json_decode(file_get_contents($jp_qp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $jp_updated_qp = $json["updatedAt"] ?? "";
  }
}

// JP updatedAt → 轉換為台灣時間
$jp_bp_local = formatTWTime($jp_updated_bp);
$jp_qp_local = formatTWTime($jp_updated_qp);

// 讀 JP 最後匯入時間
$jp_last_import_file = "$dataPath/jp_last_import.txt";
if (file_exists($jp_last_import_file)) {
  $jp_last_import = trim(file_get_contents($jp_last_import_file));
}






$sql2 = "WITH latest_snapshot AS (
            -- 取最新一次的月結快照
            SELECT *
            FROM ranking_bp_TW_history
            WHERE ts = (
              SELECT MAX(ts)
              FROM ranking_bp_TW_history
            )
          ),
          ranked AS (
            -- 給最新快照裡每位玩家，依 BP 排序編上名次
            SELECT
              ts,
              name,
              level,
              bp,
              ROW_NUMBER() OVER (ORDER BY bp DESC) AS rn
            FROM latest_snapshot
          )
          -- 篩出第 5、30、100 名
          SELECT
            rn    AS `rn`,
            ts    AS `ts`,
            name  AS `name`,
            level AS `level`,
            bp    AS `bp`
          FROM ranked
          WHERE rn IN (5, 30, 100)
          ORDER BY rn DESC;
        ";
$stmt2 = $db->query($sql2);

if (!$stmt2) {
  $err = $db->errorInfo();
  die("查詢失敗：{$err[2]}");
}
// 一定要先初始化
$ms_rank = [];
$ms_bp   = [];
$ms_ts   = [];
// 三種底線樣式：紅／藍／綠
$ms_style = [
  'style="background-color: #c1ffc1;"',
  'style="background-color: #c3c3ff;"',
  'style="background-color: #ffaeae;"'
];
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
  $ms_rank[] = (int)$row['rn'];
  $ms_bp[]   = (int)$row['bp'];
  $ms_ts[]   = $row['ts'];
}

// 主排行的比較基準直接沿用 BP Activity，避免主榜與分析區使用不同賽季。
$todayDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
$baselineTs = $bpActivityTW['compareTs'] ?? null;

// 撈出「當日第一筆」的排名快照（若不存在則留空，後面會自動跳過差異計算）
$prevSnapshot = [];
if ($baselineTs) {
  $stmtPrev = $db->prepare("
    SELECT name, rank_num, bp
    FROM ranking_bp_TW
    WHERE ts = :ts
      AND name IS NOT NULL
      AND TRIM(name) <> ''
      AND bp IS NOT NULL
  ");
  $stmtPrev->execute([':ts' => $baselineTs]);
  $prevSnapshot = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
}

$prevRank = $prevBp = [];
foreach ($prevSnapshot as $p) {
  $prevRank[$p['name']] = (int)$p['rank_num'];
  $prevBp[$p['name']]   = (int)$p['bp'];
}


// 1. 準備與執行查詢台服BP榜
$lastUpdate = $bpActivityTW['latestTs'] ?? null;

$sql = "SELECT
  r.rank_num,
  r.name,
  r.bp,
  r.ts,
  u.black_list,
  CASE
    WHEN u.black_list = 1 THEN 1
    ELSE 0
  END AS is_watch
FROM ranking_bp_TW AS r
LEFT JOIN game_user AS u
  ON u.username = r.name
 AND u.region = 'TW'
WHERE r.ts = :latest_ts
  AND r.name IS NOT NULL
  AND TRIM(r.name) <> ''
  AND r.bp IS NOT NULL
ORDER BY r.rank_num DESC;
";

$stmt = $db->prepare($sql);
$stmt->execute([':latest_ts' => $lastUpdate]);

// RANK_MAINTENANCE_GATE_V1
$rankingMaintenanceFlag = dirname(__DIR__) . '/watcher/ranking_bp_maintenance.flag';
$rankingMaintenance = is_file($rankingMaintenanceFlag);

// BP_POST_MAINTENANCE_WAITING_V1
// Maintenance can be over before the official BP list has its first real player.
// In that gap, do not fall back to a stale pre-maintenance DB snapshot.
$rankingAwaitingData = false;
$bpLiveJsonPath = dirname(__DIR__) . '/watcher/ranking_bp_TW.json';
if (!$rankingMaintenance && is_file($bpLiveJsonPath)) {
  $bpLivePayload = json_decode((string)@file_get_contents($bpLiveJsonPath), true);
  $bpLiveRows = is_array($bpLivePayload) ? ($bpLivePayload['data'] ?? []) : [];
  $hasRealBpRow = false;
  if (is_array($bpLiveRows)) {
    foreach ($bpLiveRows as $bpLiveRow) {
      if (!is_array($bpLiveRow)) continue;
      $n = trim((string)($bpLiveRow['player_name'] ?? ''));
      $v = $bpLiveRow['point'] ?? null;
      if ($n !== '' && $n !== '-' && is_numeric($v)) {
        $hasRealBpRow = true;
        break;
      }
    }
  }
  $rankingAwaitingData = !$hasRealBpRow;
}


// ------------------------------------------------------------
// ⭐ 在這裡放匯入 TW / JP 的 PHP 功能（最安全）
// ------------------------------------------------------------

/* 匯入程式開始 */
if (isset($_POST['import_tw'])) {

  $cmd = "python3 /var/www/html/unlight/watcher/import_bpqp_TW_his.py 2>&1";
  $output = shell_exec($cmd);

  echo "<pre style='background:#111;color:#0f0;padding:10px;'>
========= 🇹🇼 TW 匯入結果 =========
$output
================================
</pre>";

  echo "<script>alert('🇹🇼 TW BP/QP 歷史已成功匯入！');</script>";
  //echo "<meta http-equiv='refresh' content='1'>";
}

if (isset($_POST['import_jp'])) {
  echo "<script>alert('🇯🇵 JP / DMM 已停止追蹤，歷史匯入已停用。');</script>";
}
/* 匯入程式結束 */
function renderBpActivityPanel(array $data, string $label): void
{
  $summary = $data['summary'];
  $growthFocus = $data['growthTop'][0] ?? null;
  $riseFocus = $data['riseTop'][0] ?? null;
  $lossFocus = $data['lossTop'][0] ?? null;
  $maxDist = !empty($data['distribution']) ? max($data['distribution']) : 0;

  // TW_BP_NO_LEVEL_20260930_V1
  // Protocol 1.4 TW ranking only supplies player_name + point.
  // JP historical data may still have level, so only hide it for TW.
  $showLevel = (($data['server'] ?? '') !== 'TW');
?><div class="bp-activity-panel <?= $data['server'] === 'TW' ? 'active' : '' ?>" id="bp-activity-<?= bpSafe($data['server']) ?>">
    <?php if (($data['server'] ?? '') === 'JP'): ?>
      <div class="bp-compare-note">
        📦 DMM / JP 已停止追蹤；以下僅為最後封存快照與歷史資料，不再代表即時排行。
      </div>
    <?php endif; ?>
    <div class="bp-compare-note">
      <?php if (!empty($data['isPostResetWindow'])): ?>
        賽季重置日：只比較重置後的新賽季快照｜
        <?php if (!empty($data['compareTs'])): ?>
          基準 <?= bpSafe($data['compareTs']) ?> → 最新 <?= bpSafe($data['latestTs']) ?>
        <?php else: ?>
          目前為新賽季第一份有效快照，等待下一份快照後開始計算變化
        <?php endif; ?>
      <?php elseif (!empty($data['compareTs'])): ?>
        比較基準：<?= bpSafe($data['compareTs']) ?> → <?= bpSafe($data['latestTs']) ?>
      <?php else: ?>
        目前沒有可比較的前次快照
      <?php endif; ?>
    </div>
    <div class="bp-stat-grid">
      <div class="bp-stat-card">
        <div class="bp-stat-label">最高 BP</div>
        <div class="bp-stat-value"><?= bpFmt($summary['max_bp']) ?></div>
        <div class="bp-stat-note">目前榜首分數</div>
      </div>

      <div class="bp-stat-card">
        <div class="bp-stat-label">平均 / 中位 BP</div>
        <div class="bp-stat-value" style="font-size:18px;">
          <?= bpFmt($summary['avg_bp']) ?> / <?= bpFmt($summary['median_bp']) ?>
        </div>
        <div class="bp-stat-note">觀察整體競技分布</div>
      </div>

      <div class="bp-stat-card">
        <div class="bp-stat-label"><?= bpSafe($data['compareModeText']) ?> BP 上升玩家</div>
        <div class="bp-stat-value"><?= $data['comparisonReady'] ? bpFmt($summary['up_players']) : '—' ?></div>
        <div class="bp-stat-note"><?= $data['comparisonReady'] ? 'BP 有增加的人數' : '等待下一份有效快照' ?></div>
      </div>

      <div class="bp-stat-card">
        <div class="bp-stat-label"><?= bpSafe($data['compareModeText']) ?> BP 下降玩家</div>
        <div class="bp-stat-value"><?= $data['comparisonReady'] ? bpFmt($summary['down_players']) : '—' ?></div>
        <div class="bp-stat-note"><?= $data['comparisonReady'] ? 'BP 有減少的人數' : '等待下一份有效快照' ?></div>
      </div>
    </div>



    <div class="bp-focus-grid">
      <?php if ($growthFocus): ?>
        <a class="bp-focus-card bp-focus-card-link" href="<?= bpSafe(bpPlayerUrl($data['server'], $growthFocus['name'])) ?>">
          <div class="bp-focus-label"><?= bpSafe($data['compareModeText']) ?>最大 BP 成長</div>
          <div class="bp-focus-name"><?= bpSafe($growthFocus['name']) ?></div>
          <div class="bp-focus-main">+<?= bpFmt($growthFocus['bp_diff']) ?> BP</div>
          <div class="bp-focus-sub">
            #<?= (int)$growthFocus['old_rank'] ?> → #<?= (int)$growthFocus['now_rank'] ?>
            ｜目前 BP <?= bpFmt($growthFocus['now_bp']) ?>
            ｜點擊查看詳情
          </div>
        </a>
      <?php else: ?>
        <div class="bp-focus-card">
          <div class="bp-focus-label"><?= bpSafe($data['compareModeText']) ?>最大 BP 成長</div>
          <div class="bp-empty">目前沒有可比較的成長資料</div>
        </div>
      <?php endif; ?>

      <?php if ($riseFocus): ?>
        <a class="bp-focus-card bp-focus-card-link" href="<?= bpSafe(bpPlayerUrl($data['server'], $riseFocus['name'])) ?>">
          <div class="bp-focus-label"><?= bpSafe($data['compareModeText']) ?>最大排名躍升</div>
          <div class="bp-focus-name"><?= bpSafe($riseFocus['name']) ?></div>
          <div class="bp-focus-main">▲<?= bpFmt($riseFocus['rank_rise']) ?></div>
          <div class="bp-focus-sub">
            #<?= (int)$riseFocus['old_rank'] ?> → #<?= (int)$riseFocus['now_rank'] ?>
            ｜BP <?= (int)$riseFocus['bp_diff'] >= 0 ? '+' : '' ?><?= bpFmt($riseFocus['bp_diff']) ?>
            ｜點擊查看詳情
          </div>
        </a>
      <?php else: ?>
        <div class="bp-focus-card">
          <div class="bp-focus-label"><?= bpSafe($data['compareModeText']) ?>最大排名躍升</div>
          <div class="bp-empty">目前沒有可比較的躍升資料</div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($lossFocus): ?>
      <div class="bp-focus-grid">
        <a class="bp-focus-card bp-focus-card-link" href="<?= bpSafe(bpPlayerUrl($data['server'], $lossFocus['name'])) ?>">
          <div class="bp-focus-label"><?= bpSafe($data['compareModeText']) ?>最大 BP 下降</div>
          <div class="bp-focus-name"><?= bpSafe($lossFocus['name']) ?></div>
          <div class="bp-focus-main bp-loss"><?= bpFmt($lossFocus['bp_diff']) ?> BP</div>
          <div class="bp-focus-sub">
            #<?= (int)$lossFocus['old_rank'] ?> → #<?= (int)$lossFocus['now_rank'] ?>
            ｜目前 BP <?= bpFmt($lossFocus['now_bp']) ?>
            ｜點擊查看詳情
          </div>
        </a>

        <div class="bp-focus-card">
          <div class="bp-focus-label"><?= bpSafe($data['compareModeText']) ?>競技變化摘要</div>
          <div class="bp-focus-name">升降統計</div>
          <div class="bp-focus-main"><?= bpFmt($summary['up_players']) ?> / <?= bpFmt($summary['down_players']) ?></div>
          <div class="bp-focus-sub">
            上升玩家 / 下降玩家
            ｜不變 <?= bpFmt($summary['same_players']) ?> 人
            ｜新進 <?= bpFmt($summary['new_players']) ?> 人
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="bp-analysis-grid">
      <div class="bp-analysis-card">
        <div class="bp-analysis-title"><?= bpSafe($label) ?> 競技排行</div>

        <div class="bp-mini-tabs" data-scope="<?= bpSafe($data['server']) ?>">
          <button type="button" class="bp-mini-tab-btn active" data-tab="growth"><?= bpSafe($data['compareModeText']) ?> BP 成長</button>
          <button type="button" class="bp-mini-tab-btn" data-tab="rise"><?= bpSafe($data['compareModeText']) ?>排名躍升</button>
          <button type="button" class="bp-mini-tab-btn" data-tab="loss"><?= bpSafe($data['compareModeText']) ?> BP 掉分</button>
          <button type="button" class="bp-mini-tab-btn" data-tab="new"><?= bpSafe($data['compareModeText']) ?>新進榜</button>
        </div>

        <div class="bp-mini-panel active" id="bp-mini-<?= bpSafe($data['server']) ?>-growth">
          <?php if (empty($data['growthTop'])): ?>
            <div class="bp-empty">目前沒有 BP 成長資料</div>
          <?php else: ?>
            <div class="bp-analysis-table-wrap">
              <table class="bp-analysis-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>玩家</th>
                    <?php if ($showLevel): ?><th>Lv</th><?php endif; ?>
                    <th>BP 增加</th>
                    <th>排名變化</th>
                    <th>目前 BP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($data['growthTop'], 0, 10) as $idx => $r): ?>
                    <tr>
                      <td data-label="排行"><?= $idx + 1 ?></td>
                      <td data-label="玩家" class="bp-player-name"><?= bpPlayerLink($data['server'], $r['name'], 'bp-player-link') ?></td>
                      <?php if ($showLevel): ?><td data-label="Lv"><?= (int)$r['level'] ?></td><?php endif; ?>
                      <td data-label="BP 增加" class="bp-gain">+<?= bpFmt($r['bp_diff']) ?></td>
                      <td data-label="排名變化">#<?= (int)$r['old_rank'] ?> → #<?= (int)$r['now_rank'] ?></td>
                      <td data-label="目前 BP"><?= bpFmt($r['now_bp']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="bp-mini-panel" id="bp-mini-<?= bpSafe($data['server']) ?>-rise">
          <?php if (empty($data['riseTop'])): ?>
            <div class="bp-empty">目前沒有排名躍升資料</div>
          <?php else: ?>
            <div class="bp-analysis-table-wrap">
              <table class="bp-analysis-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>玩家</th>
                    <?php if ($showLevel): ?><th>Lv</th><?php endif; ?>
                    <th>躍升</th>
                    <th>排名變化</th>
                    <th>BP 變化</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($data['riseTop'], 0, 10) as $idx => $r): ?>
                    <tr>
                      <td data-label="排行"><?= $idx + 1 ?></td>
                      <td data-label="玩家" class="bp-player-name"><?= bpPlayerLink($data['server'], $r['name'], 'bp-player-link') ?></td>
                      <?php if ($showLevel): ?><td data-label="Lv"><?= (int)$r['level'] ?></td><?php endif; ?>
                      <td data-label="躍升" class="bp-rise">▲<?= bpFmt($r['rank_rise']) ?></td>
                      <td data-label="排名變化">#<?= (int)$r['old_rank'] ?> → #<?= (int)$r['now_rank'] ?></td>
                      <td data-label="BP 變化" class="<?= (int)$r['bp_diff'] > 0 ? 'bp-gain' : ((int)$r['bp_diff'] < 0 ? 'bp-loss' : 'bp-same') ?>">
                        <?= (int)$r['bp_diff'] > 0 ? '+' : '' ?><?= bpFmt($r['bp_diff']) ?>
                      </td>

                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="bp-mini-panel" id="bp-mini-<?= bpSafe($data['server']) ?>-loss">
          <?php if (empty($data['lossTop'])): ?>
            <div class="bp-empty">目前沒有 BP 掉分資料</div>
          <?php else: ?>
            <div class="bp-analysis-table-wrap">
              <table class="bp-analysis-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>玩家</th>
                    <?php if ($showLevel): ?><th>Lv</th><?php endif; ?>
                    <th>BP 下降</th>
                    <th>排名變化</th>
                    <th>目前 BP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($data['lossTop'], 0, 10) as $idx => $r): ?>
                    <tr>
                      <td data-label="排行"><?= $idx + 1 ?></td>
                      <td data-label="玩家" class="bp-player-name"><?= bpPlayerLink($data['server'], $r['name'], 'bp-player-link') ?></td>
                      <?php if ($showLevel): ?><td data-label="Lv"><?= (int)$r['level'] ?></td><?php endif; ?>
                      <td data-label="BP 下降" class="bp-loss"><?= bpFmt($r['bp_diff']) ?></td>
                      <td data-label="排名變化">#<?= (int)$r['old_rank'] ?> → #<?= (int)$r['now_rank'] ?></td>
                      <td data-label="目前 BP"><?= bpFmt($r['now_bp']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="bp-mini-panel" id="bp-mini-<?= bpSafe($data['server']) ?>-new">
          <?php if (empty($data['newRows'])): ?>
            <div class="bp-empty">目前沒有新進榜玩家</div>
          <?php else: ?>
            <div class="bp-analysis-table-wrap">
              <table class="bp-analysis-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>玩家</th>
                    <?php if ($showLevel): ?><th>Lv</th><?php endif; ?>
                    <th>BP</th>
                    <th>狀態</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($data['newRows'], 0, 15) as $r): ?>
                    <tr>
                      <td data-label="排名">#<?= (int)$r['rank_num'] ?></td>
                      <td data-label="玩家" class="bp-player-name"><?= bpPlayerLink($data['server'], $r['name'], 'bp-player-link') ?></td>
                      <?php if ($showLevel): ?><td data-label="Lv"><?= (int)$r['level'] ?></td><?php endif; ?>
                      <td data-label="BP"><?= bpFmt($r['bp']) ?></td>
                      <td data-label="狀態"><span class="bp-snapshot-pill">新進快照</span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="bp-analysis-card">
        <div class="bp-analysis-title"><?= bpSafe($label) ?> BP 分布</div>
        <?php foreach ($data['distribution'] as $range => $count): ?>
          <?php $width = $maxDist > 0 ? max(4, round($count / $maxDist * 100)) : 0; ?>
          <div class="bp-dist-row">
            <div><?= bpSafe($range) ?></div>
            <div class="bp-dist-bar"><span style="width:<?= (int)$width ?>%;"></span></div>
            <div><?= (int)$count ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php
}
?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <?php if ($rankingMaintenance): ?>
        <div style="margin:14px 0 18px;padding:18px;border-radius:14px;border:1px solid rgba(250,204,21,.55);background:#241f0d;color:#fde68a;">
          <div style="font-size:18px;font-weight:900;margin-bottom:6px;">🛠️ Steam 排行榜維護中</div>
          <div style="line-height:1.7;color:#fef3c7;">
            官方正在進行賽季切換，維修期間排行榜快照可能不完整或異常，因此暫停公開即時 BP 排行與競技分析。
            系統偵測到 BP 榜單完成清零、確認新賽季開始後，會自動恢復公開。
          </div>
        </div>
      <?php elseif ($rankingAwaitingData): ?>
        <div style="margin:14px 0 18px;padding:18px;border-radius:14px;border:1px solid rgba(96,165,250,.45);background:#111827;color:#dbeafe;">
          <div style="font-size:18px;font-weight:900;margin-bottom:6px;">📡 新賽季 BP 排行等待資料</div>
          <div style="line-height:1.7;color:#bfdbfe;">
            維修已結束，但官方 BP 榜目前尚未出現有效玩家資料；本站不顯示上一季舊快照，待第一份有效 BP 排行出現後會自動顯示。
          </div>
        </div>
      <?php else: ?>
      <div class="bp-activity-wrap">
        <div class="bp-activity-hero">
          <div class="bp-activity-title">⚔️ BP 競技分析</div>
          <div class="bp-activity-desc">
            BP 主要觀察玩家排名競爭、分數變化與快照趨勢。
          </div>
          <div class="bp-activity-tabs">
            <button type="button" class="bp-activity-tab-btn active" data-server="TW">Steam / TW</button>
            <button type="button" class="bp-activity-tab-btn" data-server="JP">DMM / JP（歷史封存）</button>
          </div>
        </div>

        <?php if ((int)$permission >= 2): ?>
          <div style="
            margin-bottom:14px;
            padding:14px;
            background:#211014;
            border:1px solid rgba(248,113,113,.55);
            border-radius:12px;
            color:#fecaca;
          ">
            <div style="font-size:16px;font-weight:900;margin-bottom:8px;">
              🛠 BP DEBUG（僅管理員可見）
            </div>

            <div style="font-size:13px;line-height:1.8;margin-bottom:10px;">
              <strong>TW</strong>
              ｜latest:
              <?= bpSafe($bpActivityTW['latestTs'] ?? 'NULL') ?>
              ｜compare:
              <?= bpSafe($bpActivityTW['compareTs'] ?? 'NULL') ?>
              ｜current rows:
              <?= (int)array_sum($bpActivityTW['distribution'] ?? []) ?>
              ｜reset:
              <?= bpSafe($bpActivityTW['resetBoundary'] ?? 'N/A') ?>
              ｜baseline:
              <?= bpSafe($bpActivityTW['windowBaseTs'] ?? 'NULL') ?>
              <br>

              <strong>JP</strong>
              ｜latest:
              <?= bpSafe($bpActivityJP['latestTs'] ?? 'NULL') ?>
              ｜compare:
              <?= bpSafe($bpActivityJP['compareTs'] ?? 'NULL') ?>
              ｜current rows:
              <?= (int)array_sum($bpActivityJP['distribution'] ?? []) ?>
              ｜reset:
              <?= bpSafe($bpActivityJP['resetBoundary'] ?? 'N/A') ?>
              ｜baseline:
              <?= bpSafe($bpActivityJP['windowBaseTs'] ?? 'NULL') ?>
            </div>

            <?php if (empty($bpActivityErrors)): ?>

              <div style="
                background:#102018;
                color:#86efac;
                padding:10px;
                border-radius:8px;
              ">
                ✓ BP Activity 查詢沒有捕捉到 SQL Exception
              </div>

            <?php else: ?>

              <div style="
                margin-bottom:8px;
                font-weight:800;
                color:#fca5a5;
              ">
                發現 <?= count($bpActivityErrors) ?> 個 SQL 錯誤
              </div>

              <?php foreach ($bpActivityErrors as $idx => $err): ?>

                <details style="
                  margin:8px 0;
                  padding:10px;
                  background:#0b0c10;
                  border-radius:8px;
                ">
                  <summary style="
                    cursor:pointer;
                    font-weight:800;
                    color:#fca5a5;
                  ">
                    ERROR #<?= $idx + 1 ?>
                    <?= bpSafe($err['message'] ?? 'Unknown error') ?>
                  </summary>

                  <div style="margin-top:10px;color:#fda4af;font-weight:800;">
                    SQL
                  </div>

                  <pre style="
                    white-space:pre-wrap;
                    word-break:break-word;
                    background:#111827;
                    color:#e5e7eb;
                    padding:10px;
                    border-radius:6px;
                  "><?= bpSafe($err['sql'] ?? '') ?></pre>

                  <div style="color:#fda4af;font-weight:800;">
                    Params
                  </div>

                  <pre style="
                    white-space:pre-wrap;
                    word-break:break-word;
                    background:#111827;
                    color:#e5e7eb;
                    padding:10px;
                    border-radius:6px;
                  "><?= bpSafe(json_encode(
                      $err['params'] ?? [],
                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )) ?></pre>

                </details>

              <?php endforeach; ?>

            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php renderBpActivityPanel($bpActivityTW, 'Steam / TW'); ?>
        <?php renderBpActivityPanel($bpActivityJP, 'DMM / JP（歷史封存）'); ?>
      </div>
      <?php endif; ?>
      <div class="row">
        <!-- Steam main column -->
        <div class="col-md-12">
          <?php if ($permission >= 2): ?>

            <?php
            // 取得 TW 最新匯入時間
            try {
              $tw_last_import_ts = $db->query("SELECT ts FROM ranking_bp_TW_history ORDER BY ts DESC LIMIT 1")->fetchColumn();
              $tw_last_import_bp = $tw_last_import_ts ? date("Y-m-d H:i:s", strtotime($tw_last_import_ts)) : "（無資料）";
            } catch (Exception $e) {
              $tw_last_import_bp = "（讀取失敗）";
            }

            try {
              $tw_last_import_ts_qp = $db->query("SELECT ts FROM ranking_qp_TW_history ORDER BY ts DESC LIMIT 1")->fetchColumn();
              $tw_last_import_qp = $tw_last_import_ts_qp ? date("Y-m-d H:i:s", strtotime($tw_last_import_ts_qp)) : "（無資料）";
            } catch (Exception $e) {
              $tw_last_import_qp = "（讀取失敗）";
            }
            ?>

            <!-- 🔵 可伸縮按鈕 -->
            <button class="btn btn-primary btn-block" type="button" data-toggle="collapse"
              data-target="#twImportPanel" style="max-width: 260px;margin: 0 auto;">
              🇹🇼 TW 每月榜單匯入工具 ▼
            </button>

            <!-- ▼ 展開內容 -->
            <div id="twImportPanel" class="collapse">
              <div class="alert alert-secondary" style="padding:10px; margin-bottom:10px;">
                <h5>🇹🇼 TW 每月榜單匯入工具</h5>

                <div><strong>JSON 最新更新時間 / 最後匯入時間：</strong></div>

                <div>BP：<?= htmlspecialchars($tw_bp_local) ?> / <?= htmlspecialchars($tw_last_import_bp) ?></div>
                <div>QP：<?= htmlspecialchars($tw_qp_local) ?> / <?= htmlspecialchars($tw_last_import_qp) ?></div>

                <form method="post" style="margin-top:10px;">
                  <button class="btn btn-warning btn-sm" name="import_tw" value="1"
                    onclick="return confirm('確定要匯入 TW BP/QP 歷史嗎？');">
                    🇹🇼 一鍵匯入 TW（BP + QP）
                  </button>
                </form>
              </div>
            </div>

          <?php endif; ?>


          <?php if (!$rankingMaintenance && !$rankingAwaitingData): ?>
          <div class="card-dark" style="padding: 5px;">
            <div class="card-gradient-header">本季Steam BP排行</div>
            <div class="rank-reward-legend" aria-label="季末獎勵分區">
              <span>季末獎勵區：</span>
              <span class="rank-reward-chip top5">#1–5</span>
              <span class="rank-reward-chip top30">#6–30</span>
              <span>#31+</span>
            </div>
            <div class="table-full">
              <table id="example1" class="table datatable-init-hide">
                <thead>
                  <tr>
                    <th class="col-rank">#</th>
                    <th class="col-name">名稱</th>
                    <th class="col-bp">BP</th>
                    <th class="col-rank-delta">排名變化</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $i = 0;
                  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rank_num  = (int) $row['rank_num'];
                    $name      = $row['name'];
                    $bp        = (int) $row['bp'];
                    // 計算名次變化

                    // 把時間字串取出時:分

                    if (!empty($prevRank)) {
                      // 找得到當日第一筆 → 計算與 baseline 差異（台服用台服對照表）
                      $beforeRank = $prevRank[$name] ?? null;

                      if ($beforeRank === null) {
                        $rankChange = '<span class="badge badge-new text-sm">新進榜</span>';
                        $rankDeltaText = '新進';
                        $rankDeltaClass = 'bp-rank-delta-same';
                      } elseif ($rank_num === 1 && $beforeRank > 1) {
                        $rankChange = '<span class="badge badge-top text-sm">登頂</span>';
                        $rankDeltaText = '▲' . ($beforeRank - $rank_num);
                        $rankDeltaClass = 'bp-rank-delta-up';
                      } elseif ($rank_num < $beforeRank) {
                        $delta = $beforeRank - $rank_num;
                        $rankChange = "<span class=\"badge badge-up text-sm\">↑{$delta}</span>";
                        $rankDeltaText = '▲' . $delta;
                        $rankDeltaClass = 'bp-rank-delta-up';
                      } elseif ($rank_num > $beforeRank) {
                        $delta = $rank_num - $beforeRank;
                        $rankChange = "<span class=\"badge badge-down text-sm\">↓{$delta}</span>";
                        $rankDeltaText = '▼' . $delta;
                        $rankDeltaClass = 'bp-rank-delta-down';
                      } else {
                        $rankChange = '<span class="text-sm">—</span>';
                        $rankDeltaText = '—';
                        $rankDeltaClass = 'bp-rank-delta-same';
                      }

                      $beforeBp = $prevBp[$name] ?? null;
                      if ($beforeBp !== null) {
                        $deltaBp = $bp - $beforeBp;
                        $sign    = $deltaBp >= 0 ? '+' : '';
                        $deltaClass = $deltaBp > 0 ? 'up' : ($deltaBp < 0 ? 'down' : 'same');
                        $bpChange = "<small class=\"text-sm bp-delta {$deltaClass}\">{$sign}{$deltaBp}</small>";
                      } else {
                        $bpChange = '';
                      }
                    } else {
                      // 當日完全沒有 baseline → 不顯示差異
                      $rankChange = '';
                      $bpChange   = '';
                      $rankDeltaText = '—';
                      $rankDeltaClass = 'bp-rank-delta-same';
                    }




                    // 產生連結：BP 競技詳情頁
                    $link = "/pages/fight.php?player_name=" . urlencode($name);
                    /* 對戰紀錄可另外使用：fight.php?player_name=... */

                    // 如果黑名單，把 class 換成 blacklist-name，否則就 player-name 或其他預設
                    $nameClass = 'player-name bp-player-link';

                    if (!empty($row['black_list']) && $row['black_list'] >= 2) {
                      // 黑名單
                      $nameClass .= ' blacklist-name';
                    } elseif (!empty($row['black_list']) && $row['black_list'] == 1) {
                      // 待觀察
                      $nameClass .= ' watchlist-name';
                    }

                    // ===== Tooltip（黑名單 / 待觀察）=====
                    $titleAttr = '查看對戰紀錄';

                    if (!empty($row['black_list']) && $row['black_list'] >= 2) {
                      $titleAttr = '⚠ 此玩家為黑名單｜查看對戰紀錄';
                    } elseif (!empty($row['is_watch'])) {
                      $titleAttr = '◉ 此玩家目前為待觀察狀態｜查看對戰紀錄';
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
                      <td class="col-rank"><?= $rank_num ?></td>

                      <td class="col-name">
                        <span class="rank-player-nav">
                          <a
                            href="<?= bpSafe(bpPlayerUrl('TW', $name)) ?>"
                            class="rank-player-detail-link rank-player-detail-link-bp"
                            title="查看 BP 玩家詳情">BP</a>
                          <a href="<?= $link ?>"
                            class="<?= $nameClass ?> rank-player-fight-link"
                            <?= $titleAttr ? 'title="' . htmlspecialchars($titleAttr, ENT_QUOTES) . '"' : '' ?>>
                            <?= htmlspecialchars($name, ENT_QUOTES) ?>
                          </a>
                        </span>
                        <span class="bp-rank-change-inline"><?= $rankChange ?></span>
                      </td>

                      <td class="col-bp">
                        <?= $bp ?>
                        <span class="bp-change"><?= $bpChange ?></span>
                      </td>


                      <td class="col-rank-delta bp-rank-delta-cell <?= $rankDeltaClass ?>"><?= htmlspecialchars($rankDeltaText, ENT_QUOTES) ?></td>
                    </tr>

                  <?php } // end while 
                  ?>
                </tbody>
              </table>
              <div class="text-muted small mt-2">
                ※ 2026/09 遊戲更新後勝／敗／平統計已不再可靠，本頁改以 BP 與排名快照變化為主。
              </div>
            </div>
          </div>
          <?php endif; ?>
          <!-- /.card style="display: none;"-->
          <div class="card-dark">
            <div class="box-header">
              <h3 class="box-title">上季Steam BP里程碑:</h3>
            </div>
            <!-- /.box-header -->
            <div class="box-body">
              <table class="table table-bordered table-striped milestone-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>BP</th>
                    <th>更新時間</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = count($ms_rank) - 1; $i >= 0; $i--): ?>
                    <?php
                      $milestoneRank = (int)$ms_rank[$i];
                      $milestoneStyle = [
                        5 => 'style="background-color: #ffaeae;"',
                        30 => 'style="background-color: #c3c3ff;"',
                        100 => 'style="background-color: #c1ffc1;"',
                      ][$milestoneRank] ?? '';
                    ?>
                    <tr <?= $milestoneStyle ?>>
                      <td><?= htmlspecialchars($milestoneRank) ?></td>
                      <td><?= htmlspecialchars($ms_bp[$i] ?? '') ?></td>
                      <td><?= htmlspecialchars($ms_ts[$i] ?? '') ?></td>
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
            FROM ranking_bp_JP_history
            WHERE ts = (
              SELECT MAX(ts)
              FROM ranking_bp_JP_history
            )
          ),
          ranked AS (
            -- 給最新快照裡每位玩家，依 BP 排序編上名次
            SELECT
              ts,
              name,
              level,
              bp,
              ROW_NUMBER() OVER (ORDER BY bp DESC) AS rn
            FROM latest_snapshot
          )
          -- 篩出第 5、30、100 名
          SELECT
            rn    AS `rn`,
            ts    AS `ts`,
            name  AS `name`,
            level AS `level`,
            bp    AS `bp`
          FROM ranked
          WHERE rn IN (5, 30, 100)
          ORDER BY rn DESC;
        ";
          $stmt2 = $db->query($sql2);

          if (!$stmt2) {
            $err = $db->errorInfo();
            die("查詢失敗：{$err[2]}");
          }
          // 一定要先初始化
          $ms_rank_JP = [];
          $ms_bp_JP   = [];
          $ms_ts_JP = [];
          // 三種底線樣式：紅／藍／綠
          $ms_style_JP = [
            'style="background-color: #c1ffc1;"',
            'style="background-color: #c3c3ff;"',
            'style="background-color: #ffaeae;"'
          ];
          while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $ms_rank_JP[] = (int)$row['rn'];
            $ms_bp_JP[]   = (int)$row['bp'];
            $ms_ts_JP[]   = $row['ts'];
          }
          // DMM 主排行比較基準直接沿用 BP Activity。
          $baselineTsJP = $bpActivityJP['compareTs'] ?? null;

          $prevSnapshotJP = [];
          if ($baselineTsJP) {
            $stmtPrev = $db->prepare("
            SELECT name, rank_num, bp
            FROM ranking_bp_JP
            WHERE ts = :ts
              AND name IS NOT NULL
              AND TRIM(name) <> ''
              AND bp IS NOT NULL
          ");
            $stmtPrev->execute([':ts' => $baselineTsJP]);
            $prevSnapshotJP = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
          }

          $prevRankJP = $prevBpJP = [];
          foreach ($prevSnapshotJP as $p) {
            $prevRankJP[$p['name']] = (int)$p['rank_num'];
            $prevBpJP[$p['name']]   = (int)$p['bp'];
          }



          // 1. 準備與執行查詢日服BP榜
          $lastUpdate_JP = $bpActivityJP['latestTs'] ?? null;

          $sql = "SELECT
            r.rank_num,
            r.name,
            r.bp,
            r.ts,
            u.black_list,
            CASE
              WHEN u.black_list = 1 THEN 1
              ELSE 0
            END AS is_watch
          FROM ranking_bp_JP AS r
          LEFT JOIN game_user AS u
            ON u.username = r.name
           AND u.region = 'JP'
          WHERE r.ts = :latest_ts
            AND r.name IS NOT NULL
            AND TRIM(r.name) <> ''
            AND r.bp IS NOT NULL
          ORDER BY r.rank_num DESC;
          ";

          $stmt = $db->prepare($sql);
          $stmt->execute([':latest_ts' => $lastUpdate_JP]);

          if ($permission >= 2):
          ?>

            <?php
            // 取得 JP 最新匯入時間
            try {
              $jp_last_import_ts = $db->query("SELECT ts FROM ranking_bp_JP_history ORDER BY ts DESC LIMIT 1")->fetchColumn();
              $jp_last_import_bp = $jp_last_import_ts ? date("Y-m-d H:i:s", strtotime($jp_last_import_ts)) : "（無資料）";
            } catch (Exception $e) {
              $jp_last_import_bp = "（讀取失敗）";
            }

            try {
              $jp_last_import_ts_qp = $db->query("SELECT ts FROM ranking_qp_JP_history ORDER BY ts DESC LIMIT 1")->fetchColumn();
              $jp_last_import_qp = $jp_last_import_ts_qp ? date("Y-m-d H:i:s", strtotime($jp_last_import_ts_qp)) : "（無資料）";
            } catch (Exception $e) {
              $jp_last_import_qp = "（讀取失敗）";
            }
            ?>

            <!-- ▼ JP 伸縮按鈕 -->
            <button class="btn btn-info btn-block" type="button" data-toggle="collapse"
              data-target="#jpImportPanel" style="max-width: 260px;margin: 0 auto;">
              🇯🇵 JP 每月榜單匯入工具 ▼
            </button>

            <!-- ▼ 展開內容 -->
            <div id="jpImportPanel" class="collapse">
              <div class="alert alert-secondary" style="padding:10px; margin-bottom:10px;">
                <h5>🇯🇵 JP 每月榜單匯入工具</h5>

                <div><strong>JSON 最新更新時間 / 最後匯入時間：</strong></div>

                <div>BP：<?= htmlspecialchars($jp_bp_local) ?> / <?= htmlspecialchars($jp_last_import_bp) ?></div>
                <div>QP：<?= htmlspecialchars($jp_qp_local) ?> / <?= htmlspecialchars($jp_last_import_qp) ?></div>

                <div style="margin-top:10px;color:#9ca3af;font-size:13px;">
                  📦 JP / DMM 已停止追蹤；歷史匯入功能已停用。
                </div>
              </div>
            </div>

          <?php endif; ?>



          <div class="card-dark" style="padding: 5px;">
            <div class="card-gradient-header">DMM BP排行（歷史封存）</div>
            <div class="table-full">
              <table id="exampleJP" class="table datatable-init-hide">
                <thead>
                  <tr>
                    <th class="col-rank">#</th>
                    <th class="col-name">名稱</th>
                    <th class="col-bp">BP</th>
                    <th class="col-rank-delta">排名變化</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $i = 0;
                  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rank_num  = (int) $row['rank_num'];
                    $name      = $row['name'];
                    $bp        = (int) $row['bp'];
                    // 計算名次變化



                    if (!empty($prevRankJP)) {
                      $beforeRank = $prevRankJP[$name] ?? null;
                      if ($beforeRank === null) {
                        $rankChange = '<span class="badge badge-new text-sm">新進榜</span>';
                        $rankDeltaText = '新進';
                        $rankDeltaClass = 'bp-rank-delta-same';
                      } elseif ($rank_num === 1 && $beforeRank > 1) {
                        $rankChange = '<span class="badge badge-top text-sm">登頂</span>';
                        $rankDeltaText = '▲' . ($beforeRank - $rank_num);
                        $rankDeltaClass = 'bp-rank-delta-up';
                      } elseif ($rank_num < $beforeRank) {
                        $delta = $beforeRank - $rank_num;
                        $rankChange = "<span class=\"badge badge-up text-sm\">↑{$delta}</span>";
                        $rankDeltaText = '▲' . $delta;
                        $rankDeltaClass = 'bp-rank-delta-up';
                      } elseif ($rank_num > $beforeRank) {
                        $delta = $rank_num - $beforeRank;
                        $rankChange = "<span class=\"badge badge-down text-sm\">↓{$delta}</span>";
                        $rankDeltaText = '▼' . $delta;
                        $rankDeltaClass = 'bp-rank-delta-down';
                      } else {
                        $rankChange = '<span class="text-sm">—</span>';
                        $rankDeltaText = '—';
                        $rankDeltaClass = 'bp-rank-delta-same';
                      }

                      $beforeBp = $prevBpJP[$name] ?? null;
                      if ($beforeBp !== null) {
                        $deltaBp = $bp - $beforeBp;
                        $sign    = $deltaBp >= 0 ? '+' : '';
                        $deltaClass = $deltaBp > 0 ? 'up' : ($deltaBp < 0 ? 'down' : 'same');
                        $bpChange = "<small class=\"text-sm bp-delta {$deltaClass}\">{$sign}{$deltaBp}</small>";
                      } else {
                        $bpChange = '';
                      }
                    } else {
                      $rankChange = '';
                      $bpChange   = '';
                      $rankDeltaText = '—';
                      $rankDeltaClass = 'bp-rank-delta-same';
                    }



                    // 產生連結：BP 競技詳情頁
                    $link = "/pages/fight.php?player_name=" . urlencode($name);

                    // 如果黑名單，把 class 換成 blacklist-name，否則就 player-name 或其他預設
                    $nameClass = 'player-name bp-player-link';

                    if (!empty($row['black_list']) && $row['black_list'] >= 2) {
                      // 黑名單
                      $nameClass .= ' blacklist-name';
                    } elseif (!empty($row['black_list']) && $row['black_list'] == 1) {
                      // 待觀察
                      $nameClass .= ' watchlist-name';
                    }

                    // ===== Tooltip（黑名單 / 待觀察）=====
                    $titleAttr = '查看對戰紀錄';

                    if (!empty($row['black_list']) && $row['black_list'] >= 2) {
                      $titleAttr = '⚠ 此玩家為黑名單｜查看對戰紀錄';
                    } elseif (!empty($row['is_watch'])) {
                      $titleAttr = '◉ 此玩家目前為待觀察狀態｜查看對戰紀錄';
                    }




                    // 里程碑樣式
                    $style = '';
                    if (isset($ms_bp_JP[$i]) && $bp >= $ms_bp_JP[$i]) {
                      $style = $ms_style_JP[$i];
                      $i++;
                    }
                  ?>
                    <tr <?= $style ?>>
                      <td class="col-rank"><?= $rank_num ?></td>

                      <td class="col-name">
                        <span class="rank-player-nav">
                          <a
                            href="<?= bpSafe(bpPlayerUrl('JP', $name)) ?>"
                            class="rank-player-detail-link rank-player-detail-link-bp"
                            title="查看 BP 玩家詳情">BP</a>
                          <a href="<?= $link ?>"
                            class="<?= $nameClass ?> rank-player-fight-link"
                            <?= $titleAttr ? 'title="' . htmlspecialchars($titleAttr, ENT_QUOTES) . '"' : '' ?>>
                            <?= htmlspecialchars($name, ENT_QUOTES) ?>
                          </a>
                        </span>
                        <span class="bp-rank-change-inline"><?= $rankChange ?></span>
                      </td>

                      <td class="col-bp">
                        <?= $bp ?>
                        <span class="bp-change"><?= $bpChange ?></span>
                      </td>


                      <td class="col-rank-delta bp-rank-delta-cell <?= $rankDeltaClass ?>"><?= htmlspecialchars($rankDeltaText, ENT_QUOTES) ?></td>
                    </tr>
                  <?php } // end while 
                  ?>
                </tbody>
              </table>
              <div class="text-muted small mt-2">
                ※ 2026/09 遊戲更新後勝／敗／平統計已不再可靠，本頁改以 BP 與排名快照變化為主。
              </div>
            </div>

          </div>
          <!-- /.card style="display: none;"-->
          <div class="card-dark">
            <div class="box-header">
              <h3 class="box-title">上季DMM BP里程碑:</h3>
            </div>
            <!-- /.box-header -->
            <div class="box-body">
              <table class="table table-bordered table-striped milestone-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <!-- <th>名稱</th> -->
                    <th>BP</th>
                    <!-- <th>上季-勝</th>
                  <th>上季-敗</th>
                  <th>上季-總計</th>
                  <th>勝率</th> -->
                    <th>更新時間</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = count($ms_rank_JP) - 1; $i >= 0; $i--): ?>
                    <?php
                      $milestoneRankJP = (int)$ms_rank_JP[$i];
                      $milestoneStyleJP = [
                        5 => 'style="background-color: #ffaeae;"',
                        30 => 'style="background-color: #c3c3ff;"',
                        100 => 'style="background-color: #c1ffc1;"',
                      ][$milestoneRankJP] ?? '';
                    ?>
                    <tr <?= $milestoneStyleJP ?>>
                      <td><?= htmlspecialchars($milestoneRankJP) ?></td>
                      <td><?= htmlspecialchars($ms_bp_JP[$i] ?? '') ?></td>
                      <td><?= htmlspecialchars($ms_ts_JP[$i] ?? '') ?></td>
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
       插入更新時間
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
       建立「兩排式工具列」
    =============================== */
    function buildTopToolbar(dtApi) {
      const $wrapper = $(dtApi.table().container()).closest('.dataTables_wrapper');

      // 防止重複建立
      if ($wrapper.find('.dt-top-toolbar').length) return;

      const buttons = $wrapper.find('.dt-buttons').get(0);
      const length = $wrapper.find('.dataTables_length').get(0);
      const filter = $wrapper.find('.dataTables_filter').get(0);

      if (!buttons || !length || !filter) return;

      const toolbar = document.createElement('div');
      toolbar.className = 'dt-top-toolbar';

      const rowTop = document.createElement('div');
      rowTop.className = 'dt-toolbar-row';

      const rowSearch = document.createElement('div');
      rowSearch.className = 'dt-toolbar-search';

      rowTop.appendChild(buttons);
      rowTop.appendChild(length);
      rowSearch.appendChild(filter);

      toolbar.appendChild(rowTop);
      toolbar.appendChild(rowSearch);

      // 插在最前面（更新時間會在它上面）
      $wrapper.prepend(toolbar);
    }

    /* ===============================
       DataTables 初始化
    =============================== */
    function initRankingTable(selector, defaultPageLength) {

      if ($.fn.DataTable.isDataTable(selector)) {
        $(selector).DataTable().destroy();
      }

      const table = $(selector).DataTable({
        dom: 'Blfrtip',

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

        scrollCollapse: true,
        scrollX: false,
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

        /* ⭐ 關鍵：初始化完成後再動工具列 */
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
       啟動
    =============================== */
    initRankingTable('#example1', 30);
    initRankingTable('#exampleJP', 10);

    injectUpdateTime('#example1', lastUpdateTW);
    injectUpdateTime('#exampleJP', lastUpdateJP);

    /* 顯示表格（避免白閃） */
    $('#example1, #exampleJP, .dataTables_scrollHead table, .dataTables_scrollBody table')
      .removeClass('datatable-init-hide');
  });
</script>


<script>
  document.querySelectorAll('.bp-activity-tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var server = btn.getAttribute('data-server');

      document.querySelectorAll('.bp-activity-tab-btn').forEach(function(b) {
        b.classList.remove('active');
      });

      document.querySelectorAll('.bp-activity-panel').forEach(function(panel) {
        panel.classList.remove('active');
      });

      btn.classList.add('active');

      var target = document.getElementById('bp-activity-' + server);
      if (target) target.classList.add('active');
    });
  });
</script>

<script>
  document.querySelectorAll('.bp-mini-tabs').forEach(function(tabWrap) {
    tabWrap.querySelectorAll('.bp-mini-tab-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var scope = tabWrap.getAttribute('data-scope');
        var tab = btn.getAttribute('data-tab');

        tabWrap.querySelectorAll('.bp-mini-tab-btn').forEach(function(b) {
          b.classList.remove('active');
        });

        document.querySelectorAll('#bp-activity-' + scope + ' .bp-mini-panel').forEach(function(panel) {
          panel.classList.remove('active');
        });

        btn.classList.add('active');

        var target = document.getElementById('bp-mini-' + scope + '-' + tab);
        if (target) target.classList.add('active');
      });
    });
  });
</script>



<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>