<?php
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定

$seoTitle = '計算機 Calculator | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "calculator";
$pageTitleFull = '計算機 Calculator | UL.GG 戰績網'; //桌機
$pageTitleText = '計算機 Calculator'; //手機

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* =========================================================
   1. Global / Base
========================================================= */
  .large-bold {
    font-size: 32px;
    font-weight: bold;
  }

  td,
  th {
    text-align: center;
  }

  td label {
    display: inline-block;
    width: 33px;
    text-align: center;
    padding: 5px 0;
    transition: all 0.2s linear;
  }

  td label:hover {
    transform: scale(1.2);
  }

  /* =========================================================
   2. Radio / Cost Label
========================================================= */
  .abgne-menu input[type="radio"]+label.R_card {
    background-color: dimgray;
    color: white;
  }

  .abgne-menu input[type="radio"]:checked+label.R_card {
    background-color: pink;
    color: black;
  }

  .zoomout_cost {
    transition: all 0.2s linear;
  }

  .zoomout_cost:hover {
    transform: scale(1.6);
  }

  /* =========================================================
   3. Flip Card (Front / Back)
========================================================= */
  .flip-container {
    perspective: 1000px;
    width: 140px;
    height: 200px;
  }

  .flipper {
    position: relative;
    width: 100%;
    height: 100%;
    transform-style: preserve-3d;
    transition: transform 0.6s;
  }

  .flip-container.flipped .flipper {
    transform: rotateY(180deg);
  }

  .front,
  .back {
    position: absolute;
    width: 100%;
    height: 100%;
    backface-visibility: hidden;
  }

  .back {
    transform: rotateY(180deg);
  }

  /* Click Indicator */
  .click-indicator {
    position: absolute;
    bottom: 70px;
    left: 55%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 5px;
    opacity: 0;
    transition: opacity 0.3s ease-in-out;
  }

  .flip-container:hover .click-indicator {
    opacity: 1;
  }

  .click-indicator img {
    width: 135px;
    height: 55px;
  }

  /* =========================================================
   4. Cards / Panels
========================================================= */
  .cost-panel,
  .cost-table-card {
    background: linear-gradient(180deg, #14141c, #0b0c10);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    box-shadow: 0 0 20px rgba(0, 0, 0, 0.6);
    margin-bottom: 20px;
    padding: 10px;
  }

  .cost-panel .card-header,
  .cost-table-card .card-header {
    font-weight: 600;
    color: #fff;
    background: linear-gradient(90deg, #1f2937, #111827);
    border-bottom: 1px solid rgba(120, 120, 255, 0.3);
  }

  /* =========================================================
   5. Team Slot
========================================================= */
  .team-slot-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
  }

  .team-slot {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 4px;
    border: 1px dashed rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.35);
  }

  .slot-title {
    font-weight: 700;
    color: #9fa8da;
  }

  .slot-image {
    width: 100%;
    display: flex;
    justify-content: center;
  }

  .slot-image img {
    height: 40px;
    max-width: 100px;
  }

  .slot-empty {
    height: 160px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #aaa;
    border: 1px dashed rgba(255, 255, 255, 0.25);
    border-radius: 6px;
  }

  .slot-name {
    font-weight: 600;
    color: #fff;
  }

  .slot-ref {
    font-size: 13px;
    color: #ccc;
  }

  .slot-cost {
    font-size: 14px;
    font-weight: 700;
    color: #ffdd77;
  }

  .slot-actions {
    margin-top: 6px;
  }

  /* =========================================================
   6. Total Cost / Actions
========================================================= */
  .total-cost {
    text-align: center;
    font-size: 20px;
    font-weight: bold;
  }

  .cost-value {
    color: #ffdd77;
  }

  .panel-actions {
    display: flex;
    justify-content: space-between;
    gap: 6px;
  }

  /* =========================================================
   7. Filter Panel
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

  #filter-body {
    overflow: hidden;
    max-height: 0;
    opacity: 0;
    transition: max-height 0.3s ease, opacity 0.5s ease;
  }

  #filter-body.is-open {
    max-height: 1200px;
    opacity: 1;
  }

  /* =========================================================
   8. Filter Block / Inputs
========================================================= */
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

  /* =========================================================
   9. Quick Cost Grid
========================================================= */
  .quick-cost-grid {
    display: grid;
    grid-template-columns: repeat(10, 1fr);
    gap: 8px;
  }

  .cost-pill {
    text-align: center;
    padding: 6px 0;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #ddd;
    font-size: 13px;
    text-decoration: none;
    transition: all 0.15s ease;
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
   Custom Radio (UNLIGHT Style)
========================================================= */

  /* 隱藏原生 radio */
  .cost-radio {
    display: none;
  }

  /* 基礎 label 外觀 */
  .cost-radio+label {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 30px;
    /* 原本 34 → 縮 */
    height: 26px;
    padding: 0 4px;
    font-size: 12px;
    /* 稍微小一點 */
    border-radius: 5px;

    cursor: pointer;
    background: linear-gradient(180deg, #1e1e2a, #12121a);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #ccc;
    font-weight: 600;

    box-shadow:
      inset 0 0 0 rgba(0, 0, 0, 0),
      0 0 0 rgba(0, 0, 0, 0);

    transition: all 0.18s ease;
  }

  /* Hover 效果 */
  .cost-radio+label:hover {
    transform: scale(1.15);
    background: linear-gradient(180deg, #2a2a3a, #181820);
    color: #fff;
  }

  /* 選取狀態（一般卡） */
  .cost-radio:checked+label {
    background: linear-gradient(135deg, #ffb347, #ffdd77);
    color: #222;
    border-color: #ffdd77;

    box-shadow:
      0 0 6px rgba(255, 221, 119, 0.8),
      0 0 12px rgba(255, 221, 119, 0.4);
  }

  /* R 卡樣式（未選） */
  .cost-radio+label.R_card {
    background: linear-gradient(180deg, #2a1e2a, #1a121a);
    border-color: rgba(255, 160, 200, 0.4);
    color: #f0c0d8;
  }

  /* R 卡選取 */
  .cost-radio:checked+label.R_card {
    background: linear-gradient(135deg, #ff8fb8, #ffc0d8);
    color: #2a0f1a;
    border-color: #ff8fb8;

    box-shadow:
      0 0 6px rgba(255, 143, 184, 0.8),
      0 0 12px rgba(255, 143, 184, 0.4);
  }

  /* 
  #example1 {
    width: 100% !important;
  } */

  /* #example1 th:nth-child(2) {
    padding: 3px 16px;
  } */

  .table>thead:first-child>tr:first-child>th {
    padding: 8px 4px;
  }

  #example1 td:nth-child(n+3) {
    padding: 3px 4px;
  }

  /* #example1 th:nth-child(n+3), */
  #example1 td:nth-child(n+3) {
    padding: 3px 8px;
  }

  #example1 th,
  #example1 td {
    vertical-align: middle;
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
    height: 50px;
    /* line-height: 38px; */
    padding: 0 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    white-space: nowrap;
  }

  /* =========================================================
   Cost Table Header Layout
========================================================= */

  .cost-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
  }

  /* 左側：角色按鈕 */
  .cost-header .header-left {
    display: flex;
    gap: 8px;
  }

  /* 右側：設定區 */
  .cost-header .header-right {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
  }

  /* 欄位群組 */
  .field-group {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  /* Label */
  .field-label {
    font-size: 13px;
    color: #9fa8da;
    white-space: nowrap;
  }

  /* 分隔符 */
  .field-sep {
    color: #aaa;
    font-weight: 600;
  }

  /* 輸入框縮小 */
  .cost-header .form-control.input-sm {
    width: 70px;
    height: 30px;
    padding: 4px 6px;
    font-size: 13px;
    background: #1c1c25;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #eee;
  }

  /* 操作按鈕 */
  .action-group {
    display: flex;
    gap: 6px;
  }

  /* ===============================
 * UL.GG Character Analysis Button
 * =============================== */

  .btn-char-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    font-size: 13px;
    color: #e6e9ef;
    background: linear-gradient(180deg, #1e2332, #181c28);
    border: 1px solid #2a2f42;
    border-radius: 6px;
    text-decoration: none;
    transition: all .15s ease;
    white-space: nowrap;
  }

  .btn-char-link:hover {
    color: #ffffff;
    background: linear-gradient(180deg, #252b3d, #1d2232);
    border-color: #3a4160;
    box-shadow: 0 2px 6px rgba(0, 0, 0, .45);
  }

  /* 「分析」小標 */
  .btn-char-sub {
    font-size: 11px;
    color: #8fa3ff;
    opacity: .85;
  }

  /* 行動版稍微放大點擊區 */
  @media (max-width: 768px) {
    .btn-char-link {
      padding: 6px 12px;
      font-size: 14px;
    }
  }
</style>

<?php

if (isset($_GET['cost'])) {
  $raw = $_GET['cost'];            // e.g. "100+" 或 "80"
  $current_cost = $raw;
  if (substr($raw, -1) === '+') {
    // 「100+」的情況
    $cost_limit = (int) rtrim($raw, '+'); // 100
    //$cost_max = 140;                    // 你要的上限
    //print "cost_max=$cost_max";
  } else {
    // 一般數字
    $cost_limit = (int) $raw;      // 例如 80
  }
  // 存到 Session
  /* $cost_min= $min;
    $cost_max= $max; */
  $_SESSION['cost_limit'] = $cost_limit;
}

// 在檔案最前面，可先將可能用到的 POST 資料初始化，避免 Notice
if (isset($_POST['id1']) && isset($_POST['cost'])) {
  $id1 = floor($_POST['cost'] / 100);
  $_SESSION["id1"] = $id1;
  $_SESSION["id1_char"] = floor(($id1 - 1) / 10);
  $_SESSION["id1_cost"] = $_POST['cost'] % 100;
} elseif (isset($_POST['id2']) && isset($_POST['cost'])) {
  $id2 = floor($_POST['cost'] / 100);
  $_SESSION["id2"] = $id2;
  $_SESSION["id2_char"] = floor(($id2 - 1) / 10);
  $_SESSION["id2_cost"] = $_POST['cost'] % 100;
} elseif (isset($_POST['id3']) && isset($_POST['cost'])) {
  $id3 = floor($_POST['cost'] / 100);
  $_SESSION["id3"] = $id3;
  $_SESSION["id3_char"] = floor(($id3 - 1) / 10);
  $_SESSION["id3_cost"] = $_POST['cost'] % 100;
} elseif (isset($_GET['cost_limit'])) {
  // PHP 8+: 算術運算前先把 GET 值正規化成整數。
  // 可接受 "90+" 這類輸入，避免之後出現 "string - int" Fatal error。
  $cost_limit = (int) rtrim(trim((string)$_GET['cost_limit']), '+');
  $_SESSION["cost_limit"] = $cost_limit;

  if ($cost_limit > 90) {
    $current_cost = '90+';
  } else {
    $current_cost = (string)$cost_limit;
  }
  $_SESSION["current_cost"] = $current_cost;
} elseif (isset($_POST['id1_clear'])) {
  $_SESSION["id1"] = '';
  $_SESSION["id1_cost"] = '';
} elseif (isset($_POST['id2_clear'])) {
  $_SESSION["id2"] = '';
  $_SESSION["id2_cost"] = '';
} elseif (isset($_POST['id3_clear'])) {
  $_SESSION["id3"] = '';
  $_SESSION["id3_cost"] = '';
} elseif (isset($_POST['id_all_clear'])) {
  $_SESSION["id1"] = '';
  $_SESSION["id1_cost"] = '';
  $_SESSION["id2"] = '';
  $_SESSION["id2_cost"] = '';
  $_SESSION["id3"] = '';
  $_SESSION["id3_cost"] = '';
}


$id1 = (int)($_SESSION["id1"] ?? 0);
$id2 = (int)($_SESSION["id2"] ?? 0);
$id3 = (int)($_SESSION["id3"] ?? 0);
$id1_cost = (int)($_SESSION["id1_cost"] ?? 0);
$id2_cost = (int)($_SESSION["id2_cost"] ?? 0);
$id3_cost = (int)($_SESSION["id3_cost"] ?? 0);

// 取得角色 COST
$char_costs = [
  (int)($_SESSION["id1_cost"] ?? 0),
  (int)($_SESSION["id2_cost"] ?? 0),
  (int)($_SESSION["id3_cost"] ?? 0)
];

$ttl_cost = array_sum($char_costs);
// Session 可能殘留舊版字串值（例如 "90+"），使用前強制轉成數字。
$cost_limit = (int) rtrim(trim((string)($_SESSION["cost_limit"] ?? 0)), '+');
$_SESSION["cost_limit"] = $cost_limit;

// 設定最小 COST 動態計算
$min = floor(($cost_limit - 6) / 3);
$max = $min + 6;
if ($id1_cost > 0 || $id2_cost > 0 || $id3_cost > 0) {
  $min = max($id1_cost, $id2_cost, $id3_cost) - 6;
}

/* print "max = $max<br>";
print "min = $min<br>"; */
// 設定最大 COST 限制
//$char_cost_max = [$max, $max, $max];

$id1_cost_max = $max;
$id2_cost_max = $max;
$id3_cost_max = $max;
$id1_cost_min = $min;
$id2_cost_min = $min;
$id3_cost_min = $min;
$cost_limit_count = $cost_limit;
// 計算剩餘可用 COST
if ($id1_cost) {
  $x = floor(($cost_limit_count - $id1_cost - 6) / 2) + 6;
  $id2_cost_max1 = $x;
  $id2_cost_max2 = $cost_limit_count - $id1_cost - $min;
  $id2_cost_max = min($id2_cost_max1, $id2_cost_max2, $max);
  $id3_cost_max = $id2_cost_max;
  if ($id2_cost_max - $id3_cost_max > 6) {
    $id2_cost_max = $id3_cost_max + 6;
  }
  if ($id2_cost) {
    $id3_cost_max = min(($cost_limit_count - $id1_cost - $id2_cost), min($id1_cost, $id2_cost) + 6);
  }
  if ($id1_cost - $max > 0 && $id1_cost - $max < 3) { //壓CO
    $id2_cost_min = $id1_cost - 13;
    $id3_cost_min = $id1_cost - 13;
    $x = $cost_limit_count - $id1_cost - $id2_cost_min - $id3_cost_min - 10;
    $id2_cost_max = $id2_cost_min + $x;
    $id3_cost_max = $id3_cost_min + $x;
    /* echo "id2_cost_max=$id2_cost_max<br>";
    echo "id3_cost_max=$id3_cost_max<br>"; */
    if ($id2_cost) {
      $id3_cost_max = $cost_limit_count - $id1_cost - $id2_cost - 10;
    }
    if ($x < 0) {
      $id2_cost_max = 0;
      $id2_cost_min = 0;
      $id3_cost_max = 0;
      $id3_cost_min = 0;
    }
  } elseif ($id1_cost - $max >= 3) {
    $x = $cost_limit_count - $id1_cost - $id2_cost_min - $id3_cost_min - 20;
    $id2_cost_max = $id2_cost_min + $x;
    $id3_cost_max = $id3_cost_min + $x;
    if ($id2_cost_max < 0) {
      $id2_cost_max = 0;
      $id2_cost_min = 0;
      $id3_cost_max = 0;
      $id3_cost_min = 0;
    }
    if ($id2_cost) {
      $id3_cost_max = $cost_limit_count - $id1_cost - $id2_cost - 20;
    }
  }

  /* if ($id2_cost_max > $max) {
    $id2_cost_max = $max;
  } elseif ($id2_cost_max < $min) {
    $id2_cost_max = $min;
  } */
}

if ($id1_cost_max < $id1_cost_min) {
  $id1_cost_min = $id1_cost_max;
}
if ($id2_cost_max < $id2_cost_min) {
  $id2_cost_min = $id2_cost_max;
}
if ($id3_cost_max < $id3_cost_min) {
  $id3_cost_min = $id3_cost_max;
}

/* echo"id1_cost_max=$id1_cost_max<br>";
echo"id2_cost_max=$id2_cost_max<br>";
echo"id3_cost_max=$id3_cost_max<br>"; */

$id1_color = '';
$id2_color = '';
$id3_color = '';
if ($id1_cost > $id1_cost_max) {
  $id1_color = 'style="color:red"';
} elseif ($id1_cost < $id1_cost_min && $id1_cost != 0) {
  $id1_color = 'style="color:blue"';
}
if ($id2_cost > $id2_cost_max) {
  $id2_color = 'style="color:red"';
} elseif ($id2_cost < $id2_cost_min && $id2_cost != 0) {
  $id2_color = 'style="color:blue"';
}
if ($id3_cost > $id3_cost_max) {
  $id3_color = 'style="color:red"';
} elseif ($id3_cost < $id3_cost_min && $id3_cost != 0) {
  $id3_color = 'style="color:blue"';
}

$cost_punish = 0;
$dif_a = abs($id1_cost - $id2_cost);
$dif_b = abs($id1_cost - $id3_cost);
$dif_c = abs($id2_cost - $id3_cost);
if ($dif_a > 6) {
  $cost_punish += 5;
  if ($dif_a > 13) {
    $cost_punish += 5;
  }
}
if ($dif_b > 6) {
  $cost_punish += 5;
  if ($dif_b > 13) {
    $cost_punish += 5;
  }
}
if ($dif_c > 6) {
  $cost_punish += 5;
  if ($dif_c > 13) {
    $cost_punish += 5;
  }
}

$ttl_cost_punish = $ttl_cost + $cost_punish;


$punish_arr = '';

if ($id1_cost && $id2_cost && $id3_cost) {
  if ($cost_punish) {
    $punish_arr = "+ (<span style=\"color:red\">$cost_punish</span>) = $ttl_cost_punish";
  } else {
    $punish_arr = "+ ($cost_punish) = $ttl_cost_punish";
  }
  if ($ttl_cost_punish > $cost_limit) {
    $ttl_cost_color = 'orange';
  }
}


if (isset($_SESSION["cost_cal_min"])) {
  $cost_cal_min = (int)$_SESSION["cost_cal_min"];
} else {
  $cost_cal_min = 0;
}
if (isset($_SESSION["cost_cal_max"])) {
  $cost_cal_max = (int)$_SESSION["cost_cal_max"];
} else {
  $cost_cal_max = 100;
}
if (isset($_SESSION["save"])) {
  $save = (int)$_SESSION["save"];
} else {
  $_SESSION["save"] = 0;
  $save = 0;
}

if (isset($_POST["cost_cal_min"])) {
  $cost_cal_min = (int)$_POST["cost_cal_min"];
  $_SESSION["cost_cal_min"] = $cost_cal_min;
}

if (isset($_POST["ref1"])) {
  $cost_cal_max = (int)($_POST["cost_cal_max"] ?? 100);
  $_SESSION["cost_cal_max"] = $cost_cal_max;
} elseif (isset($_POST["ref2"])) {
  $cost_cal_max = (int)($_POST["cost_cal_max"] ?? 100);
  $_SESSION["cost_cal_max"] = $cost_cal_max;
} elseif (isset($_POST["ref3"])) {
  $cost_cal_max = (int)($_POST["cost_cal_max"] ?? 100);
  $_SESSION["cost_cal_max"] = $cost_cal_max;
} elseif (isset($_POST["cost_cal_max"])) {
  $cost_cal_max = (int)$_POST["cost_cal_max"];
  $_SESSION["cost_cal_max"] = $cost_cal_max;
}

if (isset($_POST["save"]) && (int)$_POST["save"] != (int)($_SESSION["save"] ?? 0)) {
  $save = (int)$_POST["save"];
  $_SESSION["save"] = $save;
  //print "save=$save";
}


$cost_cal_min -= $save;
if ($cost_cal_min < 0) {
  $cost_cal_min = 0;
}

if (isset($_POST["reset"])) {
  $cost_cal_max = 100;
  $_SESSION["cost_cal_max"] = $cost_cal_max;
  $cost_cal_min = 0;
  $_SESSION["cost_cal_min"] = $cost_cal_min;
}

?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <!-- LEFT：COST 組合區 -->
        <div class="col-lg-4 col-md-12" style="padding: 5px;">
          <div class="card cost-panel">
            <div class="card-header">
              🧮 COST 組合計算
            </div>

            <div class="card-body">

              <!-- 快速選擇 -->
              <!-- ===========================
                🔵 進階選取區（可收合）
              ============================ -->
              <form method="GET" action="calculator.php">
                <div class="filter-panel">

                  <div class="filter-header" onclick="toggleFilter()">
                    <label>COST選擇</label>
                    <i id="filter-arrow" class="fa fa-chevron-down"></i>
                  </div>

                  <div id="filter-body" class="filter-body">
                    <div class="row">
                      <!-- 右側 -->
                      <div class="col-md-12">
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
                      </div>
                    </div>

                  </div>

                </div>
                <div class="filter-block">
                  <label>COST 設定</label>
                  <div class="input-group input-group-lg">
                    <input type="text" class="form-control" name="cost_limit" value="<?php echo "$cost_limit"; ?>">
                    <span class="input-group-btn">
                      <input type="submit" class="btn btn-default btn-flat" value="Enter">
                    </span>
                  </div>
                </div>
              </form>



              <!-- 三角色卡槽 -->
              <div class="panel-section">
                <div class="section-title">目前選擇</div>

                <div class="team-slot-grid">

                  <!-- SLOT 1 -->
                  <div class="team-slot">
                    <div class="slot-title">No.1</div>

                    <div class="slot-image">
                      <?php
                      $sql = "SELECT * FROM unlight WHERE id = '$id1';";
                      $arr = $db->query($sql);
                      $row = $arr->fetch(PDO::FETCH_ASSOC);

                      $id1_name  = '';
                      $ico_front = '';
                      $ico_back  = '';

                      if ($row) {
                        $id1_name  = $row['name'] ?? '';

                        if (!empty($row['ico'])) {
                          $ico_front = IMG_BASE . $row['ico'];
                        }

                        if (!empty($row['ico_back'])) {
                          $ico_back = IMG_BASE . $row['ico_back'];
                        }
                      }

                      if ($ico_front) {
                        if ($ico_back) {
                          echo '
                          <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
                            <div class="flipper">
                              <div class="front">
                                <img src="' . $ico_front . '" loading="lazy" style="height:140px;">
                                <div class="click-indicator">
                                  <img src="/assets/img/ui/arrow_icon.png" alt="Flip card">
                                </div>
                              </div>
                              <div class="back">
                                <img src="' . $ico_back . '" loading="lazy" style="height:140px;">
                              </div>
                            </div>
                          </div>';
                        } else {
                          echo '<img src="' . $ico_front . '" loading="lazy" style="height:140px;">';
                        }
                      } else {
                        echo '<div class="slot-empty">Unknown</div>';
                      }

                      ?>
                    </div>

                    <div class="slot-name"><?php if ($id1_name) {
                                              echo $id1_name;
                                            } ?></div>

                    <!-- 參考值 -->
                    <div class="slot-ref">
                      <form action="#" method="POST">
                        <input type="hidden" name="cost_cal_min" value="<?php echo $id1_cost_min ?>">
                        <input type="hidden" name="cost_cal_max" value="<?php echo $id1_cost_max ?>">
                        <button type="submit" name="ref1" class="btn-filter" value="1">參考值<br><?php echo "$id1_cost_min ~ $id1_cost_max"; ?></button>
                      </form>
                    </div>

                    <!-- 實際 COST -->
                    <div class="slot-cost">
                      COST：<span class="cost-num"><?php echo "$id1_cost"; ?></span>
                    </div>

                    <div class="slot-actions">
                      <form action="#" method="POST">
                        <td>
                          <button type="submit" class="btn btn-xs btn-outline-secondary btn-warning" name="id1_clear" value="1">清除</button>
                        </td>
                      </form>
                    </div>
                  </div>

                  <!-- SLOT 2 -->
                  <div class="team-slot">
                    <div class="slot-title">No.2</div>
                    <div class="slot-image">
                      <?php
                      $sql = "SELECT * FROM unlight WHERE id = '$id2';";
                      $arr = $db->query($sql);
                      $row = $arr->fetch(PDO::FETCH_ASSOC);

                      $id2_name  = '';
                      $ico_front = '';
                      $ico_back  = '';

                      if ($row) {
                        $id2_name  = $row['name'] ?? '';

                        if (!empty($row['ico'])) {
                          $ico_front = IMG_BASE . $row['ico'];
                        }

                        if (!empty($row['ico_back'])) {
                          $ico_back = IMG_BASE . $row['ico_back'];
                        }
                      }

                      if ($ico_front) {
                        if ($ico_back) {
                          echo '
                          <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
                            <div class="flipper">
                              <div class="front">
                                <img src="' . $ico_front . '" loading="lazy" style="height:140px;">
                                <div class="click-indicator">
                                  <img src="/assets/img/ui/arrow_icon.png" alt="Flip card">
                                </div>
                              </div>
                              <div class="back">
                                <img src="' . $ico_back . '" loading="lazy" style="height:140px;">
                              </div>
                            </div>
                          </div>';
                        } else {
                          echo '<img src="' . $ico_front . '" loading="lazy" style="height:140px;">';
                        }
                      } else {
                        echo '<div class="slot-empty">Unknown</div>';
                      }

                      ?>
                    </div>
                    <div class="slot-name"><?php if ($id2_name) {
                                              echo $id2_name;
                                            } ?></div>
                    <div class="slot-ref">
                      <form action="#" method="POST">
                        <input type="hidden" name="cost_cal_min" value="<?php echo $id2_cost_min ?>">
                        <input type="hidden" name="cost_cal_max" value="<?php echo $id2_cost_max ?>">
                        <button type="submit" name="ref2" value="1" class="btn-filter">參考值<br><?php echo "$id2_cost_min ~ $id2_cost_max"; ?></button>
                      </form>
                    </div>
                    <div class="slot-cost">COST：<?php echo "$id2_cost"; ?></div>
                    <div class="slot-actions">
                      <form action="#" method="POST">
                        <td>
                          <button type="submit" class="btn btn-xs btn-outline-secondary btn-warning" name="id2_clear" value="1">清除</button>
                        </td>
                      </form>
                    </div>
                  </div>

                  <!-- SLOT 3 -->
                  <div class="team-slot">
                    <div class="slot-title">No.3</div>
                    <div class="slot-image">
                      <?php
                      $sql = "SELECT * FROM unlight WHERE id = '$id3';";
                      $arr = $db->query($sql);
                      $row = $arr->fetch(PDO::FETCH_ASSOC);

                      $id3_name  = '';
                      $ico_front = '';
                      $ico_back  = '';

                      if ($row) {
                        $id3_name  = $row['name'] ?? '';

                        if (!empty($row['ico'])) {
                          $ico_front = IMG_BASE . $row['ico'];
                        }

                        if (!empty($row['ico_back'])) {
                          $ico_back = IMG_BASE . $row['ico_back'];
                        }
                      }

                      if ($ico_front) {
                        if ($ico_back) {
                          echo '
                          <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
                            <div class="flipper">
                              <div class="front">
                                <img src="' . $ico_front . '" loading="lazy" style="height:140px;">
                                <div class="click-indicator">
                                  <img src="/assets/img/ui/arrow_icon.png" alt="Flip card">
                                </div>
                              </div>
                              <div class="back">
                                <img src="' . $ico_back . '" loading="lazy" style="height:140px;">
                              </div>
                            </div>
                          </div>';
                        } else {
                          echo '<img src="' . $ico_front . '" loading="lazy" style="height:140px;">';
                        }
                      } else {
                        echo '<div class="slot-empty">Unknown</div>';
                      }

                      ?>
                    </div>
                    <div class="slot-name"><?php if ($id3_name) {
                                              echo $id3_name;
                                            } ?></div>
                    <div class="slot-ref">
                      <form action="#" method="POST">
                        <input type="hidden" name="cost_cal_min" value="<?php echo $id3_cost_min ?>">
                        <input type="hidden" name="cost_cal_max" value="<?php echo $id3_cost_max ?>">
                        <button type="submit" name="ref3" value="1" class="btn-filter">參考值<br><?php echo "$id3_cost_min ~ $id3_cost_max"; ?></button>
                      </form>
                    </div>
                    <div class="slot-cost">COST：<?php echo "$id3_cost"; ?></div>
                    <div class="slot-actions">
                      <form action="#" method="POST">
                        <td>
                          <button type="submit" class="btn btn-xs btn-outline-secondary btn-warning" name="id3_clear" value="1">清除</button>
                        </td>
                      </form>
                    </div>
                  </div>

                </div>

              </div>

              <!-- 總 COST -->
              <div class="panel-section total-cost">
                總 COST：<span class="cost-value"><?php echo $ttl_cost . $punish_arr; ?></span>
              </div>

              <!-- 操作 -->
              <div class="panel-actions">
                <form action="#" method="POST">
                  <button type="submit" class="btn btn-danger btn-sm" name="id_all_clear" value="1">清除全部</button>
                </form>
                <form action="team_analysis.php" method="POST">
                  <input type="hidden" name="id1" value="<?php echo $id1 ?>">
                  <input type="hidden" name="id2" value="<?php echo $id2 ?>">
                  <input type="hidden" name="id3" value="<?php echo $id3 ?>">
                  <button type="submit" class="btn btn-success btn-sm" name="search_team" value="1">搜尋組合</button>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- RIGHT：角色 COST 表 -->
        <div class="col-lg-8 col-md-12">
          <form action="#" method="POST">
            <div class="card-dark p-3">
              <div class="card-header cost-header">
                <div class="header-left">
                  <button type="submit" class="btn btn-info" name="id1" value="1">角色 1</button>
                  <button type="submit" class="btn btn-primary" name="id2" value="1">角色 2</button>
                  <button type="submit" class="btn btn-success" name="id3" value="1">角色 3</button>
                </div>

                <div class="header-right">
                  <div class="field-group">
                    <span class="field-label">COST 區間</span>
                    <input type="number" name="cost_cal_min" class="form-control input-sm"
                      placeholder="Min" value="<?php echo $cost_cal_min; ?>">
                    <span class="field-sep">~</span>
                    <input type="number" name="cost_cal_max" class="form-control input-sm"
                      placeholder="Max" value="<?php echo $cost_cal_max; ?>">
                  </div>

                  <div class="field-group">
                    <span class="field-label">最小預留</span>
                    <input type="number" name="save" class="form-control input-sm"
                      placeholder="預留" value="<?php echo $save; ?>">
                  </div>

                  <div class="action-group">
                    <button type="submit" class="btn btn-primary btn-sm">設定</button>
                    <button type="submit" name="reset" value="1" class="btn btn-warning btn-sm">重置</button>
                  </div>
                </div>
              </div>

              <!-- /.box-header -->
              <div class="card-body">
                <table id="example1" class="table ">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th style="padding: 8px 33px;">點擊總覽</th>
                      <th>L1</th>
                      <th>L2</th>
                      <th>L3</th>
                      <th>L4</th>
                      <th>L5</th>
                      <th>R1</th>
                      <th>R2</th>
                      <th>R3</th>
                      <th>R4</th>
                      <th>R5</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $do_all = 0;
                    $sql = "SELECT * FROM cost_unlight WHERE L1 IS NOT NULL AND on_stage = 1";
                    $arr = $db->query($sql);

                    while ($row = $arr->fetch(PDO::FETCH_ASSOC)) {

                      $id   = (int)($row['ID'] ?? 0);
                      $name =        ($row['name'] ?? '');
                      $id_code = $id - 1;
                      $id_level = ($id - 1) * 10;

                      $L1 = (int)($row['L1'] ?? 0);
                      $L2 = (int)($row['L2'] ?? 0);
                      $L3 = (int)($row['L3'] ?? 0);
                      $L4 = (int)($row['L4'] ?? 0);
                      $L5 = (int)($row['L5'] ?? 0);

                      $R1 = (int)($row['R1'] ?? 0);
                      $R2 = (int)($row['R2'] ?? 0);
                      $R3 = (int)($row['R3'] ?? 0);
                      $R4 = (int)($row['R4'] ?? 0);
                      $R5 = (int)($row['R5'] ?? 0);

                      // 原本的 COST 計算 / 顯示邏輯直接接在這裡

                      $id_level++;
                      // === 產生 radio value（ASSOC 版）===
                      $id_level = ($id - 1) * 10;

                      // === 產生 radio value（ASSOC 版）===
                      $L1_value = ($row['L1'] ?? 0) + ((++$id_level) * 100);
                      $L2_value = ($row['L2'] ?? 0) + ((++$id_level) * 100);
                      $L3_value = ($row['L3'] ?? 0) + ((++$id_level) * 100);
                      $L4_value = ($row['L4'] ?? 0) + ((++$id_level) * 100);
                      $L5_value = ($row['L5'] ?? 0) + ((++$id_level) * 100);

                      $R1_value = ($row['R1'] ?? 0) + ((++$id_level) * 100);
                      $R2_value = ($row['R2'] ?? 0) + ((++$id_level) * 100);
                      $R3_value = ($row['R3'] ?? 0) + ((++$id_level) * 100);
                      $R4_value = ($row['R4'] ?? 0) + ((++$id_level) * 100);
                      $R5_value = ($row['R5'] ?? 0) + ((++$id_level) * 100);


                      if ($L1 >= $cost_cal_min && $L1 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($L2 >= $cost_cal_min && $L2 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($L3 >= $cost_cal_min && $L3 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($L4 >= $cost_cal_min && $L4 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($L5 >= $cost_cal_min && $L5 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($R1 >= $cost_cal_min && $R1 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($R2 >= $cost_cal_min && $R2 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($R3 >= $cost_cal_min && $R3 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($R4 >= $cost_cal_min && $R4 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($R5 >= $cost_cal_min && $R5 <= $cost_cal_max) {
                        $do_all = 1;
                      }
                      if ($do_all == 1) {
                        $char_id = $id_code * 10 + 1;

                        print '<tr>
                            <td class="text-muted">' . $id_code . '</td>
                            <td>
                              <a href="/pages/character.php?char_base=' . (int)$char_id . '-' . urlencode($name) . '"
                                class="btn btn-char-link">
                                ' . htmlspecialchars($name) . '
                              </a>
                            </td>';



                        if ($L1 >= $cost_cal_min && $L1 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $L1_value . '" />
                          <label for="' . $id_level++ . '" >' . $L1 . '</label>    
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($L2 >= $cost_cal_min && $L2 <= $cost_cal_max) {
                          print ' <td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $L2_value . '" />
                          <label for="' . $id_level++ . '">' . $L2 . '</label>     
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($L3 >= $cost_cal_min && $L3 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $L3_value . '" />
                          <label for="' . $id_level++ . '">' . $L3 . '</label>    
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($L4 >= $cost_cal_min && $L4 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $L4_value . '" />
                          <label for="' . $id_level++ . '">' . $L4 . '</label>  
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($L5 >= $cost_cal_min && $L5 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $L5_value . '" />
                          <label for="' . $id_level++ . '">' . $L5 . '</label>  
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($R1 >= $cost_cal_min && $R1 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $R1_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R1 . '</label>   
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($R2 >= $cost_cal_min && $R2 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $R2_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R2 . '</label>  
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($R3 >= $cost_cal_min && $R3 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $R3_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R3 . '</label>  
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($R4 >= $cost_cal_min && $R4 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $R4_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R4 . '</label>   
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        if ($R5 >= $cost_cal_min && $R5 <= $cost_cal_max) {
                          print '<td>
                        <span>
                          <input class="cost-radio" type="radio" id="' . $id_level . '" name="cost" value="' . $R5_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R5 . '</label>
                        </span>
                      </td>';
                        } else {
                          print '<td></td>';
                        }
                        print '</tr>';
                        $do_all = 0;
                      }
                    }
                    ?>
                  </tbody>
                </table>
              </div>
              <!-- /.box-body -->
            </div>
            <!-- /.box -->
          </form>
        </div>
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
  $(function() {
    $('#example1').DataTable({
      'paging': false,
      'lengthChange': false,
      'searching': true,
      'ordering': true,
      'info': true,
      'autoWidth': false,
      'scrollX': true, // 啟用橫向滾動
      'scrollY': "550px", // 限制表格高度，超過就可滾動
      'scrollCollapse': true, // 內容變少時，自動縮小表格大小
      'fixedHeader': true // 固定表頭
    })
  })
</script>


<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>