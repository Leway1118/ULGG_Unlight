<!DOCTYPE html>
<?php
require_once('head.php');
$date = date("Y-m-d");
//session_start();
if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre>';
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}

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

if (isset($_GET['cost'])) {
  $raw = $_GET['cost'];            // e.g. "100+" 或 "80"
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
// 放在 ranking.php 一開始，或最少要在處理 search_team 之前
/* if (isset($_POST['id1']) || isset($_POST['id2']) || isset($_POST['id3'])) {
  // 先從 Session 拿出每一組的「角色類別編號」
  $chars = [];
  if (isset($_SESSION['id1_char'])) $chars[] = $_SESSION['id1_char'];
  if (isset($_SESSION['id2_char'])) $chars[] = $_SESSION['id2_char'];
  if (isset($_SESSION['id3_char'])) $chars[] = $_SESSION['id3_char'];
  // 過濾空值
  $filtered = array_filter($chars, function ($c) {
    return $c !== null && $c !== '';
  });
  if (count($filtered) !== count(array_unique($filtered))) {
    $_SESSION['flash'][] = [
      'type'    => 'danger',
      'message' => '⚠️ 同一類角色只能選一次，請重新選擇。'
    ];
    // 重導回原畫面，不做後續查詢
    //header('Location: cost.php');
    //exit;
  }
  // 如果檢查通過，就正常走下面你原本的搜尋程式
} */


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

// 取得角色 COST
$char_costs = [
  (int)($_SESSION["id1_cost"] ?? 0),
  (int)($_SESSION["id2_cost"] ?? 0),
  (int)($_SESSION["id3_cost"] ?? 0)
];

$ttl_cost = array_sum($char_costs);
$cost_limit = $_SESSION["cost_limit"] ?? 0;

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
  $_SESSION["save"] = 0;
  $save = 0;
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

    /* ✅ 點擊提示 & 箭頭 */
    .click-indicator {
      position: absolute;
      bottom: 70px;
      left: 55%;
      transform: translateX(-50%);
      /* background: rgba(0, 0, 0, 0.6); */
      color: white;
      padding: 5px 10px;
      border-radius: 10px;
      font-size: 22px;
      display: flex;
      align-items: center;
      gap: 5px;
      opacity: 0;
      transition: opacity 0.3s ease-in-out;
    }

    /* ✅ 滑鼠移上去顯示 Click 提示 */
    .flip-container:hover .click-indicator {
      opacity: 1;
    }

    /* 箭頭圖片 */
    .click-indicator img {
      width: 135px;
      height: 55px;
    }

    .box-title {
      margin: 8px !important;
      font-size: 15px !important;
    }

    .quickSelect {
      font-size: 18px;
      padding: 0px 10px;
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

    <div class="col-md-4">
      <div style="margin-top: 8px;">
        <?php
        if (!empty($_SESSION['flash'])) {
          foreach ($_SESSION['flash'] as $f) {
            // Bootstrap alert
            echo "<div class='alert alert-{$f['type']}'>{$f['message']}</div>";
          }
          unset($_SESSION['flash']);
        }
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
        echo '<i class="fa fa-hand-o-left" aria-hidden="true"></i> 快速選擇區間<br>';
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
        echo '<i class="fa fa-hand-o-left" aria-hidden="true"></i> 快速選擇區間'; */
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
        ?> </div>
      <div style="display: flex; justify-content: center; margin-top: 10px;align-items: center;">
        <div class="col-md-4 text-md-end text-center">
          <strong>COST限制</strong>
        </div>
        <div style="width: 200px;">
          <form action="#" method="POST">
            <div class="input-group input-group-lg">
              <input type="text" class="form-control" name="cost_limit" value="<?php echo "$cost_limit"; ?>">
              <span class="input-group-btn">
                <input type="submit" class="btn btn-default btn-flat" value="Enter">
              </span>
            </div>
          </form>
        </div>
      </div>



      <table id="example2" class="table table-bordered table-striped" style="text-align: center;">
        <thead>
          <tr>
            <th>
              No.1
            </th>
            <th>
              No.2
            </th>
            <th>
              No.3
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <?php
              $sql = "SELECT * FROM leway_db.unlight WHERE id = '$id1';";
              $arr = $db->query($sql);
              $row = $arr->fetch(); // 只取一筆資料
              $id1_name = $row[2];

              if ($row && !empty($row[1])) {
                $ico_front = 'uploads/' . $row[1];  // 第一張圖片
                $ico_back = (!empty($row[15])) ? 'uploads/' . $row[15] : '';  // 第二張圖片（判斷是否存在）

                if (!empty($ico_back)) {
                  // ✅ 如果 row[15] 存在，啟用翻轉
                  echo '
                  <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
                    <div class="flipper">
                      <div class="front">
                        <img src="' . $ico_front . '" loading="lazy" style="height:200px;">
                        <div class="click-indicator">
                          <img src="arrow_icon.png" alt="Click Arrow">
                        </div>
                      </div>
                      <div class="back">
                        <img src="' . $ico_back . '" loading="lazy" style="height:200px;">
                      </div>
                    </div>
                  </div>';
                } else {
                  // ❌ 如果 row[15] 沒有圖片，僅顯示前面圖片
                  echo '<img  src="' . $ico_front . '" loading="lazy" style="height:200px;">';
                }
              } else {
                echo '<div style="height: 150px; background-color: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ccc;">Unknown</div>';
              }
              ?>
            </td>

            <td>
              <?php
              $sql = "SELECT * FROM leway_db.unlight WHERE id = '$id2';";
              $arr = $db->query($sql);
              $row = $arr->fetch(); // 只取一筆資料
              $id2_name = $row[2];

              if ($row && !empty($row[1])) {
                $ico_front = 'uploads/' . $row[1];  // 第一張圖片
                $ico_back = (!empty($row[15])) ? 'uploads/' . $row[15] : '';  // 第二張圖片（判斷是否存在）

                if (!empty($ico_back)) {
                  // ✅ 如果 row[15] 存在，啟用翻轉
              ?>
                  <div class="flip-container" onclick="this.classList.toggle('flipped');">
                    <div class="flipper">
                      <div class="front">
                        <img src="<?= $ico_front ?>" loading="lazy" style="height:200px;">
                        <div class="click-indicator">
                          <img src="arrow_icon.png" alt="Click Arrow">
                        </div>
                      </div>
                      <div class="back">
                        <img src="<?= $ico_back ?>" loading="lazy" style="height:200px;">
                      </div>
                    </div>
                  </div>
              <?php
                } else {
                  // ❌ 如果 row[15] 沒有圖片，僅顯示前面圖片
                  echo '<img  src="' . $ico_front . '" loading="lazy" style="height:200px;">';
                }
              } else {
                echo '<div style="height: 150px; background-color: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ccc;">Unknown</div>';
              }
              ?>
            </td>
            <td>
              <?php
              $sql = "SELECT * FROM leway_db.unlight WHERE id = '$id3';";
              $arr = $db->query($sql);
              $row = $arr->fetch(); // 只取一筆資料
              $id3_name = $row[2];

              if ($row && !empty($row[1])) {
                $ico_front = 'uploads/' . $row[1];  // 第一張圖片
                $ico_back = (!empty($row[15])) ? 'uploads/' . $row[15] : '';  // 第二張圖片（判斷是否存在）

                if (!empty($ico_back)) {
                  // ✅ 如果 row[15] 存在，啟用翻轉
              ?>
                  <div class="flip-container" onclick="this.classList.toggle('flipped');">
                    <div class="flipper">
                      <div class="front">
                        <img src="<?= $ico_front ?>" loading="lazy" style="height:200px;">
                        <div class="click-indicator">
                          <img src="arrow_icon.png" alt="Click Arrow">
                        </div>
                      </div>
                      <div class="back">
                        <img src="<?= $ico_back ?>" loading="lazy" style="height:200px;">
                      </div>
                    </div>
                  </div>
              <?php
                } else {
                  // ❌ 如果 row[15] 沒有圖片，僅顯示前面圖片
                  echo '<img  src="' . $ico_front . '" loading="lazy" style="height:200px;">';
                }
              } else {
                echo '<div style="height: 150px; background-color: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ccc;">Unknown</div>';
              }
              ?>
            </td>
          </tr>
          <tr>
            <td>
              <?php if ($id1_name) {
                echo $id1_name;
              } ?>
            </td>
            <td>
              <?php if ($id2_name) {
                echo $id2_name;
              } ?>
            </td>
            <td>
              <?php if ($id3_name) {
                echo $id3_name;
              } ?>
            </td>
          </tr>
          <tr>
            <td>
              <form action="#" method="POST">
                <input type="hidden" name="cost_cal_min" value="<?php echo $id1_cost_min ?>">
                <input type="hidden" name="cost_cal_max" value="<?php echo $id1_cost_max ?>">
                <button type="submit" name="ref1" value="1">參考值:<?php echo "$id1_cost_min ~ $id1_cost_max"; ?></button>
              </form>
            </td>
            <td>
              <form action="#" method="POST">
                <input type="hidden" name="cost_cal_min" value="<?php echo $id2_cost_min ?>">
                <input type="hidden" name="cost_cal_max" value="<?php echo $id2_cost_max ?>">
                <button type="submit" name="ref2" value="1">參考值:<?php echo "$id2_cost_min ~ $id2_cost_max"; ?></button>
              </form>
            </td>
            <td>
              <form action="#" method="POST">
                <input type="hidden" name="cost_cal_min" value="<?php echo $id3_cost_min ?>">
                <input type="hidden" name="cost_cal_max" value="<?php echo $id3_cost_max ?>">
                <button type="submit" name="ref3" value="1">參考值:<?php echo "$id3_cost_min ~ $id3_cost_max"; ?></button>
              </form>
            </td>
          </tr>
          <tr>
            <td <?php echo $id1_color ?>>
              <?php echo "$id1_cost"; ?>
            </td>
            <td <?php echo $id2_color ?>>
              <?php echo "$id2_cost"; ?>
            </td>
            <td <?php echo $id3_color ?>>
              <?php echo "$id3_cost"; ?>
            </td>
          </tr>
          <tr>
            <form action="#" method="POST">
              <td>
                <button type="submit" class="btn btn-default" name="id1_clear" value="1">清除</button>
              </td>
              <td>
                <button type="submit" class="btn btn-default" name="id2_clear" value="1">清除</button>
              </td>
              <td>
                <button type="submit" class="btn btn-default" name="id3_clear" value="1">清除</button>
              </td>
            </form>
          </tr>
          <tr class="large-bold" style="color:<?php echo "$ttl_cost_color"; ?>">
            <td colspan="3">
              <?php echo $ttl_cost . $punish_arr; ?>
            </td>
          </tr>
          <tr>
            <td colspan="3">
              <form action="#" method="POST">
                <button type="submit" class="btn btn-default" name="id_all_clear" value="1">清除全部</button>
              </form>
            </td>
          </tr>
          <tr>
            <td colspan="3">
              <form action="ranking.php" method="POST">
                <input type="hidden" name="id1" value="<?php echo $id1 ?>">
                <input type="hidden" name="id2" value="<?php echo $id2 ?>">
                <input type="hidden" name="id3" value="<?php echo $id3 ?>">
                <button type="submit" class="btn btn-primary" name="search_team" value="1">搜尋組合</button>
              </form>
            </td>
          </tr>


        </tbody>
      </table>

    </div>


    <div class="col-md-8 abgne-menu">
      <form action="#" method="POST">
        <!-- <div class="row" style="margin-left: 125px;">
        </div> -->
        <div class="box" style="margin-left: 20px;">
          <div class="box-header">
            <div class="row">
              <div class="col-xs-4">
                <button type="submit" class="btn btn-info" name="id1" value="1">角色1</button>
                <button type="submit" class="btn btn-primary" name="id2" value="1">角色2</button>
                <button type="submit" class="btn btn-success" name="id3" value="1">角色3</button>
              </div>
              <div class="col-xs-8">
                <div class="row">
                  <div class="col-xs-3">
                    <h3 class="box-title">COST區間</h3>
                  </div>
                  <div class="col-xs-3">
                    <input type="number" name="cost_cal_min" class="form-control" placeholder="最小值" value="<?php echo $cost_cal_min; ?>">
                  </div>
                  <div class="col-xs-3">
                    <input type="number" name="cost_cal_max" class="form-control" placeholder="最大值" value="<?php echo $cost_cal_max; ?>">
                  </div>
                  <div class="col-xs-3">
                    <button type="submit" class="btn btn-primary">設定</button>
                    <button type="submit" name="reset" value="1" class="btn btn-warning">重置</button>
                  </div>
                </div>
                <div class="row">
                  <div class="col-xs-3">
                    <h3 class="box-title">最小預留</h3>
                  </div>
                  <div class="col-xs-3 ">
                    <input type="number" name="save" class="form-control" placeholder="預留" value="<?php echo $save; ?>">
                  </div>
                </div>
              </div>

            </div>
          </div>
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
                $do_all = 0;
                $sql = "SELECT * FROM leway_db.cost_unlight where l1 <> '' and on_stage='1';";
                $arr = $db->query("$sql");
                while ($row = $arr->fetch()) {
                  $name = $row[1];
                  //if (!($name == $id1_name || $name == $id2_name || $name == $id3_name))
                  $id = $row[0];
                  $id_code = $id - 1;
                  $id_level = ($id - 1) * 10;
                  $L1 = $row[2];
                  $L2 = $row[3];
                  $L3 = $row[4];
                  $L4 = $row[5];
                  $L5 = $row[6];
                  $R1 = $row[7];
                  $R2 = $row[8];
                  $R3 = $row[9];
                  $R4 = $row[10];
                  $R5 = $row[11];
                  $id_level++;
                  $L1_value = $row[2] + $id_level++ * 100;
                  $L2_value = $row[3] + $id_level++ * 100;
                  $L3_value = $row[4] + $id_level++ * 100;
                  $L4_value = $row[5] + $id_level++ * 100;
                  $L5_value = $row[6] + $id_level++ * 100;
                  $R1_value = $row[7] + $id_level++ * 100;
                  $R2_value = $row[8] + $id_level++ * 100;
                  $R3_value = $row[9] + $id_level++ * 100;
                  $R4_value = $row[10] + $id_level++ * 100;
                  $R5_value = $row[11] + $id_level++ * 100;


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
                    print '<tr>
                      <td>' . $id_code . '</td>
                      <td>' . $name . '</td>';
                    if ($L1 >= $cost_cal_min && $L1 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $L1_value . '" />
                          <label for="' . $id_level++ . '" >' . $L1 . '</label>    
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($L2 >= $cost_cal_min && $L2 <= $cost_cal_max) {
                      print ' <td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $L2_value . '" />
                          <label for="' . $id_level++ . '">' . $L2 . '</label>     
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($L3 >= $cost_cal_min && $L3 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $L3_value . '" />
                          <label for="' . $id_level++ . '">' . $L3 . '</label>    
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($L4 >= $cost_cal_min && $L4 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $L4_value . '" />
                          <label for="' . $id_level++ . '">' . $L4 . '</label>  
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($L5 >= $cost_cal_min && $L5 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $L5_value . '" />
                          <label for="' . $id_level++ . '">' . $L5 . '</label>  
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($R1 >= $cost_cal_min && $R1 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $R1_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R1 . '</label>   
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($R2 >= $cost_cal_min && $R2 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $R2_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R2 . '</label>  
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($R3 >= $cost_cal_min && $R3 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $R3_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R3 . '</label>  
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($R4 >= $cost_cal_min && $R4 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $R4_value . '" />
                          <label class="R_card" for="' . $id_level++ . '">' . $R4 . '</label>   
                        </span>
                      </td>';
                    } else {
                      print '<td></td>';
                    }
                    if ($R5 >= $cost_cal_min && $R5 <= $cost_cal_max) {
                      print '<td>
                        <span>
                          <input type="radio" id="' . $id_level . '" name="cost" value="' . $R5_value . '" />
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

  <!-- <div class="row" style="margin:auto 15px">
    <div class="col-md-6">
      <ul class="timeline">
        <li class="time-label">
          <span class="bg-green">
            2025/3/4
          </span>
        </li>
        <li>
          <i class="fa fa-envelope bg-blue"></i>

          <div class="timeline-item">

            <h3 class="timeline-header"><a href="#">Support Team</a>更新</h3>

            <div class="timeline-body">
              <b>･追加帕茉</b><br>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </div> -->








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
        'scrollY': "550px", // 限制表格高度，超過就可滾動
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