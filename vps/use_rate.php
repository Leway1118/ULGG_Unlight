<!DOCTYPE html>
<?php
require_once('head.php');
date_default_timezone_set("Asia/Taipei");
$date = date("Y-m-d H:i:s");
//session_start();



if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre>';
  var_dump($_GET);
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}

//$ack = $_SESSION["ack"] ?? 0;
$permission = $_SESSION["ack"] ?? 0;

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

function calcPerformanceByTotalGames($total_games, $win_rate, $d)
{
  // 1. 確保 $d 為整數且至少 1
  $d = max(1, intval($d));

  // 2. 定義上限
  $max_log = 250;

  // 3. 計算「raw log」或「scale」值，再做截斷（cap）
  //    這裡原本寫成 max($raw, $max_log) 會讓結果永遠 ≥ 100。
  //    正確應該是 min($raw, $max_log)，意思是「超過就取上限」。
  $raw_log = $d * 11 + 18;                // 你的線性 scaling，例：d=1→7，d=10→70，d=20→140
  $log     = min($raw_log, $max_log);
  //print "log =$log ";
  if ($total_games === 0) {
    return 0.0;
  }

  $games_factor = log($total_games + 1) / log($log);
  $performance  = min($win_rate * $games_factor * 20, 20);

  // **回傳 float**，不做字串處理
  return round($performance, 1);
}



$name_arr = '';
$ico_arr = '';
$cost_sum  = 0;
$topLimit = 200;
$count_num = 1;

if (isset($_GET["reset"])) {
  $cost_max = 140;
  $_SESSION["cost_max"] = $cost_max;
  $cost_min = 0;
  $_SESSION["cost_min"] = $cost_min;
} elseif (isset($_GET["combined"])) {
  $combined = $_GET["combined"];
  $_SESSION["combined"] = $combined;
} elseif (isset($_GET["set_time"])) { // 處理提交表單的時間範圍
  $daterange = $_GET["daterange"];
  $_SESSION["daterange"] = $daterange; // 更新 SESSION
}

if (isset($_GET['cost'])) {
  $raw = $_GET['cost'];            // e.g. "100+" 或 "80"
  if (substr($raw, -1) === '+') {
    // 「100+」的情況
    $cost_min = (int) rtrim($raw, '+'); // 100
    $cost_max = 140;                    // 你要的上限
    //print "cost_max=$cost_max";
  } else {
    // 一般數字
    $cost_min = $cost_max = (int) $raw;      // 例如 80
  }
  // 存到 Session
  /* $cost_min= $min;
    $cost_max= $max; */
  $_SESSION['cost_min'] = $cost_min;
  $_SESSION['cost_max'] = $cost_max;
}

if (isset($_SESSION["cost_min"])) {
  $cost_min = $_SESSION["cost_min"];
} else {
  $cost_min = 0;
}
if (isset($_SESSION["cost_max"])) {
  $cost_max = $_SESSION["cost_max"];
} else {
  $cost_max = 140;
}
if (isset($_SESSION["combined"])) {
  $combined = $_SESSION["combined"];
} else {
  $combined = 2;
}

// 預設顯示前7天
if (!isset($_SESSION["daterange"])) {
  //$_SESSION["daterange"] = date('Y-m-01') . " - " . date('Y-m-t');
  $_SESSION["daterange"] = date('Y-m-d', strtotime('-2 days')) . " - " . date('Y-m-d');
}
//print $_SESSION["daterange"];

//$search_team = 0;
$twovtwo = 0;
if (isset($_GET["cost_min"])) {
  $cost_min = $_GET["cost_min"];
  $_SESSION["cost_min"] = $cost_min;
}
if (isset($_GET["cost_min"])) {
  $cost_max = $_GET["cost_max"];
  $_SESSION["cost_max"] = $cost_max;
}/*  elseif (isset($_POST["search_team"])) {

  $id1 = $_POST["id1"];
  $id2 = $_POST["id2"];
  $id3 = $_POST["id3"];
  if ($id3 == 0) {
    $twovtwo = 1;

    // 放入陣列並排序
    $ids = [$id1, $id2, $id3];
    sort($ids); // 從小到大排序

    // 排序後重新分配回 $id1, $id2, $id3
    //$id1 = $ids[0];
    $id1 = $ids[1];
    $id2 = $ids[2];
  } else {
    // 放入陣列並排序
    $ids = [$id1, $id2, $id3];
    sort($ids); // 從小到大排序

    // 排序後重新分配回 $id1, $id2, $id3
    $id1 = $ids[0];
    $id2 = $ids[1];
    $id3 = $ids[2];
  }
  $search_team = 1;
} */



// 取得當前的日期範圍
$daterange = $_SESSION["daterange"];
// 解析開始與結束日期

list($start_date, $end_date) = explode(" - ", $daterange);
$end_date = date("Y-m-d", strtotime($end_date)) . " 23:59:59";
$start_date = date("Y-m-d", strtotime($start_date));

// ✅ 加入查詢天數限制（最多30天）
$max_allowed_days = 30;
$start_ts = strtotime($start_date);
$end_ts = strtotime($end_date);
$diff_days = ($end_ts - $start_ts) / (60 * 60 * 24);

if ($diff_days > $max_allowed_days && $permission != 2) {
  // 超過最大允許區間，自動縮短查詢範圍為 end_date 往前推30天
  $start_ts = strtotime("-{$max_allowed_days} days", $end_ts);
  $start_date = date("Y-m-d", $start_ts);

  // 更新 SESSION，確保畫面同步顯示
  $_SESSION["daterange"] = $start_date . " - " . substr($end_date, 0, 10); // 移除 time 部分
  $daterange = $_SESSION["daterange"];
  $_SESSION["warning"] = "查詢區間過大，已自動限制為 $max_allowed_days 天";
}
// 1. 計算包含首尾的天數
$dtStart = new DateTime($start_date);
$dtEnd   = new DateTime($end_date);
$interval = $dtStart->diff($dtEnd);
// ->days 只會回傳兩者相差的整天數 (不包含時間小數)
// 如果你要算「3/1~3/3」為 3 天，就要 +1
$d = $interval->days + 1;

// 2. 再套用你的最大允許限制
$max_allowed_days = 30;
$d = min($d, $max_allowed_days);

// 3. 確保最少為 1 天
$d = max(1, $d);


// 亞城取最新一筆
$stmt = $db->query("SELECT month_date,cost1, cost2, cost3, cost4
                      FROM quickmatch_monthly_cost WHERE chennel =1
                    ORDER BY id DESC 
                    LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// 假設原本這樣拿到完整的 month_date
$raw = $row['month_date'];  // e.g. "2025-05-06 14:30:00"

// 只取年月日
$month_date = date('Y-m-d', strtotime($raw));
$cost_values = [
  intval($row['cost1'] ?? 0),
  intval($row['cost2'] ?? 0),
  intval($row['cost3'] ?? 0),
  intval($row['cost4'] ?? 0)
];

// 遺跡取最新一筆
$stmt = $db->query("SELECT month_date,cost1, cost2, cost3, cost4
                      FROM quickmatch_monthly_cost WHERE chennel =3
                    ORDER BY id DESC 
                    LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// 假設原本這樣拿到完整的 month_date
$raw = $row['month_date'];  // e.g. "2025-05-06 14:30:00"

// 只取年月日
$month_date = date('Y-m-d', strtotime($raw));
$cost_values2 = [
  intval($row['cost1'] ?? 0),
  intval($row['cost2'] ?? 0),
  intval($row['cost3'] ?? 0),
  intval($row['cost4'] ?? 0)
];

// 預設值
$bp_min = 1000;
$bp_max = 2000;
$bp_range = $_SESSION['bp_range'] ?? 'all';
// ─── 處理 BP slider 傳入值 ───
if (isset($_GET['bp_min']) && isset($_GET['bp_max'])) {
  // 1) 來自 slider
  $bp_min = intval($_GET['bp_min']);
  $bp_max = intval($_GET['bp_max']);
  $_SESSION['bp_min'] = $bp_min;
  $_SESSION['bp_max'] = $bp_max;
} elseif (isset($_SESSION['bp_min'], $_SESSION['bp_max'])) {
  // 2) 已儲存在 session（回到分頁／刷新）
  $bp_min = $_SESSION['bp_min'];
  $bp_max = $_SESSION['bp_max'];
} else {
}

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
?>
<html lang="en">

<head>

  <!-- Tell the browser to be responsive to screen width -->
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <!-- Bootstrap 3.3.7 -->
  <link rel="stylesheet" href="bower_components/bootstrap/dist/css/bootstrap.min.css">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="bower_components/font-awesome/css/font-awesome.min.css">
  <!-- Ionicons -->
  <link rel="stylesheet" href="bower_components/Ionicons/css/ionicons.min.css">
  <!-- daterange picker -->
  <link rel="stylesheet" href="bower_components/bootstrap-daterangepicker/daterangepicker.css">
  <!-- bootstrap datepicker -->
  <link rel="stylesheet" href="bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css">
  <!-- iCheck for checkboxes and radio inputs -->
  <link rel="stylesheet" href="plugins/iCheck/all.css">
  <!-- Bootstrap Color Picker -->
  <link rel="stylesheet" href="bower_components/bootstrap-colorpicker/dist/css/bootstrap-colorpicker.min.css">
  <!-- Bootstrap time Picker -->
  <link rel="stylesheet" href="plugins/timepicker/bootstrap-timepicker.min.css">
  <!-- Select2 -->
  <link rel="stylesheet" href="bower_components/select2/dist/css/select2.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/AdminLTE.min.css">
  <!-- AdminLTE Skins. Choose a skin from the css/skins
       folder instead of downloading all of them to reduce the load. -->
  <link rel="stylesheet" href="dist/css/skins/_all-skins.min.css">
  <!-- bootstrap-slider 样式 -->
  <link rel="stylesheet" href="plugins/bootstrap-slider/slider.css">


  <!-- Google Font -->
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic">

  <style>
    /* 按鈕樣式 */
    #backToTop {
      position: fixed;
      bottom: 30px;
      right: 30px;
      width: 50px;
      height: 50px;
      background-color: #007bff;
      color: white;
      border: none;
      border-radius: 50%;
      cursor: pointer;
      display: none;
      /* 預設隱藏 */
      justify-content: center;
      align-items: center;
      font-size: 18px;
      box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.2);
      transition: opacity 0.3s ease;
      z-index: 999;
      /* 確保按鈕在最上層 */
    }

    #backToTop:hover {
      background-color: #0056b3;
    }

    .cards {
      display: flex;
      flex-direction: row;
    }




    /* ✅ 內層 .flipper 固定大小，靠 clip-path 實現裁切 */
    .flip-container {
      width: 62px;
      height: 41px;
      overflow: hidden;
      position: relative;
      display: inline-block;
      border-radius: 5px;
      /* margin-left: -30px; */
      /* 預設往左疊 */
    }

    .flip-container .front img,
    .flip-container .back img {
      width: 78px;
      height: 111px;
      object-fit: cover;
      object-position: top left;
      transition: transform 0.3s ease;
      border-radius: 5px;
    }

    .flip-container:hover {
      overflow: visible;
      /* 滑入時取消裁切 */
      z-index: 10;
    }

    .flipper {
      width: 100px;
      height: 100px;
      transform-style: preserve-3d;
      transition: transform 0.6s;
      position: relative;
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

    /* 翻轉後的背面 */
    .back {
      transform: rotateY(180deg);
      z-index: 11;
      position: absolute;
      top: -40px;
      /* 向上移動，避免被文字擋住 */
      left: 30px;
    }

    /* 背面圖片放大 */
    .flip-container.flipped .back img {
      transform: scale(2.1);
      width: 100%;
      height: 100%;
    }

    .zoomout_rank {
      transition: all 0.2s linear;
    }

    .zoomout_rank:hover {
      transform: scale(2);
      transition: all 0.2s linear;
    }

    .image-flex {
      display: flex;
      flex-direction: row;
      align-items: center;
      /* position: relative; */
    }

    .image-flex img {
      height: auto;
      transition: transform 0.3s ease-in-out;
    }

    .image-flex img:first-child {
      margin-left: 0;
      /* 第一張圖片不偏移 */
    }

    .image-flex img:hover {
      transform: scale(1.2);
      /* 滑鼠移上去放大 */
      z-index: 10;
    }

    /* 查閱按鈕 */
    .chk-btn {
      background: rgb(171, 170, 170);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 1px 10px;
      margin: 2px 1px;
      transform: scale(1.1);
    }

    /* 勝利按鈕 */
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

    .navbar {
      position: sticky !important;
    }

    .navbar-nav>li>.dropdown-menu {
      margin-top: 13px;
    }

    .quickSelect {
      font-size: 18px;
      padding: 0px 10px;
    }

    .stage-select-list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: flex;
      flex-wrap: wrap;
    }

    .stage-select-list li {
      margin: 4px;
    }

    .stage-select-list input {
      display: none;
    }

    .stage-select-list label {
      display: inline-block;
      cursor: pointer;
      padding: 6px 12px;
      border: 1px solid #ccc;
      border-radius: 4px;
      transition: background 0.2s, color 0.2s;
    }

    .stage-select-list label:hover {
      background: #f0f0f0;
    }

    .stage-select-list input:checked+label {
      background: #007bff;
      color: #fff;
      border-color: #007bff;
    }
  </style>
</head>

<body>
  <div class="navbar">
    <?php
    print $head_bar;
    ?>
  </div>
  <button id="backToTop" onclick="scrollToTop()">▲</button>

  <script>
    // 監聽滾動事件，決定是否顯示按鈕
    window.onscroll = function() {
      let button = document.getElementById("backToTop");
      if (document.documentElement.scrollTop > 200) {
        button.style.display = "flex"; // 顯示按鈕
      } else {
        button.style.display = "none"; // 隱藏按鈕
      }
    };

    // 點擊按鈕回到頂部
    function scrollToTop() {
      window.scrollTo({
        top: 0,
        behavior: "smooth" // 平滑滾動效果
      });
    }
  </script>
  <div class="row margin">
    <div class="col-md-offset-2 col-md-8">
      <div class="box">
        <div class="box-header">
          <?php if (!empty($_SESSION["warning"])): ?>
            <div class="alert alert-warning">
              <?php echo $_SESSION["warning"];
              unset($_SESSION["warning"]); ?>
            </div>
          <?php endif; ?>
          <form id="filterForm" method="GET" class="row g-3 align-items-center" style="margin: 0px -30px;">

            <!-- 第一列 -->
            <div class="col-12 d-md-flex align-items-center justify-content-between" style="margin-bottom: 50px;">

              <!-- 時間選取 -->
              <div class="col-md-6">
                <div class="input-group">
                  <button type="button" class="btn btn-outline-secondary" id="daterange-btn">
                    <i class="fa fa-calendar"></i> 時間選取 <i class="fa fa-caret-down"></i>
                  </button>
                  <input type="hidden" name="daterange" id="daterange" value="<?php echo $daterange; ?>">
                  <button type="submit" name="set_time" value="1" class="btn btn-primary">提交</button>
                </div>
              </div>
            </div>

            <div class="row margin"><!-- range select -->
              <div class="col-sm-6">
                <!-- 在滑桿上方或下方放兩個顯示容器 -->
                <div class="form-group">
                  <div style="margin-top: 8px;">
                    <?php
                    /* echo "Quickmatch COST {$month_date} (維修後):<br>";
                    echo "亞城:";
                    foreach ($cost_values as $i => $cv) {
                      // 前面三個正常
                      if ($i < count($cost_values) - 1) {
                        echo '<a class="quickSelect" href="?cost=' . $cv . '">' . $cv . '</a> / ';
                      } else {
                        // 最後一個要帶 +
                        echo '<a class="quickSelect" href="?cost=' . $cv . '%2B">' . $cv . '+</a>';
                      }
                    }
                    echo '<i class="fa fa-hand-o-left" aria-hidden="true"></i> 快速選擇<br>';
                    echo "遺跡:";
                    foreach ($cost_values2 as $i => $cv) {
                      // 前面三個正常
                      if ($i < count($cost_values) - 1) {
                        echo '<a class="quickSelect" href="?cost=' . $cv . '">' . $cv . '</a> / ';
                      } else {
                        // 最後一個要帶 +
                        echo '<a class="quickSelect" href="?cost=' . $cv . '%2B">' . $cv . '+</a>';
                      }
                    }
                    echo '<i class="fa fa-hand-o-left" aria-hidden="true"></i> 快速選擇'; */
                    ?>
                  </div>
                  <label><br>COST 區間：<span id="cost-display"><?php echo $cost_min ?> - <?php echo $cost_max ?></span></label>
                  <input
                    type="text"
                    id="cost-slider"
                    class="slider form-control slider-primary"
                    data-slider-min="0"
                    data-slider-max="140"
                    data-slider-step="1"
                    data-slider-value="[<?php echo $cost_min ?>,<?php echo $cost_max ?>]"
                    data-slider-orientation="horizontal"
                    data-slider-selection="before"
                    data-slider-tooltip="hide"
                    data-slider-id="aqua">
                </div>

                <div class="form-group">
                  <label>BP 區間：<span id="bp-display"><?php echo $bp_min ?> - <?php echo $bp_max ?></span></label>
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
              <!-- 場景選擇 -->
              <div class="col-sm-6">
                <div class="form-group">
                  <label>場景選擇：</label>
                  <ul class="stage-select-list">
                    <?php foreach ($stages as $i => $name): ?>
                      <li>
                        <input
                          type="radio"
                          id="stage<?= $i ?>"
                          name="stage"
                          value="<?= $i ?>"
                          <?= $i === (int)($_GET['stage'] ?? -1) ? 'checked' : '' ?>>
                        <label for="stage<?= $i ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></label>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
              <!-- 場景選擇 -->
            </div><!-- range select end -->

          </form>
          <h3 class="box-title">角色使用率</h3>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
          <table id="example1" class="table table-bordered table-striped">
            <thead>
              <tr>
                <th style="width: 10px">#</th>
                <th>角色</th>
                <th>角色名稱</th>
                <th>COST</th>
                <th>使用次數</th>
                <th>勝率</th>
                <th>評分</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 1;
              /* print "bp_min=$bp_min bp_max=$bp_max <br>";
              print "cost_min=$cost_min cost_max=$cost_max <br>"; */
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
                AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql
                GROUP BY e1
                UNION ALL
                SELECT e2 AS char_id, COUNT(*) AS count_num, SUM(lose) AS win, SUM(win) AS lose, MAX(update_time) AS update_time
                FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date')
                AND (bp_p1 BETWEEN '$bp_min' AND '$bp_max')
                AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql
                GROUP BY e2
                UNION ALL
                SELECT e3 AS char_id, COUNT(*) AS count_num, SUM(lose) AS win, SUM(win) AS lose, MAX(update_time) AS update_time
                FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                AND (bp_p1 BETWEEN '$bp_min' AND '$bp_max')
                AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql
                GROUP BY e3
                UNION ALL

                -- 我方角色統計
                SELECT u1 AS char_id, COUNT(*) AS count_num, SUM(win) AS win, SUM(lose) AS lose, MAX(update_time) AS update_time
                FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                AND (bp_p2 BETWEEN '$bp_min' AND '$bp_max')
                AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql
                GROUP BY u1
                UNION ALL
                SELECT u2 AS char_id, COUNT(*) AS count_num, SUM(win) AS win, SUM(lose) AS lose, MAX(update_time) AS update_time
                FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                AND (bp_p2 BETWEEN '$bp_min' AND '$bp_max')
                AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql
                GROUP BY u2
                UNION ALL
                SELECT u3 AS char_id, COUNT(*) AS count_num, SUM(win) AS win, SUM(lose) AS lose, MAX(update_time) AS update_time
                FROM arena_unlight WHERE (update_time BETWEEN '$start_date' AND '$end_date') 
                AND (bp_p2 BETWEEN '$bp_min' AND '$bp_max')
                AND (cost BETWEEN '$cost_min' AND '$cost_max') $stage_sql
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
                  $e1 = $row[0];
                  $ico = $row[1];
                  /* $total_games = $row[4]; //使用次數
                   $win_rate = round($row[7], 0); //勝率 */
                  //$games_factor=calcPerformance($total_games,$win_rate);


                  $cost = $row[4]; // 使用次數
                  $total_games = $row[5]; // 使用次數
                  $win_rate = $row[8] / 100; // 勝率直接除100
                  //$d = 0;
                  $games_factor = calcPerformanceByTotalGames($total_games, $win_rate, $d);
                  $rate_color = progress_color($win_rate * 100);
                  $rating_color = progress_color($games_factor * 5);
                  $sql2 = "SELECT ico,cost,name,level FROM unlight WHERE id='$e1';";
                  //print "sql2=$sql2";
                  $arr2 = $db->query("$sql2");
                  while ($row2 = $arr2->fetch()) {
                    $ico_front = 'uploads/' . $row2[0];
                    $name = $row2[2];
                    $level = $row2[3];
                    $ico_arr .= '<div class="cards">
                    <div class="flip-container">
                        <div class="front">
                                <img class="zoomout_rank" src="' . $ico_front . '" loading="lazy">
                            </div>
                    </div>
                    </div>';
                    //$cost_sum+=$cost;
                  }
                  $name = rtrim($name, "'");   // 移除尾巴 '
                  $name = trim($name);         // 去除空白
                  $level_name = $level . $name;

                  //$cost = $level . $name;
                  $search_link = "ranking.php?character=" . urlencode($level_name); // 建立搜尋連結
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

                  /* <tr>
                      <td>" . $i++ . "</td> */
                  $display_factor = number_format($games_factor, 1);
                  $html = "
                      <td>$ico_arr</td>
                      <td><a href=\"$search_link\" class=\"text-dark\" style=\"text-decoration: underline;\">$level_name</a></td>
                      <td>$cost</td>
                      <td>$total_games</td>
                      <td><div class=\"badge bg-$rate_color\">" . number_format($win_rate * 100, 1) . "%</div></td>
                      <td data-order=\"$games_factor\">
                      <div class=\"badge bg-$rating_color\">$display_factor</div>$mvp_icon
                      </td>";
                  /* </tr> */
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
            <!-- <tfoot>
                <tr>
                  <th>Rendering engine</th>
                  <th>Browser</th>
                  <th>Platform(s)</th>
                  <th>Engine version</th>
                </tr>
              </tfoot> -->
          </table>
        </div>
        <!-- /.box-body -->
      </div>
      <!-- /.box -->
    </div>
    <!-- /.col -->
  </div>



  <!-- jQuery 3 -->
  <script src="bower_components/jquery/dist/jquery.min.js"></script>
  <!-- Bootstrap 3.3.7 -->
  <script src="bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
  <!-- DataTables -->
  <script src="bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
  <script src="bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>
  <!-- SlimScroll -->
  <script src="bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
  <!-- FastClick -->
  <script src="bower_components/fastclick/lib/fastclick.js"></script>
  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.min.js"></script>
  <!-- AdminLTE for demo purposes -->
  <script src="dist/js/demo.js"></script>

  <!-- date-range-picker -->
  <script src="bower_components/moment/min/moment.min.js"></script>
  <script src="bower_components/bootstrap-daterangepicker/daterangepicker.js"></script>

  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.min.js"></script>


  <!-- page script -->
  <script>
    $(function() {
      // DataTables擴充: 正確處理<td data-order="數值">
      $.fn.dataTable.ext.order['dom-data-order'] = function(settings, col) {
        return this.api().column(col, {
          order: 'index'
        }).nodes().map(function(td, i) {
          return parseFloat($(td).attr('data-order')) || 0;
        });
      };

      $('#example1').DataTable({
        'paging': true,
        'lengthChange': true,
        'pageLength': 10, // 預設每頁顯示 7 筆
        'searching': true,
        'ordering': true,
        'info': true,
        'autoWidth': false,
        "order": [
          [6, "desc"]
        ],
        "columnDefs": [{
          "targets": 6,
          "orderDataType": "dom-data-order"
        }]
      });


      // 調整搜尋框的寬度
      $('.dataTables_filter input').css({
        'width': '200px', // 設定搜尋框寬度為 300px
        /* 'margin-left': '10px', */ // 增加左側間距
      });
      /* $('#example2').DataTable({
        'paging': true,
        'lengthChange': true,
        'searching': true,
        'ordering': true,
        'info': true,
        'autoWidth': false,
        "columnDefs": [{
          "type": "num",
          "targets": 0 // 第 0 欄使用數字排序
        }],
        "order": [
          [5, "desc"]
        ] // ✅ 預設以第 4 欄 (BP) 降冪排序
      }); */
    })

    //Date range picker
    $(document).ready(function() {
      // 檢查 localStorage 是否已儲存上次選擇的日期範圍
      let sessionRange = "<?php echo $daterange; ?>";

      let start = moment(sessionRange.split(" - ")[0]); // 解析開始日期
      let end = moment(sessionRange.split(" - ")[1]); // 解析結束日期

      $('#daterange-btn').daterangepicker({
        locale: {
          format: 'YYYY-MM-DD',
          applyLabel: '確定',
          cancelLabel: '取消',
          customRangeLabel: '自訂範圍'
        },
        startDate: start,
        endDate: end,
        ranges: {
          '今天': [moment(), moment()],
          '昨天': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
          '最近 3 天': [moment().subtract(2, 'days'), moment()],
          '最近 7 天': [moment().subtract(6, 'days'), moment()],
          '最近 30 天': [moment().subtract(29, 'days'), moment()],
          '本月': [moment().startOf('month'), moment().endOf('month')],
          '上個月': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
        }
      }, function(start, end) {
        // 設定選擇的日期範圍到隱藏的 input
        $('#daterange').val(start.format('YYYY-MM-DD') + ' - ' + end.format('YYYY-MM-DD'));

        // 更新按鈕顯示的日期範圍
        $('#daterange-btn').html('<i class="fa fa-calendar"></i> ' + start.format('YYYY-MM-DD') + ' 至 ' + end.format('YYYY-MM-DD') + ' <i class="fa fa-caret-down"></i>');

        // 儲存選擇的日期範圍到 localStorage
        localStorage.setItem('daterange', start.format('YYYY-MM-DD') + ' - ' + end.format('YYYY-MM-DD'));
      });

      // 初始化按鈕顯示上次選擇的日期範圍
      $('#daterange-btn').html('<i class="fa fa-calendar"></i> ' + start.format('YYYY-MM-DD') + ' 至 ' + end.format('YYYY-MM-DD') + ' <i class="fa fa-caret-down"></i>');
      $('#daterange').val(start.format('YYYY-MM-DD') + ' - ' + end.format('YYYY-MM-DD'));
    });
  </script>

  <!-- 引入 bootstrap-slider.js -->
  <script src="plugins/bootstrap-slider/bootstrap-slider.js"></script>
  <script>
    $(function() {
      // 1. 從 PHP 拿到那四個刻度值，例如 [55,61,73,100]
      //var costValues = ;

      $(function() {
        // COST 滑桿：20～100，步進 1
        $('#cost-slider').slider({
            min: 0,
            max: 140,
            step: 1,
            value: [parseInt($('#costMin').val(), 10), parseInt($('#costMax').val(), 10)],
            tooltip: 'hide'
          })
          .on('slide', function(ev) {
            $('#cost-display').text(ev.value[0] + ' - ' + ev.value[1]);
          })
          .on('slideStop', function(ev) {
            var min = ev.value[0],
              max = ev.value[1];
            $('#costMin').val(min);
            $('#costMax').val(max);
            var params = new URLSearchParams(window.location.search);
            params.set('cost_min', min);
            params.set('cost_max', max);
            window.location.search = params.toString();
          });

        // BP 滑桿（保持原有設定）
        $('#bp-slider').slider({
            min: 1000,
            max: 2000,
            step: 50,
            value: [parseInt($('#bp_min').val(), 10), parseInt($('#bp_max').val(), 10)],
            tooltip: 'hide'
          })
          .on('slide', function(ev) {
            $('#bp-display').text(ev.value[0] + ' - ' + ev.value[1]);
          })
          .on('slideStop', function(ev) {
            var min = ev.value[0],
              max = ev.value[1];
            $('#bp_min').val(min);
            $('#bp_max').val(max);
            var params = new URLSearchParams(window.location.search);
            params.set('bp_min', min);
            params.set('bp_max', max);
            // 同步 COST 參數
            params.set('cost_min', $('#costMin').val());
            params.set('cost_max', $('#costMax').val());
            window.location.search = params.toString();
          });
      });

    });
  </script>

  <script>
    document.querySelectorAll('.stage-select-list input[name="stage"]')
      .forEach(radio => {
        radio.addEventListener('change', () => {
          // 一選就送出 GET 表單
          document.getElementById('filterForm').submit();
        });
      });
  </script>
</body>

</html>