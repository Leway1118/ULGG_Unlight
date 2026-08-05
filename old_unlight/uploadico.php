<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Document</title>
</head>


<?php
session_start();
date_default_timezone_set('Asia/Taipei');
require '../Uve_Report_System/set_var.php';
$computer = new Settings;
$ip = $computer->get_ip();
print '<pre style="margin-top:30px">';
var_dump($_POST);
print '</pre>';

/* 由絕對路徑取的路徑及檔名的方法
    法一
    $path = "/home/httpd/html/index.php";
    $file = basename($path);         // index.php
    $file = basename($path, ".php"); //  index
    $dir = dirname($path);         // /home/httpd/html 注意沒有尾端的 '/' */
# 檢查檔案是否上傳成功
if ($_FILES['uploadico']['error'] === UPLOAD_ERR_OK) {
  $_SESSION["uploadico"] = 1;
  $filesize = $_FILES['uploadico']['size'] / 1024;
  $filename = basename($_FILES['uploadico']['name']);
  $id = $_POST["upname"];
  $source = $_POST["source"];
  $tmp_name = $_FILES['uploadico']['tmp_name'];
  $tmp_file = explode("/", $tmp_name);
  $tmp_file = $tmp_file[2] . '.png';
  $owner = $_SESSION["username"];
  $create_time = date('Y-m-d H:i');
  echo '檔案名稱: ' . $filename . '<br/>';
  echo '儲存名稱: ' . $upname . '<br/>';
  echo '擁有者: ' . $owner . '<br/>';
  echo '上傳時間: ' . $create_time . '<br/>';
  echo '檔案類型: ' . $_FILES['uploadico']['type'] . '<br/>';
  echo '檔案大小: ' . $filesize . ' KB<br/>';
  echo '暫存位置/名稱: ' . $tmp_name . '<br/>';
  echo '暫存位置/名稱: ' . $tmp_file . '<br/>';

  # 檢查檔案是否過大
  if ($filesize >= 16384) {
    $_SESSION["uploadico"] = 2;
    //echo '檔案過大。<br/>';
  }
  # 檢查檔案是否已經存在
  elseif (file_exists('uploads/' . $_FILES['uploadico']['tmp_name'])) {
    $_SESSION["uploadico"] = 3;
    //echo '檔案已存在。<br/>';
  } else {

    /* if (! file_exists ( $uploadPath )) {
          echo "路徑資料夾不存在";
          mkdir ( $uploadPath, 0777, true );
          chmod ( $uploadPath, 0777 );
      } */

    $file = $_FILES['uploadico']['tmp_name'];
    $dest =  'uploads/' . $tmp_file;
    # 將檔案移至指定位置
    echo "move_uploaded_file($file, $dest)";
    move_uploaded_file($file, $dest);
    /* if (move_uploaded_file($file, $dest)) {
        echo "檔案" . move_uploaded_file($file, $dest) . "上傳成功";
      } else {
        echo "檔案上傳失敗";
      } */

    try {
      $db = new PDO("mysql:host=$ip;dbname=leway_db", 'root', 'Uve%12345');
    } catch (PDOException $e) {
      print "Could not connect to the database: " . $e->getMessage();
      exit();
    }
    echo "source=$source<br>";
    if ($source == 'upload_ico') {
      $sql = "UPDATE `leway_db`.`unlight` SET `ico` = '$tmp_file' WHERE (`id` = '$id');";
    } elseif ($source == 'upload_stand') {
      $sql = "UPDATE `leway_db`.`unlight` SET `stand_ico` = '$tmp_file' WHERE (`id` = '$id');";
    } elseif ($source == 'upload_ico_back') {
      $sql = "UPDATE `leway_db`.`unlight` SET `ico_back` = '$tmp_file' WHERE (`id` = '$id');";
    } elseif ($source == 'upload_event') {
      $sql = "UPDATE `leway_db`.`unlight_eventindex` SET `ico` = '$tmp_file' WHERE (`id` = '$id');";
    } elseif ($source == 'upload_weapon') {
      $sql = "UPDATE `leway_db`.`unlight_weapon` SET `ico` = '$tmp_file' WHERE (`id` = '$id');";
    }
    echo $sql;
    $db->exec("$sql");
  }
  if ($source == 'upload_event') {
    echo "<script>
        setTimeout(function(){window.location.href='eventindex.php';},0);
    </script>";
    //時間單位為毫秒
  } elseif ($source == 'upload_weapon') {
    echo "<script>
        setTimeout(function(){window.location.href='weapon.php';},0);
    </script>";
    //時間單位為毫秒
  } else {

    echo "<script>
      setTimeout(function(){window.location.href='training.php';},0);
    </script>";
        //時間單位為毫秒
  }
} else {
  $_SESSION["uploadico"] = 4;
  if ($_FILES['uploadico']['error'] == 1) {
    $_SESSION["uploadico_err"] = '檔案過大';
  } elseif ($_FILES['uploadico']['error'] == 2) {
    $_SESSION["uploadico_err"] = '檔案過大';
  } elseif ($_FILES['uploadico']['error'] == 3) {
    $_SESSION["uploadico_err"] = '上傳不完整';
  } elseif ($_FILES['uploadico']['error'] == 4) {
    $_SESSION["uploadico_err"] = '檔案名稱不存在';
  } elseif ($_FILES['uploadico']['error'] == 6) {
    $_SESSION["uploadico_err"] = '暫存資料夾遺失';
  } elseif ($_FILES['uploadico']['error'] == 7) {
    $_SESSION["uploadico_err"] = '寫入失敗';
  } elseif ($_FILES['uploadico']['error'] == 8) {
    $_SESSION["uploadico_err"] = '上傳延期導致失敗';
  }

  //echo '錯誤代碼：' . $_FILES['uploadico']['error'] . '<br/>';
}

if (isset($_POST["upload_ico"])) {
  $ico_name = $_POST["upload_ico"];
  $source = 'upload_ico';
} elseif (isset($_POST["upload_stand"])) {
  $ico_name = $_POST["upload_stand"];
  $source = 'upload_stand';
} elseif (isset($_POST["upload_ico_back"])) {
  $ico_name = $_POST["upload_ico_back"];
  $source = 'upload_ico_back';
} elseif (isset($_POST["upload_event"])) {
  $ico_name = $_POST["upload_event"];
  $source = 'upload_event';
}elseif (isset($_POST["upload_weapon"])) {
  $ico_name = $_POST["upload_weapon"];
  $source = 'upload_weapon';
}
?>

<body>
  <?php
  print "$ico_name";
  ?>
  <form method="post" enctype="multipart/form-data" action="uploadico.php">
    <div class="box-body">
      <div class="form-group">
        <label for="uploadico">匯入檔案</label>
        <input type="file" name="uploadico" required>
        <input type="hidden" name="upname" value="<?php echo $ico_name; ?>">
        <input type="hidden" name="source" value="<?php echo $source; ?>">
      </div>
    </div>
    <!-- /.box-body -->

    <div class="box-footer">
      <button type="submit" class="btn btn-warning " name="submit" value="uphis">送出儲存</button>
    </div>
  </form>
</body>

</html>