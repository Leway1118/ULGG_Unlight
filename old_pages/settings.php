<!DOCTYPE html>
<?php
require_once('head.php');
$date = date("Y-m-d H:i:s");
//session_start();
/* if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  print '<pre>';
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>';
} */
$msg = '';
// 處理表單
// 3. channel 從 POST 拿
$channel = $_POST['channel'] ?? '';
if (isset($_POST['costSubmit'])) {
  // 1. 讀 datetime-local
  // 根據 channel 選擇正確的 name 欄位
  $selected_datetime = $_POST["month_date_time_$channel"] ?? date('Y-m-d\TH:i');
  $c1 = intval($_POST["area1_$channel"] ?? 0);
  $c2 = intval($_POST["area2_$channel"] ?? 0);
  $c3 = intval($_POST["area3_$channel"] ?? 0);
  $c4 = intval($_POST["area4_$channel"] ?? 0);

  // 4. Upsert SQL
  $sql = <<<SQL
INSERT INTO quickmatch_monthly_cost
  (chennel, month_date, cost1, cost2, cost3, cost4)
VALUES
  (?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE 
  cost1 = VALUES(cost1),
  cost2 = VALUES(cost2),
  cost3 = VALUES(cost3),
  cost4 = VALUES(cost4),
  updated_at = CURRENT_TIMESTAMP
SQL;

  /* print $sql;
  print "channel=$channel"; */
  $stmt = $db->prepare($sql);
  try {
    // ** 一定要 6 個參數，順序對應上面 VALUES(...) **
    $stmt->execute([
      $channel,
      $selected_datetime,
      $c1,
      $c2,
      $c3,
      $c4
    ]);
    $msg = '儲存成功';
  } catch (PDOException $e) {
    $msg = '儲存失敗：' . $e->getMessage();
  }
}


/* // 預設要顯示的日期
$selected_date = $_POST['month_date'] ?? date('Y-m-d');
// 讀既有資料
$stmt = $db->prepare("
    SELECT cost1, cost2, cost3, cost4
      FROM quickmatch_monthly_cost
     WHERE month_date = ?
");
$stmt->execute([$selected_date]);
$row = $stmt->fetch(PDO::FETCH_ASSOC)
  ?: ['cost1' => 0, 'cost2' => 0, 'cost3' => 0, 'cost4' => 0]; */

?>
<html lang="en">

<head>
  <style>
    .large-bold {
      font-size: 32px;
      /* 放大文字 */
      font-weight: bold;
      /* 加粗 */
    }

    td {
      text-align: center;
    }

    th {
      text-align: center;
    }

    td label {
      display: inline-block;
      /* 讓 label 變為區塊元素 */
      width: 33px;
      /* 固定寬度，例如 100px */
      text-align: center;
      /* 讓內容置中 */
      padding: 5px 0;
      /* 讓內容不會太擠 */
    }

    td label:hover {
      transform: scale(1.2);
      transition: all 0.2s linear;
    }

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
      transition: all 0.2s linear;
    }

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

    .flipped .flipper {
      transform: rotateY(180deg);
    }

    .front,
    .back {
      position: absolute;
      width: 100%;
      height: 100%;
      backface-visibility: hidden;
    }

    .front {
      z-index: 2;
    }

    .back {
      transform: rotateY(180deg);
    }
  </style>

</head>
<div class="navbar">
  <?php
  print $head_bar;
  ?>
</div>

<body>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <h1>
        對戰COST設定
        <small>Preview</small>
      </h1>
      <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Info</a></li>
        <li class="active">Settings</li>
      </ol>
    </section>

    <!-- Main content -->
    <section class="content">
      <div class="row">
        <!-- left column -->
        <div class="col-md-6">
          <!-- general form elements -->

          <form action="#" method="POST">
            <div class="box box-primary">
              <div class="box-header with-border">
                <h3 class="box-title">變更亞歷山卓城Quickmatch COST</h3>
              </div>
              <div class="box-body">
                <div class="row">
                  <div class="col-xs-6">
                    <div class="form-group">
                      <label for="month_date_time">選擇日期與時間：</label>
                      <input
                        type="datetime-local"
                        id="month_date_time_1"
                        name="month_date_time_1"
                        class="form-control"
                        value="<?php echo htmlspecialchars($selected_datetime); ?>"
                        required>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-xs-3">
                    <input type="number" name="area1_1" class="form-control" placeholder="區分1">
                  </div>
                  <div class="col-xs-3">
                    <input type="number" name="area2_1" class="form-control" placeholder="區分2">
                  </div>
                  <div class="col-xs-3">
                    <input type="number" name="area3_1" class="form-control" placeholder="區分3">
                  </div>
                  <div class="col-xs-3">
                    <input type="number" name="area4_1" class="form-control" placeholder="區分4">
                  </div>
                </div>



                <div class="row" style="margin-left: 7px;">
                  <?php // 取最新一筆
                  $stmt = $db->query("SELECT month_date,cost1, cost2, cost3, cost4
                      FROM quickmatch_monthly_cost WHERE chennel =1
                    ORDER BY id DESC
                    LIMIT 1");
                  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                  // 用前三個 cost + 固定 100，組成刻度
                  $month_date = $row['month_date'];
                  $cost_values = [
                    intval($row['cost1'] ?? 0),
                    intval($row['cost2'] ?? 0),
                    intval($row['cost3'] ?? 0),
                    intval($row['cost4'] ?? 0)
                  ];
                  echo "$month_date<br>";
                  for ($i = 0; $i <= 3; $i++) {
                    echo '<div class="col-xs-3">
                    ' . $cost_values[$i] . '
                  </div>';
                  }
                  ?>
                </div>
              </div>
              <!-- /.box-body -->
              <div class="box-footer" bis_skin_checked="1">
                <button type="submit" name="costSubmit" class="btn btn-primary">送出</button>
                <input type="hidden" name="channel" value="1">
              </div>
            </div>
          </form>
          <!-- /.box -->

          <!-- general form elements -->

          <form action="#" method="POST">
            <div class="box box-primary">
              <div class="box-header with-border">
                <h3 class="box-title">峰亥盧遺跡Quickmatch COST</h3>
              </div>
              <div class="box-body">
                <div class="row">
                  <div class="col-xs-6">
                    <div class="form-group">
                      <label for="month_date_time">選擇日期與時間：</label>
                      <input
                        type="datetime-local"
                        id="month_date_time_3"
                        name="month_date_time_3"
                        class="form-control"
                        value="<?php echo htmlspecialchars($selected_datetime); ?>"
                        required>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-xs-3">
                    <input type="number" name="area1_3" class="form-control" placeholder="區分1">
                  </div>
                  <div class="col-xs-3">
                    <input type="number" name="area2_3" class="form-control" placeholder="區分2">
                  </div>
                  <div class="col-xs-3">
                    <input type="number" name="area3_3" class="form-control" placeholder="區分3">
                  </div>
                  <div class="col-xs-3">
                    <input type="number" name="area4_3" class="form-control" placeholder="區分4">
                  </div>
                </div>



                <div class="row" style="margin-left: 7px;">
                  <?php // 取最新一筆
                  $stmt = $db->query("SELECT month_date,cost1, cost2, cost3, cost4
                      FROM quickmatch_monthly_cost  WHERE chennel =3
                    ORDER BY id DESC
                    LIMIT 1");
                  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                  // 用前三個 cost + 固定 100，組成刻度
                  $month_date = $row['month_date'];
                  $cost_values = [
                    intval($row['cost1'] ?? 0),
                    intval($row['cost2'] ?? 0),
                    intval($row['cost3'] ?? 0),
                    intval($row['cost4'] ?? 0)
                  ];
                  echo "$month_date<br>";
                  for ($i = 0; $i <= 3; $i++) {
                    echo '<div class="col-xs-3">
                    ' . $cost_values[$i] . '
                  </div>';
                  }
                  ?>
                </div>
              </div>
              <!-- /.box-body -->
              <div class="box-footer" bis_skin_checked="1">
                <button type="submit" name="costSubmit" class="btn btn-primary">送出</button>
                <input type="hidden" name="channel" value="3">
              </div>
            </div>
          </form>
          <!-- /.box -->

        </div>
        <!--/.col (left) -->


        <!-- right column -->
      </div>
      <!-- /.row -->
    </section>
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->










  <!-- jQuery 3 -->
  <script src="../AdminLTE-master/bower_components/jquery/dist/jquery.min.js"></script>
  <!-- Bootstrap 3.3.7 -->
  <script src="../AdminLTE-master/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
  <!-- DataTables -->
  <script src="../AdminLTE-master/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
  <script src="../AdminLTE-master/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>
  <!-- SlimScroll -->
  <script src="../AdminLTE-master/bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
  <!-- FastClick -->
  <script src="../AdminLTE-master/bower_components/fastclick/lib/fastclick.js"></script>
  <!-- AdminLTE App -->
  <script src="../AdminLTE-master/dist/js/adminlte.min.js"></script>
  <!-- AdminLTE for demo purposes -->
  <script src="../AdminLTE-master/dist/js/demo.js"></script>

  <!-- page script -->
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
        'scrollY': "480px", // 限制表格高度，超過就可滾動
        'scrollCollapse': true, // 內容變少時，自動縮小表格大小
        'fixedHeader': true // 固定表頭
      })
      $('#example2').DataTable({
        'paging': false,
        'lengthChange': false,
        'searching': true,
        'ordering': true,
        'info': true,
        'autoWidth': false,
        'scrollX': true // 啟用橫向滾動條
      })
    })
  </script>
</body>

</html>