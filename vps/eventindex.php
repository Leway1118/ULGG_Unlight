<!DOCTYPE html>
<?php

require_once('head.php');
date_default_timezone_set("Asia/Taipei");
$date = date("Y-m-d H:i:s");

$ip = '192.168.50.3';
$dbname = 'leway_db';
try {
  $db = new PDO("mysql:host=$ip;dbname=$dbname", 'root', 'Uve%12345');
} catch (PDOException $e) {
  print "Could not connect to the database: " . $e->getMessage();
  exit();
}

if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre>';
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}

$new_crt = 'style="display:none"';

$search_name = '';
if (isset($_SESSION["search_name"])) {
  $search_name = $_SESSION["search_name"];
} else {
  $_SESSION["search_name"] = '';
}

if (isset($_POST['edit'])) {
  $id = $_POST['edit'];
  /* $level = $_POST['level'];
  $HP = $_POST['HP'];
  $ATK = $_POST['ATK'];
  $DEF = $_POST['DEF']; */
  $cost = $_POST['cost'];
  $sword = $_POST['sword'];
  $gun = $_POST['gun'];
  $shield = $_POST['shield'];
  $move = $_POST['move'];
  $special = $_POST['special'];
  //$description = $_POST['description'];

  if (isset($cost)) {
    $db->exec("UPDATE `leway_db`.`unlight_eventindex` SET `cost` = '$cost' WHERE (`id` = '$id');");
    $x = floor(($id - 1) / 10) + 1;
    $y = $id % 10;
    switch ($y) {
      case 1:
        $z = 'L1';
        break;
      case 2:
        $z = 'L2';
        break;
      case 3:
        $z = 'L3';
        break;
      case 4:
        $z = 'L4';
        break;
      case 5:
        $z = 'L5';
        break;
      case 6:
        $z = 'R1';
        break;
      case 7:
        $z = 'R2';
        break;
      case 8:
        $z = 'R3';
        break;
      case 9:
        $z = 'R4';
        break;
      case 0:
        $z = 'R5';
        break;
    }
  }
  if (isset($sword)) {
    $db->exec("UPDATE `leway_db`.`unlight_eventindex` SET `sword` = '$sword' WHERE (`id` = '$id');");
  }
  if (isset($gun)) {
    $db->exec("UPDATE `leway_db`.`unlight_eventindex` SET `gun` = '$gun' WHERE (`id` = '$id');");
  }
  if (isset($shield)) {
    $db->exec("UPDATE `leway_db`.`unlight_eventindex` SET `shield` = '$shield' WHERE (`id` = '$id');");
  }
  if (isset($move)) {
    $db->exec("UPDATE `leway_db`.`unlight_eventindex` SET `move` = '$move' WHERE (`id` = '$id');");
  }
  if (isset($special)) {
    $db->exec("UPDATE `leway_db`.`unlight_eventindex` SET `special` = '$special' WHERE (`id` = '$id');");
  }
  if (isset($description)) {
    $db->exec("UPDATE `leway_db`.`unlight_eventindex` SET `description` = '$description' WHERE (`id` = '$id');");
  }
} elseif (isset($_POST["new_crt"])) {
  $new_crt = '';
} elseif (isset($_POST["crt_id"]) && $_POST["crt_id"] && $_POST["crt_name"]) {
  $crt_id = $_POST["crt_id"];
  $crt_name = $_POST["crt_name"];
  $sql = "INSERT INTO `leway_db`.`unlight_eventindex` (`id`, `name`) VALUES ('$crt_id', '$crt_name');";
  $db->exec($sql);
  //print "sql=$sql";
} elseif (isset($_POST["search_name"])) {
  $search_name = $_POST["search_name"];
  $_SESSION["search_name"] = $search_name;
}
//print "search_name=$search_name";


?>
<html lang="en">

<head>
  <title>Unlight</title>


  <style>
    /* 調整 DataTables 搜尋框的寬度 */
    /* 調整搜尋欄位的寬度 */
    .box .dataTables_filter input {
      width: 100px;
      /* 設定你需要的寬度 */
    }
  </style>
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
  </style>
</head>

<body>
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
  <div class="navbar">
    <?php
    print $head_bar;
    ?>
  </div>
  <div class="row container" style="margin:auto">
    <form method="POST" class="row">
      <!-- ✅ 查詢名稱輸入框 -->
      <div class="col-md-6">
        <div class="input-group">
          <input type="text" name="search_name" class="form-control" placeholder="輸入角色名稱" value='<?php print $search_name ?>'>
          <span class="input-group-btn">
            <button type="submit" class="btn btn-primary">查詢</button>
          </span>
        </div>
      </div>
    </form>
  </div>
  <div class="row container" style="margin: auto;">
    <div class="box">
      <div class="box-header">
        <h3 class="box-title">角色資訊</h3>
      </div>
      <!-- /.box-header -->
      <div class="box-body">
        <table id="example2" class="table table-bordered table-striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>ico</th>
              <th>上傳</th>
              <th>name</th>
              <th>cost</th>
              <th>劍</th>
              <th>槍</th>
              <th>盾</th>
              <th>移</th>
              <th>特</th>
              <th>描述</th>
              <th>更新</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if (isset($_POST["enter_edit"])) {
              $id = $_POST["enter_edit"];
              $sql = "SELECT * FROM unlight_eventindex where id='$id';";
            } elseif ($search_name) {
              //$search_name = $_POST['search_name'] ?? ''; // 確保變數存在
              $search_name = "%" . $search_name . "%"; // 模糊匹配
              $sql = "SELECT * FROM unlight_eventindex where name like '$search_name';";
            } else {
              $sql = "SELECT * FROM unlight_eventindex;";
            }

            $arr = $db->query("$sql");
            while ($row = $arr->fetch()) {
              $i = 0;
              $id = $row[$i++];
              $ico = $row[$i++];
              $char_name = $row[$i++];
              $cost = $row[$i++];
              $sword = $row[$i++];
              $gun = $row[$i++];
              $shield = $row[$i++];
              $move = $row[$i++];
              $special = $row[$i++];
              $description = $row[$i++];

              //$level_char_name = $level . $char_name;

              if (1) {
                print '<td>' . $id . '</td>';
                print '<td><img class="zoomout" src="uploads/' . $ico . '" loading="lazy" style="height:64px"></td>
                <td>
                  <form action="uploadico.php" method="POST">
                    <input type="hidden" name="level_char_name" value="' . $char_name . '">
                    <button type="submit" target="_blank" name="upload_event" value="' . $id . '" style="width:50px">上傳</button>
                  </form>
                </td>';
                
                //<button type="submit" target="_blank" name="upload_ico_back" value="' . $id . '" style="width:50px">卡背</button>
                //<button type="submit" target="_blank" name="upload_stand" value="' . $id . '" style="width:50px">立繪</button>
                print "<td>$char_name</td>";

                if (isset($_POST["enter_edit"]) && $id == $_POST["enter_edit"]) {
                  print '<form action="#" method="POST">';
                  print '<td><input type="text" name="cost" value="' . $cost . '" style="width:50px;"></td>';
                  print '<td><input type="text" name="sword" value="' . $sword . '" style="width:50px;"></td>';
                  print '<td><input type="text" name="gun" value="' . $gun . '" style="width:50px;"></td>';
                  print '<td><input type="text" name="shield" value="' . $shield . '" style="width:50px;"></td>';
                  print '<td><input type="text" name="move" value="' . $move . '" style="width:50px;"></td>';
                  print '<td><input type="text" name="special" value="' . $special . '" style="width:50px;"></td>';
                  print '<td><input type="text" name="special" value="' . $description . '" style="width:50px;"></td>';
                  //print '<td><textarea id="description" name="description" rows="4" cols="50" >' . $description . '</textarea></td>';
                  print '<td><button type="submit" name="edit" value="' . $id . '" style="width:50px">送出</button></td>';
                  print '</form>';
                } else {
                  print "<td>$cost</td>";
                  print "<td>$sword</td>";
                  print "<td>$gun</td>";
                  print "<td>$shield</td>";
                  print "<td>$move</td>";
                  print "<td>$special</td>";
                  print "<td>$description</td>";
                  print '<form action="#" method="POST"><td><button type="submit" name="enter_edit" value="' . $id . '" style="width:50px">編輯</button></td></form>';
                }
                print '
              </tr>';
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

  <!-- page script -->
  <script>
    $(function() {
      $('#example1').DataTable({
        'paging': true, // 開啟分頁
        'lengthChange': true, // 啟用每頁顯示筆數的選擇功能
        'searching': true, // 啟用搜尋框
        'ordering': true, // 啟用排序功能
        'info': true, // 顯示表格資訊
        'autoWidth': false // 禁用自動寬度調整

      });
      // 調整搜尋框的寬度
      $('.dataTables_filter input').css({
        'width': '100px', // 設定搜尋框寬度為 300px
        /* 'margin-left': '10px', */ // 增加左側間距
      });
      $('#example2').DataTable({
        'paging': true, // 開啟分頁
        'lengthChange': true, // 啟用每頁顯示筆數的選擇功能
        'searching': true, // 啟用搜尋框
        'ordering': true, // 啟用排序功能
        'info': true, // 顯示表格資訊
        'autoWidth': false, // 禁用自動寬度調整
        'scrollX': true, // 啟用橫向滾動條
        'pageLength': 20  // 👈 預設每頁顯示 20 筆
      })
    })
  </script>
</body>

</html>