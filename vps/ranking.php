<!DOCTYPE html>
<?php
$date = date("Y-m-d H:i:s");
ob_start(); // 啟動輸出緩衝
//session_start(); // 如果有使用 session



include('head.php');

if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre>';
  var_dump($_GET); 
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}

$permission = $_SESSION["ack"] ?? 0;
//$permission = $_SESSION["ack"] ?? 0;

$key = 'visitor_' . date('Ymd');
if (!isset($_SESSION[$key])) {
  // … 寫入 DB …
  $_SESSION[$key] = true;
}

date_default_timezone_set("Asia/Taipei");

// 方便複用的「真人條件」
$HUMAN_COND = "
  ( (is_bot = 0 OR is_bot IS NULL)
    AND user_agent REGEXP 'Windows|Macintosh|iPhone|Android|Linux'
  )
";

$HUMAN_V1 = human_cond("v1");
$HUMAN_V2 = human_cond("v2");

// 今日 / 昨日日期
$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// 1️⃣ 今日真人訪客 (Unique session)
$sql = "
  SELECT COUNT(DISTINCT session_id)
  FROM visitors
  WHERE DATE(visited_at) = :d
    AND {$HUMAN_COND}
";
$stmt = $db->prepare($sql);
$stmt->execute([':d' => $today]);
$todayVisitors = (int)$stmt->fetchColumn();

$stmt->execute([':d' => $yesterday]);
$yesterdayVisitors = (int)$stmt->fetchColumn();

// 今日 vs 昨日 變動 %
if ($yesterdayVisitors > 0) {
  $todayChangePercent = round(($todayVisitors - $yesterdayVisitors) / $yesterdayVisitors * 100, 1);
} else {
  $todayChangePercent = null; // 昨日為 0，不顯示 %
}

// 3️⃣ 今日回訪率（今天有來 & 前 7 天曾經來過）
$sqlReturn = "
  SELECT COUNT(DISTINCT v1.session_id)
  FROM visitors v1
  JOIN visitors v2 
    ON v1.session_id = v2.session_id
  WHERE DATE(v1.visited_at) = :today
    AND {$HUMAN_V1}
    AND {$HUMAN_V2}
    AND v2.visited_at >= DATE_SUB(:todayDateTime, INTERVAL 7 DAY)
    AND DATE(v2.visited_at) < :today
";
$stmt = $db->prepare($sqlReturn);
$stmt->execute([
  ':today'        => $today,
  ':todayDateTime' => $today . ' 00:00:00',
]);
$todayReturnVisitors = (int)$stmt->fetchColumn();

if ($todayVisitors > 0) {
  $todayReturnRate = round($todayReturnVisitors / $todayVisitors * 100, 1);
} else {
  $todayReturnRate = 0;
}

// 4️⃣ 本月活躍玩家（真人 Unique session）
$sqlMonth = "
  SELECT COUNT(DISTINCT session_id)
  FROM visitors
  WHERE {$HUMAN_COND}
    AND visited_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
";
$monthActiveVisitors = (int)$db->query($sqlMonth)->fetchColumn();

// 5️⃣ 當前在線（最近 5 分鐘內有動作的真人 session）
$sqlOnline = "
  SELECT COUNT(DISTINCT session_id)
  FROM visitors
  WHERE {$HUMAN_COND}
    AND visited_at >= NOW() - INTERVAL 5 MINUTE
";
$onlineNow = (int)$db->query($sqlOnline)->fetchColumn();

// 6️⃣ 今日熱門搜尋字 Top 5
$sqlSearch = "
  SELECT search_term, COUNT(*) AS cnt
  FROM visitors
  WHERE DATE(visited_at) = :today
    AND {$HUMAN_COND}
    AND search_term IS NOT NULL
    AND search_term <> ''
  GROUP BY search_term
  ORDER BY cnt DESC
  LIMIT 5
";
$stmt = $db->prepare($sqlSearch);
$stmt->execute([':today' => $today]);
$topSearchToday = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sqlChar = "
SELECT 
    character_name,
    COUNT(*) AS cnt
FROM (
    SELECT DISTINCT 
        session_id,
        character_name
    FROM visitors
    WHERE character_name IS NOT NULL
      AND visited_at >= CURDATE()
      AND visited_at < CURDATE() + INTERVAL 1 DAY
      AND is_bot = 0
) AS uniq
GROUP BY character_name
ORDER BY cnt DESC
LIMIT 5
";

$topChar = $db->query($sqlChar)->fetchAll(PDO::FETCH_ASSOC);



// 🔹 最近 7 天「真人訪客數」趨勢 (for visitorsChart)
$sql7 = "
  SELECT DATE(visited_at) AS d,
         COUNT(DISTINCT session_id) AS cnt
  FROM visitors
  WHERE {$HUMAN_COND}
    AND visited_at >= CURDATE() - INTERVAL 6 DAY
  GROUP BY DATE(visited_at)
  ORDER BY d ASC
";
$rows7 = $db->query($sql7)->fetchAll(PDO::FETCH_ASSOC);

$visitors7Labels = [];
$visitors7Counts = [];
// 先填滿 7 天（避免沒資料某天斷掉）
for ($i = 6; $i >= 0; $i--) {
  $date = date('Y-m-d', strtotime("-{$i} day"));
  $visitors7Labels[$date] = date('m-d', strtotime($date));
  $visitors7Counts[$date] = 0;
}
foreach ($rows7 as $r) {
  $d = $r['d'];
  if (isset($visitors7Counts[$d])) {
    $visitors7Counts[$d] = (int)$r['cnt'];
  }
}

// JS 用
$visitors7LabelsJS = array_values($visitors7Labels);
$visitors7CountsJS = array_values($visitors7Counts);





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
  $raw_log = $d * 4 + 9;
  $logBase = min(max($raw_log, 15), 45); // 限制範圍 15~45

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
  $score = $adj_win_rate * $games_factor * 20;

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

// 亞城取最新一筆
$stmt = $db->query("SELECT month_date,cost1, cost2, cost3, cost4
                      FROM quickmatch_monthly_cost WHERE chennel =1
                    ORDER BY id DESC 
                    LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// 假設原本這樣拿到完整的 month_date
$raw = $row['month_date'] ?? date('Y-m-d H:i:s');
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
$raw = $row['month_date'] ?? date('Y-m-d H:i:s');
// 只取年月日
$month_date = date('Y-m-d', strtotime($raw));
$cost_values2 = [
  intval($row['cost1'] ?? 0),
  intval($row['cost2'] ?? 0),
  intval($row['cost3'] ?? 0),
  intval($row['cost4'] ?? 0)
];


/* // 去重、排序
$cost_values = array_unique($cost_values);
sort($cost_values);

// JSON 給前端
$cost_values_json = json_encode(array_values($cost_values));
// 在這裡定義預設的 cost_max_sql 為刻度陣列的最大值
$cost_max_sql = max($cost_values); */

//$cost_max_sql = 60;
$cost_min = 0;
$cost_max = 140;
//重置
if (isset($_GET["reset"])) {
  $cost_min = 0;
  $cost_max = 140;
  $bp_min = 1000;
  $bp_max = 2000;
  $_SESSION["cost_min"] = $cost_min;
  $_SESSION["cost_max"] = $cost_max;
  $_SESSION["bp_min"] = $bp_min;
  $_SESSION["bp_max"] = $bp_max;
  $_SESSION["player_search"] = '';
  $player_search = '';
  $combined = 0;
  $_SESSION["combined"] = $combined;
  header("Location: ranking.php"); // ✅ 將網址中的 ?player_search 清除
  exit; // ⛳ 必須中止程式，不然會繼續往下執行
} elseif (isset($_GET["combined"])) {
  $combined = $_GET["combined"];
  $_SESSION["combined"] = $combined;
  /* if ($combined == 4) {
    $player_search = $username;
  } */
} elseif (isset($_GET["set_time"])) { // 處理提交表單的時間範圍
  $daterange = $_GET["daterange"];
  $_SESSION["daterange"] = $daterange; // 更新 SESSION
}


//print "cost=" . $_GET['cost'];
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
  $_SESSION['cost_min'] = $cost_min;
  $_SESSION['cost_max'] = $cost_max;
}




$name_arr = '';
$ico_arr = '';
$cost_sum  = 0;


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
  $combined = 0; //3人統計
}

// 預設顯示前7天
if (!isset($_SESSION["daterange"])) {
  //$_SESSION["daterange"] = date('Y-m-01') . " - " . date('Y-m-t');
  $_SESSION["daterange"] = date('Y-m-d', strtotime('-2 days')) . " - " . date('Y-m-d');
}


if (isset($_SESSION["total_games"])) {
  $total_games = $_SESSION["total_games"];
} else {
  $total_games = 0;
  $_SESSION["total_games"] = 0;
  //print "total_games=$total_games";
}


$search_team = 0;
$twovtwo = 0;
if (isset($_GET["cost_min"])) {
  $cost_min = $_GET["cost_min"];
  $_SESSION["cost_min"] = $cost_min;
  $cost_max = $_GET["cost_max"];
  $_SESSION["cost_max"] = $cost_max;
} elseif (isset($_POST["search_team"])) {

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
}
// 取得當前的日期範圍
$current_daterange = $_SESSION["daterange"];
//print "combined=$combined";
//print "total_games=$total_games";
list($start_date, $end_date) = explode(" - ", $current_daterange);
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
  $current_daterange = $_SESSION["daterange"];
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
} /* else {
  // 3) fallback：原本的 bp_range switch 會設定 $bp_min/$bp_max
  $bp_range = $_GET["bp_range"] ?? $_SESSION["bp_range"] ?? 'all';
  //print $bp_range;
  // 更新 SESSION
  $_SESSION["bp_range"] = $bp_range;

  // 根據選擇設定範圍
  switch ($bp_range) {
    case '~1399':
      $bp_min = 0;
      $bp_max = 1399;
      break;
    case '14xx':
      $bp_min = 1400;
      $bp_max = 1499;
      break;
    case '15xx':
      $bp_min = 1500;
      $bp_max = 1599;
      break;
    case '16xx':
      $bp_min = 1600;
      $bp_max = 1699;
      break;
    case '17xx':
      $bp_min = 1700;
      $bp_max = 1799;
      break;
    case '1800~':
      $bp_min = 1800;
      $bp_max = 9999;
      break;
    case 'all':
    default:
      $bp_min = 1500;
      $bp_max = 2000;
      break;
  }
} */

$player_search = '';
// 優先使用 GET 提交的值（如搜尋欄位送出）
if (isset($_GET['player_search']) && $_GET['player_search'] != '') {
  $player_search = trim($_GET['player_search']);
  $_SESSION["player_search"] = $player_search;
  $cost_min = 0;
  $cost_max = 140;
  $bp_min = 1000;
  $bp_max = 2000;
  $_SESSION["cost_max"] = $cost_max;
  $_SESSION["cost_min"] = $cost_min;
  $_SESSION['bp_min'] = $bp_min;
  $_SESSION['bp_max'] = $bp_max;
  $combined = 2;
  /* $_SESSION["combined"]=$combined; */
  //print '$_GET[player_search]';
}
// 若沒有 GET，但 Session 中已有保存過的搜尋條件
elseif (isset($_SESSION["player_search"])) {
  $player_search = $_SESSION["player_search"];
}

$srch_player = 0;
if (isset($player_search)) {
  $srch_player = 1;
}


// 先取得當前選到哪個範圍
//$current_bp_range = $bp_range ?? 'all'; // 預設是 all

/* print "cost_min=$cost_min";
print "cost_max=$cost_max";
print "bp_min=$bp_min";
print "bp_max=$bp_max"; */
//print "combined=$combined";

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

  <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> -->


  <!-- bootstrap slider -->
  <link rel="stylesheet" href="plugins/bootstrap-slider/slider.css">

  <!-- Google Font -->
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic">

  <!-- 統計拜訪人數 -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    /* 按鈕樣式 */
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

    /*======================
 1. 卡片容器（Cards Container）
======================*/
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

    /*======================
 2. 翻轉容器基底（Flip Container Base）
======================*/
    .flip-container {
      perspective: 800px;
      width: 97px;
      height: 65px;
      overflow: hidden;
      position: relative;
      display: inline-block;
      border-radius: 5px;
    }

    .flip-container:hover {
      overflow: visible;
      z-index: 10;
    }

    /*======================
    3. 翻轉動畫（Flipper）
    ======================*/
    .flipper {
      transform-style: preserve-3d;
      transition: transform 0.6s;
      width: 100px;
      height: 100px;
      position: relative;
    }

    .flip-container.flipped {
      overflow: visible !important;
      /* 讓背面圖片不被 clip */
      z-index: 9999 !important;
      /* 提到最上層 */
    }

    /* 點擊後 class="flipped" 生效： */
    .flip-container.flipped .flipper {
      transform: rotateY(180deg);
    }


    /* （可選）加個手勢提示 */
    /* .flip-container {
      cursor: pointer;
    }
 */
    /*======================
    4. 正/反面樣式（Front & Back）
    ======================*/
    .front,
    .back {
      backface-visibility: hidden;
      position: absolute;
      width: 100%;
      height: 100%;
    }

    /*======================
 5. 圖片尺寸與裁切（Images in Front/Back）
======================*/
    .flip-container .front img,
    .flip-container .back img {
      width: 75px;
      height: 108px;
      object-fit: cover;
      object-position: top left;
      transition: transform 0.3s ease;
      border-radius: 5px;
    }

    /*======================
 6. 滑入放大（Hover Scale）
======================*/
    .flip-container:hover .front img,
    .flip-container:hover .back img {
      transform: scale(1.6);
    }

    /*======================
 7. 反面定位與翻轉放大（Back Position & Flipped Back Scale）
======================*/
    .back {
      transform: rotateY(180deg);
      z-index: 11;
      /* top: -40px;
      left: 30px; */
    }

    .flip-container.flipped .back img {
      transform: scale(2.1);
    }

    /*======================
 8. 縮放效果（Zoom Rank）
======================*/
    .zoomout_rank {
      transition: all 0.2s linear;
    }

    .zoomout_rank:hover {
      transform: scale(2);
      transition: all 0.2s linear;
    }

    /*======================
 9. 圖片彈性排版（Image Flex）
======================*/
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

    .bp-group {
      padding: 10px 17px;
    }

    .highlight {
      background-color: yellow;
      font-weight: bold;
    }

    .character-col {
      min-width: 91px;
    }

    .btn {
      margin: 0px 2px;
    }

    .navbar {
      position: sticky !important;
    }

    .navbar-nav>li>.dropdown-menu {
      margin-top: 13px;
    }

    .slider.slider-horizontal .slider-tick-label-container .slider-tick-label {
      padding-top: 20px;
    }



    label {
      margin-bottom: 0px;
    }

    .slider {
      max-width: 330px;
    }

    table.table-bordered.dataTable td {
      padding: 3px
    }

    .quickSelect {
      font-size: 18px;
      padding: 0px 10px;
    }

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
      /* ← 保留這行 */
      z-index: 9999;
      opacity: 0;
      transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
    }

    .flip-container:hover .click-indicator {
      opacity: 1;
      transform: translateX(-50%) scale(1);
      /* hover 時還原大小 */
    }

    .click-indicator img {
      width: 255px !important;
      height: auto;
      display: block;
      transform: scale(0.4) !important;
    }

    #visitorsChart {
      width: 200px !important;
      height: 120px !important;
    }

    .hot-search,
    .hot-char {
      text-decoration: underline;
      color: #337ab7;
      /* Bootstrap link color */
      cursor: pointer;
      transition: 0.15s ease;
    }

    .hot-search:hover,
    .hot-char:hover {
      color: #23527c;
      text-decoration: underline;
      transform: translateY(-1px);
    }

    .sidebar-dim {
      opacity: 0.55;
      /* 淡化所有元素 */
      filter: grayscale(30%);
      /* 降低色彩飽和度 */
      transition: 0.3s;
    }

    .sidebar-dim:hover {
      opacity: 0.95;
      /* 滑過恢復可讀性 */
      filter: grayscale(0%);
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
  <?php if (!empty($_SESSION["warning"])): ?>
    <div class="alert alert-warning">
      <?php echo $_SESSION["warning"];
      unset($_SESSION["warning"]); ?>
    </div>
  <?php endif; ?>
  <div class="row" style="margin:auto 20px">
    <div class="col-md-offset-2 col-md-8">
      <div class="box">
        <div class="box-header">
          <!-- <p class="text-danger fw-bold">
            ※網站聲明：本網站資料皆為「延後一小時」揭露，僅供資料分析使用，並不涉及即時遊戲內未公開資訊。
          </p> -->
          <div class="container-fluid my-3">
            <form method="GET" action="#" class="row g-3 align-items-center" style="margin: 0px -30px;">
              <div class="row" style="margin:auto 20px">
                <div class="col-md-6">
                  <!-- 時間選取 -->
                  <div class="input-group">
                    <button type="button" class="btn btn-outline-secondary" id="daterange-btn">
                      <i class="fa fa-calendar"></i> 時間選取 <i class="fa fa-caret-down"></i>
                    </button>
                    <input type="hidden" name="daterange" id="daterange" value="<?php echo $current_daterange; ?>">
                    <button type="submit" name="set_time" value="1" class="btn btn-primary">提交</button>
                  </div>
                  <!-- 時間選取 end  -->

                  <!-- BP & COST區間選擇 -->
                  <div>
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
                        echo "快速選擇:<br>";

                        // 第一行 51~60
                        for ($i = 51; $i <= 60; $i++) {
                          echo '<a class="quickSelect" href="?cost=' . $i . '">' . $i . '</a>';
                          if ($i < 60) echo '/';
                        }
                        echo '<br>';

                        // 第二行 61~70
                        for ($i = 61; $i <= 70; $i++) {
                          echo '<a class="quickSelect" href="?cost=' . $i . '">' . $i . '</a>';
                          if ($i < 70) echo '/';
                        }
                        echo '<br>';

                        // 第三行 71~80
                        for ($i = 71; $i <= 80; $i++) {
                          echo '<a class="quickSelect" href="?cost=' . $i . '">' . $i . '</a>';
                          if ($i < 80) echo '/';
                        }
                        echo '<br>';

                        // 最後一行 90+
                        echo '<a class="quickSelect" href="?cost=90%2B">90+</a>';
                        ?>
                      </div>
                      <label><br>COST 區間：<span id="cost-display"><?php echo $cost_min ?> - <?php echo $cost_max ?></span></label>
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

                  <!-- BP & COST區間選擇 end -->
                </div>

                <div class="col-md-6">
                  <!-- 組合/玩家 切換 -->
                  <div style="margin: 5px 0px;">
                    <button type="submit" name="combined" value="0" class="btn btn-success">依組合</button>
                    <button type="submit" name="combined" value="2" class="btn btn-default">依玩家</button>
                    <?php
                    printf(
                      '<button type="submit" name="combined" value="2" class="btn btn-info"
                        onclick="document.getElementById(\'player_search\').value=\'%s\';">
                        個人戰績
                    </button>',
                      addslashes($username)
                    );
                    ?>
                  </div>
                  <!-- 組合/玩家 切換 end -->
                  <!-- 輸入玩家名稱查詢 -->
                  <div class="input-group">
                    <input type="text" name="player_search" id="player_search" class="form-control" placeholder="輸入玩家名稱查詢" value="<?php echo htmlspecialchars($player_search ?? ''); ?>">
                    <div class="input-group-btn">
                      <button type="button" class="btn btn-outline-secondary" id="clearSearch" style="display: none;">✖</button>
                      <button type="submit" class="btn btn-outline-primary">搜尋</button>
                      <button type="submit" name="reset" value="1" class="btn btn-warning">重置</button>
                    </div>
                  </div>
                  <!-- 輸入玩家名稱查詢 end -->
                </div>
              </div>
              <!-- row end   -->
            </form>
          </div>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
          <table id="example2" class="table table-bordered table-striped">
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
                  //$orderation = 5;
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
              if ($permission == 2) {
                //$limit = 200;
                $time_range = 0;
              } else {
                //$limit = 200;
                $time_range = 0;
              }


              if ($player_search !== '') {
                //print"here";
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
                //// 確保日期格式正確，避免 SQL 錯誤
                if ($search_team) { //cost計算機跳轉
                  if ($combined) {
                    $sql = "SELECT * FROM leway_db.best_combined_unlight WHERE (char1='$id1' and char2='$id2') and (update_time BETWEEN '$start_date' AND '$end_date')AND (update_time < (NOW() - INTERVAL $time_range MINUTE))  LIMIT $limit;";
                  } elseif ($twovtwo == 1) {
                    $sql = "SELECT * FROM leway_db.best_3v3_teams_unlight WHERE ((char1='$id1' and char2='$id2') or (char1='$id1' and char3='$id2')or(char2='$id1' and char3='$id2'))and (update_time BETWEEN '$start_date' AND '$end_date')AND (update_time < (NOW() - INTERVAL $time_range MINUTE))  LIMIT $limit;";
                  } else {
                    $sql = "SELECT * FROM leway_db.best_3v3_teams_unlight WHERE (char1='$id1' and char2='$id2' and char3='$id3') and (update_time BETWEEN '$start_date' AND '$end_date')AND (update_time < (NOW() - INTERVAL $time_range MINUTE))  LIMIT $limit;";
                  }
                } elseif ($combined == 2) { //玩家BP統計
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
              if ($player_search == '') {

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



              // 執行
              $stmt->execute();
              $character_info = []; // ✅ 新增這個
              $columns = []; // 收集所有結果
              $final_rows = [];
              $i = 1; // 從 1 開始，等一下列出編號

              // 主查詢迴圈
              while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                // 解構資料
                $player_name = '';
                $ids = []; // 初始化

                if ($combined == 2) { // 玩家搜尋
                  $e1 = $row[0];
                  $e2 = $row[1];
                  $e3 = $row[2];
                  $win = $row[3];
                  $lose = $row[4];
                  $bp = $row[5];
                  $player_name = $row[6];
                  $update_time = $row[7];
                  $cost_sum = $row[8];
                  $win_rate = round($row[9], 0);
                  $rate_color = progress_color($win_rate);

                  $result_win = $row[10];
                  $result_lose = $row[11];
                  $result_tie = $row[12];
                  $opponent_bp = isset($row[13]) ? $row[13] : 1500;  // 若不存在就給預設值 1500


                  $result = ($result_win == 1) ? 1 : (($result_tie == 1) ? 0.5 : 0);
                  $bp_after = round($bp + 32 * ($result - 1 / (1 + pow(10, (($opponent_bp - $bp) / 500)))), 0);
                  //$bp_masked = substr((string)$bp_after, 0, -2) . 'XX';
                  //$bp_trend=($result == 1)?$bp_after.'↑':(($result == 0.5)?$bp_after.'-':$bp_after.'↓');
                  /* $bp_trend = ($bp_after > $bp)
                    ? "<span style='color:green;'><i class='fas fa-arrow-up'></i> $bp_after</span>"
                    : (($bp_after == $bp)
                      ? "<span style='color:orange;'><i class='fas fa-minus'></i> $bp_after</span>"
                      : "<span style='color:red;'><i class='fas fa-arrow-down'></i> $bp_after</span>"); */


                  $ids = array_filter([$e1, $e2, $e3]);
                } elseif ($combined == 0) { // 3人組
                  $e1 = $row[0];
                  $e2 = $row[1];
                  $e3 = $row[2];
                  $win = $row[3];
                  $lose = $row[4];
                  $all = $row[5];
                  $win_rate = round($row[6], 0);
                  $update_time = $row[7];
                  $cost_sum = $row[8];
                  $rate_color = progress_color($win_rate);

                  $ids = array_filter([$e1, $e2, $e3]);
                } /* elseif ($combined == 3) { // 模糊組合
                  $e1 = $row[0] * 10 + 5;
                  $e2 = $row[1] * 10 + 5;
                  $e3 = $row[2] * 10 + 5;
                  $win = $row[3];
                  $lose = $row[4];
                  $all = $row[5];
                  $win_rate = round($row[6], 0);
                  $update_time = $row[7];
                  $cost = $row[8];
                  $rate_color = progress_color($win_rate);

                  $ids = array_filter([$e1, $e2, $e3]);
                } */

                // ✅ 確保 ids 不會空白，組成 SQL
                if (!empty($ids)) {
                  $id_list = implode("','", $ids);
                  $sql2 = "SELECT ico, cost, name, level, ico_back, id 
                  FROM leway_db.unlight 
                  WHERE id IN ('$id_list') 
                  ORDER BY FIELD(id, '$id_list')";
                } else {
                  $sql2 = ''; // 如果真的都沒有角色，就避免出錯
                }

                $char_ids = "[$e1][$e2]";
                if (!empty($e3)) {
                  $char_ids .= "[$e3]";
                }

                // 第二段查詢（角色圖＋COST）
                $arr2 = $db->query($sql2);

                $ico_arr = '';
                $name_arr = '';
                //$cost_sum = 0;
                $cost_arr = [];

                while ($row2 = $arr2->fetch(PDO::FETCH_NUM)) {
                  $ico = $row2[0];
                  $ico_front = 'uploads/' . $row2[0];
                  $cost = $row2[1];
                  $name = $row2[2];
                  $level = $row2[3];
                  $ico_back = !empty($row2[4]) ? 'uploads/' . $row2[4] : '';
                  $id = $row2[5];
                  if ($combined == 3) { //模糊統計
                    $name_arr .= $name . "<br>";
                  } else {

                    $name_arr .= $level . $name . "<br>";
                  }
                  // 收錄角色資訊到陣列
                  $character_info[$id] = [
                    'ico' => $ico,
                    'ico_back' => $ico_back
                  ];

                  /* if (!empty($ico_back)) {
                    $ico_arr .= "
                      <div class='flip-container' onclick='this.classList.toggle(\"flipped\");'>
                          <div class='flipper'>
                              <div class='front'>
                                  <img class='zoomout_rank' src='$ico_front' loading='lazy' style='height:110px'>
                              </div>
                              <div class='back'>
                                  <img class='zoomout_rank' src='$ico_back' loading='lazy' style='height:110px'>
                              </div>
                          </div>
                      </div>";
                  } else {
                    $ico_arr .= "<img class='zoomout_rank' src='$ico_front' loading='lazy' style='height:110px'>";
                  } */
                  $ico_arr .= "[$id]";

                  //$cost_sum += $cost;
                  $cost_arr[] = $cost;
                }

                if (count($cost_arr) >= 3) {
                  $punishment = cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
                  //$cost_sum += $punishment;
                } else {
                  $punishment = 0;
                }

                $cost_color = ($punishment > 0) ? 'text-danger' : '';

                if ($cost_sum >= $cost_min && $cost_sum <= $cost_max) {

                  $total_games = $win + $lose;
                  //$log_base = ($combined == 1) ? 70 : 70;

                  $games_factor = calcPerformanceByTotalGames($total_games, $win_rate / 100, $d);
                  $display_factor = number_format($games_factor, 1);
                  $rating_color = progress_color($games_factor * 5);
                  //$showDate = date('m-d H:i', strtotime($update_time));
                  if ($combined == 2) { //玩家BP排序
                    // 1. 先拿出 P1 / P2 的名字

                    $show_private1 = getShowPrivate($db, $player_name);
                    if ($show_private1 || $permission == 2 || $player_name == $username) {
                      $html = "<td class=\"image-flex\">
                              <form action='fight.php' method='POST'>
                                <button class='chk-btn' type='submit' name='check' value='1'>查</button>
                                <input type='hidden' name='e1' value='$e1'>
                                <input type='hidden' name='e2' value='$e2'>
                                <input type='hidden' name='e3' value='$e3'>
                                <input type='hidden' name='player_name' value='$player_name'>
                                <input type='hidden' name='check_rate' value='$win_rate'>
                                <input type='hidden' name='bp_min' value='$bp_min'>
                                <input type='hidden' name='bp_max' value='$bp_max'>
                                <input type='hidden' name='combined' value='$combined'>
                                <input type='hidden' name='srch_player' value='$srch_player'>
                              </form>
                              $ico_arr
                              </td>
                              <td class=\"character-col\">$name_arr</td>
                              <td class='" . ($punishment > 0 ? 'text-danger' : '') . "'>$cost_sum</td>
                              <td><div class='badge bg-$rate_color'>$win_rate%</div></td>
                              <td>$bp_after</td>
                              <td>$player_name</td>
                              <td>$win</td>
                              <td>$lose</td>
                              <td>$total_games</td>";
                    } else {
                      $html = "<td>
                                <form action='fight.php' method='POST'>
                                  <button class='chk-btn' type='submit' name='check' value='1'>查</button>
                                  <input type='hidden' name='player_name' value='$player_name'>
                                  <input type='hidden' name='check_rate' value='$win_rate'>
                                  <input type='hidden' name='bp_min' value='$bp_min'>
                                  <input type='hidden' name='bp_max' value='$bp_max'>
                                  <input type='hidden' name='combined' value='$combined'>
                                  <input type='hidden' name='srch_player' value='$srch_player'>
                                </form>
                              </td>
                                <td>???</td>
                                <td>?</td>
                                <td><div class='badge bg-$rate_color'>$win_rate%</div></td>
                                <td>$bp_after</td>
                                <td>$player_name</td>
                                <td>$win</td>
                                <td>$lose</td>
                                <td>$total_games</td>";
                    }
                  } else { //23人
                    $html = "<td class=\"image-flex\">
                              <form action='fight.php' method='POST'>
                                <button class='chk-btn' type='submit' name='check' value='1'>查</button>
                                <input type='hidden' name='e1' value='$e1'>
                                <input type='hidden' name='e2' value='$e2'>
                                <input type='hidden' name='e3' value='$e3'>
                                <input type='hidden' name='player_name' value='$player_name'>
                                <input type='hidden' name='check_rate' value='$win_rate'>
                                <input type='hidden' name='bp_min' value='$bp_min'>
                                <input type='hidden' name='bp_max' value='$bp_max'>
                                <input type='hidden' name='combined' value='$combined'>
                                <input type='hidden' name='srch_player' value='$srch_player'>
                              </form>
                              $ico_arr
                              </td>
                              <td class=\"character-col\">$name_arr</td>";
                    if ($combined == 3) {
                      $html .= "<td></td>";
                    } else {
                      $html .= "<td class='" . ($punishment > 0 ? 'text-danger' : '') . "'>$cost_sum</td>";
                    }
                    $html .= "<td><div class='badge bg-$rate_color'>$win_rate%</div></td>
                              <td data-order=\"$games_factor\"><div class='badge bg-$rating_color'>$display_factor</div></td>
                              <td>$win</td>
                              <td>$lose</td>
                              <td>$total_games</td>                              
                              ";
                    //<td>$showDate</td>
                    /* $final_rows[] = [
                      'char_ids' => $char_ids,
                      'html' => $html,
                      'sort_key' => ($combined == 2) ? ($bp_after ?? 0) : ($games_factor ?? 0)
                    ]; */
                  }
                  $final_rows[] = [
                    'char_ids' => $char_ids,
                    'html' => $html,
                    'sort_key' => ($combined == 2) ? ($bp_after ?? 0) : ($games_factor ?? 0)
                    /* 'sort_key' => $games_factor ?? 0 */
                  ];
                }

                unset($ico_arr, $name_arr, $cost_arr, $cost_sum);
              }



              // 🔥 按 sort_key 排序
              usort($final_rows, function ($a, $b) {
                return $b['sort_key'] <=> $a['sort_key'];
              });

              // 🔥 輸出
              $i = 1;
              foreach ($final_rows as $row) {
                echo "<tr><td>{$i}</td>";

                // 圖片處理
                preg_match_all('/\[(\d+)\]/', $row['char_ids'], $matches);
                $img_html = '';
                /* if ($combined == 2 && $i > 50) {} */
                $img_html = "<div class='cards'>";
                foreach ($matches[1] as $char_id) {
                  $ico = $character_info[$char_id]['ico'] ?? '';
                  $ico_back = $character_info[$char_id]['ico_back'] ?? '';
                  $ico_front = !empty($ico) ? 'uploads/' . $ico : '';

                  if (!empty($ico_back)) {
                    // 有背面 → render flip-container
                    $img_html .= <<<HTML
                    <div class="flip-container" onclick="this.classList.toggle('flipped');">
                      <div class="flipper">
                        <div class="front">
                          <img src="{$ico_front}" loading="lazy" >
                          <div class="click-indicator">
                            <img src="arrow_icon.png" alt="Click Arrow">
                          </div>
                        </div>
                        <div class="back">
                          <img src="{$ico_back}" loading="lazy" >
                        </div>
                      </div>
                    </div>
                    HTML;
                  } else {
                    // 只有正面
                    $img_html .= <<<HTML
                    <div class="flip-container flippable ">
                      <div class="flipper">
                        <div class="front">
                          <img class="zoomout_rank" src="{$ico_front}" loading="lazy">
                        </div>
                      </div>
                    </div>
                    HTML;
                  }
                }
                $img_html .= "</div>";

                // ⚡ 把原本html裡的 [id][id][id] 替換成生成的img_html
                $html = str_replace($row['char_ids'], $img_html, $row['html']);

                echo $html;
                echo "</tr>";

                $i++;
              }


              ?>
              <!--  <script>
                // ✅ 只在 POPUP 視窗內顯示 按鈕
                if (window.opener) {
                  document.querySelectorAll(".popup-buttons").forEach(function(el) {
                    el.style.display = "block"; // 顯示按鈕
                  });
                } else {
                  document.querySelectorAll(".popup-buttons").forEach(function(el) {
                    el.style.display = "none"; // 隱藏按鈕
                  });
                }

                function selectTeam(team, e1, e2, e3, player_name) {
                  if (window.opener) {
                    window.opener.setSelectedCharacters(team, e1, e2, e3, player_name);
                    window.close(); // ✅ 選擇完成後關閉 POP 視窗
                  }
                }
              </script> -->

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

    <div class="col-md-2 col-sm-12 sidebar-dim">
      <div class="row">
        <!-- 1️⃣ 今日真人訪客 -->
        <div class="col-6 col-md-12 mb-2">
          <div class="small-box bg-aqua">
            <div class="inner">
              <h3>
                <?= $todayVisitors ?>
                <?php if ($todayChangePercent !== null): ?>
                  <small style="font-size:14px;">
                    <?= ($todayChangePercent >= 0 ? '▲' : '▼') . abs($todayChangePercent) . '%' ?>
                  </small>
                <?php endif; ?>
              </h3>
              <p>今日訪客</p>
            </div>
            <div class="icon">
              <i class="fa fa-users"></i>
            </div>
          </div>
        </div>

        <!-- 3️⃣ 今日回訪率 -->
        <!-- <div class="col-6 col-md-12 mb-2">
          <div class="small-box bg-green">
            <div class="inner">
              <h3><?= $todayReturnRate ?>%</h3>
              <p>今日回訪率</p>
              <p style="font-size:11px;margin:0;">
                回訪人數：<?= $todayReturnVisitors ?>
              </p>
            </div>
            <div class="icon">
              <i class="fa fa-refresh"></i>
            </div>
          </div>
        </div> -->

        <!-- 4️⃣ 本月活躍玩家 -->
        <div class="col-6 col-md-12 mb-2">
          <div class="small-box bg-yellow">
            <div class="inner">
              <h3><?= $monthActiveVisitors ?></h3>
              <p>本月活躍玩家</p>
            </div>
            <div class="icon">
              <i class="fa fa-calendar"></i>
            </div>
          </div>
        </div>

        <!-- 5️⃣ 當前在線 -->
        <div class="col-6 col-md-12 mb-2">
          <div class="small-box bg-purple">
            <div class="inner">
              <h3><?= $onlineNow ?></h3>
              <p>目前在線（5 分鐘內）</p>
            </div>
            <div class="icon">
              <i class="fa fa-signal"></i>
            </div>
          </div>
        </div>

        <!-- 🔍 今日熱門搜尋字 Top 5 -->
        <div class="col-6 col-md-12 mb-2">
          <div class="box box-solid box-primary">
            <div class="box-header with-border">
              <h3 class="box-title" style="font-size:14px;">🔍 今日熱門搜尋</h3>
            </div>
            <div class="box-body" style="padding:8px 10px;">
              <?php if (empty($topSearchToday)): ?>
                <span style="font-size:12px;color:#ccc;">今日尚無搜尋紀錄</span>
              <?php else: ?>
                <table class="table table-condensed" style="font-size:12px;margin-bottom:0;">
                  <tbody>
                    <?php foreach ($topSearchToday as $i => $row): ?>
                      <tr>
                        <td style="width:24px;"><?= $i + 1 ?>.</td>

                        <td>
                          <a href="ranking.php?player_search=<?= urlencode($row['search_term']) ?>"
                            class="hot-search"
                            style="pointer-events:auto;"
                            data-term="<?= htmlspecialchars($row['search_term'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($row['search_term'], ENT_QUOTES, 'UTF-8') ?>
                          </a>
                        </td>

                        <td style="width:40px;text-align:right;"><?= (int)$row['cnt'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- 🎴 今日熱門角色頁 Top 5 -->
        <div class="col-6 col-md-12 mb-2">
          <div class="box box-solid box-danger">
            <div class="box-header with-border">
              <h3 class="box-title" style="font-size:14px;">🎴 今日熱門角色</h3>
            </div>
            <div class="box-body" style="padding:8px 10px;">

              <?php if (empty($topChar)): ?>
                <span style="font-size:12px;color:#ccc;">今日尚無角色瀏覽紀錄</span>
              <?php else: ?>
                <table class="table table-condensed" style="font-size:12px;margin-bottom:0;">
                  <tbody>
                    <?php foreach ($topChar as $i => $row): ?>
                      <tr>
                        <td style="width:24px;"><?= $i + 1 ?>.</td>

                        <td>
                          <a href="#"
                            class="hot-char"
                            data-char="<?= htmlspecialchars($row['character_name'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($row['character_name'], ENT_QUOTES) ?>
                          </a>
                        </td>

                        <td style="width:40px;text-align:right;"><?= (int)$row['cnt'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>

            </div>
          </div>
        </div>


        <!-- 📈 最近 7 天真人訪客趨勢 -->
        <div class="col-6 col-md-12 mb-2">
          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title" style="font-size:14px;">最近 7 天訪客趨勢</h3>
            </div>
            <div class="box-body" style="padding:8px 10px;">
              <canvas id="visitorsChart" height="120"></canvas>
            </div>
          </div>
        </div>

      </div>
    </div>





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


  <!-- <script>
// 等 DOM ready 之後
document.querySelectorAll('.flip-container').forEach(el => {
  // 點一下才翻
  el.addEventListener('click', e => {
    e.stopPropagation();
    el.classList.toggle('flipped');
  });

  // 真正滑出整個容器才收回
  el.addEventListener('mouseout', e => {
    // e.relatedTarget 是滑出的那個新目標，若 new target 不在 this 裏面，就移除 flipped
    if (!el.contains(e.relatedTarget)) {
      el.classList.remove('flipped');
    }
  });
});

// 點到畫面其他地方也能收回
document.addEventListener('click', e => {
  if (!e.target.closest('.flip-container')) {
    document.querySelectorAll('.flip-container.flipped')
            .forEach(el => el.classList.remove('flipped'));
  }
});
</script> -->






  <!-- page script -->
  <script>
    $(document).ready(function() {
      const playerSearch = getQueryParam("character");

      const table2 = $('#example2').DataTable({
        paging: true,
        lengthChange: true,
        pageLength: 10, // 預設每頁顯示 7 筆
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,

        order: [
          [<?php echo (int)$orderation ?>, 'desc']
        ],
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





    //Date range picker
    $(document).ready(function() {
      // 檢查 localStorage 是否已儲存上次選擇的日期範圍
      let sessionRange = "<?php echo $current_daterange; ?>";

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
    document.addEventListener('DOMContentLoaded', () => {
      const stats = {
        today: <?php echo $todayVisitors; ?>,
        yesterday: <?php echo $yesterdayVisitors; ?>,
        month: <?php echo $monthVisitors; ?>,
        last7days: <?= json_encode($last7days, JSON_UNESCAPED_UNICODE) ?>,
      };
      console.log(stats);
      console.log(stats.last7days);
      console.log('labels:', stats.last7days);
      console.log('dataArr:', stats.last7days);
      const arr = stats.last7days;
      if (!Array.isArray(arr)) {
        console.error('last7days 不是陣列！', arr);
        return;
      }
      const labels = arr.map(e => e.date);
      // 填入小卡
      document.getElementById('todayVisitors').textContent = stats.today;
      document.getElementById('yesterdayVisitors').textContent = stats.yesterday;
      document.getElementById('monthVisitors').textContent = stats.month;

      // 繪製折線圖
      const ctx = document.getElementById('visitorsChart').getContext('2d');
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: stats.last7days.map(e => e.date),
          datasets: [{
            label: '訪客數',
            data: stats.last7days.map(e => e.count),
            fill: false,
            tension: 0.3,
          }]
        },
        options: {
          responsive: false, // ← 關掉自動縮放
          maintainAspectRatio: true,
          scales: {
            y: {
              beginAtZero: true
            }
          },
          plugins: {
            legend: {
              display: false
            }
          }
        }
      });

    });
  </script>

  <script>
    const visitors7Labels = <?= json_encode($visitors7LabelsJS, JSON_UNESCAPED_UNICODE) ?>;
    const visitors7Counts = <?= json_encode($visitors7CountsJS, JSON_UNESCAPED_UNICODE) ?>;

    (function() {
      const ctx = document.getElementById('visitorsChart');
      if (!ctx || typeof Chart === 'undefined') return;

      new Chart(ctx, {
        type: 'line',
        data: {
          labels: visitors7Labels,
          datasets: [{
            label: '訪客',
            data: visitors7Counts,
            borderColor: '#4caf50',
            backgroundColor: 'rgba(76,175,80,0.2)',
            fill: true,
            tension: 0.25,
            pointRadius: 3,
            pointHitRadius: 8
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            x: {
              ticks: {
                color: '#b0bec5',
                font: {
                  size: 10
                }
              },
              grid: {
                color: 'rgba(255,255,255,0.05)'
              }
            },
            y: {
              ticks: {
                color: '#b0bec5',
                font: {
                  size: 10
                },
                precision: 0
              },
              grid: {
                color: 'rgba(255,255,255,0.07)'
              }
            }
          }
        }
      });
    })();
  </script>
  <script>
    // 🎴 點擊「熱門角色」
    $(document).on('click', '.hot-char', function(e) {
      e.preventDefault();

      let keyword = $(this).data('char');

      $('#example2_filter input')
        .val(keyword)
        .trigger('keyup');

      $('html, body').animate({
        scrollTop: $('#example2').offset().top - 100
      }, 300);
    });
  </script>



</body>

</html>