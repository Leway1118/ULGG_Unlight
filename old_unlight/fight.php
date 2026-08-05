<!DOCTYPE html>

<?php
// 其他程式碼……
ob_start();
require_once('head.php');

if (isset($_SESSION['upload_success'])) {
  echo "<script>alert('" . $_SESSION['upload_success'] . "');</script>";
  unset($_SESSION['upload_success']); // 顯示後清除
}

/* if (!isset($_SESSION['upload_token'])) {
  $_SESSION['upload_token'] = bin2hex(random_bytes(32)); // 產生隨機 Token
}
$upload_token = $_SESSION['upload_token']; // 取得 Token */

if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre>';
  var_dump($_GET);
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
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

function isBlackList(PDO $db, string $username): int
{
  $stmt = $db->prepare("
    SELECT black_list
    FROM game_user
    WHERE (username) = (:u)
    LIMIT 1
  ");
  $stmt->execute([':u' => $username]);
  $val = $stmt->fetchColumn();
  return $val !== false ? (int)$val : 0;
}
// ✅ 共用 function（你原本的 function 放下面）
function progress_color($rate)
{
  if ($rate < 25) return 'red';
  if ($rate < 50) return 'yellow';
  if ($rate < 75) return 'green';
  return 'aqua';
}
function cost_punish(int $id1_cost, int $id2_cost, int $id3_cost)
{
  $punish = 0;
  $diffs = [abs($id1_cost - $id2_cost), abs($id1_cost - $id3_cost), abs($id2_cost - $id3_cost)];
  foreach ($diffs as $diff) {
    if ($diff > 6) $punish += 5;
    if ($diff > 13) $punish += 5;
  }
  return $punish;
}

function getMostUsedEvent(array $arr): array
{
  $counts = [];
  foreach ($arr as $v) {
    // null 單獨計入 'null' 這個 key
    if (is_null($v)) {
      $counts['null'] = ($counts['null'] ?? 0) + 1;
    }
    // 整數當成卡片 ID
    elseif (is_int($v)) {
      $counts[$v] = ($counts[$v] ?? 0) + 1;
    }
  }
  // 如果都沒資料，就回傳空
  if (empty($counts)) {
    return ['id' => null, 'count' => 0];
  }
  arsort($counts);
  $mostKey   = key($counts);
  $mostCount = current($counts);
  return ['id' => $mostKey, 'count' => $mostCount];
}


$username = $_SESSION["username"];
$permission = $_SESSION["ack"] ?? 0;
$disabled = ($permission == 2) ? '' : 'disabled title="權限不足"';
//$disabled = '';
//print "permission=$permission";

// ─── ① 事件卡表只查一次，建立 map ───────────────────────────────────────
$eventMapIco     = [];  // [event_id] => ico.png
$eventMapName    = [];  // [event_id] => 卡片名稱
$eventMapSword   = [];  // [event_id] => sword 欄位數值
$eventMapGun     = [];  // [event_id] => gun 欄位數值
$eventMapShield  = [];  // [event_id] => shield 欄位數值
$eventMapShift   = [];  // [event_id] => shift 欄位數值
$eventMapSpecial = [];  // [event_id] => special 欄位數值

$sqlEvt = "SELECT id, ico, name, sword, gun, shield, `shift`, special 
           FROM unlight_eventindex";
foreach ($db->query($sqlEvt, PDO::FETCH_ASSOC) as $r) {
  $id                   = (int)$r['id'];
  $eventMapIco[$id]     = $r['ico']  ?: 'na_event.png';
  $eventMapName[$id]    = $r['name'] ?: '';
  $eventMapSword[$id]   = (int)$r['sword'];
  $eventMapGun[$id]     = (int)$r['gun'];
  $eventMapShield[$id]  = (int)$r['shield'];
  $eventMapShift[$id]   = (int)$r['shift'];
  $eventMapSpecial[$id] = (int)$r['special'];
}


/* ----------  處理「動作」按鈕  ---------- */
if (isset($_POST['set_win1'])) {
  $id          = intval($_POST['set_win1']);
  $player_name = trim($_POST['player_name1']);
  /* 1) 先抓資料 */
  $stmt = $db->prepare("
      SELECT name_p1, name_p2
      FROM   arena_unlight
      WHERE  id = :id
      LIMIT  1
  ");
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $_SESSION['upload_success'] = "找不到這筆對戰 (ID=' . $id . ')";
    die('❌ 找不到這筆對戰 (ID=' . $id . ')');
  }
  /* 2) 判斷玩家是 P1 還是 P2 */
  if ($row['name_p1'] === $player_name) {
    // 玩家是 P1 → P1 勝、P2 敗
    $sql = "
          UPDATE arena_unlight
          SET win = 0,  lose = 1, tie = 0,
              ack1 = 1, ack2 = 1
          WHERE id = :id
      ";
  } elseif ($row['name_p2'] === $player_name) {
    // 玩家其實是 P2 → P1 敗、P2 勝
    $sql = "
          UPDATE arena_unlight
          SET win = 1,  lose = 0, tie = 0,
              ack1 = 1, ack2 = 1
          WHERE id = :id
      ";
  } else {
    $_SESSION['upload_success'] = '名稱不符，請重新整理後再試！';
    die('❌ 名稱不符，請重新整理後再試！');
  }
  /* 3) 執行更新 */
  $db->prepare($sql)->execute([':id' => $id]);
  /* 4) 更新成功 → 導回 */
  $_SESSION['upload_success'] = "$player_name 勝！";
  //header('Location: queue.php');      // ← 導回 queue.php
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['set_win2'])) {
  $id          = intval($_POST['set_win2']);
  $player_name = trim($_POST['player_name2']);
  /* 1) 先抓資料 */
  $stmt = $db->prepare("
      SELECT name_p1, name_p2
      FROM   arena_unlight
      WHERE  id = :id
      LIMIT  1
  ");
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $_SESSION['upload_success'] = '找不到這筆對戰 (ID=' . $id . ')';
    die('❌ 找不到這筆對戰 (ID=' . $id . ')');
  }
  /* 2) 判斷玩家是 P1 還是 P2 */
  if ($row['name_p1'] === $player_name) {
    // 玩家是 P1 → P1 勝、P2 敗
    $sql = "
          UPDATE arena_unlight
          SET win = 0,  lose = 1, tie = 0,
              ack1 = 1, ack2 = 1
          WHERE id = :id
      ";
  } elseif ($row['name_p2'] === $player_name) {
    // 玩家其實是 P2 → P1 敗、P2 勝
    $sql = "
          UPDATE arena_unlight
          SET win = 1,  lose = 0, tie = 0,
              ack1 = 1, ack2 = 1
          WHERE id = :id
      ";
  } else {
    $_SESSION['upload_success'] = '名稱不符，請重新整理後再試';
    die('❌ 名稱不符，請重新整理後再試！');
  }
  /* 3) 執行更新 */
  $db->prepare($sql)->execute([':id' => $id]);
  /* 4) 回饋訊息 */
  $_SESSION['upload_success'] = '感謝上傳！勝場已更新！';
  //header('Location: queue.php');      // ← 導回 queue.php
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['tie'])) {
  $id = $_POST['tie'];
  $sql_insert = "UPDATE `leway_db`.`arena_unlight` 
  SET `ack1` = :ack1 ,`ack2` = :ack2,`tie` = :tie
  WHERE `id` = :id";
  $stmt_insert = $db->prepare($sql_insert);
  $stmt_insert->execute([
    ':ack1' => 1,
    ':ack2' => 1,
    ':tie' => 1,
    ':id' => $id,
  ]);
  $_SESSION['upload_success'] = '平！';
  //header('Location: queue.php');      // ← 導回 queue.php
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['is_read'])) {
  $id = $_POST['is_read'];
  $sql_insert = "UPDATE `leway_db`.`arena_unlight`
    SET `is_read` = :is_read
    WHERE `id` = :id";
  $stmt_insert = $db->prepare($sql_insert);
  $stmt_insert->execute([
    ':is_read' => 1,
    ':id'      => $id,
  ]);
  $_SESSION['upload_success'] = '標示已讀';
  //header('Location: queue.php');      // ← 導回 queue.php
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['del'])) {
  $id = $_POST['del'];
  $sql_insert = "DELETE FROM `leway_db`.`arena_unlight` WHERE `id` = :id";
  $stmt_insert = $db->prepare($sql_insert);
  $stmt_insert->execute([':id' => $id]);
  $_SESSION['upload_success'] = '大刀一砍！';
  //header('Location: queue.php');      // ← 導回 queue.php
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
}

// ✅ 讀取 Session 值
$get_player = 0;
$combined = $_SESSION['combined'] ?? 0;
$check = $_SESSION['check'] ?? false;
$check_rate = $_SESSION['check_rate'] ?? '';
$e1 = $_SESSION['e1'] ?? 0;
$e2 = $_SESSION['e2'] ?? 0;
$e3 = $_SESSION['e3'] ?? 0;
$player_name = $_SESSION['player_name'] ?? '';
$bp_min = $_SESSION['bp_min'] ?? 1000;
$bp_max = $_SESSION['bp_max'] ?? 2000;
// ✅ 如果 GET 有 player_name，優先處理並切成單人模式
// -------- ① 處理 GET['player_name']，但是「先驗證權限」 --------
if (isset($_GET['player_name'])) {
  $requestedPlayer = trim($_GET['player_name']);

  $isSelf      = ($requestedPlayer === $username);
  $showPrivate = getShowPrivate($db, $requestedPlayer);
  $isBlack     = isBlackList($db, $requestedPlayer);
  $isAdmin     = ($permission === 2);

  /* if (! $isAdmin && ! $showPrivate && ! $isSelf && ! $isBlack) {
    // 1) 寫進 PHP error log，記錄嘗試越權的使用者、目標名稱和時間
    $msg = sprintf(
      "[%s] 權限檢查失敗：使用者 '%s' 嘗試以 GET 存取玩家 '%s'，但權限不足。\n",
      date('Y-m-d H:i:s'),
      $username,
      $requestedPlayer
    );
    error_log($msg);

    // 2) 跳出 alert 給使用者提示
    echo "<script>
                alert('權限不足：你無法查看「{$requestedPlayer}」的資料！');
              </script>";

    // 3) 把 GET 砍掉、退回到預設（不進單人模式）
    unset($_GET['player_name']);
    $player_name = '';
    $combined    = 0;
    $check       = 0;
  } else {
    // 合法，就走原本的單人模式邏輯
    $player_name = $requestedPlayer;
    $combined    = 2;
    $_SESSION['player_name'] = $player_name;
    $_SESSION['check_rate']  = '';
    $check       = 1;
    $get_player  = 1;
  } */
  // 合法，就走原本的單人模式邏輯
  $player_name = $requestedPlayer;
  $combined    = 2;
  $_SESSION['player_name'] = $player_name;
  $_SESSION['check_rate']  = '';
  $check       = 1;
  $get_player  = 1;
} // end if GET player_name
// ✅ 權限設定
/* $username = $_SESSION["username"];
$permission = $_SESSION["ack"] ?? 0;
$disabled = ($permission == 2) ? '' : 'disabled title="權限不足"'; */

// ✅ PRG：如果是 POST，處理完直接重導回自己，避免 F5 重新送出表單
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ✅ 一般欄位統一存進 SESSION
  foreach ($_POST as $key => $value) {
    $_SESSION[$key] = $value;
  }
  // ✅ 特別處理 set_team（組隊）
  if (isset($_POST['set_team'])) {
    if ($_POST['set_team'] === '組合1') {
      $_SESSION['e1'] = $_POST['e1'] ?? 0;
      $_SESSION['e2'] = $_POST['e2'] ?? 0;
      $_SESSION['e3'] = $_POST['e3'] ?? 0;
    } elseif ($_POST['set_team'] === '組合2') {
      $_SESSION['u1'] = $_POST['u1'] ?? 0;
      $_SESSION['u2'] = $_POST['u2'] ?? 0;
      $_SESSION['u3'] = $_POST['u3'] ?? 0;
    }
  }
  // ✅ 安全檢查：確保 bp_min / bp_max 一定存在
  $_SESSION['bp_min'] = isset($_POST['bp_min']) ? (int)$_POST['bp_min'] : 1000;
  $_SESSION['bp_max'] = isset($_POST['bp_max']) ? (int)$_POST['bp_max'] : 2000;
  // ✅ 最後導回自己
  header('Location: ' . $_SERVER['PHP_SELF']);
  exit();
}

//$count_en = 0;
$e1 = $_SESSION['e1'] ?? $_POST['e1'] ?? '';
$e2 = $_SESSION['e2'] ?? $_POST['e2'] ?? '';
$e3 = $_SESSION['e3'] ?? $_POST['e3'] ?? '';
/* for ($i = 1; $i <= 3; $i++) {
      if (${"e" . $i}) {
        $count_en++;
      }
    } */
if (isset($e1)) {
  // 檢查並轉換 $e1, $e2, $e3 的值
  $e1 = is_numeric($e1) ? (int)$e1 : 0;
  $e2 = is_numeric($e2) ? (int)$e2 : 0;
  $e3 = is_numeric($e3) ? (int)$e3 : 0;
  $_SESSION['e1'] = $e1;
  $_SESSION['e2'] = $e2;
  $_SESSION['e3'] = $e3;
}
if (isset($u1)) {
  $u1 = is_numeric($u1) ? (int)$u1 : 0;
  $u2 = is_numeric($u2) ? (int)$u2 : 0;
  $u3 = is_numeric($u3) ? (int)$u3 : 0;
  $_SESSION['u1'] = $u1;
  $_SESSION['u2'] = $u2;
  $_SESSION['u3'] = $u3;
}
// 讀取POST過來的bp_min / bp_max
/* $bp_min = isset($_POST['bp_min']) ? (int)$_POST['bp_min'] : 0;
$bp_max = isset($_POST['bp_max']) ? (int)$_POST['bp_max'] : 9999; */
/* print "bp_min=$bp_min";
print "bp_max=$bp_max"; */
//print $sql.' id='.$id;
/* if ($show_all) {
  $sql = "SELECT * FROM unlight where ico <> '' and id > 0;";
} else {
  $sql = "SELECT * FROM use_rate_unlight where count_ttl >(SELECT sum(count_ttl)/800 FROM use_rate_unlight) and id>0 order by id;";
} */
/* $i = 0;
$arr_sl = ""; // 確保變數已初始化
$arr1 = $db->query($sql); // 假設 $sql 是安全的，建議使用預備語句
$p_name = ""; // 避免未初始化的變數
while ($row = $arr1->fetch()) {
  $id = htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8'); // 避免 XSS
  $ico = htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8'); // 處理圖片路徑
  $name = htmlspecialchars($row[2], ENT_QUOTES, 'UTF-8'); // 處理名稱
  // 確保相同名稱的項目不重複顯示
  if ($p_name != $name) {
    $paddedStr = str_pad($name, 18, "　", STR_PAD_RIGHT);
    $paddedStr = "<span class='padded-name'>$paddedStr</span>";
    $arr_sl .= "<br>" . $paddedStr;
    }
  // 生成圖片 HTML
  $ico_html = '<img class="cropped-img" src="uploads/' . $ico . '" loading="lazy" height="100px">';
  // 生成 checkbox 和 label
  $arr_sl .= "<input type=\"checkbox\" id=\"$id\" name=\"ch[]\" value=\"$id\"><label for=\"$id\">$ico_html</label>";
  $i++;
  $p_name = $name; // 更新前一個名稱
} */
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
?>
<html lang="en">

<head>
  <!-- <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unlight</title> -->
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

  <!-- <script>
        var divs= $("input[name='skill[]']:checked").map(function() { return $(this).val(); }).get();
    </script> -->
  <!-- <script>
    function clearAll(ch) {
      var myCheckBox = document.getElementsByTagName('input');
      for (var i = 0; i < myCheckBox.length; i++) {
        if (myCheckBox[i].type == "checkbox") {
          myCheckBox[i].checked = false;
        }
      }
    }
  </script> -->

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


    /* ✅ 浮動按鈕 */
    #historyBtn {
      position: fixed;
      right: 32px;
      bottom: 110px;
      transform: translateY(50%);
      background-color: #28a745;
      color: white;
      border: none;
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 16px;
      cursor: pointer;
      box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.2);
      transition: background-color 0.3s ease, transform 0.3s ease;
      z-index: 999;
      /* 確保在最上層 */
    }

    /* ✅ 滑鼠移上去時改變顏色 */
    #historyBtn:hover {
      background-color: #218838;
    }

    /* ✅ 讓按鈕固定在「頂部按鈕」與「底部按鈕」之間 */
    @media (max-width: 768px) {
      #historyBtn {
        right: 15px;
        padding: 10px 14px;
        font-size: 14px;
      }
    }


    .fight-all {
      display: flex;
      flex-direction: column-reverse;
      align-items: center;
      /* gap: 20px; */
    }


    .fight-form {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 20px;
    }


    /* ✅ 偶數行灰色 */
    .gray-bg {
      background-color: rgba(181, 181, 181, 0.51);
    }

    /* ✅ 奇數行白色 */
    .white-bg {
      background-color: #ffffff;
    }

    /* 組合1 & 組合2組合 */



    .cost-char {
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    /* VS 置中 */
    .vs {
      font-size: 24px;
      font-weight: bold;
      /* padding: 10px; */
    }

    .timestamp {
      font-size: 16px;
      background-color: #f8f9fa;
      padding: 3px 6px;
      margin: 3px 0px;
      border-radius: 5px;
      display: inline-block;
    }

    .vs-container {
      text-align: center;
      width: 100px;
      display: flex;
      text-align: center;
      flex-direction: column;
    }

    .set-win-btn {
      background: rgb(181, 181, 191);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 6px;
      margin: 1px 4px;
      width: 36px;
    }

    .set-win-btn1 {
      background: #ff4e4e;
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 6px;
      margin: 1px 4px;
      width: 36px;
    }

    .set-win-btn2 {
      background: rgb(60, 153, 253);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 6px;
      margin: 1px 4px;
      width: 36px;
    }

    .set-tie-btn {
      background: rgb(0, 193, 61);
      border: solid 1px rgb(0, 193, 61);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 8px;
      /* margin: 2px 5px; */
      /* transform: scale(1.1); */
      width: 33px;
    }

    /* 設定標讀按鈕 */
    .skip-btn {
      background: rgb(181, 181, 191);
      border: solid 1px rgb(181, 181, 191);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 8px;
      /* margin: 2px 5px; */
      /* transform: scale(1.1); */
      width: 33px;
    }

    .set-del-btn {
      background: rgb(199, 39, 18);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 3px 8px;
      /* margin: 2px 5px; */
      /* transform: scale(1.1); */
      width: 33px;
    }


    /* COST 按鈕 */
    .cost-btn {
      width: auto;
      padding: 0px 5px;
      font-weight: bold;
      background: white;
      border-radius: 8px;
      border: 2px solid #ddd;
      box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
    }

    /* win rate 按鈕 */
    .wr-btn {
      /* width: 70px; */
      padding: 3px 8px;
      margin: 1px 0px;
      font-weight: bold;
      background: white;
      border-radius: 8px;
      border: 2px solid #ddd;
      box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
    }

    /* 勝利按鈕 */
    .enemy-win-btn {
      background: rgb(60, 153, 253);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      /* padding: 3px 8px; */
      /* transform: scale(1.1); */
      min-width: 70px;
    }

    /* 顯示未判按鈕 */
    .queue-btn {
      background: rgb(212, 189, 126);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      /* padding: 3px 8px; */
      /* transform: scale(1.1); */
      min-width: 70px;
    }

    /* 平手按鈕 */
    .tie-btn {
      background: rgb(42, 163, 95);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      /* padding: 3px 8px; */
      /* transform: scale(1.1); */
      min-width: 70px;
    }



    .player-win-btn {
      background: #ff4e4e;
      color: white;
      border-radius: 8px;
      font-weight: bold;
      /* padding: 3px 8px; */
      /* transform: scale(1.1); */
      min-width: 70px;
    }


    /* 勝率按鈕 */
    .winrate-container {
      margin-left: 40px;
    }

    .winrate-btn {
      background: green;
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 5px 15px;
    }

    .abgne-menu input[type="checkbox"] {
      display: none;
      /* 隱藏 checkbox */
    }

    .abgne-menu input[type="checkbox"]+label {
      display: inline-block;
      background-color: #ccc;
      cursor: pointer;
      padding: 5px;
      margin: 0 2px;
      border-radius: 5px;
      position: relative;
      width: 75px;
      height: 75px;
      overflow: hidden;
      transition: background-color 0.3s ease, border 0.3s ease;
      border: 5px solid transparent;
      /* 預設無外框 */
      box-sizing: border-box;
      /* 確保邊框內縮，不影響內部內容 */
    }

    .abgne-menu input[type="checkbox"]:checked+label {
      background-color: pink;
      border: 5px solid red;
      /* 勾選時加上粉紅色外框 */
    }

    /* 讓圖片放大 150% 但不影響 input checkbox */
    .cropped-img {
      width: 130%;
      height: 100%;
      object-fit: cover;
      object-position: top left;
      /* 讓圖片從左上角開始顯示 */
      transform: scale(1.2);
      /* 放大 150% */
      transition: transform 0.3s ease;
      /* 平滑過渡效果 */
    }

    .sticky-top {
      position: sticky;
      top: 0;
      z-index: 1000;
      background-color: dimgray;
      border-bottom: 2px solid #ddd;
      padding: 10px;
      border-radius: 5px;
      width: 380px;
      margin: auto;
      /* 自動左右外邊距，水平置中 */
    }

    .character-list {
      text-align: center;
    }

    .player-input {
      /* margin-top: 10px; */
      display: flex;
      flex-direction: column;
      /* gap: 5px; */
      width: 90px;
      align-items: center;
    }


    .player-input input {
      /* width: 70%; */
      padding: 5px;
      border-radius: 5px;
    }

    .navbar {
      position: sticky !important;
    }

    .navbar-nav>li>.dropdown-menu {
      margin-top: 13px;
    }

    .compare {
      color: red;
    }

    /* 角色卡片排列成一行 */
    .d-character {
      display: flex;
      flex-direction: column;
    }

    /* 角色圖片 */
    .d-character-img {
      height: 149px;
      border-radius: 8px;
      border: 1px solid #ccc;
    }

    .team {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2px 10px;
      gap: 5px;
      /* margin: 7px 0px; */

    }

    .char_weapon .team_event {
      display: wrap;

    }

    .weapon_attr {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-around;
      font-weight: bold;
    }

    a.text-dark {

      display: flex;
      justify-content: center;
    }

    .fight-container {
      gap: 0;
    }

    /* ✅ 有用到：fight 對戰區 */
    .counter-container {
      display: flex;
      align-items: center;
      gap: 8px;
      justify-content: center;
      padding: 3px 5px;
      border-radius: 11px;
    }

    /* ✅ 有用到：fight 小螢幕排列 */
    @media (max-width: 900px) {
      .counter-container {
        display: flex;
        flex-wrap: wrap;
        gap: 2px;
        margin: 10px 0px;
      }

      .vs {
        margin-right: 52px;
      }
    }

    .characters {
      gap: 0px;
      /* padding: 0px 8px; */
      /* position: relative; */
      /* display: inline-block; */
    }

    /* 馬賽克：放大再 pixelated 並加模糊 */
    .mosaic-mask img {
      transform: scale(10);
      image-rendering: pixelated;
      filter: blur(2px);
      width: calc(100% / 10);
      height: calc(100% / 10);
    }

    /* 按鈕覆蓋層 */
    .profile-overlay {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      z-index: 10;
    }

    .profile-overlay .btn {
      opacity: 0.9;
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
      width: 84px;
      height: 84px;
      position: relative;
      display: inline-block;
      border-radius: 5px;
      overflow: hidden;
    }

    .flip-container:hover {
      overflow: visible;
      z-index: 10;
    }

    /*======================
 3. 翻轉動畫（Flipper）
======================*/
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

    /*======================
 4. 正/反面樣式（Front & Back）
======================*/
    .front,
    .back {
      position: absolute;
      width: 100%;
      height: 100%;
      backface-visibility: hidden;
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
      top: -40px;
      left: 30px;
    }

    .flip-container.flipped .back img {
      transform: scale(2.1);
    }

    /*======================
 8. 縮放效果（Zoom Rank）
======================*/
    /* 1. 圖片本身裁切下半部 */
    .zoomout_rank {
      /* inset(top, right, bottom, left)：下半部遮掉 50% */
      clip-path: inset(0 0 50% 0);
      transform-origin: bottom center;
      /* 放大時以底部為中心 */
      transition: transform 0.2s linear;
    }

    /* 2. hover 時只做向上放大 */
    .zoomout_rank:hover {
      transform: scale(2);
    }

    /*======================
 9. 圖片彈性排版（Image Flex）
======================*/
    /* .image-flex {
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
    } */

    .characters>.flip-container:not(:first-child) {
      margin-left: -20px;
    }

    .event {
      /* transform: scale(0.8); */
      border-radius: 6px;
    }

    /*
    .event-group{
      display: flex;
      flex-direction: column;
    align-items: center;
    } */
    /* .event-group {
      display: inline-flex;
      flex-direction: column;
      align-items: center;
    } */

    .event-label {
      margin: 0;
      /* 清掉可能的預設 margin */
      padding: 0;
    }

    .event {
      margin: 0;
      vertical-align: middle;
      transform: scale(0.8);
      max-width: 44px;
      transition: transform 0.3s ease-in-out;
    }


    .event:hover {
      transform: scale(1.1);
      z-index: 10;
    }

    .event-slot-empty {
      width: 44px;
      height: 68px;
      transform: scale(0.8);
      background: repeating-linear-gradient(45deg,
          #ccc,
          #ccc 5px,
          #eee 5px,
          #eee 10px);
      border-radius: 5px;
    }

    /* .event img {
      width: 100%;
      height: 100%;
      transition: transform 0.3s ease-in-out;
      border-radius: 5px;
    } */
    .costEvent {
      display: flex;
      flex-direction: column;
    }

    .event-slot-empty::before {
      /* content: "？"; */
      font-weight: bold;
      font-size: 20px;
      color: #555;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100%;
    }

    .empty-container {
      display: flex;
      flex-direction: row;
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




  <?php



  /* print "combined=$combined";
  print "check=$check";
  print "e1=$e1";
  print "e2=$e2";
  print "e3=$e3"; */

  //print "player_name=$player_name";


  //if (isset($_POST['ch']) || isset($_POST['e1'])  || isset($_SESSION["e1"])) {
  $outPutString = '';
  if ($check == 1) {
    //print '<div class="main"></div>';
    if (!isset($_SESSION["daterange"])) {
      $today = date("Y-m-d");
      $_SESSION["daterange"] = "$today - $today"; // 預設值為今天
    }

    $current_daterange = $_SESSION["daterange"];
    list($start_date, $end_date) = explode(" - ", $current_daterange);
    $end_date = date("Y-m-d", strtotime($end_date)) . " 23:59:59";

    print '<div class="d-flex align-items-center" style="gap: 20px; margin: 20px 60px;">';
    print '<p class="text-danger fw-bold">
            ※網站聲明：本網站資料皆為「延後一小時」揭露，僅供資料分析使用，並不涉及即時遊戲內未公開資訊。
          </p>';
    print '<h4 class="mb-0">對戰統計【History】- 近期統計</h4>';

    // 2. 其他地方如果要顯示勝率，記得判斷

    // 勝率顯示判斷
    if (isset($check_rate) && $check_rate !== '') {
      $rate_color = progress_color($check_rate);
      echo '<div class="progress flex-grow-1" style="height: 33px;">';
      echo '<div class="badge bg-' . $rate_color . '" role="progressbar" style="width: ' . $check_rate . '%;padding: 6px 0px;font-size: 18px;">
      勝率 ' . $check_rate . '%
     </div>';
      echo '</div>';
    }

    $flag = 1;
    $use_flag = 1;
    $index = 0; // 追蹤行數

    /* print "combined=$combined";
    print "player_name=$player_name"; */
    if ($permission == 2) {
      $time_range = 0;
      $match_range = 200;
    } else {
      $time_range = 60; //延後x分鐘
      $match_range = 20; //顯示場數
    }
    // ✅ 黑名單玩家也享有不延遲顯示（等同管理員視角）
    if ($combined == 2 && !empty($player_name)) {
      $isTargetBlack = isBlackList($db, $player_name);
      if ($isTargetBlack) {
        $time_range = 0;     // 取消 60 分鐘延遲
        $match_range = 200;  // 放寬場數
      }
    }
    // 玩家搜尋
    if ($combined == 2) {
      // 這裡新增：目標玩家若在黑名單，放寬延遲與數量
      $isTargetBlack = isBlackList($db, $player_name);
      if ($isTargetBlack) {
        $time_range = 0;     // 取消 60 分鐘延遲
        $match_range = 200;  // 放寬場數
      }
      $sql = "SELECT *
          FROM (
            SELECT * FROM (
                SELECT 
                    id,cost,stage,e1, e2, e3, u1, u2, u3, win, lose, tie, update_time, username,
                    name_p1 AS player_name1, bp_p1 AS bp1,eventindex1 as eventindex1, eventindex2 as eventindex2,
                    name_p2 AS player_name2, bp_p2 AS bp2, ack1, ack2,win_p1,draw_p1,lose_p1
                FROM arena_unlight
                UNION ALL 
                SELECT 
                    id,cost,stage,u1, u2, u3, e1, e2, e3, lose, win, tie, update_time, username,
                    name_p2 AS player_name1, bp_p2 AS bp1,eventindex2 as eventindex1, eventindex1 as eventindex2, 
                    name_p1 AS player_name2, bp_p1 AS bp2, ack1, ack2, win_p2,draw_p2,lose_p2
                FROM arena_unlight
            ) AS temp
          WHERE temp.player_name1 = :player_name
            AND temp.update_time < (NOW() - INTERVAL :time_range MINUTE)
          ORDER BY temp.update_time DESC
          LIMIT $match_range
          ) AS recent
        -- 最後再把這 N 筆反向排序為 ASC
        ORDER BY recent.update_time ASC
        ";

      $params = [
        ':player_name' => $player_name,
        ':time_range' => $time_range
      ];
    } /* elseif ($combined == 1) { // 雙人
      $sql = "
          SELECT * FROM (
              SELECT 
                  e1, e2, e3, u1, u2, u3, win, lose, tie, update_time, username,
                  name_p1 AS player_name1, bp_p1 AS bp1, name_p2 AS player_name2, bp_p2 AS bp2, ack1, ack2, id,win_p1,draw_p1,lose_p1
              FROM arena_unlight
              UNION ALL 
              SELECT 
                  u1, u2, u3, e1, e2, e3, lose, win, tie, update_time, username,
                  name_p2 AS player_name1, bp_p2 AS bp1, name_p1 AS player_name2, bp_p1 AS bp2, ack1, ack2, id,win_p2,draw_p2,lose_p2
              FROM arena_unlight
          ) AS temp
          WHERE (
                (temp.e1 = :e1 AND temp.e2 = :e2)
             OR (temp.e1 = :e2 AND temp.e2 = :e1)
             OR (temp.e2 = :e1 AND temp.e3 = :e2)
             OR (temp.e2 = :e2 AND temp.e3 = :e1)
             OR (temp.e1 = :e1 AND temp.e3 = :e2)
             OR (temp.e1 = :e2 AND temp.e3 = :e1)
          )
            AND temp.update_time < (NOW() - INTERVAL :time_range MINUTE)
            AND temp.update_time BETWEEN :start_date AND :end_date
            AND temp.bp1 BETWEEN :bp_min AND :bp_max
          ORDER BY temp.update_time DESC
          LIMIT $match_range) AS recent
        -- 最後再把這 N 筆反向排序為 ASC
        ORDER BY recent.update_time ASC
        ";

      $params = [
        ':e1' => $e1,
        ':e2' => $e2,
        ':time_range' => $time_range,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':bp_min' => $bp_min,
        ':bp_max' => $bp_max
      ];
    } */ elseif ($combined == 0) { // 3人組
      $sql = "SELECT *
              FROM (SELECT * FROM (
                  SELECT 
                      id,cost,stage,e1, e2, e3, u1, u2, u3, win, lose, tie, update_time, username,
                      name_p1 AS player_name1, bp_p1 AS bp1,eventindex1 as eventindex1, eventindex2 as eventindex2,
                      name_p2 AS player_name2, bp_p2 AS bp2, ack1, ack2, win_p1,draw_p1,lose_p1
                  FROM arena_unlight
                  UNION ALL 
                  SELECT 
                      id,cost,stage,u1, u2, u3, e1, e2, e3, lose, win, tie, update_time, username,
                      name_p2 AS player_name1, bp_p2 AS bp1,eventindex2 as eventindex1, eventindex1 as eventindex2,
                      name_p1 AS player_name2, bp_p1 AS bp2, ack1, ack2, win_p2,draw_p2,lose_p2
                  FROM arena_unlight
              ) AS temp
              WHERE ((temp.e1 = :e1
                AND temp.e2 = :e2
                AND temp.e3 = :e3)OR
                (temp.e1 = :e1
                AND temp.e2 = :e3
                AND temp.e3 = :e2))
                AND temp.update_time < (NOW() - INTERVAL :time_range MINUTE)
                AND temp.update_time BETWEEN :start_date AND :end_date
                AND temp.bp1 BETWEEN :bp_min AND :bp_max
              ORDER BY temp.update_time DESC
              LIMIT $match_range) AS recent
            -- 最後再把這 N 筆反向排序為 ASC
            ORDER BY recent.update_time ASC
            ";

      $params = [
        ':e1' => $e1,
        ':e2' => $e2,
        ':e3' => $e3,
        ':time_range' => $time_range,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':bp_min' => $bp_min,
        ':bp_max' => $bp_max
      ];
    } /* elseif ($combined == 3) { // 模糊搜尋

      // 以 e1=5, 代表 0階角色 → 尋找 ID 1~10 → 5±5
      $range1_min = $e1 - 5;
      $range1_max = $e1 + 4;
      $range2_min = $e2 - 5;
      $range2_max = $e2 + 4;
      $range3_min = $e3 - 5;
      $range3_max = $e3 + 4;

      $sql = "
          SELECT *
          FROM (SELECT * FROM (
              SELECT 
                  e1, e2, e3, u1, u2, u3, win, lose, tie, update_time, username,
                  name_p1 AS player_name1, bp_p1 AS bp1, name_p2 AS player_name2, bp_p2 AS bp2, ack1, ack2, id,win_p1,draw_p1,lose_p1
              FROM arena_unlight
              UNION ALL 
              SELECT 
                  u1, u2, u3, e1, e2, e3, lose, win, tie, update_time, username,
                  name_p2 AS player_name1, bp_p2 AS bp1, name_p1 AS player_name2, bp_p1 AS bp2, ack1, ack2, id,win_p2,draw_p2,lose_p2
              FROM arena_unlight
          ) AS temp
           WHERE (
      (temp.e1 BETWEEN :r1min AND :r1max AND temp.e2 BETWEEN :r2min AND :r2max AND temp.e3 BETWEEN :r3min AND :r3max) OR
      (temp.e1 BETWEEN :r1min AND :r1max AND temp.e2 BETWEEN :r3min AND :r3max AND temp.e3 BETWEEN :r2min AND :r2max) OR
      (temp.e1 BETWEEN :r2min AND :r2max AND temp.e2 BETWEEN :r1min AND :r1max AND temp.e3 BETWEEN :r3min AND :r3max) OR
      (temp.e1 BETWEEN :r2min AND :r2max AND temp.e2 BETWEEN :r3min AND :r3max AND temp.e3 BETWEEN :r1min AND :r1max) OR
      (temp.e1 BETWEEN :r3min AND :r3max AND temp.e2 BETWEEN :r1min AND :r1max AND temp.e3 BETWEEN :r2min AND :r2max) OR
      (temp.e1 BETWEEN :r3min AND :r3max AND temp.e2 BETWEEN :r2min AND :r2max AND temp.e3 BETWEEN :r1min AND :r1max)
  )
            AND temp.update_time < (NOW() - INTERVAL :time_range MINUTE)
            AND temp.update_time BETWEEN :start_date AND :end_date
            AND temp.bp1 BETWEEN :bp_min AND :bp_max
          ORDER BY temp.update_time DESC
          LIMIT $match_range) AS recent
        -- 最後再把這 N 筆反向排序為 ASC
        ORDER BY recent.update_time ASC
        ";

      $params = [
        ':r1min' => $range1_min,
        ':r1max' => $range1_max,
        ':r2min' => $range2_min,
        ':r2max' => $range2_max,
        ':r3min' => $range3_min,
        ':r3max' => $range3_max,
        ':time_range' => $time_range,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':bp_min' => $bp_min,
        ':bp_max' => $bp_max
      ];
    } */

    // --- 這裡不變 ---
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $bp1_pre = 0;
    $unknown_pre = 0;
    $opponent_bp = 0;
    $bp_after = 1500;
    $result_print = '';
    $wdl = '';
    // 在外層先準備好同一份 SQL，只要改 name 就能重覆使用
    // 假設 $rawDate 是對戰的 update_time，例如 "2025-06-08 23:42:00"
    /* $dt = new DateTime($rawDate);
    $dt->modify('+1 day');
    $nextMidnight = $dt->format('Y-m-d') . ' 00:00:00'; */

    // 準備一條 COALESCE + 兩個子查詢的 SQL
    // 在 while 迴圈外先準備好兩個預處理
    $stmtRank = $db->prepare(
      <<<'SQL'
      SELECT COALESCE(
        ( SELECT rank_num
            FROM ranking_bp_TW
          WHERE ts = :next_midnight AND name = :name
          ORDER BY ts ASC
          LIMIT 1
        )
      ) AS rank_num
    SQL
    );

    $stmtRank_JP = $db->prepare(
      <<<'SQL'
      SELECT COALESCE(
        ( SELECT rank_num
            FROM ranking_bp_JP
          WHERE ts = :next_midnight AND name = :name
          ORDER BY ts ASC
          LIMIT 1
        )
      ) AS rank_num
    SQL
    );
    $outPutString .= '<div class="fight-all">';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $use_flag = 0;
      $flag = 0;
      $bg_class = ($index % 2 == 0) ? "gray-bg" : "white-bg"; // 設定背景顏色
      $index++; // 增加索引計數
      $id = $row['id'];
      $costRule = $row['cost'] . 'C';
      if ($costRule >= 90) {
        $costRule = '90+';
      }
      $stage = (int)$row['stage'];
      $showStage = isset($stages[$stage])
        ? $stages[$stage]
        : '未知場景';

      $win         = $row['win'];
      $lose        = $row['lose'];
      $tie         = $row['tie'];
      //$date = $row[8];
      $rawDate  = $row['update_time'];
      $nextMidnight = date('Y-m-d 00:00:00', strtotime($rawDate . ' +1 day'));

      $player_name1 = (string) ($row['player_name1'] ?? '');
      $bp1          = $row['bp1'];
      $player_name2 = (string) ($row['player_name2'] ?? '');
      $bp2          = $row['bp2'];
      $eventindex1 = $row['eventindex1'];
      $eventindex2 = $row['eventindex2'];
      $ack1 = $row['ack1'];
      $ack2 = $row['ack2'];
      $win_p1 = $row['win_p1'];
      $draw_p1 = $row['draw_p1'];
      $lose_p1 = $row['lose_p1'];
      //print $eventindex1.'<br>';

      // 查 P1 的排名
      $stmtRank->execute([
        ':next_midnight' => $nextMidnight,
        ':name'          => $player_name1,
      ]);
      $rankNumP1 = $stmtRank->fetchColumn();
      if ($rankNumP1 === null) {
        // 查 P1 JP的排名
        $stmtRank_JP->execute([
          ':next_midnight' => $nextMidnight,
          ':name'          => $player_name1,
        ]);
        $rankNumP1 = $stmtRank_JP->fetchColumn();
        if ($rankNumP1 === null) {
          $rankNumP1 = '';  // 沒找到就顯示破折號
        } else {
          $rankNumP1 = 'JP#' . $rankNumP1;
        }
      } else {
        $rankNumP1 = '#' . $rankNumP1;
      }
      // 「屏蔽」個位數：將所有數字字元以 X 取代
      if ($rankNumP1 !== '') {
        // 方式一：substr
        $rankNumP1_masked = substr($rankNumP1, 0, -1) . 'X';
      }

      // 查 P2 的排名
      $stmtRank->execute([
        ':next_midnight' => $nextMidnight,
        ':name'          => $player_name2,
      ]);
      $rankNumP2 = $stmtRank->fetchColumn();
      if ($rankNumP2 === null) {
        $stmtRank_JP->execute([
          ':next_midnight' => $nextMidnight,
          ':name'          => $player_name2,
        ]);
        $rankNumP2 = $stmtRank_JP->fetchColumn();
        if ($rankNumP2 === null) {
          $rankNumP2 = '';  // 沒找到就顯示破折號
        } else {
          $rankNumP2 = 'JP#' . $rankNumP2;
        }
      } else {
        $rankNumP2 = '#' . $rankNumP2;
      }
      // 「屏蔽」個位數：將所有數字字元以 X 取代
      if ($rankNumP2 !== '') {
        // 方式一：substr
        $rankNumP2_masked = substr($rankNumP2, 0, -1) . 'X';
      }

      // 事先給預設，避免未定義
      $primaryId  = null;
      $primaryId2 = null;

      // 解析 JSON
      $arr1 = json_decode($row['eventindex1'], true);
      $arr2 = json_decode($row['eventindex2'], true);
      if (!is_array($arr1)) $arr1 = [];
      if (!is_array($arr2)) $arr2 = [];

      // 取得最常用 event
      $evt1 = getMostUsedEvent($arr1);
      $evt2 = getMostUsedEvent($arr2);

      // ─── 處理 evt1 ────────────────────────────────────

      // 1. 統計
      $typeCounts = array_fill_keys(['sword', 'gun', 'shield', 'shift', 'special'], 0);
      $numCounts  = array_fill_keys(['sword', 'gun', 'shield', 'shift', 'special'], []);
      foreach ($arr1 as $raw) {
        if ($raw === 'null' || is_null($raw)) continue;
        $eid = (int)$raw;
        foreach (['Sword', 'Gun', 'Shield', 'Shift', 'Special'] as $T) {
          $mapVar = 'eventMap' . $T;
          $v      = ${$mapVar}[$eid] ?? 0;
          if ($v > 0) {
            $key = strtolower($T);
            $typeCounts[$key]++;
            $numCounts[$key][$v] = ($numCounts[$key][$v] ?? 0) + 1;
            // break; // 如果一張卡只算一次屬性
          }
        }
      }
      /* if ($username == 'way.lee') {
        print $typeCounts[$key].',';
      } */

      $maxCount  = 0;
      $maxType   = null;
      $maxSubKey = null;

      foreach ($numCounts as $type => $subCounts) {
        foreach ($subCounts as $subKey => $cnt) {
          if ($cnt > $maxCount) {
            $maxCount  = $cnt;
            $maxType   = $type;
            $maxSubKey = $subKey;
          }
        }
      }

      $id1   = (int)$evt1['id'];
      $count1 = $evt1['count'];

      /* if ($username == 'way.lee') {
        print $name1.':'.$count1.', ';
      } */
      // 從 typeCounts 裡取這個 id 的次數，若不存在就當 0
      //$limit1 = $typeCounts[$id1] ?? 0;
      if ($count1 > 9 || $count1 > $maxCount) {
        // 出現夠多，直接顯示
        if ($evt1['id'] === 'null' || is_null($evt1['id'])) {
          $ico1  = '';
          $name1 = 'NULL';
        } else {
          // 普通顯示
          $ico1  = $eventMapIco[$id1]  ?? 'na_event.png';
          $name1 = $eventMapName[$id1] ?? '?';
        }
      } else {
        // 2. 找 maxType / majorNum
        $keys = array_keys($typeCounts, max($typeCounts), true);
        $maxType = $keys[0] ?? null;
        $majorNum = null;

        if ($maxType !== null && !empty($numCounts[$maxType])) {
          arsort($numCounts[$maxType]);
          $majorNum = key($numCounts[$maxType]);
        }

        // 3. 收集符合「主屬性 = majorNum && 其他 = 0」
        if ($maxType !== null && $majorNum !== null) {
          $allMaps = [
            'sword' => $eventMapSword,
            'gun' => $eventMapGun,
            'shield' => $eventMapShield,
            'shift' => $eventMapShift,
            'special' => $eventMapSpecial,
          ];
          $mainMap   = $allMaps[$maxType];
          $otherMaps = $allMaps;
          unset($otherMaps[$maxType]);

          $filtered = [];
          foreach ($mainMap as $eid => $val) {
            if ($val !== $majorNum) continue;
            $ok = true;
            foreach ($otherMaps as $map) {
              if (($map[$eid] ?? 0) > 0) {
                $ok = false;
                break;
              }
            }
            if ($ok) $filtered[] = $eid;
          }

          // 4. 計算出現頻率（含未出現＝0）並排序
          $jsonCounts = [];
          foreach ($arr1 as $r) {
            if (is_int($r) || is_string($r)) {
              $jsonCounts[(string)$r] = ($jsonCounts[(string)$r] ?? 0) + 1;
            }
          }
          $fullCounts = [];
          foreach ($filtered as $eid) {
            $fullCounts[$eid] = $jsonCounts[(string)$eid] ?? 0;
          }
          arsort($fullCounts);
          $primaryId = key($fullCounts) ?: null;
        }

        // 5. 設定 icon/name
        if ($primaryId) {
          $ico1  = $eventMapIco[$primaryId];
          $name1 = $eventMapName[$primaryId];
        } else {
          $ico1  = '';
          $name1 = '';
        }
      }

      // ─── 處理 evt2 ────────────────────────────────────
      // 1. 統計
      $typeCounts = array_fill_keys(['sword', 'gun', 'shield', 'shift', 'special'], 0);
      $numCounts  = array_fill_keys(['sword', 'gun', 'shield', 'shift', 'special'], []);
      //$numCounts[$key][$v]=0;
      foreach ($arr2 as $raw) {
        // 跳過 null
        if ($raw === 'null' || $raw === null || trim($raw) === '') {
          continue;
        }
        $eid = (int)$raw;

        // 五個屬性的對映表都一樣的迴圈邏輯
        foreach (['Sword', 'Gun', 'Shield', 'Shift', 'Special'] as $T) {
          $mapVar = 'eventMap' . $T;
          $v      = ${$mapVar}[$eid] ?? 0;
          if ($v > 0) {
            $key = strtolower($T);

            // 這裡保證 key 已經存在，所以++ 不會炸
            $typeCounts[$key]++;

            // 這裡保證 numCounts[$key] 已經是陣列
            if (! isset($numCounts[$key][$v])) {
              $numCounts[$key][$v] = 0;
            }
            $numCounts[$key][$v]++;

            // 如果一張卡只想算一次屬性，就 break 掉
            // break;
          }
        }
      }
      $maxCount  = 0;
      $maxType   = null;
      $maxSubKey = null;

      foreach ($numCounts as $type => $subCounts) {
        foreach ($subCounts as $subKey => $cnt) {
          if ($cnt > $maxCount) {
            $maxCount  = $cnt;
            $maxType   = $type;
            $maxSubKey = $subKey;
          }
        }
      }
      // 同理做第二組
      $id2    = $evt2['id'];
      $count2 = $evt2['count'];
      //$limit2 = $typeCounts[$id2] ?? 0;
      /* if ($username == 'way.lee') {
        echo "全域最大出現次數：{$maxCount}\n";
        echo "屬性類型：{$maxType}\n";
        echo "該值：{$maxSubKey}\n";
        print "count2=$count2";
        print "maxCount=$maxCount";
      } */
      if ($count2 > 9 || $count2 > $maxCount) {
        // 出現夠多，直接顯示
        if ($evt2['id'] === 'null' || is_null($evt2['id'])) {
          $ico2  = '';
          $name2 = 'NULL';
        } else {
          //$id2   = (int)$evt2['id'];
          $ico2  = $eventMapIco[$id2]  ?? 'na_event.png';
          $name2 = $eventMapName[$id2] ?? '?';
        }
      } else {
        // 2. 找 maxType / majorNum
        $keys = array_keys($typeCounts, max($typeCounts), true);
        $maxType = $keys[0] ?? null;
        $majorNum = null;

        if ($maxType !== null && !empty($numCounts[$maxType])) {
          arsort($numCounts[$maxType]);
          $majorNum = key($numCounts[$maxType]);
        }

        // 3. 收集符合「主屬性 = majorNum && 其他 = 0」
        if ($maxType !== null && $majorNum !== null) {
          $allMaps = [
            'sword' => $eventMapSword,
            'gun' => $eventMapGun,
            'shield' => $eventMapShield,
            'shift' => $eventMapShift,
            'special' => $eventMapSpecial,
          ];
          $mainMap   = $allMaps[$maxType];
          $otherMaps = $allMaps;
          unset($otherMaps[$maxType]);

          $filtered = [];
          foreach ($mainMap as $eid => $val) {
            if ($val !== $majorNum) continue;
            $ok = true;
            foreach ($otherMaps as $map) {
              if (($map[$eid] ?? 0) > 0) {
                $ok = false;
                break;
              }
            }
            if ($ok) $filtered[] = $eid;
          }

          // 4. 計算出現頻率（含未出現＝0）並排序
          $jsonCounts = [];
          foreach ($arr2 as $r) {
            if (is_int($r) || is_string($r)) {
              $jsonCounts[(string)$r] = ($jsonCounts[(string)$r] ?? 0) + 1;
            }
          }
          $fullCounts = [];
          foreach ($filtered as $eid) {
            $fullCounts[$eid] = $jsonCounts[(string)$eid] ?? 0;
          }
          arsort($fullCounts);
          $primaryId = key($fullCounts) ?: null;
        }

        // 5. 設定 icon/name
        if ($primaryId) {
          $ico2  = $eventMapIco[$primaryId];
          $name2 = $eventMapName[$primaryId];
        } else {
          $ico2  = '';
          $name2 = '';
        }
      }

      $showDate = date('m-d H:i', strtotime($rawDate));
      $unknown = 0;
      if ($win + $lose + $tie == 0) {
        $unknown = 1;
      }
      if ($permission == 2) {
        $wdl = $win_p1 . '/' . $draw_p1 . '/' . $lose_p1;
      }
      if ($unknown == 0 && $unknown_pre == 0) {
        $wdl = '';
      }
      if ($bp1_pre) {
        $result = ($bp1 - $bp1_pre >= 10) ? 1 : (($bp1 - $bp1_pre <= -10) ? 0 : 0.5);
        //$result_print = ($bp1 - $bp1_pre >= 10) ? "勝" : (($bp1 - $bp1_pre <= -10) ? "負" : "平");
        // 將結果套上顏色
        // 套用顏色
        switch ($result) {
          case "1":
            $result_print = '<span style="color:blue;">勝</span>';
            $result_print_p2 = '<span style="color:red;">負</span>';
            break;
          case "0":
            $result_print = '<span style="color:red;">負</span>';
            $result_print_p2 = '<span style="color:blue;">勝</span>';
            break;
          default:
            $result_print = '<span style="color:green;">平</span>';
            $result_print_p2 = '<span style="color:green;">平</span>';
            break;
        }
        $bp_after = round($bp1_pre + 32 * ($result - 1 / (1 + pow(10, (($opponent_bp - $bp1_pre) / 500)))), 0);
      }
      // 假設 $win==1 代表 Team2 勝，否則視為敗
      $teamWon = (((int)$win) === 1);
      //print "teamWon=$teamWon";
      $opponents = [$row['u1'], $row['u2'], $row['u3']];
      foreach ($opponents as $cid) {
        if (!isset($counterStats[$cid])) {
          $counterStats[$cid] = ['wins' => 0, 'losses' => 0];
        }
        if ($teamWon) {
          $counterStats[$cid]['wins']++;
        } else {
          $counterStats[$cid]['losses']++;
        }
      }

      $show_private1 = getShowPrivate($db, $player_name1);
      $show_private2 = getShowPrivate($db, $player_name2);
      $isBlackList1 = isBlackList($db, $player_name1);
      $isBlackList2 = isBlackList($db, $player_name2);

      $cost_sum_team1 = 0;
      $cost_sum_team2 = 0;
      //$outPutString .= '<div class="fight-form">';
      if ($unknown == 0 || $permission == 2) {
        $outPutString .= '<div class="fight-container ' . $bg_class . '">'; // ✅ 加入背景顏色

        $cost_sum = 0;
        $img_arr = '';
        $punishment = 0;
        $cost_color = '';
        unset($cost_arr);
        // 組合1組合

        $outPutString .= '<div class="team">';

        for ($i = 1; $i <= 3; $i++) {
          $cid = $row["e{$i}"];
          $sql = "SELECT ico,cost FROM unlight WHERE id='$cid';";
          $arr = $db->query("$sql");
          while ($row1 = $arr->fetch()) {
            $ico = $row1[0];
            $cost = $row1[1];
            $cost_sum += $cost;
            $cost_arr[] = $cost;
            $img_arr .= '<div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
              <img class="character-img" src="uploads/' . $ico . '" loading="lazy">
            </div>';
          }
          /* if (!is_array($cost_arr) || count($cost_arr) < 3) {
            $punishment = 0;
          } else {
            $punishment = cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
          }
          $cost_sum += $punishment;
          $cost_color = ($punishment > 0) ? 'text-danger' : ''; // 設定紅色類別 */
        }

        // ❗懲罰應該在 cost_arr 三張都抓完再計算
        if (count($cost_arr) === 3) {
          $punishment = cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
          $cost_sum += $punishment;
          $cost_color = ($punishment > 0) ? 'text-danger' : '';
        } else {
          $punishment = 0;
          $cost_color = '';
        }


        $outPutString .= '<div class="costEvent"><button type="button" class="btn btn-light cost-btn '
          . $cost_color
          . '">'
          . $costRule
          . '</button>';
        // --- 第一隊事件 icon & 名稱 ---
        //$outPutString .= '<span class="evt-group">';
        //$outPutString .= '主要<br>';

        //$outPutString .= '</span>';
        // 如果沒圖就不印

        if ($combined == 2 && !($permission == 2 || $show_private1 || $player_name1 == $username || $isBlackList1)) {
          $outPutString .= '<span></span></div>';
          // ─────────────── 修改開始 ───────────────
          $outPutString .= '<div class="characters">';
          $outPutString .= '<div class="empty-container">';
          $outPutString .= '<div class="event-slot-empty" title="未公開角色"></div>';
          $outPutString .= '<div class="event-slot-empty" title="未公開角色"></div>';
          $outPutString .= '<div class="event-slot-empty" title="未公開角色"></div>';
          $outPutString .= '</div>';
          $outPutString .= '</div>';
          // ─────────────── 修改結束 ───────────────
        } else {
          if ($ico1) {
            $outPutString .= '<img src="uploads/' . htmlspecialchars($ico1) .
              '" class="event" title="' . htmlspecialchars($name1) . '" /></div>';
          } else {
            $outPutString .= '<span class="event-slot-empty"></span></div>';
          }
          $outPutString .= '<div class="characters">';
          $outPutString .= $img_arr;
          $outPutString .= '</div>'; //characters
        }

        $outPutString .= '<div class="player-input">';
        if ($unknown == 1) {
          $outPutString .= '<button type="button" class="btn btn-light wr-btn queue-btn">未判<br>' . $wdl . '</button>';
        } elseif ($tie == 1) {
          $outPutString .= '<button type="button" class="btn btn-light wr-btn tie-btn">平<br>' . $wdl . '</button>';
        } elseif ($lose == 1) {
          $outPutString .= '<button type="button" class="btn btn-light wr-btn enemy-win-btn">勝<br>' . $wdl . '</button>';
        } else {
          $outPutString .= '<button type="button" class="btn btn-light wr-btn player-win-btn">負<br>' . $wdl . '</button>';
        }

        $search_link = 'fight.php?player_name=' . rawurlencode($player_name1);
        $rank_text   = (string) $rankNumP1;
        $rank_badge  = $rank_text !== ''
          ? '<span class="badge badge-pill badge-info">' . htmlspecialchars($rank_text, ENT_QUOTES, 'UTF-8') . '</span> '
          : '';

        // 先統一準備連結與徽章（徽章只吃純文字）
        $search_link = 'fight.php?player_name=' . rawurlencode($player_name1);
        $rank_text   = isset($rankNumP1) ? (string)$rankNumP1 : '';
        $rank_badge  = $rank_text !== ''
          ? '<span class="badge badge-pill badge-info">' . htmlspecialchars($rank_text, ENT_QUOTES, 'UTF-8') . '</span> '
          : '';

        if ($permission == 2 || $show_private1 || $player_name1 == $username || $isBlackList1 || $combined == 2) {
          // 連結：徽章 → 名字（黑名單多一個紅色 ? 放在連結內）
          $outPutString .=
            '<a href="' . htmlspecialchars($search_link, ENT_QUOTES, 'UTF-8') . '" class="player-name">'
            . $rank_badge
            . htmlspecialchars($player_name1, ENT_QUOTES, 'UTF-8');

          if ($isBlackList1) {
            $outPutString .= ' <span class="badge badge-danger" style="background-color:#e52626;">?</span>';
          }

          $outPutString .= '</a>';

          // 未判
          if ($unknown_pre == 1 && $permission == 2) {
            $outPutString .= '<button type="button" class="btn btn-light wr-btn">BP <span class="compare">'
              . htmlspecialchars($bp1, ENT_QUOTES, 'UTF-8') . '</span><br>'
              . $result_print . '↓(<span class="compare">'
              . htmlspecialchars($bp_after, ENT_QUOTES, 'UTF-8') . '</span>)</button>';
          } else {
            $outPutString .= '<button type="button" class="btn btn-light wr-btn">BP '
              . htmlspecialchars($bp1, ENT_QUOTES, 'UTF-8') . '</button>';
          }
        } else {
          // 將整數轉成字串
          $bp_str = (string)$bp1;

          // 遮罩處理：長度 >= 2 才取前段；否則只顯示 'XX'
          $bp_masked = (strlen($bp_str) >= 2) ? substr($bp_str, 0, -2) . 'XX' : 'XX';

          // 公開視圖不給連結；若要保留樣式，改用 span 承接 player-name
          if (strlen($rank_text) > 0) {
            $outPutString .= '<span class="player-name">' . $rank_badge . '</span>';
          }

          $outPutString .= '<button type="button" class="btn btn-light wr-btn">BP ' . htmlspecialchars($bp_masked, ENT_QUOTES, 'UTF-8') . '</button>';
        }

        //print '<input type="hidden" name="upload_token" value="' . $_SESSION['upload_token'] . '">';
        $outPutString .= '</div>';

        $cost_sum = 0;
        $img_arr = '';
        $punishment = 0;
        $cost_color = '';
        unset($cost_arr);

        $outPutString .= '</div>';

        // VS 標示
        $outPutString .= '<div class="vs-container">';

        //if ($permission == 2) {
        if (1) {
          $outPutString .= '<div class="detail-input">
          <form action="detail.php" method="POST">
            <input type="hidden" name="match_id" value="' . $id . '">
            <input type="hidden" name="player_name" value="' . $player_name1 . '">
            <button type="submit" name="check_detail" value="1" class="btn btn-sm btn-default skip2-btn"  style="    font-size: 16px;"  >
              🔍 詳細
            </button>
          </form>
        </div>';
          //echo "Fight表單送出的 match_id=$id, player_name=$player_name1<br>";
        }
        $outPutString .= '<span class="timestamp">' . $showDate . '</span>';
        // set_win
        if ($permission == 2 && $win + $lose + $tie < 1) {
          $outPutString .= '<form action="queue.php?debug=1" method="POST">';
          if ($result == 1) {
            $outPutString .= '<button type="submit" name="set_win1" value="' . $id . '" class="btn btn-default set-win-btn2" >勝</button>';
            $outPutString .= '<button type="submit" name="set_win2" value="' . $id . '" class="btn btn-default set-win-btn1" >勝</button>';
          } elseif ($result == 0) {
            $outPutString .= '<button type="submit" name="set_win1" value="' . $id . '" class="btn btn-default set-win-btn2" >勝</button>';
            $outPutString .= '<button type="submit" name="set_win2" value="' . $id . '" class="btn btn-default set-win-btn1" >勝</button>';
          } else {

            $outPutString .= '<button type="submit" name="set_win1" value="' . $id . '" class="btn btn-default set-win-btn" >勝</button>';
            $outPutString .= '<button type="submit" name="set_win2" value="' . $id . '" class="btn btn-default set-win-btn" >勝</button>';
          }
          $outPutString .= '<div class="btn-group">';
          /* <button type="button" class="btn btn-default">Left</button>
                        <button type="button" class="btn btn-default">Middle</button>
                        <button type="button" class="btn btn-default">Right</button> */
          $outPutString .= '<button type="submit" name="tie" value="' . $id . '" class="btn btn-success set-tie-btn">平</button>';
          $outPutString .= '<button type="submit" name="is_read" value="' . $id . '" class="btn btn-default skip-btn">讀</button>';
          $outPutString .= '<button type="submit" name="del" value="' . $id . '" class="btn btn-danger set-del-btn">刪</button>';
          $outPutString .= '</div>';
          $outPutString .= '<input type="hidden" name="player_name1" value="' . htmlspecialchars($player_name1, ENT_QUOTES) . '">';
          $outPutString .= '<input type="hidden" name="player_name2" value="' . htmlspecialchars($player_name2, ENT_QUOTES) . '">';
          $outPutString .= '</form>';
        } else {
          $outPutString .= '<span class="timestamp">' . htmlspecialchars($showStage, ENT_QUOTES) . '</span>';
        }
        $outPutString .= '</div>';

        // 組合2組合
        $outPutString .= '<div class="team">';

        for ($i = 1; $i <= 3; $i++) {
          $cid = $row["u{$i}"];
          $sql = "SELECT ico,cost,CONCAT(`level`, `name`) AS levelName,ico_back  FROM unlight WHERE id='$cid';";
          $arr = $db->query("$sql");
          while ($row1 = $arr->fetch()) {
            $ico = $row1[0];
            $cost = $row1[1];
            $levelName = $row1[2];
            $ico_back = $row1[3];
            $cost_sum += $cost;
            $cost_arr[] = $cost;
            $img_arr .= '<div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
              <img class="character-img" src="uploads/' . $ico . '" loading="lazy">
            </div>';

            $counterStats[$cid]['levelName'] = $levelName;
            $counterStats[$cid]['ico'] = $ico;
            $counterStats[$cid]['ico_back'] = $ico_back;
          }
        }

        // ❗懲罰應該在 cost_arr 三張都抓完再計算
        if (count($cost_arr) === 3) {
          $punishment = cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
          $cost_sum += $punishment;
          $cost_color = ($punishment > 0) ? 'text-danger' : '';
        } else {
          $punishment = 0;
          $cost_color = '';
        }

        $outPutString .= '<div class="costEvent"><button type="button" class="btn btn-light cost-btn '
          . $cost_color
          . '">'
          . $costRule
          . '</button>';
        // 如果沒圖就不印




        if ($combined == 2 && !($permission == 2 || $show_private2 || $isBlackList2)) {
          $outPutString .= '<span></span></div>';
          // ─────────────── 修改開始 ───────────────
          $outPutString .= '<div class="characters">';
          $outPutString .= '<div class="empty-container">';
          $outPutString .= '<div class="event-slot-empty" title="未公開角色"></div>';
          $outPutString .= '<div class="event-slot-empty" title="未公開角色"></div>';
          $outPutString .= '<div class="event-slot-empty" title="未公開角色"></div>';
          $outPutString .= '</div>';
          $outPutString .= '</div>';
          // ─────────────── 修改結束 ───────────────
        } else {
          if ($ico2) {
            $outPutString .= '<img src="uploads/' . htmlspecialchars($ico2) .
              '" class="event" title="' . htmlspecialchars($name2) . '" /></div>';
          } else {
            $outPutString .= '<span class="event-slot-empty"></span></div>';
          }
          $outPutString .= '<div class="characters">';
          $outPutString .= $img_arr;
          $outPutString .= '</div>'; //characters
        }

        $outPutString .= '<div class="player-input">';
        if ($unknown == 1) {
          $outPutString .= '<button type="button" class="btn btn-light wr-btn queue-btn">未判</button>';
        } elseif ($tie == 1) {
          $outPutString .= '<button type="button" class="btn btn-light wr-btn tie-btn">平</button>';
        } elseif ($win == 1) {
          $outPutString .= '<button type="button" class="btn btn-light wr-btn enemy-win-btn">勝</button>';
        } else {
          $outPutString .= '<button type="button" class="btn btn-light wr-btn player-win-btn">負</button>';
        }

        // 統一準備：連結與徽章（徽章只吃純文字）
        $search_link2 = 'fight.php?player_name=' . rawurlencode($player_name2);
        $rank_text2   = isset($rankNumP2) ? (string)$rankNumP2 : '';
        $rank_badge2  = $rank_text2 !== ''
          ? '<span class="badge badge-pill badge-info">' . htmlspecialchars($rank_text2, ENT_QUOTES, 'UTF-8') . '</span> '
          : '';

        if ($permission == 2 || $show_private2 || $isBlackList2 || $combined == 2) {
          // 允許顯示詳情：用 <a>，順序＝徽章 → 名字（黑名單多一個紅色 ?）
          $outPutString .=
            '<a href="' . htmlspecialchars($search_link2, ENT_QUOTES, 'UTF-8') . '" class="player-name">'
            . $rank_badge2
            . htmlspecialchars($player_name2, ENT_QUOTES, 'UTF-8');

          if ($isBlackList2) {
            $outPutString .= ' <span class="badge badge-danger" style="background-color:#e52626;">?</span>';
          }

          $outPutString .= '</a>';

          // 未判
          if ($unknown_pre == 1 && $permission == 2) {
            $outPutString .= '<button type="button" class="btn btn-light wr-btn">BP <span class="compare">'
              . htmlspecialchars($bp2, ENT_QUOTES, 'UTF-8') . '</span><br>'
              . $result_print_p2 . '↓</button>';
          } else {
            $outPutString .= '<button type="button" class="btn btn-light wr-btn">BP '
              . htmlspecialchars($bp2, ENT_QUOTES, 'UTF-8') . '</button>';
          }
        } elseif ($player_name1 == $username) {
          // 不給超連結：用 <span>，但仍保持同樣視覺（徽章 → 名字）
          $outPutString .= '<span class="player-name">'
            .  $rank_badge2
            .  htmlspecialchars($player_name2, ENT_QUOTES, 'UTF-8')
            .  '</span>';
          $outPutString .= '<button type="button" class="btn btn-light wr-btn">BP '
            .  htmlspecialchars($bp2, ENT_QUOTES, 'UTF-8') . '</button>';
        } else {
          // 隱私：BP 遮罩；同樣顯示徽章但不連結
          $bp_str2    = (string)$bp2;
          $bp_masked2 = (strlen($bp_str2) >= 2) ? substr($bp_str2, 0, -2) . 'XX' : 'XX';

          if ($rank_text2 !== '') {
            $outPutString .= '<span class="player-name">' . $rank_badge2 . '</span>';
          }

          $outPutString .= '<button type="button" class="btn btn-light wr-btn">BP '
            .  htmlspecialchars($bp_masked2, ENT_QUOTES, 'UTF-8') . '</button>';
        }

        $outPutString .= '</div>'; //-player-input
        $outPutString .= '</div>'; //team
        $outPutString .= '</div>'; // fight-container
        //$outPutString .= '</div>'; // fight-form

        $bp1_pre = $bp1;
        $unknown_pre = $unknown;
        $opponent_bp = $bp2;
      }
    }

    $outPutString .= '</div>'; //fight-all
  }
  // 1. 先將關聯陣列轉為索引陣列，並計算每個角色的勝率（0～1 之間）
  $counterStats = $counterStats ?? [];
  $entries = [];
  // 1. 初始化
  //$counterStats = [];
  foreach ($counterStats as $cid => $data) {
    $total = $data['wins'] + $data['losses'];
    $rate  = $total ? ($data['wins'] / $total) : 0.0;
    $entries[] = [
      'cid'       => $cid,
      'ico' => $data['ico'],
      'ico_back' => $data['ico_back'],
      'levelName' => $data['levelName'],
      'wins'      => $data['wins'],
      'losses'    => $data['losses'],
      'total'      => $total,
      'rate'      => $rate,
    ];
  }

  usort($entries, function ($a, $b) {
    // 先比較勝率（rate）降冪
    $cmp = $b['rate'] <=> $a['rate'];
    if ($cmp !== 0) {
      return $cmp;
    }
    // 如果勝率相同，再比較總場次（total）降冪
    return $b['total'] <=> $a['total'];
  });

  // 3. 只取前 6 名
  $top = array_slice($entries, 0, 10);
  if ($combined == 0) {
    // 4. 輸出 HTML 表格
    echo '<h4>難以應對角色</h4>';
    echo '<div class="counter-container">';
    foreach ($top as $row) {
      $total = $row['wins'] + $row['losses'];
      if ($total > 1) {
        $rateDisplay = $row['rate'] > 0
          ? round($row['rate'] * 100, 0) . '%'
          : '0%';
        if ($row['rate'] > 0.5) {
          $search_link = "ranking.php?character=" . urlencode($row['levelName']); // 建立搜尋連結
          echo '<div class="d-character">';
          echo '<div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
            <img class="character-img" src="uploads/' . $row['ico'] . '" loading="lazy">
          </div>
          <a href="' . $search_link . '" class="text-dark">' . $row['levelName'] . '</a>
          <div class="weapon_attr">';
          echo '<span style="color:blue">' . $rateDisplay . '</span>
          (' . $row['wins'] . '/' . $total . ')';
          echo '</div></div>';
        }
      }
    }
    echo '</div>';
  }


  /* echo '<table class="table table-bordered" style="width:auto; margin-bottom:40px;">';
  echo '<tr><th>角色 ID</th><th>角色名稱</th><th>卡背</th><th>levelName</th><th>勝場</th><th>敗場</th></tr>';
  foreach ($top as $row) {
    // 將 0～1 的勝率轉為百分比顯示
    $rateDisplay = $row['rate'] > 0
      ? round($row['rate'] * 100, 1) . '%'
      : 'N/A';
    echo '<tr>';
    echo "<td>{$row['cid']}</td>";
    echo '<td><div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
              <img class="character-img" src="uploads/' . $row['ico'] . '" loading="lazy">
            </div>
            <br>
            <div class="weapon_attr">' . $rateDisplay . '
            </div>
            </td>';
    echo "<td>{$row['ico_back']}</td>";
    echo "<td>{$row['levelName']}</td>";
    echo "<td>{$row['wins']}</td>";
    echo "<td>{$row['losses']}</td>";
    //echo "<td>{$rateDisplay}</td>";
    echo '</tr>';
  }
  echo '</table>'; */

  echo $outPutString;

  ?>

  </div>




  <!-- <h3 style="margin:5px"> 對手(多選)</h3> -->




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

</body>

</html>