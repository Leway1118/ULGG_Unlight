<!DOCTYPE html>

<?php
session_start(); // 這一定要最最最前面

// ✅ 登出優先處理
if (isset($_POST['logout'])) {
  session_destroy();
  header("Location: detail.php");
  exit;
}

function progress_color($rate)
{
  if ($rate < 25) {
    $color = 'red';
  } elseif ($rate < 50) {
    $color = 'yellow';
  } elseif ($rate < 75) {
    $color = 'green';
  } else {
    $color = 'aqua';
  }
  return $color;
}
function all_equal($x, $y, $z)
{
  if ($x === $y || $y === $z || $x === $z) {
    return 1;
  }
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

function btn($class, $label)
{
  return '<button class="btn btn-light wr-btn ' . $class . '">' . $label . '</button>';
}



//print "<br>same_ch_flag0=$same_ch_flag";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST["check_detail"])) {
    $_SESSION["check_detail"] = $_POST["check_detail"];
  }

  if (isset($_POST["match_id"])) {
    $_SESSION["match_id"] = $_POST["match_id"];
  }

  if (isset($_POST["player_name"])) {
    $_SESSION["player_name"] = $_POST["player_name"];
  }

  // 🧹 POST處理完就轉跳，避免重新送出表單
  header("Location: detail.php");
  exit;
}

// ✅ 防呆：如果沒有必要的SESSION資料，就自動回 fight.php
if (!isset($_SESSION["match_id"]) || !isset($_SESSION["player_name"])) {
  header("Location: fight.php");
  exit;
}


require_once('head.php');


if ($_SESSION["username"] == 'way.lee') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre>';
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}


$username = $_SESSION["username"];
$permission = $_SESSION["ack"] ?? 0;

// 取得資料
$check_detail = $_SESSION["check_detail"] ?? 0;
$match_id = $_SESSION["match_id"] ?? 0;
$player_name = $_SESSION["player_name"] ?? '';


/* print "match_id=$match_id";
print "player_name=$player_name"; */

?>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unlight</title>

  <!-- <script>
        var divs= $("input[name='skill[]']:checked").map(function() { return $(this).val(); }).get();
    </script> -->
  <script>
    function clearAll(ch) {
      var myCheckBox = document.getElementsByTagName('input');
      for (var i = 0; i < myCheckBox.length; i++) {
        if (myCheckBox[i].type == "checkbox") {
          myCheckBox[i].checked = false;
        }
      }
    }
  </script>

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




    .fight-form {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 20px;
    }

    .weapon_attr {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-around;
      font-weight: bold;
    }


    /* ───── 角色+事件的外框(左右各一塊) ───── */
    .side-block {
      /* 新增 */
      display: flex;
      flex-direction: column;
      /* 直向：team 在上，event 在下 */
      align-items: center;
    }


    /* 角色卡片排列成一行 */
    .d-character {
      display: flex;
      flex-direction: row;
      gap: 10px;
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
      gap: 20px;
      justify-content: center;
      margin: 7px 0px;

    }

    .char_weapon .team_event {
      display: wrap;

    }



    /* ✅ 偶數行灰色 */
    .gray-bg {
      background-color: rgba(181, 181, 181, 0.51);
    }

    /* ✅ 奇數行白色 */
    .white-bg {
      background-color: #ffffff;
    }



    /* VS 置中 */
    .vs {
      font-size: 24px;
      font-weight: bold;
      /* padding: 10px; */
    }

    .timestamp {
      font-size: 18px;
      background-color: #f8f9fa;
      padding: 3px 6px;
      border-radius: 5px;
      display: inline-block;
    }

    .vs-container {
      text-align: center;
      /* margin-right: 40px; */
    }




    .back {
      position: absolute;
      width: 100%;
      height: 100%;
      backface-visibility: hidden;
      scale: 1.3;
    }

    .front {
      position: absolute;
      width: 100%;
      height: 100%;
      backface-visibility: hidden;
      z-index: 2;
    }


    .back {
      transform: rotateY(180deg);
    }

    /* ✅ 點擊提示 & 箭頭 */
    .click-indicator {
      position: absolute;
      bottom: 26px;
      left: 50%;
      transform: translateX(-50%);
      color: white;
      border-radius: 10px;
      display: flex;
      align-items: center;
      gap: 5px;
      opacity: 0;
      transition: opacity 0.3s ease-in-out;
      scale: 0.9;
    }

    .flip-container {
      perspective: 1000px;
      width: 105px;
      height: 132px;
      position: relative;
      /* 新增：讓絕對定位子元素能脫離文檔流後依然相對於此容器 */
      z-index: 1;
      /* 翻轉前先給個基礎層級 */
    }

    .flipper {
      position: relative;
      width: 100%;
      height: 100%;
      transform-style: preserve-3d;
      transition: transform 0.6s;
    }

    /* 正面 / 背面都要隱藏背面 */
    .flip-container .front,
    .flip-container .back {
      position: absolute;
      width: 100%;
      height: 100%;
      backface-visibility: hidden;
    }

    /* 背面預設先旋轉 180° 放在底層 */
    .flip-container .back {
      transform: rotateY(180deg);
      z-index: 1;
    }

    /* 加上 “flipped” 類時 */
    .flip-container.flipped {
      z-index: 999;
      /* 整個容器拉到最上層 */
    }

    .flip-container.flipped .flipper {
      transform: rotateY(180deg) scale(1.3);
      /* 翻轉 + 放大 */
    }

    /* 放大後，背面要在上層、正面在下層 */
    .flip-container.flipped .back {
      z-index: 2;
    }

    .flip-container.flipped .front {
      z-index: 1;
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


    /* COST 按鈕 */
    .cost-btn {
      width: auto;
      padding: 5px 15px;
      font-weight: bold;
      background: white;
      border-radius: 8px;
      border: 2px solid #ddd;
      box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
    }

    /* win rate 按鈕 */
    .wr-btn {
      width: 70px;
      padding: 5px 0px;
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
      padding: 5px 15px;
      width: 70px;
    }

    /* 未判按鈕 */
    .queue-btn {
      background: rgb(163, 131, 42);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 5px 15px;
      width: 70px;
    }

    /* 平手按鈕 */
    .tie-btn {
      background: rgb(42, 163, 95);
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 5px 15px;
      width: 70px;
    }

    .player-win-btn {
      background: #ff4e4e;
      color: white;
      border-radius: 8px;
      font-weight: bold;
      padding: 5px 15px;
      width: 70px;
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


    /* 對戰區塊 */
    .fight-container {
      display: flex;
      flex-wrap: wrap;
      flex-direction: row;
      align-items: center;
      justify-content: center;
      gap: 20px;
      margin: 48px 0;
      padding: 3px 5px;
      border-radius: 6px;
    }

    .fight-container.reverse {
      /* 只有在有 reverse class 時才反轉 */
      flex-direction: row-reverse;
    }

    /* 小於 1200px 時，上下排列並讓 VS 區塊有上下邊距 */
    @media (max-width: 1300px) {
      .fight-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        /* 交叉軸置中 */
        gap: 12px;
        margin: 24px 0;
      }

      .fight-container.reverse {
        /* 反轉成上下顛倒 */
        flex-direction: column-reverse;
      }

      .vs-container {
        /* 針對 .vs-container（非 .vs）加上下邊距 */
        margin: 0;

      }
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
      gap: 5px;
      width: 90px;
      align-items: center;
    }

    .player-input input {
      /* width: 70%; */
      padding: 5px;
      border-radius: 5px;
    }

    /* 組合1 & 組合2組合 */
    .team {
      display: flex;
      align-items: center;
      gap: 10px;
      justify-content: space-evenly
    }





    .event_group {
      display: flex;
      justify-content: center;
      align-items: flex-start;
      flex-wrap: nowrap;
      gap: 0;
      padding: 10px;
      margin: 0;
      max-width: 1200px;

      /* 新增：使用 book.png 當背景 */
      background: url('uploads/book2.png') no-repeat center center;
      background-size: cover;

      /* 其它選項可視需求調整 */
      /* background-size: contain; */
      border-radius: 12px;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .player-event {
      /* background: white;
      border: 2px solid #ccc; */
      border-radius: 10px;
      padding: 5px;
      width: 190px;
      /* min-height: 250px; */
      box-shadow: 2px 4px 10px rgba(0, 0, 0, 0.1);
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 5px;
      position: relative;
    }

    .player-event::before {
      /* content: "角色卡組"; */
      position: absolute;
      top: -20px;
      left: 50%;
      transform: translateX(-50%);
      background: #555;
      color: white;
      font-size: 12px;
      padding: 2px 8px;
      border-radius: 10px;
    }

    .event {
      width: 55px;
      height: 80px;
      background: #eee;
      border-radius: 5px;
      /* overflow: hidden; */
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.3s ease-in-out;
    }

    .event img {
      width: 100%;
      height: 100%;
      /* object-fit: cover; */
      transition: transform 0.3s ease-in-out;
      border-radius: 5px;
    }

    .event:hover {
      transform: scale(1.2);
      z-index: 10;
    }

    .evt-name {
      font-size: 10px;
      text-align: center;
      color: #666;
      padding: 2px;
    }

    .event-slot-empty {
      width: 55px;
      height: 80px;
      background: repeating-linear-gradient(45deg,
          #ccc,
          #ccc 5px,
          #eee 5px,
          #eee 10px);
      border-radius: 5px;
    }

    .cost-btn-group {
      display: flex;
      flex-direction: column;
      gap: 7px;
    }
  </style>

</head>
<div class="navbar">
  <?php
  print $head_bar;
  ?>
</div>

<body>
  <?php

  if ($check_detail == 1) {
    /**
     * 根據近戰和遠程屬性值，產生顯示字串和顏色
     *
     * @param int $melee   近戰值
     * @param int $ranged  遠程值
     * @param string $prefix 'ATK' 或 'DEF'
     * @return array [0=>屬性字串, 1=>顏色]
     */
    function compute_attr(int $melee, int $ranged, string $prefix): array
    {
      if ($melee && $ranged) {
        $value = $melee;
        $color = 'black';     // 全距離：黑色
      } elseif ($melee) {
        $value = $melee;
        $color = 'red';       // 只有近戰：紅色
      } elseif ($ranged) {
        $value = $ranged;
        $color = 'green';     // 只有遠程：綠色
      } else {
        return ['', ''];      // 都沒值就空白
      }
      // 處理正負號
      $sign = $value >= 0 ? '+' : '';
      $attr = "{$prefix}{$sign}{$value}";
      return [$attr, $color];
    }

    /// ① 事件卡表只查一次，建立两张 map
    $eventMapIco  = [];  // [event_id] => ico.png
    $eventMapName = [];  // [event_id] => 卡片名称
    $eventMapCost = [];  // [event_id] => 卡片名称
    $sqlEvt = "SELECT id, ico, name,cost FROM unlight_eventindex";
    foreach ($db->query($sqlEvt, PDO::FETCH_ASSOC) as $r) {
      $id   = (int)$r['id'];
      $ico  = $r['ico']  ?: 'na_event.png';
      $name = $r['name'] ?: '';
      $cost = $r['cost'] ?: 0;
      $eventMapIco[$id]  = $ico;
      $eventMapName[$id]  = $name;
      $eventMapCost[$id]  = $cost;
    }

    /// ① 武器表只查一次，建立两张 map
    $weaponMapIco  = [];  // [event_id] => ico.png
    $weaponMapName = [];  // [event_id] => 卡片名称
    $weaponMapCost  = [];  // [event_id] => ico.png
    $weaponMapatk_melee = [];
    $weaponMapatk_ranged = [];
    $weaponMapdef_melee = [];
    $weaponMapdef_ranged = [];
    $sqlEvt = "SELECT id,ico,name, cost, atk_melee, atk_ranged, def_melee, def_ranged FROM unlight_weapon";
    foreach ($db->query($sqlEvt, PDO::FETCH_ASSOC) as $r) {
      $id   = (int)$r['id'];
      $ico  = $r['ico']  ?: 'na_event.png';
      $name = $r['name'] ?: '';
      $cost  = $r['cost']  ?: 0;
      $atk_melee = $r['atk_melee'] ?: 0;
      $atk_ranged = $r['atk_ranged'] ?: 0;
      $def_melee = $r['def_melee'] ?: 0;
      $def_ranged = $r['def_ranged'] ?: 0;
      $weaponMapIco[$id]  = $ico;
      $weaponMapName[$id]  = $name;
      $weaponMapCost[$id]  = $cost;
      $weaponMapatk_melee[$id]  = $atk_melee;
      $weaponMapatk_ranged[$id]  = $atk_ranged;
      $weaponMapdef_melee[$id]  = $def_melee;
      $weaponMapdef_ranged[$id]  = $def_ranged;
    }

    /// 2.角色卡表
    $charMapIco  = [];  // [event_id] => ico.png
    $charMapIco_back  = [];  // [event_id] => ico.png
    $charMap_cost  = [];  // [event_id] => ico.png
    $sqlEvt = "SELECT id, ico,ico_back,cost FROM unlight";
    foreach ($db->query($sqlEvt, PDO::FETCH_ASSOC) as $r) {
      $id   = (int)$r['id'];
      $ico  = $r['ico']  ?: 'phpU7B4pq.png'; //unknown
      $ico_back  = $r['ico_back']  ?: ''; //unknown
      $cost   = (int)$r['cost'];
      $charMapIco[$id]  = $ico;
      $charMapIco_back[$id]  = $ico_back;
      $charMap_cost[$id]  = $cost;
    }
    /* ---------- 1. 撈資料 ---------- */

    // 假設前面已經 include 了那支建立 $db PDO 連線的檔案
    // require_once __DIR__ . '/config.php';

    // 1. 先拿出 P1 / P2 的名字
    $sql = "
      SELECT name_p1, name_p2
      FROM arena_unlight
      WHERE id = :match_id
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':match_id' => $match_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $name_p1 = $row['name_p1'];
    $name_p2 = $row['name_p2'];

    // 2. 幫你封裝一個小函式，一次拿 show_private
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

    $show_private1 = getShowPrivate($db, $name_p1);
    $show_private2 = getShowPrivate($db, $name_p2);
    $isBlackList1 = isBlackList($db, $name_p1);
    $isBlackList2 = isBlackList($db, $name_p2);

    // 3. 根據旗標決定要不要顯示私有卡




    $sql = "
      SELECT
          update_time,
          id,
          win      AS p2_win,   -- 本場 P2 勝
          lose     AS p2_lose,  -- 本場 P1 勝
          tie,
          -- P1
          e1,e2,e3,w1,w2,w3,
          name_p1      AS p1_name,
          bp_p1        AS p1_bp,
          win_p1  AS p1_win,    -- 生涯
          lose_p1 AS p1_lose,
          draw_p1 AS p1_tie,
          eventindex1, ack1,
          -- P2
          u1,u2,u3,v1,v2,v3,
          name_p2      AS p2_name,
          bp_p2        AS p2_bp,
          win_p2  AS p2_win_hist,
          lose_p2 AS p2_lose_hist,
          draw_p2 AS p2_tie_hist,
          eventindex2, ack2
      FROM arena_unlight
      WHERE id = :match_id";

    $stmt = $db->prepare($sql);
    $stmt->execute([':match_id' => $match_id]);
    /* ---------- 2. 用 FETCH_ASSOC ---------- */
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {/* === 房間共同資料 === */

      $id       = $row['id'];
      $showDate = date('m-d H:i', strtotime($row['update_time']));

      $p1_events = json_decode($row['eventindex1'] ?? '[]', true);   // 18 個 id
      $p2_events = json_decode($row['eventindex2'] ?? '[]', true);


      /* ─ 判定旗標 ─ */
      $p1_is_win  =  $row['p2_lose'] == 1;
      $p1_is_lose =  $row['p2_win']  == 1;
      $is_tie     =  $row['tie']     == 1;
      $unknown    = ($row['p2_win'] + $row['p2_lose'] + $row['tie']) == 0 ? 1 : 0;

      /* === 玩家 1 (P1) === */
      $p1_chars = [$row['e1'], $row['e2'], $row['e3']];
      $p1_wp = [$row['w1'], $row['w2'], $row['w3']];
      $p1_name  = $row['p1_name'];
      $p1_bp    = $row['p1_bp'];
      /* $p1_win   = $row['p1_win'];
      $p1_lose  = $row['p1_lose'];
      $p1_tie   = $row['p1_tie']; */
      $ack1     = $row['ack1'];

      /* === 玩家 2 (P2) === */
      $p2_chars = [$row['u1'], $row['u2'], $row['u3']];
      $p2_wp = [$row['v1'], $row['v2'], $row['v3']];
      $p2_name  = $row['p2_name'];
      $p2_bp    = $row['p2_bp'];
      $ack2     = $row['ack2'];


      /* === 勝負未知旗標 === */
      //$unknown = ($p1_win + $p1_lose + $p1_tie) == 0 ? 1 : 0;

      // 決定是否反向排列
      $reverseClass = ($player_name === $p2_name) ? ' reverse' : '';
      /* if($username=='way.lee'){
        print "player_name=$player_name;p2_name=$p2_name";

      } */

      /* ╔══════════════════════════════════╗ */
      /* ║              HTML                ║ */
      /* ╚══════════════════════════════════╝ */
      $html = '';
      $html .= '<div class="fight-container' . $reverseClass . '">';

      /*─────────────  組合 1  ─────────────*/
      $html .= '<div class="team_event">';

      if ($show_private1 || $permission == 2 || $p1_name == $username || $isBlackList1) {
        //echo "玩家 <strong>{$name_p1}</strong> 選擇要顯示私有事件卡<br>";
        // ... 撈出所有 event
        $html .= '<div class="team">';

        $icons     = [];
        $cost_arr  = [];
        $cost_sum  = 0;

        foreach ($p1_chars as $cid) {
          $sql2  = "SELECT ico,ico_back, cost FROM unlight WHERE id = '$cid'";
          $row2  = $db->query($sql2)->fetch(PDO::FETCH_NUM);
          if (!$row2) continue;
          [$ico, $ico_back, $cost] = $row2;

          $icons[]    = $ico; //圖片檔名
          $icons_back[]    = $ico_back; //圖片檔名
          $cost_arr[] = (int)$cost;
          $cost_sum  += (int)$cost;
        }
        foreach ($p1_wp as $wid) {
          $weapon_name = $weaponMapName[$wid] ?? '';
          $weapon_cost = $weaponMapCost[$wid] ?? 0;
          $atk_melee = $weaponMapatk_melee[$wid] ?? 0;
          $atk_ranged = $weaponMapatk_ranged[$wid] ?? 0;
          $def_melee = $weaponMapdef_melee[$wid] ?? 0;
          $def_ranged = $weaponMapdef_ranged[$wid] ?? 0;

          $weapon_name_arr[] = $weapon_name;
          $weapon_cost_arr[] = $weapon_cost;
          $atk_melee_arr[] = $atk_melee;
          $atk_ranged_arr[] = $atk_ranged;
          $def_melee_arr[] = $def_melee;
          $def_ranged_arr[] = $def_ranged;
          // HTML 編碼避免 XSS
          // echo " （$name Cost: " . intval($cost) . "）<br>";
        }
        if (count($cost_arr) === 3) {
          $cost_sum += cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
        }
        $cost_color = ($cost_sum > array_sum($cost_arr)) ? 'text-danger' : '';

        $weapon_cost_sum = 0;
        for ($i = 0; $i <= 2; $i++) {
          $weapon_cost_sum += $weapon_cost_arr[$i];
        }
        $event_cost_sum = 0;
        for ($i = 0; $i < 18; $i++) {
          $eid = $p1_events[$i] ?? null;     // 事件卡 id
          $event_cost_sum += $eventMapCost[$eid] ?? 0;
        }
        $total_cost = $cost_sum + $weapon_cost_sum + $event_cost_sum;

        $html .= '<div class="cost-btn-group">';
        $html .= '<button type="button" class="btn btn-light cost-btn ' . $cost_color . '">COST ' . $cost_sum . '</button>';
        $html .= '<button type="button" class="btn btn-light cost-btn"style="color:#3594df;">武器 ' . $weapon_cost_sum . 'C</button>';
        $html .= '<button type="button" class="btn btn-light cost-btn"style="color:#ff7521fa;">事件 ' . $event_cost_sum . 'C</button>';
        $html .= '<button type="button" class="btn btn-light cost-btn"style="color:#d833ff;padding: 9px 17px;">總COST ' . $total_cost . '</button>';
        $html .= '</div>';

        $html .= '<div class="d-character">';
        $i = 1;
        $weapon_cost_sum = 0;
        for ($i = 1; $i <= 3; $i++) {
          $j = $i - 1;

          // 防止 Undefined array key
          $icon       = $icons[$j] ?? 'na.png';
          $icon_back  = $icons_back[$j] ?? null;
          $atk_m      = $atk_melee_arr[$j] ?? 0;
          $atk_r      = $atk_ranged_arr[$j] ?? 0;
          $def_m      = $def_melee_arr[$j] ?? 0;
          $def_r      = $def_ranged_arr[$j] ?? 0;
          $wp_name    = $weapon_name_arr[$j] ?? '';
          $wp_cost    = $weapon_cost_arr[$j] ?? 0;

          $html .= '<div class="char_weapon">';

          if (!$icon_back) {
            $html .= '
            <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
              <img class="d-character-img" src="uploads/' . $icon . '" loading="lazy">
            </div>';
          } else {
            $html .= '
            <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
                <div class="flipper">
                    <div class="front">
                        <img class="d-character-img" src="uploads/' . $icon . '" loading="lazy">
                    </div>
                    <div class="back">
                        <img class="d-character-img" src="uploads/' . $icon_back . '" loading="lazy">
                    </div>
                </div>
            </div>';
          }

          $atk_attr = '';
          $atk_color = '';
          $def_attr = '';
          $def_color = '';

          list($atk_attr, $atk_color) = compute_attr(
            $atk_melee_arr[$j] ?? 0,
            $atk_ranged_arr[$j] ?? 0,
            'ATK'
          );
          list($def_attr, $def_color) = compute_attr(
            $def_melee_arr[$j] ?? 0,
            $def_ranged_arr[$j] ?? 0,
            'DEF'
          );

          // 輸出攻防屬性
          $html .= '<br>';
          $html .= '<div class="weapon_attr">';

          // 如果至少有一個屬性不為空，才輸出 span；否則放個空白（或 &nbsp;）當占位
          if ($atk_attr !== '' || $def_attr !== '') {
            $html .= '<span style="color:' . $atk_color . '">' . $atk_attr . '</span>';
            $html .= '<span style="color:' . $def_color . '">' . $def_attr . '</span>';
          } else {
            // 占位不會影響排版
            $html .= '&nbsp;';
          }

          $html .= '</div>';
          // 輸出武器名稱（無論是否有資料都先開容器）
          $html .= '<div class="weapon_attr">';

          if (!empty($weapon_name_arr[$j])) {
            $name = $weapon_name_arr[$j];

            // 超過5個字才進行簡化處理
            if (mb_strlen($name, 'UTF-8') > 5) {
              $first3 = mb_substr($name, 0, 3, 'UTF-8');
              $last1  = mb_substr($name, -1, 1, 'UTF-8');
              $name = $first3 . '…' . $last1;
            }

            $html .= '<span style="color:#3594df;">'
              . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
              . ' / ' . intval($weapon_cost_arr[$j]) . 'C'
              . '</span>';
          } else {
            $html .= '&nbsp;';
          }


          $html .= '</div>';  // weapon_attr

          $html .= '</div>'; // char_weapon
        }
        $html .= '</div>'; // .d-character

        /* P1 名稱 / BP / 勝負標籤 */
        $html .= '<div class="player-input">';
        if ($unknown) {
          $html .= btn('queue-btn', '未判');
        } elseif ($is_tie) {
          $html .= btn('tie-btn', '平');
        } elseif ($p1_is_win) {
          $html .= btn('enemy-win-btn', '勝');
        } else {
          $html .= btn('player-win-btn', '負');
        }

        //echo $p1_name;
        $search_link = "fight.php?player_name=" . urlencode($p1_name); // 建立搜尋連結
        $html .= '<a href="' . $search_link . '" class="player-name">' . htmlspecialchars($p1_name, ENT_QUOTES, 'UTF-8') . '</a>';
        $html .= '<button class="btn btn-light wr-btn">BP ' . $p1_bp . '</button>';
        $html .= '</div>';            // player‑input
        $html .= '</div>';            // team‑1


        $html .= '<div class="event_group">';
        for ($i = 0; $i < 3; $i++) {             // 3 個角色
          $html .= '<div class="player-event">';
          for ($j = 0; $j < 6; $j++) {          // 每人 6 張 
            $idx = $i * 6 + $j;                  // 0–17
            $eid = $p1_events[$idx] ?? null;     // 事件卡 id
            $html .= '<div class="event">';
            if ($eid) {
              // 取出 ico & name
              $ico  = $eventMapIco[$eid]  ?? 'na_event.png';
              $name = $eventMapName[$eid] ?? '';
              //$event_cost_sum += $eventMapCost[$eid] ?? 0;
              $p1_events_name[] = $name;
              // 如果不是缺省图，就显示图 + 名称
              if ($ico !== 'na_event.png') {
                $html .= '<img src="uploads/' . htmlspecialchars($ico) . '" class="evt" loading="lazy" title="' . htmlspecialchars($name) . '">';
                //echo '<div class="evt-name">'.htmlspecialchars($name).'</div>';
              } else {
                // 只有缺省图时改成只显示名称
                $html .= '<div class="evt-name">' . htmlspecialchars($name) . '</div>';
              }
            } else {
              // 没带卡，就空占位
              $html .= '<span class="event-slot-empty"></span>';
            }
            $html .= '</div>'; // .event
          }
          $html .= '</div>';   // .player-event
        }
        $html .= '</div>';     // .event_group
      } else {
        //echo "玩家 <strong>{$name_p1}</strong> 不顯示私有事件卡<br>";
        $html .= "玩家 <strong>{****}</strong> 不顯示私有事件卡<br>";
        // ... 只撈公開 event
      }





      $html .= '</div>';            // team+event1

      /*─────────────  VS & 時間  ──────────*/
      $html .= '<div class="vs-container">
              <span class="timestamp">' . $showDate . '</span><br>
              <strong>VS</strong>
            </div>'; // vs-container

      /*─────────────  組合 2  ─────────────*/

      $html .= '<div class="team_event">';


      if ($show_private2  || $permission == 2 || $p2_name == $username || $isBlackList2) {
        //echo "玩家 <strong>{$name_p2}</strong> 選擇要顯示私有事件卡<br>";
        /* print "show_private2=$show_private2<br>";
        print "permission=$permission<br>";
        print "p2_name=$p2_name<br>";
        print "username=$username<br>";
        print "isBlackList2=$isBlackList2<br>"; */
        $html .= '<div class="team">';

        $icons     = [];
        $icons_back     = [];
        $cost_arr  = [];
        $cost_sum  = 0;
        $weapon_name_arr = [];
        $weapon_cost_arr = [];
        $atk_melee_arr = [];
        $atk_ranged_arr = [];
        $def_melee_arr = [];
        $def_ranged_arr = [];

        foreach ($p2_chars as $cid) {
          $sql2  = "SELECT ico,ico_back, cost FROM unlight WHERE id = '$cid'";
          $row2  = $db->query($sql2)->fetch(PDO::FETCH_NUM);
          if (!$row2) continue;
          [$ico, $ico_back, $cost] = $row2;

          $icons[]    = $ico; //圖片檔名
          $icons_back[]    = $ico_back; //圖片檔名
          $cost_arr[] = (int)$cost;
          $cost_sum  += (int)$cost;
        }
        foreach ($p2_wp as $wid) {
          $weapon_name = $weaponMapName[$wid] ?? '';
          $weapon_cost = $weaponMapCost[$wid] ?? 0;
          $atk_melee = $weaponMapatk_melee[$wid] ?? 0;
          $atk_ranged = $weaponMapatk_ranged[$wid] ?? 0;
          $def_melee = $weaponMapdef_melee[$wid] ?? 0;
          $def_ranged = $weaponMapdef_ranged[$wid] ?? 0;

          $weapon_name_arr[] = $weapon_name;
          $weapon_cost_arr[] = $weapon_cost;
          $atk_melee_arr[] = $atk_melee;
          $atk_ranged_arr[] = $atk_ranged;
          $def_melee_arr[] = $def_melee;
          $def_ranged_arr[] = $def_ranged;
          // HTML 編碼避免 XSS
          // echo " （$name Cost: " . intval($cost) . "）<br>";
        }
        if (count($cost_arr) === 3) {
          $cost_sum += cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
        }
        $cost_color = ($cost_sum > array_sum($cost_arr)) ? 'text-danger' : '';

        $weapon_cost_sum = 0;
        for ($i = 0; $i <= 2; $i++) {
          $weapon_cost_sum += $weapon_cost_arr[$i];
        }
        $event_cost_sum = 0;
        for ($i = 0; $i < 18; $i++) {
          $eid = $p2_events[$i] ?? null;     // 事件卡 id
          $event_cost_sum += $eventMapCost[$eid] ?? 0;
        }
        $total_cost = $cost_sum + $weapon_cost_sum + $event_cost_sum;

        $html .= '<div class="cost-btn-group">';
        $html .= '<button type="button" class="btn btn-light cost-btn ' . $cost_color . '">COST ' . $cost_sum . '</button>';
        $html .= '<button type="button" class="btn btn-light cost-btn"style="color:#3594df;">武器 ' . $weapon_cost_sum . 'C</button>';
        $html .= '<button type="button" class="btn btn-light cost-btn"style="color:#ff7521fa;">事件 ' . $event_cost_sum . 'C</button>';
        $html .= '<button type="button" class="btn btn-light cost-btn"style="color:#d833ff;padding: 9px 17px;">總COST ' . $total_cost . '</button>';
        $html .= '</div>';

        $html .= '<div class="d-character">';
        $i = 1;
        for ($i = 1; $i <= 3; $i++) {
          $j = $i - 1;

          // --------------- 防呆初始化（核心修正） ---------------
          $icon       = $icons[$j] ?? 'na.png';
          $icon_back  = $icons_back[$j] ?? null;
          $wp_name    = $weapon_name_arr[$j] ?? '';
          $wp_cost    = intval($weapon_cost_arr[$j] ?? 0);

          $atk_m      = $atk_melee_arr[$j] ?? 0;
          $atk_r      = $atk_ranged_arr[$j] ?? 0;
          $def_m      = $def_melee_arr[$j] ?? 0;
          $def_r      = $def_ranged_arr[$j] ?? 0;

          // 處理攻防屬性
          list($atk_attr, $atk_color) = compute_attr($atk_m, $atk_r, 'ATK');
          list($def_attr, $def_color) = compute_attr($def_m, $def_r, 'DEF');
          // ---------------------------------------------------------

          $html .= '<div class="char_weapon">';

          // 沒背面 → 單圖模式
          if (!$icon_back) {
            $html .= '
        <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
            <img class="d-character-img" src="uploads/' . htmlspecialchars($icon, ENT_QUOTES) . '" loading="lazy">
        </div>';
          }
          // 有背面 → flip 模式
          else {
            $html .= '
        <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
            <div class="flipper">
                <div class="front">
                    <img class="d-character-img" src="uploads/' . htmlspecialchars($icon, ENT_QUOTES) . '" loading="lazy">
                    <div class="click-indicator"><img src="arrow_icon.png" alt="Click Arrow"></div>
                </div>
                <div class="back">
                    <img class="d-character-img" src="uploads/' . htmlspecialchars($icon_back, ENT_QUOTES) . '" loading="lazy">
                </div>
            </div>
        </div>';
          }

          // ------------------ 攻防屬性輸出 ------------------
          $html .= '<br><div class="weapon_attr">';

          if ($atk_attr !== '' || $def_attr !== '') {
            $html .= '<span style="color:' . $atk_color . '">' . $atk_attr . '</span>';
            $html .= '<span style="color:' . $def_color . '">' . $def_attr . '</span>';
          } else {
            $html .= '&nbsp;';
          }

          $html .= '</div>';
          // ---------------------------------------------------

          // ------------------ 武器名稱 + cost ------------------
          $html .= '<div class="weapon_attr">';

          if ($wp_name !== '') {

            // 5 字以上簡化
            if (mb_strlen($wp_name, 'UTF-8') > 5) {
              $first3 = mb_substr($wp_name, 0, 3, 'UTF-8');
              $last1  = mb_substr($wp_name, -1, 1, 'UTF-8');
              $wp_name = $first3 . '…' . $last1;
            }

            $html .= '<span style="color:#3594df;">'
              . htmlspecialchars($wp_name, ENT_QUOTES, 'UTF-8')
              . ' / ' . $wp_cost . 'C'
              . '</span>';
          } else {
            $html .= '&nbsp;';
          }

          $html .= '</div>';  // weapon_attr
          // ----------------------------------------------------

          $html .= '</div>'; // char_weapon
        }

        $html .= '</div>'; // .d-character

        $html .= '<div class="player-input">';
        if ($unknown) {
          $html .= btn('queue-btn', '未判');
        } elseif ($is_tie) {
          $html .= btn('tie-btn', '平');
        } elseif ($p1_is_win) {
          $html .= btn('player-win-btn', '負');
        } else {
          $html .= btn('enemy-win-btn', '勝');
        }


        //echo $p2_name;
        $search_link = "fight.php?player_name=" . urlencode($p2_name); // 建立搜尋連結
        $html .= '<a href="' . $search_link . '" class="player-name">' . htmlspecialchars($p2_name, ENT_QUOTES, 'UTF-8') . '</a>';
        $html .= '<button class="btn btn-light wr-btn">BP ' . $p2_bp . '</button>';
        $html .= '</div>';            // player‑input
        $html .= '</div>';            // team‑2

        $html .= '<div class="event_group">';
        for ($i = 0; $i < 3; $i++) {             // 3 個角色
          $html .= '<div class="player-event">';
          for ($j = 0; $j < 6; $j++) {           // 每人 6 張
            $idx = $i * 6 + $j;                  // 0–17
            $eid = $p2_events[$idx] ?? null;     // 事件卡 id
            $html .= '<div class="event">';
            if ($eid) {
              // 取出 ico & name
              $ico  = $eventMapIco[$eid]  ?? 'na_event.png';
              $name = $eventMapName[$eid] ?? '';
              $p2_events_name[] = $name;
              // 如果不是缺省图，就显示图 + 名称
              if ($ico !== 'na_event.png') {
                $html .= '<img src="uploads/' . htmlspecialchars($ico) . '" class="evt" loading="lazy" title="' . htmlspecialchars($name) . '">';
                //echo '<div class="evt-name">'.htmlspecialchars($name).'</div>';
              } else {
                // 只有缺省图时改成只显示名称
                $html .= '<div class="evt-name">' . htmlspecialchars($name) . '</div>';
              }
            } else {
              // 没带卡，就空占位
              $html .= '<span class="event-slot-empty"></span>';
            }
            $html .= '</div>'; // .event
          }
          $html .= '</div>';   // .player-event
        }
        $html .= '</div>';     // .event_group
      } else {
        //echo "玩家 <strong>{$name_p1}</strong> 不顯示私有事件卡<br>";
        $html .= "玩家 <strong>{****}</strong> 不顯示私有事件卡<br>";
      }


      $html .= '</div>';            // team+event2



      $html .= '</div>';            // fight‑container

    } //while-end
    echo $html;

    /* $i = 1;
    print "$p1_name:";
    print_r($p1_events);
    print "<br>";
    // 假設 $p1_events_name 已經是你想顯示的名稱陣列
    $rows = array_chunk($p1_events_name, 6);  // 切成每組 6 個
    foreach ($rows as $row) {
      // 用空格隔開每個名稱，並且做 HTML 編碼以防 XSS
      print $i++ . ')';
      echo implode(' ', array_map(function ($n) {
        return htmlspecialchars($n, ENT_QUOTES, 'UTF-8');
      }, $row));
      echo '<br>';
    }
    $i = 1;
    print "<br>$p2_name:";
    print_r($p2_events);
    print "<br>";
    foreach ($p2_events_name as $idx => $name) {
      $no = $idx + 1;  // 編號從 1 開始
      echo $no . '. ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
      if ($no % 6 === 0) {
        echo '<br>';
      } else {
        echo ' ';  // 同一列用空格隔開
      }
    } */

    /* // 先印出整個原始陣列（方便除錯）
    echo '<pre>';
    print_r($p1_wp);
    print_r($p2_wp);
    echo '</pre>'; */

    // 再把對應的「名稱 + cost」印出來
    /* print "$p1_name:<br>";
    foreach ($p1_wp as $wid) {
      $name = $weaponMapName[$wid] ?? '';
      $cost = $weaponMapCost[$wid] ?? 0;
      $atk_melee = $weaponMapatk_melee[$wid] ?? 0;
      $atk_ranged = $weaponMapatk_ranged[$wid] ?? 0;
      $def_melee = $weaponMapdef_melee[$wid] ?? 0;
      $def_ranged = $weaponMapdef_ranged[$wid] ?? 0;
      // HTML 編碼避免 XSS
      echo " （$wid $name Cost: " . intval($cost) . "）<br>";
    }
    print "$p2_name:<br>";
    foreach ($p2_wp as $wid) {
      $name = $weaponMapName[$wid] ?? '';
      $cost = $weaponMapCost[$wid] ?? 0;
      $atk_melee = $weaponMapatk_melee[$wid] ?? 0;
      $atk_ranged = $weaponMapatk_ranged[$wid] ?? 0;
      $def_melee = $weaponMapdef_melee[$wid] ?? 0;
      $def_ranged = $weaponMapdef_ranged[$wid] ?? 0;
      // HTML 編碼避免 XSS
      echo " （$wid $name Cost: " . intval($cost) . "）<br>";
    } */
  }



  ?>

  </div>

  <!-- jQuery 3 -->
  <script src="bower_components/jquery/dist/jquery.min.js"></script>
  <!-- jQuery UI 1.11.4 -->
  <script src="bower_components/jquery-ui/jquery-ui.min.js"></script>
  <!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
  <script>
    $.widget.bridge('uibutton', $.ui.button);
  </script>
  <!-- Bootstrap 3.3.7 -->
  <script src="bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
  <!-- Morris.js charts -->
  <script src="bower_components/raphael/raphael.min.js"></script>
  <script src="bower_components/morris.js/morris.min.js"></script>
  <!-- Sparkline -->
  <script src="bower_components/jquery-sparkline/dist/jquery.sparkline.min.js"></script>
  <!-- jvectormap -->
  <script src="plugins/jvectormap/jquery-jvectormap-1.2.2.min.js"></script>
  <script src="plugins/jvectormap/jquery-jvectormap-world-mill-en.js"></script>
  <!-- jQuery Knob Chart -->
  <script src="bower_components/jquery-knob/dist/jquery.knob.min.js"></script>
  <!-- daterangepicker -->
  <script src="bower_components/moment/min/moment.min.js"></script>
  <script src="bower_components/bootstrap-daterangepicker/daterangepicker.js"></script>
  <!-- datepicker -->
  <script src="bower_components/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js"></script>
  <!-- Bootstrap WYSIHTML5 -->
  <script src="plugins/bootstrap-wysihtml5/bootstrap3-wysihtml5.all.min.js"></script>
  <!-- Slimscroll -->
  <script src="bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
  <!-- FastClick -->
  <script src="bower_components/fastclick/lib/fastclick.js"></script>
  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.min.js"></script>
  <!-- AdminLTE dashboard demo (This is only for demo purposes) -->
  <script src="dist/js/pages/dashboard.js"></script>
  <!-- AdminLTE for demo purposes -->
  <script src="dist/js/demo.js"></script>

  <!-- Bootstrap CSS (若已引入可略過) -->
  <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> -->

  <!-- Bootstrap JavaScript (必要，讓 collapse 運作) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>