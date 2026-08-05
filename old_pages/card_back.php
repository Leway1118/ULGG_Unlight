<!DOCTYPE html>
<?php
require_once('head.php');
$date = date("Y-m-d H:i:s");
//session_start();
if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre><br><br><br>';
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}


// 在檔案最前面，可先將可能用到的 POST 資料初始化，避免 Notice
if (isset($_POST['id1']) && isset($_POST['cost'])) {
  $_SESSION["id1"] = floor($_POST['cost'] / 100);
  $_SESSION["id1_cost"] = $_POST['cost'] % 100;
} elseif (isset($_POST['id2']) && isset($_POST['cost'])) {
  $_SESSION["id2"] = floor($_POST['cost'] / 100);
  $_SESSION["id2_cost"] = $_POST['cost'] % 100;
} elseif (isset($_POST['id3']) && isset($_POST['cost'])) {
  $_SESSION["id3"] = floor($_POST['cost'] / 100);
  $_SESSION["id3_cost"] = $_POST['cost'] % 100;
} elseif (isset($_POST['cost_limit'])) {
  $_SESSION["cost_limit"] = $_POST['cost_limit'];
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

$ttl_cost = $id1_cost + $id2_cost + $id3_cost;

$cost_limit = $_SESSION["cost_limit"] ?? 0;;
$min = floor(($cost_limit - 6) / 3);
$max = $min + 6;
$id1_cost_max = $max;
if ($id1_cost) {
  if ($id2_cost) {
    $id3_cost_max = $cost_limit - $id1_cost - $id2_cost;
  } else {
    $id3_cost_max = $id1_cost - 6;
  }
  if ($id3_cost_max < $min) {
    $id3_cost_max = $min;
  } elseif ($id3_cost_max > $max) {
    $id3_cost_max = $max;
  }
  $id2_cost_max = $cost_limit - $id1_cost - $id3_cost_max;
  if ($id2_cost_max > $max) {
    $id2_cost_max = $max;
  } elseif ($id2_cost_max < $min) {
    $id2_cost_max = $min;
  }
} else {
  $id3_cost_max = $id1_cost_max - 6;
  $id2_cost_max = $cost_limit - $id1_cost_max - $id3_cost_max;
}


$cost_punish = 0;
$dif_a = abs($id1_cost - $id2_cost);
$dif_b = abs($id1_cost - $id3_cost);
$dif_c = abs($id2_cost - $id3_cost);
if ($dif_a > 6) {
  $cost_punish += 5;
  if ($dif_a > 12) {
    $cost_punish += 5;
  }
}
if ($dif_b > 6) {
  $cost_punish += 5;
  if ($dif_b > 12) {
    $cost_punish += 5;
  }
}
if ($dif_c > 6) {
  $cost_punish += 5;
  if ($dif_c > 12) {
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
  $cost_cal_min = $_SESSION["cost_cal_min"];
} else {
  $cost_cal_min = 0;
}
if (isset($_SESSION["cost_cal_max"])) {
  $cost_cal_max = $_SESSION["cost_cal_max"];
} else {
  $cost_cal_max = 100;
}
if (isset($_SESSION["save"])) {
  $save = $_SESSION["save"];
} else {
  $_SESSION["save"] = 2;
  $save = 2;
}

if (isset($_POST["cost_cal_min"])) {
  $cost_cal_min = $_POST["cost_cal_min"];
  $_SESSION["cost_cal_min"] = $cost_cal_min;
}

if (isset($_POST["ref1"])) {
  $cost_cal_max = $_POST["cost_cal_max"];
  $_SESSION["cost_cal_max"] = $cost_cal_max;
} elseif (isset($_POST["ref2"])) {
  $cost_cal_max = $_POST["cost_cal_max"];
  $_SESSION["cost_cal_max"] = $cost_cal_max;
} elseif (isset($_POST["ref3"])) {
  $cost_cal_max = $_POST["cost_cal_max"];
  $_SESSION["cost_cal_max"] = $cost_cal_max;
} elseif (isset($_POST["cost_cal_max"])) {
  $cost_cal_max = $_POST["cost_cal_max"];
  $_SESSION["cost_cal_max"] = $cost_cal_max;
}

if (isset($_POST["save"]) && $_POST["save"] != ($_SESSION["save"] ?? null)) {
  $save = $_POST["save"];
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

  <div class="row" style="margin:auto 15px">

    <div class="col-md-8 abgne-menu">
      <div class="box" style="margin-left: 20px;">

        <!-- /.box-header -->
        <div class="box-body">
          <table id="example1" class="table table-bordered table-striped">
            <thead>
              <tr>
                <th>ID</th>
                <th>name</th>
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
              $name = '';
              $p_name = '';
              $count_back = 0;
              //$id = 1;
              // ✅ **每次迴圈都要初始化 exist_arr**
              $exist_arr = [];
              $sql = "SELECT name, level, ico_back,id FROM unlight where id>0 AND `name` <> '' ;";
              $arr = $db->query("$sql");

              while ($row = $arr->fetch()) {
                $name = $row[0];
                $level = $row[1];
                $ico_back = $row[2];
                $id = $row[3];
                //print "ico_back=$ico_back<br>";
                $id_level = (($id - 1) / 10) - 1;


                if ($name != $p_name && $p_name != '') {
                  if ($count_back < 10) {
                    print '<tr>
                    <td>' . $id_level . '</td>
                    <td>' . $p_name . '</td>';
                    // ✅ **使用 for 迴圈來簡化程式碼**
                    $id_shift = $id - 10;
                    for ($i = 0; $i < 10; $i++) {
                      $val = isset($exist_arr[$i]) ? $exist_arr[$i] : 0;
                      $class = $val ? 'class="R_card"' : '';
                      print '<td> 
                      <span>
                        <input type="radio" id="' . $id_shift . '" name="cost" value="' . $val . '" />
                        <label ' . $class . ' for="' . $id_shift++ . '">' . $val . '</label>    
                      </span>
                    </td>';
                    }
                    print '</tr>';
                  }
                  // ✅ **每次迴圈都要初始化 exist_arr**
                  unset($exist_arr);
                  $count_back = 0;
                }


                if ($ico_back) {
                  $exist_arr[] = 1; // 代表這個角色有背面圖
                  $count_back++;
                } else {
                  $exist_arr[] = 0;
                }
                //print_r($exist_arr) . '<br>';

                $p_name = $row[0]; // 記錄上一個角色名稱
              }
              if ($count_back < 10) {
                print '<tr>
                <td>' . $id_level . '</td>
                <td>' . $p_name . '</td>';
                // ✅ **使用 for 迴圈來簡化程式碼**
                $id_shift = $id - 10;
                for ($i = 0; $i < 10; $i++) {
                  $class = '';
                  if ($exist_arr[$i]) {
                    $class = 'class="R_card"';
                  }
                  print '<td>
                  <span>
                    <input type="radio" id="' . $id_shift . '" name="cost" value="' . $exist_arr[$i] . '" />
                    <label ' . $class . ' for="' . $id_shift++ . '">' . $exist_arr[$i] . '</label>    
                  </span>
                </td>';
                }
                print '</tr>';
              }


              ?>
            </tbody>
          </table>
        </div>
        <!-- /.box-body -->
      </div>
      <!-- /.box -->
    </div>




  </div>










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