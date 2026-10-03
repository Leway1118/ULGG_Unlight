<?php
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定

$seoTitle = '角色排行 Character | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "ranking_char";
$pageTitleFull = '角色排行 Character | UL.GG 戰績網'; //桌機
$pageTitleText = '角色排行'; //手機

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
   1. Back To Top Button
========================================================= */
  #backToTop {
    position: fixed;
    bottom: 80px;
    right: 30px;
    width: 50px;
    height: 50px;
    background-color: #007bff;
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    display: none;
    justify-content: center;
    align-items: center;
    font-size: 18px;
    box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.2);
    transition: opacity 0.3s ease;
    z-index: 999;
  }

  #backToTop:hover {
    background-color: #0056b3;
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
  }

  .flip-container.flipped {
    overflow: visible !important;
    z-index: 9999 !important;
  } */

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

  .flip-container.flipped .back img {
    transform: scale(2.1);
  }

  /* Click indicator */
  .click-indicator {
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
  }

  .flip-container:hover .click-indicator {
    opacity: 1;
    transform: translateX(-50%) scale(1);
  }

  .click-indicator img {
    width: 255px !important;
    height: auto;
    display: block;
    transform: scale(0.4) !important;
  }

  /* =========================================================
   3. Image Hover / Zoom
========================================================= */
  /* .zoomout_rank:hover {
    transform: scale(2);
    transition: all 0.2s linear;
  } */

  .image-flex {
    display: flex;
    flex-direction: row;
    align-items: center;
    padding: 6px 0;
  }

  .image-flex img {
    height: auto;
    transition: transform 0.3s ease-in-out;
  }

  .image-flex img:first-child {
    margin-left: 0;
  }

  .image-flex img:hover {
    transform: scale(1.2);
    z-index: 10;
  }

  /* =========================================================
   4. Buttons / Status Pills
========================================================= */
  .chk-btn {
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
  }

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
    padding-top: 6px;
    padding-bottom: 6px;
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
  .cost-pill-range{
    padding-left:5px ;
    padding-right:5px ;
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

  /* =========================================================
   Stage Grid（與 COST Grid 統一風格）
========================================================= */
  .stage-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 8px;
  }

  .stage-pill {
    text-align: center;
    padding: 6px 8px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #ddd;
    font-size: 13px;
    cursor: pointer;
    user-select: none;
    transition: all .15s ease;
  }

  .stage-pill:hover {
    background: rgba(120, 120, 255, 0.35);
    color: #fff;
    border-color: rgba(120, 120, 255, 0.8);
  }

  /* 選中狀態（完全對齊 cost-picked） */
  .stage-picked {
    background: linear-gradient(135deg, #ffb347, #ffdd77);
    color: #222;
    font-weight: 700;
    border-color: rgba(255, 200, 120, 0.9);
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

  /* 只修 DataTables 自己產生的 row */
  .dataTables_wrapper>.row {
    margin-left: 0 !important;
    margin-right: 0 !important;
  }

  /* =========================
   表格內容「上下左右置中」
========================= */

  /* 所有表格儲存格 */
  #example2 td,
  #example2 th {
    vertical-align: middle !important;
    /* 上下置中 */
    text-align: center;
    /* 左右置中 */
  }

  /* =========================
   🖥 桌機（預設）
========================= */

  /* 圖片欄橫排 */
  .char-mobile-stack {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* 桌機不顯示圖片下方名稱 */
  .char-name-mobile {
    display: none;
  }

  /* 桌機顯示獨立名稱欄 */
  .char-name-desktop {
    display: table-cell;
  }

  /* =========================
   📱 手機模式
========================= */
  @media (max-width: 768px) {

    /* 🔥 隱藏「角色名稱（桌機欄）」 */
    /* #example2 th:nth-child(2),
  #example2 td:nth-child(2) {
    display: none !important;
  } */

    /* 🔥 若你已決定手機不顯示 COST / 次數，也可一併 */
    #example2 th:nth-child(1),
    #example2 td:nth-child(1),
    #example2 th:nth-child(3),
    #example2 td:nth-child(3),
    #example2 th:nth-child(4),
    #example2 td:nth-child(4) {
      display: none !important;
    }

    /* 圖片 + 名稱 改為直排 */
    .char-mobile-stack {
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }

    /* 顯示圖片下方名稱 */
    .char-name-mobile {
      display: block;
      font-size: 12px;
      line-height: 1.2;
      text-align: center;
      white-space: nowrap;
    }

    /* 圖片尺寸縮小 */
    .flip-container {
      width: 60px;
      height: 45px;
    }

    .flip-container img {
      width: 55px;
      height: auto;
    }
  }

  #backToTop {
    bottom: 90px;
    right: 13px;
  }

  @media (max-width: 768px) {
    .table-full {
      touch-action: pan-y;
      user-select: none;
    }
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
  $priorGames = 15;   // 假設先驗有 20 場平衡資料
  $priorRate  = 0.5;  // 預設勝率 50%

  // 原本勝率修正成平滑後勝率
  $adj_win_rate = ($win_rate * $total_games + $priorRate * $priorGames)
    / ($total_games + $priorGames);

  // -------------------------------
  // 3) 自動 logBase（依據「希望最大場次的加權」來定）
  // -------------------------------
  // 你原本用 d*4+9，我們保留邏輯基底，但用 min/max 限制不會亂跳
  $raw_log = $d * 15 + 30;
  $logBase = max($raw_log, 15); // 限制範圍 15~45

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
 * 接收 slider 傳來的 cost_min / cost_max（補齊）
 * ========================================================= */
/* =========================================================
 * Cost 篩選（🔥 定義優先順序）
 * ========================================================= */

// 1️⃣ range cost（優先）

// 2️⃣ slider cost（只有在「沒有 cost」時才用）
if (isset($_GET['cost_min'], $_GET['cost_max'])) {

  $_SESSION['cost_min'] = (int)$_GET['cost_min'];
  $_SESSION['cost_max'] = (int)$_GET['cost_max'];
} elseif (isset($_GET['cost']) && $_GET['cost'] !== '') {

  $raw = trim($_GET['cost']);

  if (preg_match('/^\d+\+$/', $raw)) {
    $min = (int) rtrim($raw, '+');
    $max = 140;
  } elseif (is_numeric($raw)) {
    $min = $max = (int)$raw;
  } else {
    $min = 0;
    $max = 140;
  }

  $_SESSION['cost_min'] = $min;
  $_SESSION['cost_max'] = $max;
}
$current_cost = $_GET['cost'] ?? '';

/* =========================================================
 * Stage（場景）篩選
 * ========================================================= */
if (isset($_GET['stage'])) {
  $_SESSION['stage'] = (int)$_GET['stage'];
}

// 回填用
$current_stage = (int)($_SESSION['stage'] ?? -1);

/* =========================================================
 * 接收 daterange（🔥 修正重點）
 * ========================================================= */
if (isset($_GET['daterange'])) {
  $current_daterange = $_GET['daterange'];
  $_SESSION['daterange'] = $current_daterange;
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
  ]);

  header("Location: ranking_char.php");
  exit;
}


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
$search_team = 0;
$twovtwo = 0;
$isSearchTeam = 0;
if (isset($_POST['search_team'])) {

  $ids = [
    (int)$_POST['id1'],
    (int)$_POST['id2'],
    (int)$_POST['id3'],
  ];

  /* sort($ids); */

  if ($ids[0] === 0) {
    $twovtwo = 1;
    [$id1, $id2] = [$ids[1], $ids[2]];
    $id3 = 0;
  } else {
    [$id1, $id2, $id3] = $ids;
  }

  $search_team = 1;
  $isSearchTeam = 1;
}

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

/* =========================================================
 * 場景選擇
 * ========================================================= */

$stages = [
  -1    => '全部',
  0    => '雷城',
  1    => '誘森',
  2    => '垃圾',
  3    => '冰封',
  4    => '人魂',
  5    => '盡村',
  6    => '風暴',
  7    => '峰亥盧',
  8    => '魔都',
  9    => '狂山',
  10   => '魔女山谷',
  11   => '隨機',
  12   => '烏波斯黑湖',
];
// 1. 讀 GET（預設 -1 代表「全部場景」）
$selectedStage = isset($_GET['stage'])
  ? (int)$_GET['stage']
  : -1;
// 如果使用者選了非「全部」（index>0），就產生 AND 子句
$stage_sql = '';
if ($selectedStage >= 0) {
  // intval 防止 SQL Injection
  //$selectedStageCode = $selectedStage - 1;
  $stage_sql = ' AND `stage` = ' . intval($selectedStage);
  // 3. 確保最少為 1 天
  $d = max(1, $d / 12);
}
/* =========================================================
 * SQL 用參數預設值（避免 Undefined & SQL 爆炸）
 * ========================================================= */
$count_num = isset($count_num) ? (int)$count_num : 1;      // 最少出現次數
$topLimit  = isset($topLimit)  ? (int)$topLimit  : 200;     // 顯示筆數上限

?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-8"
          style="float:none; margin:0 auto;padding:0 5px;">
          <!-- ===========================
            🔵 進階選取區（可收合）
          ============================ -->
          <div class="filter-panel">

            <div class="filter-header" onclick="toggleFilter()">
              <span>🔍 進階篩選條件</span>
              <i id="filter-arrow" class="fa fa-chevron-down"></i>
            </div>

            <div id="filter-body" class="filter-body">

              <form method="GET" action="ranking_char.php">
                <div style="margin: 0px -5px;">

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
                    <!-- 場景選擇 -->
                    <div class="form-group">
                      <label class="filter-label">場景選擇：</label>

                      <div class="stage-grid">
                        <?php foreach ($stages as $i => $name): ?>
                          <label
                            class="stage-pill <?= $i === $current_stage ? 'stage-picked' : '' ?>"
                            data-stage="<?= $i ?>">
                            <?= htmlspecialchars($name, ENT_QUOTES) ?>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>



                  </div>

                  <!-- 右側 -->
                  <div class="col-md-6">
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
                    <!-- COST選取 -->
                    <div class="filter-block">
                      <label>
                        隊伍 COST 區間
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
                    <!-- 快速 COST 區間-->
                    <div class="filter-block">
                      <label>快速 COST 區間</label>
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

                          echo '<a class="cost-pill cost-pill-range'
                            . ($isActive ? 'cost-picked' : '')
                            . '" href="?' . $query . '">'
                            . $label
                            . '</a>';
                        }
                        ?>

                      </div>
                    </div>
                    <form method="GET" action="calculator.php">
                      <!-- 快速 COST -->
                      <div class="filter-block">
                        <div class="quick-cost-grid">
                          <?php
                          if (isset($_GET['cost']) && $_GET['cost'] !== '') {
                            $raw = trim($_GET['cost']);
                            // 🔥 同步 current_cost（給 UI 用）
                            $_SESSION['current_cost'] = $raw;
                            $current_cost = $raw;
                          }
                          $current_cost = $_SESSION['current_cost'] ?? '';
                          for ($i = 51; $i <= 80; $i++) {
                            $isActive = ((string)$i === (string)$current_cost);
                            echo '<a class="cost-pill ' . ($isActive ? 'cost-picked' : '') . '" href="?cost=' . $i . '">' . $i . '</a>';
                          }
                          // 90+
                          $isPlusActive = ($current_cost === '90+');
                          echo '<a class="cost-pill cost-plus ' . ($isPlusActive ? 'cost-picked' : '') . '" href="?cost=90%2B">90+</a>';
                          ?>
                        </div>
                      </div>

                    </form>

                    <input type="hidden" name="bp_min" id="bp_min" value="<?php echo $bp_min; ?>">
                    <input type="hidden" name="bp_max" id="bp_max" value="<?php echo $bp_max; ?>">
                    <input type="hidden" name="cost_min" id="costMin" value="<?php echo $cost_min; ?>">
                    <input type="hidden" name="cost_max" id="costMax" value="<?php echo $cost_max; ?>">




                  </div>

                </div>
              </form>

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
                    <th class="col-rank hide-mobile">#</th>
                    <th class="col-char">角色</th>
                    <th class="col-name">角色名稱</th>
                    <th class="col-cost hide-mobile">COST</th>
                    <th class="col-count">用次</th>
                    <th class="col-rate">勝率</th>
                    <th class="col-score">評分</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $i = 1;

                  if (strtotime($start_date) && strtotime($end_date)) {
                    $sql = "SELECT 
                    char_usage.char_id AS id,
                    b.ico AS ico,
                    b.name AS name,
                    b.level AS level,
                    b.cost AS cost,
                    SUM(char_usage.count_num) AS count_ttl,
                    SUM(char_usage.win) AS win,
                    SUM(char_usage.lose) AS lose,
                    ROUND((SUM(char_usage.win) / NULLIF(SUM(char_usage.win + char_usage.lose), 0)) * 100, 1) AS rate
                    FROM (
                    -- 敵方角色統計
                    SELECT e1 AS char_id, COUNT(*) AS count_num, SUM(lose) AS win, SUM(win) AS lose, MAX(update_time) AS update_time
                    FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                    AND (bp_p1 BETWEEN '$bp_min' AND '$bp_max')
                    AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql $server_sql
                    GROUP BY e1
                    UNION ALL
                    SELECT e2 AS char_id, COUNT(*) AS count_num, SUM(lose) AS win, SUM(win) AS lose, MAX(update_time) AS update_time
                    FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date')
                    AND (bp_p1 BETWEEN '$bp_min' AND '$bp_max')
                    AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql $server_sql
                    GROUP BY e2
                    UNION ALL
                    SELECT e3 AS char_id, COUNT(*) AS count_num, SUM(lose) AS win, SUM(win) AS lose, MAX(update_time) AS update_time
                    FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                    AND (bp_p1 BETWEEN '$bp_min' AND '$bp_max')
                    AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql $server_sql
                    GROUP BY e3
                    UNION ALL

                    -- 我方角色統計
                    SELECT u1 AS char_id, COUNT(*) AS count_num, SUM(win) AS win, SUM(lose) AS lose, MAX(update_time) AS update_time
                    FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                    AND (bp_p2 BETWEEN '$bp_min' AND '$bp_max')
                    AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql $server_sql
                    GROUP BY u1
                    UNION ALL
                    SELECT u2 AS char_id, COUNT(*) AS count_num, SUM(win) AS win, SUM(lose) AS lose, MAX(update_time) AS update_time
                    FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                    AND (bp_p2 BETWEEN '$bp_min' AND '$bp_max')
                    AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql $server_sql
                    GROUP BY u2
                    UNION ALL
                    SELECT u3 AS char_id, COUNT(*) AS count_num, SUM(win) AS win, SUM(lose) AS lose, MAX(update_time) AS update_time
                    FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                    AND (bp_p2 BETWEEN '$bp_min' AND '$bp_max')
                    AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql $server_sql
                    GROUP BY u3
                    ) char_usage
                    LEFT JOIN unlight b ON char_usage.char_id = b.id
                    WHERE char_usage.char_id IS NOT NULL
                    GROUP BY char_usage.char_id 
                    HAVING SUM(char_usage.count_num) > $count_num   -- <<< 這裡               
                    ORDER BY count_ttl DESC, rate DESC LIMIT $topLimit;
                    ";
                    //print "sql=$sql";
                    //$sql = "SELECT * FROM best_combined_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') and (total_games > $total_games) order by update_time desc;;";
                    //$sql = "SELECT * FROM ranking;";
                    //$sql = "SELECT * FROM view_char_usage_unlight order by count_ttl desc;";

                    $final_rows = [];
                    $arr = $db->query("$sql");
                    while ($row = $arr->fetch()) {

                      $char_id     = (int)($row['id'] ?? 0);
                      $ico         = $row['ico']   ?? '';
                      $name        = $row['name']  ?? '';
                      $level       = $row['level'] ?? '';
                      $cost        = (int)($row['cost'] ?? 0);
                      $total_games = (int)($row['count_ttl'] ?? 0);

                      $win   = (int)($row['win'] ?? 0);
                      $lose  = (int)($row['lose'] ?? 0);
                      $rate  = (float)($row['rate'] ?? 0);

                      $win_rate = $rate / 100;
                      //$d = 0;
                      $games_factor = calcPerformanceByTotalGames($total_games, $win_rate, $d);
                      $rate_color = progress_color($win_rate * 100);
                      $rating_color = progress_color($games_factor * 5);
                      $ico_arr = ''; // ⭐ 一定要先初始化（關鍵）

                      $sql2 = "SELECT id,ico, cost, name, level FROM unlight WHERE id = ?";
                      $stmt2 = $db->prepare($sql2);
                      $stmt2->execute([$char_id]);

                      while ($row2 = $stmt2->fetch(PDO::FETCH_ASSOC)) {

                        $ico_front = IMG_BASE . ($row2['ico'] ?? '');
                        $id  = $row2['id']  ?? '';
                        $name  = $row2['name']  ?? '';
                        $level = $row2['level'] ?? '';

                        $ico_arr .= '
                        <div class="cards">
                          <div class="flip-container">
                            <div class="front">
                              <img class="zoomout_rank" src="' . htmlspecialchars($ico_front) . '" loading="lazy">
                            </div>
                          </div>
                        </div>';
                      }
                      $name = rtrim($name, "'");   // 移除尾巴 '
                      $name = trim($name);         // 去除空白
                      $level_name = $level . $name;

                      //$cost = $level . $name;
                      $search_link = "ranking_team.php?character=" . urlencode($level_name) . "&char_id=$id"; // 建立搜尋連結
                      // 判斷 icon
                      if (abs($games_factor - 13.0) < 0.01) {
                        $mvp_icon = ' 👑MVP';
                      } elseif (abs($games_factor - 3.0) < 0.01) {
                        // 用 Emoji
                        $mvp_icon = ' 🐶';
                        // 或用 Font Awesome
                        // $mvp_icon = ' <i class="fas fa-dog"></i>';
                      } else {
                        $mvp_icon = '';
                      }

                      $display_factor = number_format($games_factor, 1);
                      $display_factor = number_format($games_factor, 1);
                      $analysis_link = sprintf(
                        'analysis_character_card.php?char_id=%d&char=%s',
                        $char_id,
                        urlencode($level_name)
                      );
                      $html = "
                        <!-- ① 角色欄：圖片 +（手機用）名稱 -->
                        <td class=\"char-cell\">
                        <div class=\"char-mobile-stack\">

                          <!-- 角色 ICO 容器 -->
                          <div class=\"char-ico-wrap\">
                            
                            <!-- 🔍 角色分析浮動按鈕 -->
                            <a
                              href=\"$analysis_link\"
                              class=\"btn-check-float\"
                              data-bs-toggle=\"tooltip\"
                              title=\"角色分析\"
                            >
                              📊
                            </a>

                              <!-- 原本的角色 ICO -->
                              $ico_arr

                            </div>

                            <!-- 手機用角色名稱 -->
                            <div class=\"char-name-mobile\">
                              <a href=\"$search_link\" class=\"text-dark\">
                                $level_name
                              </a>
                            </div>

                          </div>
                        </td>


                        <!-- ② 角色名稱欄（桌機用，手機會隱藏） -->
                        <td class=\"char-name-desktop\">
                          <a href=\"$search_link\" class=\"text-dark\" style=\"text-decoration: underline;\">
                            $level_name
                          </a>
                        </td>

                        <!-- ③ COST -->
                        <td>$cost</td>

                        <!-- ④ 使用次數 -->
                        <td>$total_games</td>

                        <!-- ⑤ 勝率 -->
                        <td>
                          <div class=\"badge bg-$rate_color\">
                            " . number_format($win_rate * 100, 1) . "%</div>
                        </td>

                        <!-- ⑥ 評分 -->
                        <td data-order=\"$games_factor\">
                          <div class=\"badge bg-$rating_color\">$display_factor</div>$mvp_icon
                        </td>
                      ";




                      $ico_arr = '';
                      $final_rows[] = [
                        'html' => $html,
                        'sort_key' => $games_factor ?? 0
                      ];
                    }

                    // 🔥 按 sort_key 排序
                    usort($final_rows, function ($a, $b) {
                      return $b['sort_key'] <=> $a['sort_key'];
                    });

                    $i = 1;
                    foreach ($final_rows as $row) {
                      echo "<tr><td>{$i}</td>";
                      echo $row['html'];
                      echo "</tr>";

                      $i++;
                    }
                  }
                  ?>
                </tbody>
              </table>
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
  let table2; // ⭐ 全域
  $(document).ready(function() {
    const playerSearch = getQueryParam("character");

    table2 = $('#example2').DataTable({
      paging: true,
      pageLength: 10,
      searching: true,
      ordering: true,
      info: true,

      scrollCollapse: true,
      scrollX: false, // ⭐ 先關
      autoWidth: false, // ⭐ 讓 CSS 控制
      deferRender: true,

      order: [
        [6, "desc"] // 預設用第 7 欄（評分）由大到小排序
      ],
      columnDefs: [{
        targets: 6,
        orderDataType: "dom-data-order"
      }],
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
          [3, 5].forEach(function(colIndex) {
            $('#example2 tbody td:nth-child(' + colIndex + ')')
              .unhighlight()
              .highlight(searchText);
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
    // ⭐ DataTable ready 後，才啟用 swipe
    initTableSwipe(table2);
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

<!-- 左右滑動 -->
<script>
  function initTableSwipe(table) {
    if (window.innerWidth > 768) return;

    const target = document.querySelector('.table-full');
    if (!target) return;

    let startX = 0;
    let startY = 0;

    const SWIPE_THRESHOLD = 60;
    const VERTICAL_LIMIT = 80;

    target.addEventListener('touchstart', function(e) {
      if (e.touches.length !== 1) return;
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
    }, {
      passive: true
    });

    target.addEventListener('touchend', function(e) {
      if (!startX || !startY) return;

      const diffX = e.changedTouches[0].clientX - startX;
      const diffY = e.changedTouches[0].clientY - startY;

      if (Math.abs(diffY) > VERTICAL_LIMIT) return;

      if (Math.abs(diffX) > SWIPE_THRESHOLD) {
        diffX < 0 ?
          table.page('next').draw('page') :
          table.page('previous').draw('page');

        if (navigator.vibrate) navigator.vibrate(12);
      }

      startX = startY = 0;
    });
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
  document.querySelectorAll('.stage-pill').forEach(el => {
    el.addEventListener('click', function() {
      const stage = this.dataset.stage;

      const params = new URLSearchParams(window.location.search);
      params.set('stage', stage);

      // 保留其他條件（很重要）
      if (document.getElementById('costMin')) {
        params.set('cost_min', document.getElementById('costMin').value);
        params.set('cost_max', document.getElementById('costMax').value);
      }

      if (document.getElementById('bp_min')) {
        params.set('bp_min', document.getElementById('bp_min').value);
        params.set('bp_max', document.getElementById('bp_max').value);
      }

      window.location.search = params.toString();
    });
  });
</script>



<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>