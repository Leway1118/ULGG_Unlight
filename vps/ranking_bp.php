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
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}



//$ack = $_SESSION["ack"] ?? 0;
$permission = $_SESSION["ack"] ?? 0;

// -----------------------------------------------------------
//  TW / JP 每月榜單匯入工具：初始化變數
// -----------------------------------------------------------
$dataPath = __DIR__ . "/steam/data";

// 安全初始化
$tw_updated_bp  = "";
$tw_updated_qp  = "";
$tw_last_import = "";

// --- 將 UTC 時間字串轉換成本地台灣時間 ---
function formatTWTime($utcString)
{
  if (empty($utcString)) return "（無資料）";

  try {
    $dt = new DateTime($utcString, new DateTimeZone("UTC"));
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
    $tw_updated_bp = $json["updatedAt"] ?? "";
  }
}

if (file_exists($tw_qp_file)) {
  $json = json_decode(file_get_contents($tw_qp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $tw_updated_qp = $json["updatedAt"] ?? "";
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

// 取得「當日第一筆（最早）快照」的 ts
$todayDate = date('Y-m-d');
$stmtFirstTs = $db->prepare("
  SELECT MIN(ts) AS first_ts
  FROM ranking_bp_TW
  WHERE ts >= :start AND ts < DATE_ADD(:start, INTERVAL 1 DAY)
");
$stmtFirstTs->execute([':start' => $todayDate . ' 00:00:00']);
$baselineTs = $stmtFirstTs->fetchColumn();

// 撈出「當日第一筆」的排名快照（若不存在則留空，後面會自動跳過差異計算）
$prevSnapshot = [];
if ($baselineTs) {
  $stmtPrev = $db->prepare("
    SELECT name, rank_num, bp
    FROM ranking_bp_TW
    WHERE ts = :ts
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
try {
  $stmt = $db->query("SELECT MAX(`ts`) FROM `ranking_bp_TW`");
  $lastUpdate = $stmt->fetchColumn();
} catch (PDOException $e) {
  $lastUpdate = null;
}

$sql = "SELECT r.rank_num, r.name, r.bp, r.win_ranked, r.lose_ranked, r.ts,
                u.black_list
          FROM ranking_bp_TW AS r
          LEFT JOIN game_user AS u
            ON r.name = u.username
            WHERE r.ts = (
            SELECT MAX(ts) FROM ranking_bp_TW
          )
          ORDER BY r.rank_num DESC
        ";
$stmt = $db->query($sql);

if (!$stmt) {
  $err = $db->errorInfo();
  die("查詢失敗：{$err[2]}");
}


// ------------------------------------------------------------
// ⭐ 在這裡放匯入 TW / JP 的 PHP 功能（最安全）
// ------------------------------------------------------------

/* 匯入程式開始 */
if (isset($_POST['import_tw'])) {

  $cmd = "python3 /var/www/unlight_project/steam/data/import_bpqp_TW_his.py 2>&1";
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

  $cmd = "python3 /var/www/unlight_project/steam/data/import_bpqp_JP_his.py 2>&1";
  $output = shell_exec($cmd);

  echo "<pre style='background:#111;color:#0f0;padding:10px;'>
========= 🇯🇵 JP 匯入結果 =========
$output
================================
</pre>";

  echo "<script>alert('🇯🇵 JP BP/QP 歷史已成功匯入！');</script>";
  //echo "<meta http-equiv='refresh' content='1'>";
}
/* 匯入程式結束 */
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


  <!-- Google Font -->
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic">

  <style>
    /* 回到頂部按鈕 */
    #backToTop,
    #goToBottom {
      position: fixed;
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

    /* 回到頂部按鈕 (▲) */
    #backToTop {
      bottom: 90px;
    }

    /* 滾到底部按鈕 (▼) */
    #goToBottom {
      bottom: 30px;
    }

    #backToTop:hover,
    #goToBottom:hover {
      background-color: #0056b3;
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


    .flip-container {
      perspective: 1000px;
      display: inline-block;
      position: relative;
      /*  */
    }

    .flipper {
      position: relative;
      width: 70px;
      height: 110px;
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

    /* 1. 卡片漸層標題 */
    .card-gradient-header {
      background: linear-gradient(45deg, #36D1DC, #5B86E5);
      color: #fff;
      padding: .75rem 1.25rem;
      font-size: 1.25rem;
      font-weight: 600;
      border-top-left-radius: .25rem;
      border-top-right-radius: .25rem;
    }

    /* 2. 表格：去掉外框，改用內邊框，並顏色分段 */
    .card-body table {
      border-collapse: separate;
      border-spacing: 0 0.5rem;
    }

    .card-body table thead th {
      background: #f4f6f9;
      color: #333;
      border: none;
    }

    .card-body table tbody tr {
      background: #fff;
      transition: background .2s;
    }

    .card-body table tbody tr:hover {
      background: rgba(91, 134, 229, 0.15);
    }

    .card-body table td,
    .card-body table th {
      border: none;
      vertical-align: middle;
      padding: .75rem 1rem;
    }

    /* 3. 彩色 Badge */
    .badge-up {
      background-color: #28a745;
    }

    /* 綠 */
    .badge-new {
      background-color: #17a2b8;
    }

    /* 淺藍 */
    .badge-top {
      background-color: #ffc107;
      color: #212529;
    }

    /* 黃 */
    .badge-down {
      background-color: #dc3545;
    }

    /* 紅 */

    /* 4. 小字體 */
    .text-sm {
      font-size: .85rem;
    }

    td {
      height: 23px;
    }

    .blacklist-name {
      background-color: #000;
      /* 黑底 */
      color: yellow !important;
      /* 白字，!important 確保蓋掉 a 的預設色 */
      padding: 2px 6px;
      /* 你可以再微調 */
      border-radius: 4px;
      text-decoration: none;
      /* 去掉底線 */
      display: inline-block;
    }
  </style>
</head>

<body>
  <div class="navbar">
    <?php
    print $head_bar;
    ?>
  </div>
  <!-- Go To Top Button -->
  <button id="backToTop" onclick="scrollToTop()">▲</button>

  <!-- Go To Bottom Button -->
  <button id="goToBottom" onclick="scrollToBottom()">▼</button>

  <script>
    // 監聽滾動事件，決定是否顯示按鈕
    window.onscroll = function() {
      let topButton = document.getElementById("backToTop");
      let bottomButton = document.getElementById("goToBottom");
      let scrollTop = document.documentElement.scrollTop;
      let scrollHeight = document.documentElement.scrollHeight;
      let clientHeight = document.documentElement.clientHeight;

      if (scrollTop > 200) {
        topButton.style.display = "flex"; // 顯示回到頂部按鈕
      } else {
        topButton.style.display = "none"; // 隱藏按鈕
      }

      if (scrollTop + clientHeight < scrollHeight - 200) {
        bottomButton.style.display = "flex"; // 顯示滾到底部按鈕
      } else {
        bottomButton.style.display = "none"; // 隱藏按鈕
      }
    };

    // 點擊按鈕回到頂部
    function scrollToTop() {
      window.scrollTo({
        top: 0,
        behavior: "smooth" // 平滑滾動效果
      });
    }

    // 點擊按鈕滾到底部
    function scrollToBottom() {
      window.scrollTo({
        top: document.documentElement.scrollHeight,
        behavior: "smooth" // 平滑滾動效果
      });
    }
  </script>

  <div class="row" style="margin:auto 5px">
    <div class="col-md-6">
      <?php if ($permission == 2): ?>

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
          data-target="#twImportPanel" style="margin-bottom:10px;">
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


      <div class="card">
        <div class="card-gradient-header">本季BP排行</div>
        <div class="card-body">
          <table id="example1" class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>名稱</th>
                <th>BP</th>
                <th>勝</th>
                <th>敗</th>
                <th>總計</th>
                <th>勝率</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 0;
              while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rank_num  = (int) $row['rank_num'];
                $name      = $row['name'];
                $bp        = (int) $row['bp'];
                $win       = (int) $row['win_ranked'];
                $lose      = (int) $row['lose_ranked'];
                $total     = $win + $lose;
                $rate      = $total > 0 ? round($win / $total * 100, 1) . '%' : '0%';
                // 計算名次變化

                // 把時間字串取出時:分

                if (!empty($prevRank)) {
                  // 找得到當日第一筆 → 計算與 baseline 差異（台服用台服對照表）
                  $beforeRank = $prevRank[$name] ?? null;

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

                  $beforeBp = $prevBp[$name] ?? null;
                  if ($beforeBp !== null) {
                    $deltaBp = $bp - $beforeBp;
                    $sign    = $deltaBp >= 0 ? '+' : '';
                    $bpChange = "<small class=\"text-sm\">{$sign}{$deltaBp}</small>";
                  } else {
                    $bpChange = '';
                  }
                } else {
                  // 當日完全沒有 baseline → 不顯示差異
                  $rankChange = '';
                  $bpChange   = '';
                }




                // 產生連結
                $link = "ranking.php?player_search="
                  . urlencode($name)
                  . "&cost_min=20&cost_max=100&bpMin=1000&bpMax=2000&combined=2";

                // 如果黑名單，把 class 換成 blacklist-name，否則就 player-name 或其他預設
                $nameClass = ((int)$row['black_list'] === 1)
                  ? 'blacklist-name'
                  : 'player-name';   // 或你原本的預設 class

                // 里程碑樣式
                $style = '';
                if (isset($ms_bp[$i]) && $bp >= $ms_bp[$i]) {
                  $style = $ms_style[$i];
                  $i++;
                }
              ?>
                <tr <?= $style ?>>
                  <td><?= $rank_num ?></td>
                  <td>
                    <a href="<?= $link ?>" class="<?= $nameClass ?>">
                      <?= htmlspecialchars($name, ENT_QUOTES) ?>
                    </a>
                    <?= $rankChange ?>
                    <!-- 原本的黑名單小徽章就可以拿掉了 -->
                  </td>
                  <td>
                    <?= $bp ?> <?= $bpChange ?>
                  </td>
                  <td><?= $win ?></td>
                  <td><?= $lose ?></td>
                  <td><?= $total ?></td>
                  <td><?= $rate ?></td>
                </tr>
              <?php } // end while 
              ?>
            </tbody>
          </table>
        </div>
      </div>
      <!-- /.card style="display: none;"-->
      <div class="box">
        <div class="box-header">
          <h3 class="box-title">上季BP里程碑:</h3>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
          <div id="example2_wrapper" class="dataTables_wrapper dt-bootstrap4">
            <table id="example2" class="table table-bordered table-striped">
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
                <?php for ($i = 0; $i < count($ms_rank); $i++): ?>
                  <tr <?= $ms_style[$i] ?>>
                    <td><?= htmlspecialchars($ms_rank[$i]) ?></td>
                    <td><?= htmlspecialchars($ms_bp[$i]) ?></td>
                    <td><?= htmlspecialchars($ms_ts[$i]) ?></td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>
        <!-- /.box-body -->
      </div>
      <!-- /.box -->
    </div>
    <!-- /.col -->



    <div class="col-md-6">
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
      // 撈出 DMM「當日第一筆（最早）快照」
      $stmtFirstTsJP = $db->prepare("
      SELECT MIN(ts) AS first_ts
      FROM ranking_bp_JP
      WHERE ts >= :start AND ts < DATE_ADD(:start, INTERVAL 1 DAY)
    ");
      $stmtFirstTsJP->execute([':start' => $todayDate . ' 00:00:00']);
      $baselineTsJP = $stmtFirstTsJP->fetchColumn();

      $prevSnapshotJP = [];
      if ($baselineTsJP) {
        $stmtPrev = $db->prepare("
      SELECT name, rank_num, bp
      FROM ranking_bp_JP
      WHERE ts = :ts
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
      try {
        $stmt = $db->query("SELECT MAX(`ts`) FROM `ranking_bp_JP`");
        $lastUpdate_JP = $stmt->fetchColumn();
      } catch (PDOException $e) {
        $lastUpdate_JP = null;
      }

      $sql = "SELECT r.rank_num, r.name, r.bp, r.win_ranked, r.lose_ranked, r.ts,
                u.black_list
          FROM ranking_bp_JP AS r
          LEFT JOIN game_user AS u
            ON r.name = u.username
            WHERE r.ts = (
            SELECT MAX(ts) FROM ranking_bp_JP
          )
          ORDER BY r.rank_num DESC
        ";
      $stmt = $db->query($sql);

      if (!$stmt) {
        $err = $db->errorInfo();
        die("查詢失敗：{$err[2]}");
      }
      if ($permission == 2): 
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
          data-target="#jpImportPanel" style="margin-bottom:10px;">
          🇯🇵 JP 每月榜單匯入工具 ▼
        </button>

        <!-- ▼ 展開內容 -->
        <div id="jpImportPanel" class="collapse">
          <div class="alert alert-secondary" style="padding:10px; margin-bottom:10px;">
            <h5>🇯🇵 JP 每月榜單匯入工具</h5>

            <div><strong>JSON 最新更新時間 / 最後匯入時間：</strong></div>

            <div>BP：<?= htmlspecialchars($jp_bp_local) ?> / <?= htmlspecialchars($jp_last_import_bp) ?></div>
            <div>QP：<?= htmlspecialchars($jp_qp_local) ?> / <?= htmlspecialchars($jp_last_import_qp) ?></div>

            <form method="post" style="margin-top:10px;">
              <button class="btn btn-info btn-sm" name="import_jp" value="1"
                onclick="return confirm('確定要匯入 JP BP/QP 歷史嗎？');">
                🇯🇵 一鍵匯入 JP（BP + QP）
              </button>
            </form>
          </div>
        </div>

      <?php endif; ?>



      <div class="card">
        <div class="card-gradient-header">DMM BP排行</div>
        <div class="card-body">
          <table id="exampleJP" class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>名稱</th>
                <th>BP</th>
                <th>勝</th>
                <th>敗</th>
                <th>總計</th>
                <th>勝率</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 0;
              while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rank_num  = (int) $row['rank_num'];
                $name      = $row['name'];
                $bp        = (int) $row['bp'];
                $win       = (int) $row['win_ranked'];
                $lose      = (int) $row['lose_ranked'];
                $total     = $win + $lose;
                $rate      = $total > 0 ? round($win / $total * 100, 1) . '%' : '0%';
                // 計算名次變化



                if (!empty($prevRankJP)) {
                  $beforeRank = $prevRankJP[$name] ?? null;
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

                  $beforeBp = $prevBpJP[$name] ?? null;
                  if ($beforeBp !== null) {
                    $deltaBp = $bp - $beforeBp;
                    $sign    = $deltaBp >= 0 ? '+' : '';
                    $bpChange = "<small class=\"text-sm\">{$sign}{$deltaBp}</small>";
                  } else {
                    $bpChange = '';
                  }
                } else {
                  $rankChange = '';
                  $bpChange   = '';
                }



                // 產生連結
                $link = "ranking.php?player_search="
                  . urlencode($name)
                  . "&cost_min=20&cost_max=100&bpMin=1000&bpMax=2000&combined=2";

                // 如果黑名單，把 class 換成 blacklist-name，否則就 player-name 或其他預設
                $nameClass = ((int)$row['black_list'] === 1)
                  ? 'blacklist-name'
                  : 'player-name';   // 或你原本的預設 class

                // 里程碑樣式
                $style = '';
                if (isset($ms_bp_JP[$i]) && $bp >= $ms_bp_JP[$i]) {
                  $style = $ms_style_JP[$i];
                  $i++;
                }
              ?>
                <tr <?= $style ?>>
                  <td><?= $rank_num ?></td>
                  <td>
                    <a href="<?= $link ?>" class="<?= $nameClass ?>">
                      <?= htmlspecialchars($name, ENT_QUOTES) ?>
                    </a>
                    <?= $rankChange ?>
                    <!-- 原本的黑名單小徽章就可以拿掉了 -->
                  </td>
                  <td>
                    <?= $bp ?> <?= $bpChange ?>
                  </td>
                  <td><?= $win ?></td>
                  <td><?= $lose ?></td>
                  <td><?= $total ?></td>
                  <td><?= $rate ?></td>
                </tr>
              <?php } // end while 
              ?>
            </tbody>
          </table>
        </div>
      </div>
      <!-- /.card style="display: none;"-->
      <div class="box">
        <div class="box-header">
          <h3 class="box-title">上季DMM BP里程碑:</h3>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
          <div id="example3_wrapper" class="dataTables_wrapper dt-bootstrap4">
            <table id="example3" class="table table-bordered table-striped">
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
                <?php for ($i = 0; $i < count($ms_rank_JP); $i++): ?>
                  <tr <?= $ms_style_JP[$i] ?>>
                    <td><?= htmlspecialchars($ms_rank_JP[$i]) ?></td>
                    <td><?= htmlspecialchars($ms_bp_JP[$i]) ?></td>
                    <td><?= htmlspecialchars($ms_ts_JP[$i]) ?></td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>
        <!-- /.box-body -->
      </div>
      <!-- /.box -->
    </div>
    <!-- /.col -->
  </div>

  <?php
  // 3. 清理
  $stmt = null;
  $db   = null;
  ?>

  <!-- jQuery 3 -->
  <script src="bower_components/jquery/dist/jquery.min.js"></script>
  <!-- Bootstrap 3.3.7 -->
  <script src="bower_components/bootstrap/dist/js/bootstrap.min.js"></script>

  <!-- DataTables -->
  <!-- Buttons extension -->
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css" />
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
  <!-- Excel export 需要 JSZip -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

  <!-- 1. 載入 html2canvas -->
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

  <!-- 2. DataTables + Buttons -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" />

  <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

  <!-- SlimScroll -->
  <script src="bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
  <!-- FastClick -->
  <script src="bower_components/fastclick/lib/fastclick.js"></script>
  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.min.js"></script>

  <!-- date-range-picker -->
  <script src="bower_components/moment/min/moment.min.js"></script>
  <script src="bower_components/bootstrap-daterangepicker/daterangepicker.js"></script>



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
      // 1. 把 PHP 時間傳到 JS
      const lastUpdate = <?= json_encode($lastUpdate) ?>;

      // 2. DataTable 設定
      const dt = $('#example1').DataTable({
        dom: 'Bfrtip',
        buttons: [
          'excelHtml5',
          {
            text: '匯出截圖',
            action: function(e, dt, node, config) {
              // 這裡我們要截 wrapper 而非只有 table
              const wrapper = dt.table().container().parentNode;
              html2canvas(wrapper).then(canvas => {
                canvas.toBlob(blob => {
                  const url = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = url;
                  a.download = 'datatable_screenshot.png';
                  document.body.appendChild(a);
                  a.click();
                  document.body.removeChild(a);
                  URL.revokeObjectURL(url);
                });
              });
            }
          }
        ],
        'paging': false,
        'lengthChange': true,
        'searching': true,
        'ordering': true,
        'info': true,
        'autoWidth': false,
        "order": [
          [0, "asc"]
        ],
        /* "columnDefs": [{
          "targets": 5,
          "orderDataType": "dom-data-order"
        }] */
      });

      // 3. 插入「最後更新」時間到 wrapper 最上方
      const wrapperEl = $('#example1').closest('.dataTables_wrapper');
      if (lastUpdate) {
        const formatted = new Date(lastUpdate).toLocaleString('zh-TW', {
          hour12: false
        });
        wrapperEl.prepend(
          `<div style="text-align:right; margin-bottom:8px; font-size:0.9em; color:#666;">
         最後更新：${formatted}
       </div>`
        );
      }

      // 1. 把 PHP 時間傳到 JS
      const lastUpdate_JP = <?= json_encode($lastUpdate_JP) ?>;
      // 2. JP DataTable 設定
      const dtJP = $('#exampleJP').DataTable({
        dom: 'Bfrtip',
        buttons: [
          'excelHtml5',
          {
            text: '匯出截圖',
            action: function(e, dtJP, node, config) {
              // 這裡我們要截 wrapper 而非只有 table
              const wrapper = dtJP.table().container().parentNode;
              html2canvas(wrapper).then(canvas => {
                canvas.toBlob(blob => {
                  const url = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = url;
                  a.download = 'datatable_screenshot.png';
                  document.body.appendChild(a);
                  a.click();
                  document.body.removeChild(a);
                  URL.revokeObjectURL(url);
                });
              });
            }
          }
        ],
        'paging': false,
        'lengthChange': true,
        'searching': true,
        'ordering': true,
        'info': true,
        'autoWidth': false,
        "order": [
          [0, "asc"]
        ],
        /* "columnDefs": [{
          "targets": 5,
          "orderDataType": "dom-data-order"
        }] */
      });

      // 3. 插入「最後更新」時間到 wrapper 最上方
      const wrapperElJP = $('#exampleJP').closest('.dataTables_wrapper');
      if (lastUpdate_JP) {
        const formatted = new Date(lastUpdate_JP).toLocaleString('zh-TW', {
          hour12: false
        });
        wrapperElJP.prepend(
          `<div style="text-align:right; margin-bottom:8px; font-size:0.9em; color:#666;">
         最後更新：${formatted}
       </div>`
        );
      }

      // 調整搜尋框的寬度
      $('.dataTables_filter input').css({
        'width': '200px', // 設定搜尋框寬度為 300px
        /* 'margin-left': '10px', */ // 增加左側間距
      });


      // 2. 台服每月快照DataTable 設定
      const dt2 = $('#example2').DataTable({
        dom: 'Bfrtip',
        buttons: [
          'excelHtml5',
          {
            text: '匯出截圖',
            action: function(e, dt2, node, config) {
              // 這裡我們要截 wrapper 而非只有 table
              const wrapper = dt2.table().container().parentNode;
              html2canvas(wrapper).then(canvas => {
                canvas.toBlob(blob => {
                  const url = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = url;
                  a.download = 'datatable_screenshot.png';
                  document.body.appendChild(a);
                  a.click();
                  document.body.removeChild(a);
                  URL.revokeObjectURL(url);
                });
              });
            }
          }
        ],
        'paging': false,
        'lengthChange': true,
        'searching': true,
        'ordering': true,
        'info': true,
        'autoWidth': false,
        "order": [
          [0, "asc"]
        ],
        /* "columnDefs": [{
          "targets": 5,
          "orderDataType": "dom-data-order"
        }] */
      });

      // 3. DMM每月快照DataTable 設定
      const dt3 = $('#example3').DataTable({
        dom: 'Bfrtip',
        buttons: [
          'excelHtml5',
          {
            text: '匯出截圖',
            action: function(e, dt3, node, config) {
              // 這裡我們要截 wrapper 而非只有 table
              const wrapper = dt3.table().container().parentNode;
              html2canvas(wrapper).then(canvas => {
                canvas.toBlob(blob => {
                  const url = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = url;
                  a.download = 'datatable_screenshot.png';
                  document.body.appendChild(a);
                  a.click();
                  document.body.removeChild(a);
                  URL.revokeObjectURL(url);
                });
              });
            }
          }
        ],
        'paging': false,
        'lengthChange': true,
        'searching': true,
        'ordering': true,
        'info': true,
        'autoWidth': false,
        "order": [
          [0, "asc"]
        ],
        /* "columnDefs": [{
          "targets": 5,
          "orderDataType": "dom-data-order"
        }] */
      });
    })
  </script>

</body>

</html>