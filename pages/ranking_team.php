<?php
session_start();

require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定

$seoTitle = '對戰組合 | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "ranking_team";
$pageTitleFull = '對戰組合 | UL.GG 戰績網'; //桌機
$pageTitleText = '對戰組合'; //手機

ob_start();  // ⭐ 開始收集本頁 HTML

/* var_dump(IMG_BASE); */
?>




<style>
  /* =========================================================
   0. Utilities / Global
========================================================= */
  label {
    margin-bottom: 0px;
  }

  .btn {
    margin: 0px 2px;
  }

  .highlight {
    background-color: yellow;
    color: black;
    font-weight: bold;
  }

  .character-col {
    min-width: 91px;
  }

  .bp-group {
    padding: 10px 17px;
  }

  table.table-bordered.dataTable td {
    padding: 3px;
  }

  /* =========================================================
   2. Cards / Flip Container
========================================================= */
  .cards {
    display: flex;
    flex-direction: row;
  }

  .cards .flip-container {
    margin-left: -33px;
  }

  .cards .flip-container:first-of-type {
    margin-left: 0;
  }

  /* --- Flip Base --- */
  .flip-container {
    perspective: 800px;
    width: 97px;
    height: 65px;
    overflow: hidden;
    position: relative;
    display: inline-block;
    border-radius: 5px;
  }

  /* .flip-container:hover {
    overflow: visible;
    z-index: 10;
  } */

  .flip-container.flipped {
    overflow: visible !important;
    z-index: 9999 !important;
  }

  /* --- Flipper --- */
  .flipper {
    transform-style: preserve-3d;
    transition: transform 0.6s;
    width: 100px;
    height: 100px;
    position: relative;
  }

  .flip-container.flipped .flipper {
    transform: rotateY(180deg);
  }

  /* --- Front / Back --- */
  .front,
  .back {
    backface-visibility: hidden;
    position: absolute;
    width: 100%;
    height: 100%;
  }

  /* --- Images --- */
  .flip-container .front img,
  .flip-container .back img {
    width: 75px;
    height: 108px;
    object-fit: cover;
    object-position: top left;
    transition: transform 0.3s ease;
    border-radius: 5px;
  }

  /* Hover scale */
  /* .flip-container:hover .front img,
  .flip-container:hover .back img {
    transform: scale(1.6);
  } */

  /* Back side */
  .back {
    transform: rotateY(180deg);
    z-index: 11;
  }

  /* .flip-container.flipped .back img {
    transform: scale(2.1);
  } */

  /* Click indicator */
  /* .click-indicator {
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%) scale(0.9);
    max-width: 74px;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    z-index: 9999;
    opacity: 0;
    transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
  } */

  /* .flip-container:hover .click-indicator {
    opacity: 1;
    transform: translateX(-50%) scale(1);
  } */

  /* .click-indicator img {
    width: 255px !important;
    height: auto;
    display: block;
    transform: scale(0.4) !important;
  } */

  /* =========================================================
   3. Image Hover / Zoom
========================================================= */
  /* .zoomout_rank:hover {
    transform: scale(2);
    transition: all 0.2s linear;
  } */

  .image-flex {
    position: relative;
    display: flex;
    gap: 6px;
    align-items: center;
  }




  .image-flex img {
    height: auto;
    transition: transform 0.3s ease-in-out;
  }

  .image-flex img:first-child {
    margin-left: 0;
  }

  /* .image-flex img:hover {
    transform: scale(1.2);
    z-index: 10;
  } */

  /* =========================================================
   4. Buttons / Status Pills
========================================================= */
  /* .chk-btn {
    background: rgb(171, 170, 170);
    color: white;
    border-radius: 8px;
    font-weight: bold;
    padding: 4px 18px;
    margin: 2px 5px;
    transform: scale(1.1);
  }

  .enemy-win-btn {
    background: #ff4e4e;
    color: white;
    border-radius: 8px;
    font-weight: bold;
    padding: 1px 2px;
    margin: 2px -4px;
    transform: scale(1.1);
  }

  .player-win-btn {
    background: rgb(60, 153, 253);
    color: white;
    border-radius: 8px;
    font-weight: bold;
    padding: 1px 2px;
    margin: 2px -4px;
    transform: scale(1.1);
  } */

  /* =========================================================
   5. Navbar / Sidebar
========================================================= */
  .navbar {
    position: sticky !important;
  }

  .navbar-nav>li>.dropdown-menu {
    margin-top: 13px;
  }

  .sidebar-dim {
    opacity: 0.55;
    filter: grayscale(30%);
    transition: 0.3s;
  }

  .sidebar-dim:hover {
    opacity: 0.95;
    filter: grayscale(0%);
  }

  /* =========================================================
   6. Slider / COST Quick Select
========================================================= */
  .slider {
    max-width: 330px;
  }

  .slider.slider-horizontal .slider-tick-label-container .slider-tick-label {
    padding-top: 20px;
  }

  .quickSelect {
    font-size: 18px;
    padding: 0px 10px;
  }

  /* =========================================================
   7. Filter Panel (Dark UI)
========================================================= */
  .filter-panel {
    background: #14141c;
    border: 1px solid rgba(120, 120, 255, 0.2);
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 0 12px rgba(0, 0, 0, 0.4);
  }

  .filter-header {
    padding: 14px 18px;
    cursor: pointer;
    font-size: 16px;
    font-weight: 600;
    color: #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .filter-header:hover {
    background: rgba(255, 255, 255, 0.05);
  }

  /* 進階篩選收合動畫 */
  #filter-body {
    overflow: hidden;
    max-height: 0;
    opacity: 0;
    transition: max-height 0.3s ease, opacity 0.5s ease;
  }

  /* 展開狀態 */
  #filter-body.is-open {
    max-height: 1200px;
    /* 給一個足夠大的值即可 */
    opacity: 1;
  }

  /* --- Filter Block --- */
  .filter-block {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.06);
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 16px;
    margin: 5px;
  }

  .filter-block label {
    display: flex;
    justify-content: space-between;
    color: #b7b7ff;
    font-weight: 600;
  }

  /* --- Inputs --- */
  .input-dark,
  .input-group-dark .form-control {
    width: 100%;
    background: #1c1c25;
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #eee;
    border-radius: 6px;
    padding: 8px;
  }

  .input-dark::placeholder,
  .input-group-dark .form-control::placeholder {
    color: #aaa;
  }

  .input-dark:focus,
  .input-group-dark .form-control:focus {
    outline: none;
    border-color: #7a7aff;
  }

  .input-group-dark {
    display: flex;
    gap: 8px;
  }

  /* --- Filter Buttons --- */
  .btn-filter {
    background: #1f1f2b;
    border: 1px solid rgba(120, 120, 255, 0.4);
    color: #ccc;
    border-radius: 6px;
  }

  .btn-filter:hover {
    background: rgba(120, 120, 255, 0.2);
  }

  .btn-apply {
    background: linear-gradient(135deg, #6d6cff, #8a7dff);
    color: #fff;
    border: none;
    border-radius: 8px;
  }

  .btn-reset {
    background: #f0a44b;
    color: #111;
    border-radius: 8px;
    text-decoration: none;
  }

  .btn-reset:hover {
    opacity: 0.85;
  }

  .btn-apply,
  .btn-reset,
  .btn-filter {
    height: 38px;
    line-height: 38px;
    padding: 0 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    white-space: nowrap;
  }

  /* =========================================================
   8. Quick COST Grid
========================================================= */
  .quick-cost {
    color: #ccc;
    font-size: 14px;
    line-height: 22px;
  }

  .quick-cost span {
    font-weight: 700;
    color: #ffdd77;
  }

  .quick-cost-grid {
    display: grid;
    grid-template-columns: repeat(10, 1fr);
    gap: 8px;
  }

  .cost-pill {
    text-align: center;
    padding: 6px 11px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #ddd;
    font-size: 13px;
    text-decoration: none;
    transition: all .15s ease;
    white-space: nowrap;
    /* ⭐ 禁止換行（關鍵） */
    word-break: keep-all;
    /* ⭐ 不允許 - 被斷行 */
    text-align: center;

  }

  .cost-pill:hover {
    background: rgba(120, 120, 255, 0.35);
    color: #fff;
    border-color: rgba(120, 120, 255, 0.8);
  }

  .cost-pill.cost-plus {
    grid-column: span 10;
  }

  .cost-pill.cost-picked {
    background: linear-gradient(135deg, #ffb347, #ffdd77);
    color: #222;
    font-weight: 700;
  }



  /* =========================================================
   10. DateRangePicker (Dark Theme)
========================================================= */
  .daterangepicker {
    background: #1c1c25 !important;
    border: 1px solid rgba(120, 120, 255, 0.4);
    color: #ddd;
  }

  .daterangepicker .ranges li {
    background: #1c1c25;
    color: #ccc;
    border-color: rgba(255, 255, 255, 0.1);
  }

  .daterangepicker .ranges li:hover {
    background: rgba(120, 120, 255, 0.3);
    color: #fff;
  }

  .daterangepicker .ranges li.active {
    background: #6d6dff !important;
    color: #fff !important;
  }

  .daterangepicker .calendar-table {
    background: #1c1c25 !important;
    color: #ccc;
  }

  .daterangepicker td.available,
  .daterangepicker th.available {
    color: #ccc !important;
  }

  .daterangepicker td.available:hover {
    background: rgba(120, 120, 255, 0.25) !important;
    color: #fff !important;
  }

  .daterangepicker td.in-range {
    background: rgba(120, 120, 255, 0.28) !important;
    color: #fff !important;
  }

  .daterangepicker td.start-date,
  .daterangepicker td.end-date,
  .daterangepicker td.active {
    background: #6d6dff !important;
    color: #fff !important;
  }

  .daterangepicker td.off {
    color: #555 !important;
    background: #14141c !important;
  }

  /* =========================================================
   11. Charts
========================================================= */
  #visitorsChart {
    width: 200px !important;
    height: 120px !important;
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
  }

  /* 表格一定吃滿 */
  .table-full table.dataTable {
    width: 100% !important;
    table-layout: auto;
    /* ⭐ 不要 fixed */
  }



  /* 只修 DataTables 自己產生的 row */
  .dataTables_wrapper>.row {
    margin-left: 0 !important;
    margin-right: 0 !important;
  }

  /* =========================
   Mobile 3V3 Card Layout
========================= */

  .team-mobile-list {
    display: none;
  }

  /* 📱 手機模式 */
  @media (max-width: 768px) {

    /* 隱藏原本 table */
    .dataTables_wrapper {
      display: none;
    }

    .team-mobile-list {
      display: flex;
      flex-direction: column;
      gap: 14px;
      padding: 10px;
    }

    .team-mobile-card {
      background: #14141c;
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 12px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35);
    }

    .team-mobile-deck .cards {
      justify-content: center;
    }

    .team-mobile-names {
      text-align: center;
      font-size: 14px;
      margin-top: 6px;
      color: #ddd;
      line-height: 1.4;
    }

    .team-mobile-stats {
      display: flex;
      justify-content: space-around;
      margin-top: 10px;
      font-size: 13px;
      color: #bbb;
    }

    .team-mobile-stats b {
      color: #ffdd77;
    }

    .team-mobile-result {
      text-align: center;
      margin-top: 6px;
      font-size: 12px;
      color: #aaa;
    }

    .team-mobile-action {
      margin-top: 10px;
      text-align: center;
    }
  }

  .search_bar {
    width: 50%;
    margin: auto;
    min-width: 320px;
  }

  /* ⭐ 關鍵修正 */
  .dataTables_scrollBody {
    overflow: visible !important;
  }

  .card-dark {
    padding: 10px 4px;
  }
</style>


<?php

//php start

$permission = $_SESSION["ack"] ?? 0;
$username = $_SESSION['username'] ?? '';
//$permission = $_SESSION["ack"] ?? 0;






function progress_color($rate)
{
  if ($rate < 25) {
    $color = 'red';
  } elseif ($rate < 50) {
    $color = 'yellow';
  } elseif ($rate < 75) {
    $color = 'blue';
  } else {
    $color = 'green';
  }
  return $color;
}

function cost_punish(int $id1_cost, int $id2_cost, int $id3_cost)
{
  $punish = 0;
  $dif_a = abs($id1_cost - $id2_cost);
  $dif_b = abs($id1_cost - $id3_cost);
  $dif_c = abs($id2_cost - $id3_cost);
  if ($dif_a > 6) {
    $punish += 5;
    if ($dif_a > 13) {
      $punish += 5;
    }
  }
  if ($dif_b > 6) {
    $punish += 5;
    if ($dif_b > 13) {
      $punish += 5;
    }
  }
  if ($dif_c > 6) {
    $punish += 5;
    if ($dif_c > 13) {
      $punish += 5;
    }
  }

  return $punish;
}

/**
 *  新版評分函式（相容舊版 calcPerformanceByTotalGames）
 *  - 平滑勝率 (Bayesian Smoothing)
 *  - 自動調整 logBase（依最大場次縮放）
 *  - 上限 20 分（保持與舊版一致）
 *
 *  @param int   $total_games  總場次
 *  @param float $win_rate     0~1 的勝率（你原本傳入的）
 *  @param int   $d            期間天數（相容舊版，可忽略）
 */
function calcPerformanceByTotalGames($total_games, $win_rate, $d)
{
  // -------------------------------
  // 1) 安全檢查
  // -------------------------------
  if ($total_games <= 0) {
    return 0.0;
  }

  // -------------------------------
  // 2) 勝率平滑（避免 6–0 → 100%）
  // -------------------------------
  $priorGames = 20;   // 假設先驗有 20 場平衡資料
  $priorRate  = 0.5;  // 預設勝率 50%

  // 原本勝率修正成平滑後勝率
  $adj_win_rate = ($win_rate * $total_games + $priorRate * $priorGames)
    / ($total_games + $priorGames);

  // -------------------------------
  // 3) 自動 logBase（依據「希望最大場次的加權」來定）
  // -------------------------------
  // 你原本用 d*4+9，我們保留邏輯基底，但用 min/max 限制不會亂跳
  $raw_log = $d * 7 + 2;
  $logBase = min(max($raw_log, 5), 60); // 限制範圍 15~45

  // 避免除以 0
  if ($logBase <= 1) $logBase = 15;

  // -------------------------------
  // 4) 場次加權（log scale）
  // -------------------------------
  $games_factor = log($total_games + 1) / log($logBase);

  // 限制值避免跑太偏（0.3~2.0）
  $games_factor = max(0.3, min($games_factor, 2.0));

  // -------------------------------
  // 5) 計算最終評分（0~20）
  // -------------------------------
  $win_power = 2;
  $score = pow($adj_win_rate, $win_power) * $games_factor * 35;
  return round(min($score, 20.0), 1);
}
function getShowPrivate(PDO $db, string $username): int
{
  $stmt = $db->prepare("
    SELECT show_private
    FROM game_user
    WHERE (username) = (:u)
    LIMIT 1
  ");
  $stmt->execute([':u' => $username]);
  $val = $stmt->fetchColumn();
  return $val !== false ? (int)$val : 0;
}

function human_cond($alias = "")
{
  $a = $alias ? "$alias." : "";
  return "(
        ({$a}is_bot = 0 OR {$a}is_bot IS NULL)
        AND {$a}user_agent REGEXP 'Windows|Macintosh|iPhone|Android|Linux'
    )";
}


/**
 * 依 char_ids 產生卡組圖片 HTML
 * char_ids 格式：[12][45][78]
 */
function buildDeckImageHtml(string $char_ids, array $character_info): string
{
  preg_match_all('/\[(\d+)\]/', $char_ids, $matches);

  $html = "<div class='cards'>";

  foreach ($matches[1] as $char_id) {

    $ico = $character_info[$char_id]['ico'] ?? '';
    if (empty($ico)) continue;

    $ico_front = IMG_BASE . $ico;

    $html .= <<<HTML
    <div class="flip-container flippable">
      <div class="flipper">
        <div class="front">
          <img class="zoomout_rank" src="{$ico_front}" loading="lazy">
        </div>
      </div>
    </div>
    HTML;
  }

  $html .= "</div>";

  return $html;
}


/* =========================================================
 * Session 預設值
 * ========================================================= */
$_SESSION['cost_min']     ??= 0;
$_SESSION['cost_max']     ??= 140;
$_SESSION['bp_min']       ??= 1000;
$_SESSION['bp_max']       ??= 2000;
$_SESSION['combined']     ??= 0;
$_SESSION['total_games']  ??= 0;
$_SESSION['daterange']    ??= date('Y-m-d', strtotime('-2 days')) . ' - ' . date('Y-m-d');

/* =========================================================
 * Reset
 * ========================================================= */
if (isset($_GET['reset'])) {
  $_SESSION = array_merge($_SESSION, [
    'cost_min' => 0,
    'cost_max' => 140,
    'bp_min'   => 1000,
    'bp_max'   => 2000,
    'player_search' => '',
    'combined' => 0,
    'current_cost' => '',
  ]);

  header("Location: ranking_team.php");
  exit;
}
/* =========================================================
 * 接收 slider 傳來的 cost_min / cost_max（補齊）/Cost 篩選（🔥 定義優先順序）
 * ========================================================= */
if (isset($_GET['cost_min'], $_GET['cost_max'])) {
  $_SESSION['cost_min'] = (int)$_GET['cost_min'];
  $_SESSION['cost_max'] = (int)$_GET['cost_max'];
} /* elseif (isset($_GET['cost']) && $_GET['cost'] !== '') {
  $raw = trim($_GET['cost']);


  if (preg_match('/^\d+\+$/', $raw)) {
    $cost_min = (int) rtrim($raw, '+');
    $cost_max = 140;
  } elseif (is_numeric($raw)) {
    $cost_min = $cost_max = (int)$raw;
  } else {
    $cost_min = 0;
    $cost_max = 140;
  }

  $_SESSION['cost_min'] = $cost_min;
  $_SESSION['cost_max'] = $cost_max;

  // 🔥 同步 current_cost（給 UI 用）
  $_SESSION['current_cost'] = $raw;
  $current_cost = $raw;
} */
$current_cost = $_SESSION['current_cost'] ?? '';
/* print "current_cost=$current_cost"; */

/* =========================================================
 * 接收 daterange（🔥 修正重點）
 * ========================================================= */
if (isset($_GET['daterange'])) {
  $current_daterange = $_GET['daterange'];
  $_SESSION['daterange'] = $current_daterange;
}

$character_id = null;

if (isset($_GET['char_id']) && ctype_digit($_GET['char_id'])) {
  $character_id = (int)$_GET['char_id'];
}

/* =========================================================
 * ✅ 回填：讓後面舊程式仍可用 $cost_min/$cost_max/$combined
 * ========================================================= */
$cost_min = (int)($_SESSION['cost_min'] ?? 0);
$cost_max = (int)($_SESSION['cost_max'] ?? 140);
$combined = (int)($_SESSION['combined'] ?? 0);
$total_games = (int)($_SESSION['total_games'] ?? 0);
$current_daterange = $_SESSION['daterange'] ?? (date('Y-m-d', strtotime('-2 days')) . ' - ' . date('Y-m-d'));

$currentServer = strtoupper($_SESSION["server"] ?? 'ALL');
if (isset($_GET['server'])) {
  $currentServer = strtoupper($_GET['server']);
}
if (!in_array($currentServer, ['STEAM', 'DMM', 'ALL'], true)) {
  $currentServer = 'ALL';
}

$_SESSION["server"] = $currentServer;
if ($currentServer == 'ALL') {
  $server_sql = "";
} elseif ($currentServer == 'DMM') {
  $server_sql = "AND region='JP'";
} elseif ($currentServer == 'STEAM') {
  $server_sql = "AND region='TW'";
}
/* print "cost_min=$cost_min";
print "cost_max=$cost_max"; */

/* =========================================================
 * Combined / DateRange
 * ========================================================= */
if (isset($_GET['combined'])) {
  $_SESSION['combined'] = (int)$_GET['combined'];
}

if (isset($_GET['set_time'], $_GET['daterange'])) {
  $_SESSION['daterange'] = $_GET['daterange'];
}

/* =========================================================
 * 隊伍搜尋（2v2 / 3v3）
 * ========================================================= */

$search_team = isset($_POST['search_team']) ? 1 : 0;
// ==============================
// 防止非 POST 入口造成 undefined
// ==============================
/* if ($search_team) {
  $id1 = (int)($_SESSION['id1'] ?? 0);
  $id2 = (int)($_SESSION['id2'] ?? 0);
  $id3 = (int)($_SESSION['id3'] ?? 0);
} */
$twovtwo = 0;
$isSearchTeam = 0;
if (isset($_POST['search_team'])) {

  $ids = [
    (int)$_POST['id1'],
    (int)$_POST['id2'],
    (int)$_POST['id3'],
  ];

  /* $min_ids=min($ids[1],$ids[2],$ids[3]); */

  /* sort($ids); */
  if ($ids[2] === 0) {
    $twovtwo = 1;
    [$id1, $id2] = [$ids[0], $ids[1]];
    $id3 = 0;
  } else {
    [$id1, $id2, $id3] = $ids;
  }

  $search_team = 1;
  $isSearchTeam = 1;
}
/* print "<br>twovtwo=$twovtwo"; */
/* print "<br>id1=$id1";
print ";id2=$id2";
print ";id3=$id3"; */
// ✅ 預設值（避免 Undefined variable）
$id1 = $id1 ?? 0;
$id2 = $id2 ?? 0;
$id3 = $id3 ?? 0;

$e1 = 0;
$e2 = 0;
$e3 = 0;

/* =========================================================
 * 日期區間處理 + 最多 30 天限制
 * ========================================================= */
list($start_date, $end_date) = explode(' - ', $_SESSION['daterange']);

$start_ts = strtotime($start_date);
$end_ts   = strtotime($end_date . ' 23:59:59');

$max_days = 30;
$diff_days = ($end_ts - $start_ts) / 86400;

if ($diff_days > $max_days && $permission != 2) {
  $start_ts = strtotime("-{$max_days} days", $end_ts);
  $start_date = date('Y-m-d', $start_ts);

  $_SESSION['daterange'] = $start_date . ' - ' . $end_date;
  $_SESSION['warning'] = "查詢區間過大，已限制為 {$max_days} 天";
}

/* =========================================================
 * 實際查詢用日期
 * ========================================================= */
$start_date = date('Y-m-d', $start_ts);
$end_date   = date('Y-m-d H:i:s', $end_ts);

/* =========================================================
 * 天數（含首尾）
 * ========================================================= */
$dtStart = new DateTime($start_date);
$dtEnd   = new DateTime($end_date);
$d = min(max($dtStart->diff($dtEnd)->days + 1, 1), $max_days);

/* =========================================================
 * BP 範圍（預設值）
 * ========================================================= */
$bp_min = 1000;
$bp_max = 2000;
$bp_range = $_SESSION['bp_range'] ?? 'all';

/* =========================================================
 * BP Slider / Session 處理
 * ========================================================= */
if (isset($_GET['bp_min'], $_GET['bp_max'])) {
  // 1️⃣ 來自 slider（GET）
  $bp_min = (int)$_GET['bp_min'];
  $bp_max = (int)$_GET['bp_max'];

  $_SESSION['bp_min'] = $bp_min;
  $_SESSION['bp_max'] = $bp_max;
} elseif (isset($_SESSION['bp_min'], $_SESSION['bp_max'])) {
  // 2️⃣ 從 Session 還原（刷新 / 返回）
  $bp_min = (int)$_SESSION['bp_min'];
  $bp_max = (int)$_SESSION['bp_max'];
}

/* =========================================================
 * 玩家搜尋（player_search）
 * ========================================================= */
$player_search = '';

if (!empty($_GET['player_search'])) {
  // 1️⃣ 使用者主動搜尋
  $player_search = trim($_GET['player_search']);
  $_SESSION['player_search'] = $player_search;

  // 🔁 搜尋時重置其他條件（保持你原本行為）
  $cost_min = 0;
  $cost_max = 140;
  $bp_min   = 1000;
  $bp_max   = 2000;
  $combined = 2;

  $_SESSION['cost_min'] = $cost_min;
  $_SESSION['cost_max'] = $cost_max;
  $_SESSION['bp_min']   = $bp_min;
  $_SESSION['bp_max']   = $bp_max;
  // $_SESSION['combined'] = $combined; // ← 你原本有註解，我照留

} elseif (!empty($_SESSION['player_search'])) {
  // 2️⃣ 沒有 GET，但 Session 有紀錄
  $player_search = $_SESSION['player_search'];
}

/* =========================================================
 * 是否為玩家搜尋模式
 * ========================================================= */
$srch_player = ($player_search !== '') ? 1 : 0;



?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-10" style="float:none; margin:0 auto;">
          <!-- ===========================
        🔵 進階選取區（可收合）
      ============================ -->
          <div class="filter-panel">
            <div class="filter-block search_bar">
              <label>搜尋玩家</label>
              <form method="GET" action="ranking_team.php">
                <div class="input-group input-group-dark">
                  <input
                    type="text"
                    class="input-dark"
                    name="player_search"
                    placeholder="輸入玩家名稱"
                    value="<?php echo $player_search ?>">


                  <button type="submit" class="btn btn-apply">
                    搜尋
                  </button>

                  <a href="ranking_team.php?reset=1" class="btn btn-reset">
                    重置
                  </a>
                </div>
              </form>
            </div>
            <div class="filter-header" onclick="toggleFilter()">
              <span>🔍 進階篩選條件</span>
              <i id="filter-arrow" class="fa fa-chevron-down"></i>
            </div>

            <div id="filter-body" class="filter-body">

              <form method="GET" action="ranking_team.php">
                <div class="row">

                  <!-- 左側 -->
                  <div class="col-md-6">

                    <!-- 時間選取 -->
                    <div class="filter-block">
                      <label>時間選取</label>
                      <div class="input-group">
                        <button type="button" class="btn-filter" id="daterange-btn">
                          <i class="fa fa-calendar"></i>
                          <span id="daterange-text">選擇區間</span>
                        </button>

                        <input type="hidden" name="daterange" id="daterange" value="<?= htmlspecialchars($current_daterange) ?>">
                        <button type="submit" name="set_time" value="1" class="btn-apply">確定</button>
                      </div>
                    </div>



                    <!-- BP選取 -->
                    <div class="filter-block">
                      <label>
                        BP 區間
                        <span class="range-value" id="bp-display">
                          <?= $bp_min ?> - <?= $bp_max ?>
                        </span>
                      </label>

                      <input
                        type="text"
                        id="bp-slider"
                        class="slider form-control slider-primary"

                        data-slider-value="[<?php echo $bp_min ?>,<?php echo $bp_max ?>]"
                        data-slider-orientation="horizontal"
                        data-slider-selection="before"
                        data-slider-tooltip="hide"
                        data-slider-id="blue">
                    </div>

                    <input type="hidden" name="bp_min" id="bp_min" value="<?php echo $bp_min; ?>">
                    <input type="hidden" name="bp_max" id="bp_max" value="<?php echo $bp_max; ?>">
                    <input type="hidden" name="cost_min" id="costMin" value="<?php echo $cost_min; ?>">
                    <input type="hidden" name="cost_max" id="costMax" value="<?php echo $cost_max; ?>">


                  </div>

                  <!-- 右側 -->
                  <div class="col-md-6">

                    <!-- 快速 COST -->
                    <div class="filter-block">
                      <label>快速 COST</label>
                      <div class="quick-cost-grid">

                        <?php
                        $quickCosts = [
                          '50-59' => [50, 59],
                          '60-69' => [60, 69],
                          '70-80' => [70, 80],
                          '90+'   => [90, 140],
                          '全部'   => [0, 140],
                        ];

                        foreach ($quickCosts as $label => [$min, $max]) {

                          // 判斷是否為目前選中
                          $isActive = (
                            (int)$cost_min === $min &&
                            (int)$cost_max === $max
                          );

                          $query = http_build_query([
                            'cost_min' => $min,
                            'cost_max' => $max,
                          ]);

                          echo '<a class="cost-pill '
                            . ($isActive ? 'cost-picked' : '')
                            . '" href="?' . $query . '">'
                            . $label
                            . '</a>';
                        }
                        ?>

                      </div>
                    </div>
                    <!-- COST選取 -->
                    <div class="filter-block">
                      <label>
                        COST 區間
                        <span class="range-value" id="cost-display">
                          <?= $cost_min ?> - <?= $cost_max ?>
                        </span>
                      </label>

                      <input
                        type="text"
                        id="cost-slider"
                        class="slider form-control slider-primary"
                        data-slider-min="20"
                        data-slider-max="140"
                        data-slider-step="1"
                        data-slider-value="[<?php echo $cost_min ?>,<?php echo $cost_max ?>]"
                        data-slider-orientation="horizontal"
                        data-slider-selection="before"
                        data-slider-tooltip="hide"
                        data-slider-id="aqua">
                    </div>



                  </div>

                </div>
              </form>

            </div>

          </div>
          <!-- ===========================
📊 Score 說明（可收合）
============================ -->
          <div class="card-dark p-2 mb-2" style="font-size:13px; color:#cfd3ff;">
            <div
              style="display:flex; align-items:center; gap:8px; cursor:pointer;"
              onclick="toggleScoreHint()">
              <b>📊 評分（Score）</b>
              <span id="scoreHintToggle"
                style="color:#7aa2ff; font-size:12px;">
                ❓ 評分說明
              </span>
            </div>

            <div id="scoreHintBody"
              style="display:none; margin-top:8px; color:#cfd3ff;">

              Score 為「<b>勝率 × 出場次數可信度</b>」的綜合指標，用於衡量卡組整體穩定度：
              <ul style="margin:6px 0 0 18px; padding:0;">
                <li>採用 <b>勝率平滑</b>，避免少量對戰（如 3～5 場）造成極端勝率</li>
                <li>出場次數以 <b>對數加權</b>，場次越多評分越可信</li>
                <li>評分範圍為 <b>0～20</b>，分數越高代表整體表現越穩定</li>
              </ul>
              <span style="color:#aaa;">
                ※ 若對戰場次過少（例如 &lt; 10 場），即使勝率高，評分也會被刻意壓低
              </span>
            </div>
          </div>

          <!-- ===========================
          🔵 表格
          ============================ -->
          <div class="card-dark p-3">
            <div class="table-full">
              <table id="example2" class="table datatable-init-hide">
                <thead>

                  <tr>
                    <th style="width: 10px">#</th>

                    <?php

                    $orderation = 5;
                    if ($combined == 2) { //玩家統計
                      print "
                        <th>最近使用</th>
                        <th>角色名稱</th>
                        <th>cost</th>
                        <th>勝率</th>
                        <th>BP</th>
                        <th>玩家</th>
                        <th>勝</th>
                        <th>負</th>
                        <th>總</th>
                        ";
                    } else {
                      print "
                        <th>Deck 排組</th>
                        <th>角色名稱</th>
                        <th>cost</th>
                        <th>勝率</th>
                        <th>評分</th>
                        <th>勝</th>
                        <th>負</th>
                        <th>總</th>";
                    }
                    ?>
                    <!-- <th>更新日期</th> -->
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $i = 1;
                  $total_games = intval($total_games ?? 0);
                  $limit = 100;
                  if ($permission >= 2) {
                    //$limit = 200;
                    $time_range = 0;
                  } else {
                    //$limit = 200;
                    $time_range = 0;
                  }


                  $sql_condition = 0;
                  if ($player_search !== '') {
                    $sql_condition = 1;
                    $combined = 2;
                    $_SESSION["combined"] = 2;
                    $sql = "WITH combined AS (
                        SELECT 
                            e1 AS char1, e2 AS char2, e3 AS char3,
                            lose AS win, win AS lose,
                            bp_p1 AS bp,
                            name_p1 AS player_name,
                            name_p2 AS enemy_name,
                            bp_p2 AS enemy_bp,
                            draw_p1 AS tie,
                            update_time, cost
                        FROM arena_unlight
                        WHERE ack1 = 1 AND ack2 = 1

                        UNION ALL

                        SELECT 
                            u1 AS char1, u2 AS char2, u3 AS char3,
                            win, lose,
                            bp_p2 AS bp,
                            name_p2 AS player_name,
                            name_p1 AS enemy_name,
                            bp_p1 AS enemy_bp,
                            tie AS tie,
                            update_time, cost
                        FROM arena_unlight
                        WHERE ack1 = 1 AND ack2 = 1
                    ),
                    latest AS (
                        SELECT *
                        FROM (
                            SELECT *, ROW_NUMBER() OVER (PARTITION BY player_name ORDER BY update_time DESC) AS rn
                            FROM combined
                        ) AS ranked
                        WHERE rn = 1
                    ),
                    summary AS (
                        SELECT 
                            player_name,
                            SUM(win) AS total_win,
                            SUM(lose) AS total_lose
                        FROM combined
                        GROUP BY player_name
                    )
                    SELECT 
                        latest.char1, latest.char2, latest.char3,
                        summary.total_win, summary.total_lose,
                        latest.bp, latest.player_name, latest.update_time, latest.cost,
                        ROUND(summary.total_win / (summary.total_win + summary.total_lose) * 100, 1) AS win_rate,
                        CASE WHEN latest.win = 1 THEN 1 ELSE 0 END AS last_win,
                        CASE WHEN latest.tie = 1 THEN 1 ELSE 0 END AS last_tie,
                        latest.enemy_bp
                    FROM latest
                    JOIN summary ON latest.player_name = summary.player_name
                    WHERE 
                        latest.player_name LIKE :search
                        
                    ";
                    //print "search_player";
                  } elseif (strtotime($start_date) && strtotime($end_date)) {
                    $sql_condition = 2;
                    //// 確保日期格式正確，避免 SQL 錯誤
                    if ($search_team) { //cost計算機跳轉
                      if ($combined) {
                        $sql = "SELECT * FROM best_combined_unlight WHERE (char1='$id1' and char2='$id2') and (update_time BETWEEN '$start_date' AND '$end_date')AND (update_time < (NOW() - INTERVAL $time_range MINUTE))  LIMIT $limit;";
                      } elseif ($twovtwo == 1) {
                        $sql = "SELECT * FROM best_3v3_teams_unlight WHERE ((char1='$id1' and char2='$id2') or (char1='$id1' and char3='$id2')or(char2='$id1' and char3='$id2'));";
                      } else {
                        $sql = "SELECT * FROM best_3v3_teams_unlight 
                    WHERE (char1='$id1' and char2='$id2' and char3='$id3') 
                    OR (char1='$id1' and char2='$id3' and char3='$id2') 
                    OR (char1='$id2' and char2='$id1' and char3='$id3') 
                    OR (char1='$id2' and char2='$id3' and char3='$id1') 
                    OR (char1='$id3' and char2='$id1' and char3='$id2') 
                    OR (char1='$id3' and char2='$id2' and char3='$id1') 
                    ";
                      }
                    } elseif ($combined == 2) { //玩家BP統計
                      $sql_condition = 3;
                      $sql = "WITH combined AS (
                      SELECT 
                        e1 AS char1, e2 AS char2, e3 AS char3,
                        lose AS win, win AS lose,
                        bp_p1 AS bp,
                        name_p1 AS player_name,
                        name_p2 AS enemy_name,
                        bp_p2 AS enemy_bp,
                        tie AS tie,
                        update_time, cost
                      FROM arena_unlight
                      WHERE update_time BETWEEN '$start_date' AND '$end_date'
                        AND ack1 = 1 AND ack2 = 1

                      UNION ALL

                      SELECT 
                        u1 AS char1, u2 AS char2, u3 AS char3,
                        win, lose,
                        bp_p2 AS bp,
                        name_p2 AS player_name,
                        name_p1 AS enemy_name,
                        bp_p1 AS enemy_bp,
                        tie AS tie,
                        update_time, cost
                      FROM arena_unlight
                      WHERE update_time BETWEEN '$start_date' AND '$end_date'
                        AND ack1 = 1 AND ack2 = 1
                      ),
                      latest AS (
                        SELECT *
                        FROM (
                          SELECT *, ROW_NUMBER() OVER (PARTITION BY player_name ORDER BY update_time DESC) AS rn
                          FROM combined
                        ) AS ranked
                        WHERE rn = 1
                      ),
                      summary AS (
                        SELECT 
                          player_name,
                          SUM(win) AS total_win,
                          SUM(lose) AS total_lose
                        FROM combined
                        GROUP BY player_name
                      )
                      SELECT 
                        latest.char1, 
                        latest.char2, 
                        latest.char3,
                        summary.total_win, 
                        summary.total_lose,
                        latest.bp, 
                        latest.player_name, 
                        latest.update_time, 
                        latest.cost,
                        ROUND(summary.total_win / (summary.total_win + summary.total_lose) * 100, 1) AS win_rate,
                        CASE WHEN latest.win = 1 THEN 1 ELSE 0 END AS last_win,
                        CASE WHEN latest.lose = 1 THEN 1 ELSE 0 END AS last_lose,
                        CASE WHEN latest.tie = 1 THEN 1 ELSE 0 END AS last_tie,
                        latest.enemy_bp
                      FROM latest
                      JOIN summary ON latest.player_name = summary.player_name
                      AND (update_time < (NOW() - INTERVAL $time_range MINUTE))
                      AND(bp BETWEEN $bp_min AND $bp_max)
                      ORDER BY bp DESC
                      LIMIT $limit;";
                    } elseif ($combined == 0) { //3人統計
                      $sql_condition = 4;
                      $sql = "WITH combined AS (
                        -- P1 陣容
                        SELECT
                          e1 AS char1,
                          LEAST(e2,e3)    AS char2,
                          GREATEST(e2,e3) AS char3,
                          lose AS win, win AS lose,
                          update_time,
                          cost,
                          bp_p1 AS bp
                        FROM arena_unlight
                        WHERE ack1=1 AND ack2=1
                        $server_sql
                        AND (
                          :character_id IS NULL
                          OR e1 = :character_id
                          OR e2 = :character_id
                          OR e3 = :character_id
                        )
                        UNION ALL

                        -- P2 陣容
                        SELECT
                          u1 AS char1,
                          LEAST(u2,u3)    AS char2,
                          GREATEST(u2,u3) AS char3,
                          win, lose,
                          update_time,
                          cost,
                          bp_p2 AS bp
                        FROM arena_unlight
                        WHERE ack1=1 AND ack2=1
                        $server_sql
                        AND (
                            :character_id IS NULL
                            OR u1 = :character_id
                            OR u2 = :character_id
                            OR u3 = :character_id
                          )
                      ),
                      summary AS (
                        SELECT
                          char1, char2, char3,
                          SUM(win)          AS total_wins,
                          SUM(lose)         AS total_loses,
                          SUM(win+lose)     AS total_games,
                          COALESCE(
                            SUM(win) / NULLIF(SUM(win+lose),0) * 100
                          , 0)                AS win_rate,
                          MAX(update_time)   AS last_played,
                          SUBSTRING_INDEX(
                            GROUP_CONCAT(cost ORDER BY update_time DESC SEPARATOR ','),
                            ',',1
                          )                 AS cost
                        FROM combined
                        WHERE
                          -- 只篩 時間 & BP
                          update_time BETWEEN :start_date AND :end_date
                          AND update_time < (NOW() - INTERVAL :time_range MINUTE)
                          AND bp BETWEEN :bp_min AND :bp_max
                        GROUP BY char1, char2, char3
                        HAVING SUM(win + lose) > 2
                      )
                      SELECT
                        *,
                        -- cost 落在區間內的先排 in_range=1
                        (cost BETWEEN :cost_min AND :cost_max) AS in_range
                      FROM summary
                      ORDER BY
                        in_range DESC,     -- 先內圈
                        total_games DESC   -- 再照出場次數
                      LIMIT :limit;
                      ";
                    }
                  }
                  // 預備 SQL
                  $stmt = $db->prepare($sql);
                  if ($player_search == '' && !$search_team) {
                    // 基本參數
                    $stmt->bindValue(':start_date',  $start_date);
                    $stmt->bindValue(':end_date',    $end_date);
                    // 看 SQL 裡有沒有用到這些參數，再綁
                  }
                  if (strpos($sql, ':time_range')   !== false) {
                    $stmt->bindValue(':time_range', (int)$time_range, PDO::PARAM_INT);
                  }
                  if (strpos($sql, ':bp_min')       !== false) {
                    $stmt->bindValue(':bp_min',     (int)$bp_min,     PDO::PARAM_INT);
                  }
                  if (strpos($sql, ':bp_max')       !== false) {
                    $stmt->bindValue(':bp_max',     (int)$bp_max,     PDO::PARAM_INT);
                  }
                  // 加入 cost 範圍的綁定
                  if (strpos($sql, ':cost_min')     !== false) {
                    $stmt->bindValue(':cost_min',   (int)$cost_min,   PDO::PARAM_INT);
                  }
                  if (strpos($sql, ':cost_max')     !== false) {
                    $stmt->bindValue(':cost_max',   (int)$cost_max,   PDO::PARAM_INT);
                  }
                  if (strpos($sql, ':search')       !== false) {
                    $stmt->bindValue(':search',     '%' . ($player_search ?? '') . '%', PDO::PARAM_STR);
                  }

                  if (strpos($sql, ':limit')        !== false) {
                    $stmt->bindValue(':limit',      (int)$limit,       PDO::PARAM_INT);
                  }
                  if (strpos($sql, ':character_id') !== false) {
                    if ($character_id !== null) {
                      $stmt->bindValue(':character_id', $character_id, PDO::PARAM_INT);
                    } else {
                      $stmt->bindValue(':character_id', null, PDO::PARAM_NULL);
                    }
                  }



                  /* echo "sql=$sql_condition : $sql"; */
                  // 執行
                  $stmt->execute();
                  $character_info = []; // ✅ 新增這個
                  $columns = []; // 收集所有結果
                  $final_rows = [];
                  $i = 1; // 從 1 開始，等一下列出編號

                  // 主查詢迴圈
                  while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    // ============================
                    // 解構資料（依 combined 模式）
                    // ============================
                    $player_name = '';
                    $ids = [];

                    /*
                    * combined == 2：玩家搜尋模式
                    * row 結構固定，直接解構
                    */
                    if ($combined == 2) {

                      // ── 角色組合 ──
                      $e1 = (int)$row[0];
                      $e2 = (int)$row[1];
                      $e3 = (int)$row[2];
                      $ids = array_filter([$e1, $e2, $e3]);

                      // ── 勝負與 BP ──
                      $win  = (int)$row[3];
                      $lose = (int)$row[4];
                      $bp   = (int)$row[5];

                      // ── 玩家資訊 ──
                      $player_name = $row[6];
                      $update_time = $row[7];

                      // ── 統計數值 ──
                      $cost_sum = (int)$row[8];
                      $win_rate = round((float)$row[9], 0);
                      $rate_color = progress_color($win_rate);

                      // ── 對戰結果（ELO 計算用）──
                      $result_win  = (int)$row[10];
                      $result_lose = (int)$row[11];
                      $result_tie  = (int)$row[12];
                      $opponent_bp = isset($row[13]) ? (int)$row[13] : 1500;

                      // ── 勝負結果轉換（Win=1 / Tie=0.5 / Lose=0）──
                      if ($result_win === 1) {
                        $result = 1;
                      } elseif ($result_tie === 1) {
                        $result = 0.5;
                      } else {
                        $result = 0;
                      }

                      // ── BP After（ELO）──
                      $bp_after = round(
                        $bp + 32 * (
                          $result - 1 / (1 + pow(10, ($opponent_bp - $bp) / 500))
                        ),
                        0
                      );
                    } /*
                      * isSearchTeam == 1
                      * 使用者指定 3 人組搜尋
                      */ elseif ($isSearchTeam == 1) {

                      // ── 角色組合 ──
                      $e1 = (int)$row[0];
                      $e2 = (int)$row[1];
                      $e3 = (int)$row[2];

                      // ids 一定要先組（後面 SQL / 顯示都靠它）
                      $ids = array_values(array_filter([$e1, $e2, $e3]));

                      // ── 勝負統計 ──
                      $win = (int)$row[3];
                      $lose = (int)$row[4];
                      $all = (int)$row[5];

                      $win_rate = round((float)$row[6], 0);
                      $rate_color = progress_color($win_rate);

                      $update_time = $row[7];

                      // ── COST 即時計算（unlight 表）──
                      $cost_sum = 0;

                      if (!empty($ids)) {

                        $placeholders = implode(',', array_fill(0, count($ids), '?'));

                        $sql_cost = "SELECT SUM(cost)
                          FROM unlight
                          WHERE id IN ($placeholders)
                        ";

                        $stmt_cost = $db->prepare($sql_cost);
                        $stmt_cost->execute($ids);

                        $cost_sum = (int)$stmt_cost->fetchColumn();
                      }
                    } /*
                      * combined == 0
                      * 一般 3 人組排行
                      */ elseif ($combined == 0) {

                      // ── 角色組合 ──
                      $e1 = (int)$row[0];
                      $e2 = (int)$row[1];
                      $e3 = (int)$row[2];

                      $ids = array_filter([$e1, $e2, $e3]);

                      // ── 勝負統計 ──
                      $win  = (int)$row[3];
                      $lose = (int)$row[4];
                      $all  = (int)$row[5];

                      $win_rate = round((float)$row[6], 0);
                      $rate_color = progress_color($win_rate);

                      // ── 其他統計 ──
                      $update_time = $row[7];
                      $cost_sum = (int)$row[8];
                    }
                    // ============================
                    // 角色資料查詢（圖片 / 名稱 / COST）
                    // ============================
                    if (empty($ids)) {
                      continue; // 沒角色就跳過
                    }

                    $id_list = implode("','", $ids);

                    $sql2 = "SELECT ico, cost, name, level, ico_back, id
                      FROM unlight
                      WHERE id IN ('$id_list')
                      ORDER BY FIELD(id, '$id_list')
                    ";

                    $arr2 = $db->query($sql2);

                    // 給後面 replace 用的識別字串
                    $char_ids = '';
                    foreach ($ids as $cid) {
                      $char_ids .= "[$cid]";
                    }

                    // 初始化
                    $ico_arr  = '';
                    $name_arr = '';
                    $cost_arr = [];

                    /*
                    * Deck 角色資料組裝
                    * 來源：unlight 表
                    */
                    while ($row2 = $arr2->fetch(PDO::FETCH_NUM)) {

                      // ── 解構角色資料 ──
                      $ico       = $row2[0];
                      $cost      = (int)$row2[1];
                      $name      = $row2[2];
                      $level     = $row2[3];
                      $ico_back  = !empty($row2[4]) ? IMG_BASE . $row2[4] : '';
                      $id        = (int)$row2[5];

                      $ico_front = IMG_BASE . $ico;

                      // ── 組角色名稱顯示 ──
                      if ($combined == 3) {
                        // 模糊統計（不顯示等級）
                        $name_arr .= $name . "<br>";
                      } else {
                        $name_arr .= $level . $name . "<br>";
                      }

                      // ── 記錄角色圖資訊（供後面 replace）──
                      $character_info[$id] = [
                        'ico'      => $ico,
                        'ico_back' => $ico_back
                      ];

                      // ── Deck 佔位符（後面會被 <img> 取代）──
                      $ico_arr .= "[$id]";

                      // ── 收集 COST（供後續懲罰計算）──
                      $cost_arr[] = $cost;
                    }

                    if (count($cost_arr) >= 3) {
                      $punishment = cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
                      //$cost_sum += $punishment;
                    } else {
                      $punishment = 0;
                    }

                    $cost_color = ($punishment > 0) ? 'text-danger' : '';

                    if (($cost_sum >= $cost_min && $cost_sum <= $cost_max) or $isSearchTeam == 1) {

                      // ============================
                      // 共用數值計算
                      // ============================
                      $total_games = $win + $lose;

                      $games_factor = calcPerformanceByTotalGames(
                        $total_games,
                        $win_rate / 100,
                        $d
                      );

                      $display_factor = number_format($games_factor, 1);

                      // ⭐ Ranking -> Fight GET URL
                      // 玩家搜尋模式：維持原本「看玩家近期對戰」語意
                      if ($combined == 2 && $player_name !== '') {

                        $fightUrl = 'fight.php?' . http_build_query([
                          'player_name' => $player_name,
                          'server'      => $currentServer,
                        ]);

                      } else {

                        // 一般 3V3 排行：直接把隊伍帶給 Fight
                        $fightUrl = 'fight.php?' . http_build_query([
                          'e1'         => (int)$e1,
                          'e2'         => (int)$e2,
                          'e3'         => (int)$e3,
                          'bp_min'     => (int)$bp_min,
                          'bp_max'     => (int)$bp_max,
                          'start_date' => date('Y-m-d', strtotime($start_date)),
                          'end_date'   => date('Y-m-d', strtotime($end_date)),
                          'server'     => $currentServer,
                        ]);
                      }

                      $fightUrlHtml = htmlspecialchars(
                        $fightUrl,
                        ENT_QUOTES,
                        'UTF-8'
                      );
                      $rating_color   = progress_color($games_factor * 5);

                      // ============================
                      // 玩家搜尋 / BP 排序
                      // ============================
                      if ($combined == 2) {

                        $show_private1 = getShowPrivate($db, $player_name);

                        // 是否允許顯示完整卡組
                        $can_show_deck = (
                          $show_private1 ||
                          $permission >= 2 ||
                          $player_name === $username
                        );

                        if ($can_show_deck) {

                          $html = "
                            <td class=\"image-flex\">
                              <a href='$fightUrlHtml'
   class='btn-check-float'
   title='查看對戰紀錄'>
  <i class='fas fa-search'></i>
</a>

                              $ico_arr
                            </td>

                            <td class=\"character-col\">$name_arr</td>
                            <td class=\"" . ($punishment > 0 ? 'text-danger' : '') . "\">$cost_sum</td>
                            <td><div class='badge bg-$rate_color'>$win_rate%</div></td>
                            <td>$bp_after</td>
                            <td>$player_name</td>
                            <td>$win</td>
                            <td>$lose</td>
                            <td>$total_games</td>
                            ";
                        } else {

                          $html = "
                            <td class=\"image-flex\">
                              <a href='$fightUrlHtml'
   class='btn-check-float'
   title='查看對戰紀錄'>
  <i class='fas fa-search'></i>
</a>
                            </td>

                            <td>???</td>
                            <td>?</td>
                            <td><div class='badge bg-$rate_color'>$win_rate%</div></td>
                            <td>$bp_after</td>
                            <td>$player_name</td>
                            <td>$win</td>
                            <td>$lose</td>
                            <td>$total_games</td>
                            ";
                        }
                      } // ============================
                      // 非玩家 BP 搜尋（一般 3V3 / 計算機搜尋）
                      // ============================
                      else {

                        // ── 角色圖 + 快速查看 ──
                        $html = "
                          <td class=\"image-flex\">
                            <a href='$fightUrlHtml'
   class='btn-check-float'
   title='查看對戰紀錄'>
  <i class='fas fa-search'></i>
</a>

                            $ico_arr
                          </td>

                          <td class=\"character-col\">$name_arr</td>
                          ";

                        // ── COST 欄位（模糊統計不顯示）──
                        if ($combined == 3) {
                          $html .= "<td></td>";
                        } else {
                          $html .= "
                            <td class=\"" . ($punishment > 0 ? 'text-danger' : '') . "\">
                              $cost_sum
                            </td>
                          ";
                        }

                        // ── 勝率 / 評分 / 場次 ──
                        $html .= "
                          <td><div class='badge bg-$rate_color'>$win_rate%</div></td>

                          <td data-order=\"$games_factor\">
                            <div class='badge bg-$rating_color'>$display_factor</div>
                          </td>

                          <td>$win</td>
                          <td>$lose</td>
                          <td>$total_games</td>
                        ";
                      }

                      $final_rows[] = [
                        // 原本的
                        'char_ids' => $char_ids,
                        'html'     => $html,
                        'sort_key' => ($combined == 2) ? ($bp_after ?? 0) : ($games_factor ?? 0),

                        // mobile action
                        'e1' => $e1,
                        'e2' => $e2,
                        'e3' => $e3,
                        'fight_url' => $fightUrl,

                        // 🔥【關鍵補齊】
                        'player_name'  => $player_name,
                        'bp'           => $bp_after ?? 0,
                        'show_private' => $show_private1 ?? false,

                        // mobile 顯示
                        'names'    => trim($name_arr),
                        'cost'     => $cost_sum ?? 0,
                        'win_rate' => $win_rate ?? 0,
                        'score'    => $display_factor ?? 0,

                        'win'   => $win ?? 0,
                        'lose'  => $lose ?? 0,
                        'total' => ($win ?? 0) + ($lose ?? 0),
                      ];
                    }

                    unset($ico_arr, $name_arr, $cost_arr, $cost_sum);
                  }



                  // ============================
                  // 依 sort_key 排序    usort會 直接改變原陣列順序（in-place）  $a <=> $b → 小到大（ASC）$b <=> $a → 大到小（DESC）
                  // ============================
                  usort($final_rows, function ($a, $b) {
                    return $b['sort_key'] <=> $a['sort_key'];
                  });

                  // ============================
                  // 輸出表格列
                  // ============================
                  $i = 1;

                  foreach ($final_rows as $row) {

                    echo "<tr>";
                    echo "<td>{$i}</td>";

                    // ── 將 [id][id][id] 轉為角色圖片 ──
                    $img_html = buildDeckImageHtml(
                      $row['char_ids'],
                      $character_info
                    );

                    // ── 將佔位符替換為圖片 HTML ──
                    $html = str_replace(
                      $row['char_ids'],
                      $img_html,
                      $row['html']
                    );

                    echo $html;
                    echo "</tr>";

                    $i++;
                  }

                  ?>
                </tbody>
              </table>
              <!-- 📱 Mobile 3V3 Cards -->
              <div class="team-mobile-list">

                <?php foreach ($final_rows as $index => $row): ?>

                  <?php
                  // 是否為玩家搜尋模式
                  $is_player_search = ($combined == 2);

                  // 權限判斷（只在 combined == 2 時有意義）
                  $can_show_deck = false;
                  if ($is_player_search) {
                    $can_show_deck = (
                      ($row['show_private'] ?? false) ||
                      $permission >= 2 ||
                      $player_name === $username
                    );
                  }

                  // 卡組 HTML（只有需要時才產生）
                  $deck_html = '';
                  if (!$is_player_search || $can_show_deck) {
                    $deck_html = buildDeckImageHtml(
                      $row['char_ids'],
                      $character_info
                    );
                  }
                  ?>

                  <div class="team-mobile-card">

                    <?php if (!$is_player_search || $can_show_deck): ?>
                      <!-- 最近使用（卡組） -->
                      <div class="team-mobile-deck">
                        <?= $deck_html ?>
                      </div>

                      <!-- 角色名稱 -->
                      <div class="team-mobile-names">
                        <?php
                        $names = $row['names'] ?? '';
                        $arr = array_filter(explode('<br>', $names));
                        echo implode(' | ', $arr);
                        ?>
                      </div>
                    <?php endif; ?>

                    <!-- 關鍵數值 -->
                    <div class="team-mobile-stats">
                      <?php if (!$is_player_search || $can_show_deck): ?>
                        <span>COST <b><?= $row['cost'] ?? '-' ?></b></span>
                      <?php endif; ?>
                      <span>勝率 <b><?= $row['win_rate'] ?? '-' ?>%</b></span>
                    </div>

                    <!-- 勝敗 / BP / 玩家 -->
                    <div class="team-mobile-result">
                      <?php if ($is_player_search): ?>
                        BP <?= htmlspecialchars($row['bp'] ?? '-', ENT_QUOTES) ?><br>
                        <?= htmlspecialchars($row['player_name'] ?? '', ENT_QUOTES) ?><br>
                      <?php endif; ?>
                      勝 <?= $row['win'] ?? 0 ?>
                      ／敗 <?= $row['lose'] ?? 0 ?>
                      ／共 <?= $row['total'] ?? 0 ?>
                    </div>

                    <!-- 行動 -->
                    <div class="team-mobile-action">

                      <?php if (!$is_player_search || $can_show_deck): ?>
                        <!-- 有權限：可查看卡組 -->
                        <a
  href="<?= htmlspecialchars($row['fight_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  class="btn btn-sm btn-primary">
  🔍 查看卡組
</a>

                      <?php else: ?>
                        <a href="fight.php?player_name=<?= rawurlencode($row['player_name'] ?? '') ?>"
                          class="btn btn-sm btn-outline-secondary">
                          🔍 近期對戰
                        </a>
                      <?php endif; ?>

                    </div>

                  </div>

                <?php endforeach; ?>

              </div>


            </div>

          </div>
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



<!-- page script -->

<!-- 引入 bootstrap-slider.js -->
<script src="../assets/plugins/bootstrap-slider/bootstrap-slider.js"></script>
<!-- DateRange Picker -->
<link rel="stylesheet" href="../assets/bower_components/bootstrap-daterangepicker/daterangepicker.css">
<script src="../assets/bower_components/moment/min/moment.min.js"></script>
<script src="../assets/bower_components/bootstrap-daterangepicker/daterangepicker.js"></script>

<!-- Slider -->
<link rel="stylesheet" href="../assets/plugins/bootstrap-slider/slider.css">
<script src="../assets/plugins/bootstrap-slider/bootstrap-slider.js"></script>

<!-- DataTables -->
<link rel="stylesheet" href="../assets/bower_components/datatables.net-bs/css/dataTables.bootstrap.min.css">
<script src="../assets/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="../assets/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>

<script>
  $(document).ready(function() {
    const playerSearch = getQueryParam("character");

    const table2 = $('#example2').DataTable({
      paging: true,
      pageLength: 10,
      searching: true,
      ordering: true,
      info: true,

      scrollX: true, // ⭐ 開啟
      scrollCollapse: true,
      autoWidth: false,
      deferRender: true,

      order: [
        [<?= (int)$orderation ?>, 'desc']
      ],

      language: {
        paginate: {
          previous: '‹',
          next: '›'
        }
      },
      drawCallback: function(settings) {
        var api = this.api();


        var searchText = api.search();
        if (searchText) {
          [3, 10].forEach(function(colIndex) {
            $('#example2 tbody td:nth-child(' + colIndex + ')').unhighlight();
            $('#example2 tbody td:nth-child(' + colIndex + ')').highlight(searchText);
          });
        }
      }
    });
    // ⭐ 把 scrollHead / scrollBody 的 clone 一起解除
    $('#example2, .dataTables_scrollHead table, .dataTables_scrollBody table')
      .removeClass('datatable-init-hide');

    if (playerSearch) {
      table2.search(playerSearch).draw();
    }

    $('.dataTables_filter input').css('width', '200px');
  });


  // 🔥 強化版 highlight
  jQuery.fn.highlight = function(str, className) {
    if (str) {
      var regex = new RegExp(str.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1"), 'gi');
      this.each(function() {
        $(this).contents().each(function() {
          if (this.nodeType === 3 && this.nodeValue.trim() !== "") {
            var matches = this.nodeValue.match(regex);
            if (matches) {
              var new_html = this.nodeValue.replace(regex, function(matched) {
                return '<span class="' + (className || "highlight") + '">' + matched + '</span>';
              });
              var new_element = document.createElement('span');
              new_element.innerHTML = new_html;
              this.parentNode.replaceChild(new_element, this);
            }
          }
        });
      });
    }
    return this;
  };

  jQuery.fn.unhighlight = function() {
    this.find('span.highlight').each(function() {
      this.parentNode.replaceChild(document.createTextNode(this.textContent), this);
    });
    return this;
  };

  // 🔥 取網址參數
  function getQueryParam(key) {
    const params = new URLSearchParams(window.location.search);
    return params.get(key);
  }
</script>


<!-- daterangepicker -->
<script>
  $(function() {

    /* =========================================================
     * 1️⃣ 以 PHP session 為準（唯一真實來源）
     * ========================================================= */
    let sessionRange = "<?= $current_daterange ?>".trim();

    // 防呆：如果 PHP 沒給，才用今天 ~ 今天
    if (!sessionRange || !sessionRange.includes(' - ')) {
      sessionRange = moment().format('YYYY-MM-DD') + ' - ' + moment().format('YYYY-MM-DD');
    }

    let parts = sessionRange.split(' - ');
    let start = moment(parts[0], 'YYYY-MM-DD');
    let end = moment(parts[1], 'YYYY-MM-DD');

    /* =========================================================
     * 2️⃣ 初始化 daterangepicker
     * ========================================================= */
    $('#daterange-btn').daterangepicker({
      startDate: start,
      endDate: end,
      locale: {
        format: 'YYYY-MM-DD',
        applyLabel: '確定',
        cancelLabel: '取消',
        customRangeLabel: '自訂範圍'
      },
      ranges: {
        '今天': [moment(), moment()],
        '昨天': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
        '最近 3 天': [moment().subtract(2, 'days'), moment()],
        '最近 7 天': [moment().subtract(6, 'days'), moment()],
        '最近 14 天': [moment().subtract(13, 'days'), moment()],
        '最近 30 天': [moment().subtract(29, 'days'), moment()],
        '本月': [moment().startOf('month'), moment().endOf('month')],
        '上個月': [
          moment().subtract(1, 'month').startOf('month'),
          moment().subtract(1, 'month').endOf('month')
        ],
      }
    }, function(start, end) {

      let rangeText = start.format('YYYY-MM-DD') + ' - ' + end.format('YYYY-MM-DD');

      /* =====================================================
       * 3️⃣ 同步所有狀態（一次做完）
       * ===================================================== */
      $('#daterange').val(rangeText);

      $('#daterange-btn').html(
        '<i class="fa fa-calendar"></i> ' +
        start.format('YYYY-MM-DD') +
        ' 至 ' +
        end.format('YYYY-MM-DD') +
        ' <i class="fa fa-caret-down"></i>'
      );

      // 可留可不留（現在只是備份，不是來源）
      localStorage.setItem('daterange', rangeText);
    });

    /* =========================================================
     * 4️⃣ 初始化畫面（🔥 關鍵：第一次就回填）
     * ========================================================= */
    $('#daterange').val(
      start.format('YYYY-MM-DD') + ' - ' + end.format('YYYY-MM-DD')
    );

    $('#daterange-btn').html(
      '<i class="fa fa-calendar"></i> ' +
      start.format('YYYY-MM-DD') +
      ' 至 ' +
      end.format('YYYY-MM-DD') +
      ' <i class="fa fa-caret-down"></i>'
    );

  });
</script>


<!-- cost-slider -->
<script>
  $(function() {

    /* =========================
     * COST Slider
     * ========================= */
    var costMin = parseInt($('#costMin').val(), 10);
    var costMax = parseInt($('#costMax').val(), 10);

    $('#cost-slider')
      .slider({
        min: 0,
        max: 140,
        step: 1,
        value: [costMin, costMax],
        tooltip: 'hide'
      })
      .on('slide', function(ev) {
        $('#cost-display').text(ev.value[0] + ' - ' + ev.value[1]);
      })
      .on('slideStop', function(ev) {
        var min = ev.value[0];
        var max = ev.value[1];

        $('#costMin').val(min);
        $('#costMax').val(max);

        var params = new URLSearchParams(window.location.search);
        params.set('cost_min', min);
        params.set('cost_max', max);

        window.location.search = params.toString();
      });

    // 初始顯示
    $('#cost-display').text(costMin + ' - ' + costMax);

    /* =========================
     * BP Slider
     * ========================= */
    var bpMin = parseInt($('#bp_min').val(), 10);
    var bpMax = parseInt($('#bp_max').val(), 10);

    $('#bp-slider')
      .slider({
        min: 1000,
        max: 2000,
        step: 50,
        value: [bpMin, bpMax],
        tooltip: 'hide'
      })
      .on('slide', function(ev) {
        $('#bp-display').text(ev.value[0] + ' - ' + ev.value[1]);
      })
      .on('slideStop', function(ev) {
        var min = ev.value[0];
        var max = ev.value[1];

        $('#bp_min').val(min);
        $('#bp_max').val(max);

        var params = new URLSearchParams(window.location.search);
        params.set('bp_min', min);
        params.set('bp_max', max);

        // 同步 COST（避免被吃掉）
        params.set('cost_min', $('#costMin').val());
        params.set('cost_max', $('#costMax').val());

        window.location.search = params.toString();
      });

    // 初始顯示
    $('#bp-display').text(bpMin + ' - ' + bpMax);

  });
</script>


<!-- 進階篩選收合 -->
<script>
  function toggleFilter() {
    const body = document.getElementById("filter-body");
    const arrow = document.getElementById("filter-arrow");

    const isOpen = body.classList.contains("is-open");

    if (isOpen) {
      body.classList.remove("is-open");
      arrow.classList.remove("fa-chevron-up");
      arrow.classList.add("fa-chevron-down");
    } else {
      body.classList.add("is-open");
      arrow.classList.remove("fa-chevron-down");
      arrow.classList.add("fa-chevron-up");
    }
  }
</script>

<script>
  function toggleScoreHint() {
    const body = document.getElementById('scoreHintBody');
    const toggle = document.getElementById('scoreHintToggle');

    const isOpen = body.style.display === 'block';

    body.style.display = isOpen ? 'none' : 'block';
    toggle.textContent = isOpen ? '❓ 評分說明' : '❓ 收合說明';
  }
</script>



<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>