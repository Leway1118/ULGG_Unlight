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


// 今天 00:00:00 的時間字串
$todayMidnight = date('Y-m-d') . ' 00:00:00';
// 撈出今天 00:00:00 的排名快照
$stmtPrev = $db->prepare("SELECT name, rank_num, qp
            FROM ranking_qp_TW
          WHERE ts = :midnight");
$stmtPrev->execute([':midnight' => $todayMidnight]);
$prevSnapshot = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
// 建立查表用的陣列
$prevRank = $prevBp = [];
foreach ($prevSnapshot as $p) {
  $prevRank[$p['name']] = (int)$p['rank_num'];
  $prevBp[$p['name']] = (int)$p['qp'];
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
ORDER BY `ts` DESC, `rank_num` ASC LIMIT 700;";

$stmt = $db->query($sql);

if (!$stmt) {
  $err = $db->errorInfo();
  die("查詢失敗：{$err[2]}");
}


?>
<html lang="en">

<head>

  <!-- Tell the browser to be responsive to screen width -->
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <!-- Bootstrap 3.3.7 -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/font-awesome/css/font-awesome.min.css">
  <!-- Ionicons -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/Ionicons/css/ionicons.min.css">
  <!-- daterange picker -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/bootstrap-daterangepicker/daterangepicker.css">
  <!-- bootstrap datepicker -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css">
  <!-- iCheck for checkboxes and radio inputs -->
  <link rel="stylesheet" href="../AdminLTE-master/plugins/iCheck/all.css">
  <!-- Bootstrap Color Picker -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/bootstrap-colorpicker/dist/css/bootstrap-colorpicker.min.css">
  <!-- Bootstrap time Picker -->
  <link rel="stylesheet" href="../AdminLTE-master/plugins/timepicker/bootstrap-timepicker.min.css">
  <!-- Select2 -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/select2/dist/css/select2.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="../AdminLTE-master/dist/css/AdminLTE.min.css">
  <!-- AdminLTE Skins. Choose a skin from the css/skins
       folder instead of downloading all of them to reduce the load. -->
  <link rel="stylesheet" href="../AdminLTE-master/dist/css/skins/_all-skins.min.css">


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
  <div class="row" style="margin:auto 20px">
    <div class="col-md-6">
      <div class="card">
        <div class="card-gradient-header">本季QP排行</div>
        <div class="card-body">
          <table id="example1" class="table">
            <thead>
              <tr>
                <th>排名</th>
                <th>玩家</th>
                <th>等級</th>
                <th>QP</th>
                <th>時間</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 0;
              while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rank_num  = (int)$row['rank_num'];
                $name      = htmlspecialchars($row['name'], ENT_QUOTES);
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

                  // --- QP 差值 ---
                  $beforeQp = $prevBp[$name] ?? null;
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
                    <?= $name ?> <?= $rankChange ?>
                  </td>
                  <td><?= $level ?></td>
                  <td><?= $qp ?> <?= $qpChange ?></td>
                  <td><?= $ts ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="box">
        <div class="box-header">


          <h3 class="box-title">上季QP里程碑:</h3>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
          <div id="example2_wrapper" class="dataTables_wrapper dt-bootstrap4">
            <table id="example2" class="table table-bordered table-striped">
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
        </div>

        <!-- /.box-body -->
      </div>
      <!-- /.box -->
    </div>

    <!-- /.col -->

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


    // 今天 00:00:00 的時間字串
    $todayMidnight = date('Y-m-d') . ' 00:00:00';
    // 撈出今天 00:00:00 的排名快照
    $stmtPrev = $db->prepare("SELECT name, rank_num, qp
            FROM ranking_qp_JP
          WHERE ts = :midnight");
    $stmtPrev->execute([':midnight' => $todayMidnight]);
    $prevSnapshot = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
    // 建立查表用的陣列
    $prevRank = $prevBp = [];
    foreach ($prevSnapshot as $p) {
      $prevRank[$p['name']] = (int)$p['rank_num'];
      $prevBp[$p['name']] = (int)$p['qp'];
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
      ORDER BY `ts` DESC, `rank_num` ASC LIMIT 700;";

    $stmt = $db->query($sql);

    if (!$stmt) {
      $err = $db->errorInfo();
      die("查詢失敗：{$err[2]}");
    }

    ?>

    <div class="col-md-6">
      <div class="card">
        <div class="card-gradient-header">DMM QP排行</div>
        <div class="card-body">
          <table id="exampleJP" class="table">
            <thead>
              <tr>
                <th>排名</th>
                <th>玩家</th>
                <th>等級</th>
                <th>QP</th>
                <th>時間</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 0;
              while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rank_num  = (int)$row['rank_num'];
                $name      = htmlspecialchars($row['name'], ENT_QUOTES);
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


                  // --- QP 差值 ---
                  $beforeQp = $prevBp[$name] ?? null;
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
                    <?= $name ?> <?= $rankChange ?>
                  </td>
                  <td><?= $level ?></td>
                  <td><?= $qp ?> <?= $qpChange ?></td>
                  <td><?= $ts ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>
      <!-- /.card -->
      <div class="box">
        <div class="box-header">
          <h3 class="box-title">上季DMM QP里程碑:</h3>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
          <div id="example3_wrapper" class="dataTables_wrapper dt-bootstrap4">
            <table id="example3" class="table table-bordered table-striped">
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
  <script src="../AdminLTE-master/bower_components/jquery/dist/jquery.min.js"></script>
  <!-- Bootstrap 3.3.7 -->
  <script src="../AdminLTE-master/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>

  <!-- DataTables -->
  <script src="../AdminLTE-master/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
  <script src="../AdminLTE-master/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>
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
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css" />

  <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

  <!-- SlimScroll -->
  <script src="../AdminLTE-master/bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
  <!-- FastClick -->
  <script src="../AdminLTE-master/bower_components/fastclick/lib/fastclick.js"></script>
  <!-- AdminLTE App -->
  <script src="../AdminLTE-master/dist/js/adminlte.min.js"></script>
  <!-- AdminLTE for demo purposes -->
  <script src="../AdminLTE-master/dist/js/demo.js"></script>

  <!-- date-range-picker -->
  <script src="../AdminLTE-master/bower_components/moment/min/moment.min.js"></script>
  <script src="../AdminLTE-master/bower_components/bootstrap-daterangepicker/daterangepicker.js"></script>

  <!-- AdminLTE App -->
  <script src="../AdminLTE-master/dist/js/adminlte.min.js"></script>


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
        paging: true, // 啟用分頁
        pageLength: 100, // 每頁顯示 100 列
        'lengthChange': true,
        'searching': true,
        'ordering': false,
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
        paging: true, // 啟用分頁
        pageLength: 100, // 每頁顯示 100 列
        'lengthChange': true,
        'searching': true,
        'ordering': false,
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

      // 2. DataTable 設定
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

      
    })
  </script>

</body>

</html>