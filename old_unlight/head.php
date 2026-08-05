<?php
header("Cache-Control: no-cache");
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<!-- <meta http-equiv="refresh" content="5;url=https://ulgg.online"> -->
<title>網站搬遷通知</title>
<style>
body { font-family: sans-serif; text-align:center; margin-top: 50px; }
</style>
</head>
<body>
<!-- <h2>⚠️ ULGG 網站搬遷公告</h2>
<p>ULGG 網站即將移至新網址：</p>
<p><a href="https://ulgg.online">https://ulgg.online</a></p>
<p>請更新您的書籤</p> -->
<!-- <p>請更新您的書籤，5 秒後自動前往新網站…</p> -->
</body>
</html>

<?php

header("Cache-Control:private");
session_start();
$_SESSION['return_after_steam'] = $_SERVER['REQUEST_URI'];

if ($_SESSION["username"] == 'way.lee' || $_SESSION["username"] == '咕嚕．挖2朵(待審)') {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);

  /* print '<pre>';
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */
}
/* print '<pre>';
  var_dump($_POST);
  var_dump($_SESSION);
  print '</pre>'; */


function maskUsername($username)
{
  $length = mb_strlen($username); // 取得字串長度
  if ($length <= 2) {
    $first = mb_substr($username, 0, 1); // 取前 1 個字
    return $first . '*';
  }

  $first = mb_substr($username, 0, 2); // 取前 2 個字
  $last = mb_substr($username, -2); // 取最後 2 個字

  return $first . '***' . $last;
}

$ip = '192.168.50.3';
$dbname = 'leway_db';
try {
  $db = new PDO("mysql:host=$ip;dbname=$dbname", 'root', 'Uve%12345');
} catch (PDOException $e) {
  print "Could not connect to the database: " . $e->getMessage();
  exit();
}
date_default_timezone_set("Asia/Taipei");
function getClientIp()
{
  foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
    if (!empty($_SERVER[$key])) {
      foreach (explode(',', $_SERVER[$key]) as $ip) {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
          return $ip;
        }
      }
    }
  }
  return '0.0.0.0';
}

$todayKey = 'visitor_' . date('Ymd');
if (!isset($_SESSION[$todayKey])) {
  $ip          = getClientIp();
  $ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $page        = $_SERVER['REQUEST_URI'];
  $searchTerm  = isset($_GET['player_search'])
    ? rawurldecode($_GET['player_search'])
    : null;
  $character_name   = isset($_GET['character'])
    ? rawurldecode($_GET['character'])
    : null;
  $time        = date('Y-m-d H:i:s');

  $stmt = $db->prepare("
    INSERT INTO visitors 
      (ip, user_agent, page, search_term, `character_name`, visited_at)
    VALUES 
      (?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $ip,
    $ua,
    $page,
    $searchTerm,
    $character_name,
    $time
  ]);

  $_SESSION[$todayKey] = true;
}


$logout_button = ''; // 預設為空
$username = "Steam登入";
$result = 0;
$ack = 0;
if (isset($_POST["login"])) {
  // 登出按鈕 HTML（留意需在 form 裡）
  $logout_button = '<button type="submit" name="logout" value="1" class="btn btn-primary logout-btn">登出</button>';

  // 取得使用者輸入
  $check_username = $_POST['username'] ?? '';

  // 準備不分大小寫的查詢
  $sql = "
    SELECT *
    FROM game_user
    WHERE LOWER(username) = LOWER(:username)
  ";

  // PDO 預備與執行
  $stmt = $db->prepare($sql);
  $stmt->execute([':username' => $check_username]);

  // 擷取結果
  while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $result = $row[1];    // 假設第 2 欄是你要的結果 ->username
    $ack    = $row[4];    // 假設第 5 欄是 ack
    $_SESSION['ack'] = $ack;
  }
  /* echo "ack=$ack<br>";
  echo "sql=$sql<br>";
  echo "result=$result<br>"; */
  $_SESSION["ack"] = $ack ?? 0;
  if ($result && $ack == 0) {
    echo "<script>alert('尚未審核...')</script>";
  } elseif ($result && $ack >= 1) {
    $_SESSION["username"] = $check_username;
    $username = $_POST["username"];
    $logout_button = '<button type="submit" name="logout" value="1" class="btn btn-primary logout-btn">登出</button>';
  } else {
    echo "<script>alert('查無此UL ID暱稱...')</script>";
  }
} elseif (isset($_POST["bind_ul"])) {
  if ($ack) {
    echo "<script>alert('此帳號已開通...')</script>";
  } elseif ($result) {
    echo "<script>alert('請稍後，後台即將為您開通。或來信:gbgbdavidlee@gmail.com')</script>";
    //echo "sql=$sql<br>";
    $logout_button = ''; // 預設為空
    $username = "Steam登入";
    $_SESSION["username"] = $username;
  } else {
    $username = $_POST["username"] ?? '';
    $steam_id = $_SESSION['steam_id'] ?? '';
    $sql = "UPDATE `game_user`
                SET `username`  = :username,
                    `update_time` = NOW(),
                    `apply` = 1,
                    `ack` = 0
                WHERE `steamID` = :steam_id";
    $stmt = $db->prepare($sql);
    $stmt->execute([
      ':username' => $username,
      ':steam_id' => $steam_id,
    ]);
    echo "<script>alert('綁定完成,為確認帳號真實性,請在 Discord 聯絡 @30dollar_computer_damn 為您開通,或來信:gbgbdavidlee@gmail.com')</script>";
    //echo "sql=$sql<br>";
    //$logout_button = ''; // 預設為空
    $username .= '(待審)';
    $_SESSION["username"] = $username;
  }
} elseif (!empty($_POST["logout"])) {
  //print "logout";
  $username = "Steam登入";
  $_SESSION["username"] = '';
  $_SESSION["ack"] = 0;
  $_SESSION['steam_id'] = '';
} elseif ($_SESSION["username"]) {
  $username = $_SESSION["username"];
  $logout_button = '<button type="submit" name="logout" value="1" class="btn btn-primary logout-btn">登出</button>';
}
$ack = $_SESSION["ack"] ?? 0;


//<a class="navbar-brand" href="#">UL.GG</a>
// 組出 $head_bar
$head_bar = <<<HTML
<nav class="navbar navbar-expand navbar-light bg-light">
  <button class="navbar-toggler" type="button" data-toggle="collapse"
          data-target="#mainNavbar" aria-controls="mainNavbar"
          aria-expanded="false" aria-label="切換導覽">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="mainNavbar">
    <ul class="navbar-nav mr-auto">
      <!-- <li class="nav-item"><a class="nav-link" href="fight.php">對戰紀錄</a></li> -->
      <li class="nav-item"><a class="nav-link" href="ranking.php">組合統計</a></li>
      <li class="nav-item"><a class="nav-link" href="use_rate.php">使用率</a></li>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="detailMenu" role="button"
           data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          BBQ榜
        </a>
        <div class="dropdown-menu" aria-labelledby="detailMenu">
          <a class="dropdown-item" href="ranking_bp.php">BP榜</a>
          <a class="dropdown-item" href="ranking_qp.php">QP榜</a>
        </div>
      </li>
      <li class="nav-item"><a class="nav-link" href="cost.php">計算C</a></li>
HTML;
//print "ack=$ack";
if ($ack == 2) {
  $head_bar .= <<<HTML
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="detailMenu" role="button"
           data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          詳細資料
        </a>
        <div class="dropdown-menu" aria-labelledby="detailMenu">
          <a class="dropdown-item" href="training.php">角色資料設定</a>
          <a class="dropdown-item" href="card_back.php">卡背進度</a>
          <a class="dropdown-item" href="eventindex.php">事件卡</a>
          <a class="dropdown-item" href="weapon.php">武器</a>
          <a class="dropdown-item" href="settings.php">對戰COST設定</a>
        </div>
      </li>
      <li class="nav-item"><a class="nav-link" href="queue.php">佇列</a></li>
HTML;
}


$head_bar .= <<<HTML
    </ul>
    <ul class="navbar-nav ml-auto">
      <li class="nav-item">
        <a class="nav-link" href="#" data-toggle="modal" data-target="#exampleModal">
        $username
        </a>
      </li>
HTML;

// 如果有登入（這裡假設「登入」字串代表沒登入），才顯示登出按鈕
if (!empty($_SESSION['username']) && $_SESSION['username'] !== 'Steam登入') {
  $head_bar .= <<<HTML
      <li class="nav-item">
        <form method="POST" class="form-inline">
          <button type="submit" name="logout" value="1"
                  class="btn btn-outline-danger my-2 my-sm-0 logout-btn">
            登出
          </button>
        </form>
      </li>
HTML;
}

// 最後再補上尾巴
$head_bar .= <<<HTML
    </ul>
  </div>
</nav>
HTML;


// 然後在想顯示 header 的地方：
//echo $head_bar;



?>
<!DOCTYPE html>

<head>
  <meta charset="UTF-8">
  <meta name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover">

  <title>UL.GG</title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <link rel="icon" href="icon/icon.ico" type="image/x-icon" />
  <!-- Font Awesome -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/font-awesome/css/font-awesome.min.css">
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" />
  <!-- Bootstrap 3.3.7 -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/bootstrap/dist/css/bootstrap.min.css">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/font-awesome/css/font-awesome.min.css">
  <!-- Ionicons -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/Ionicons/css/ionicons.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="../AdminLTE-master/dist/css/AdminLTE.min.css">
  <!-- AdminLTE Skins. Choose a skin from the css/skins
       folder instead of downloading all of them to reduce the load. -->
  <link rel="stylesheet" href="../AdminLTE-master/dist/css/skins/_all-skins.min.css">
  <!-- Morris chart -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/morris.js/morris.css">
  <!-- jvectormap -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/jvectormap/jquery-jvectormap.css">

  <!-- bootstrap wysihtml5 - text editor -->
  <link rel="stylesheet" href="../AdminLTE-master/plugins/bootstrap-wysihtml5/bootstrap3-wysihtml5.min.css">

  <!-- DataTables -->
  <link rel="stylesheet" href="../AdminLTE-master/bower_components/datatables.net-bs/css/dataTables.bootstrap.min.css">

  <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
  <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
  <!--[if lt IE 9]>
  <script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
  <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
  <![endif]-->

  <!-- Google Font -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic">



  <style type="text/css">
    /* ✅ 有用到：abgne-menu 樣式（你還沒接上，但可以先保留） */
    .abgne-menu input[type="radio"] {
      display: none;
    }

    .abgne-menu input[type="radio"]+label {
      display: inline-block;
      background-color: #ccc;
      cursor: pointer;
      padding: 4px 4px;
      margin: 1px 1px;
      border-radius: 5px;
    }

    .abgne-menu input[type="radio"]:checked+label {
      background-color: pink;
    }

    /* ✅ 有用到：主要 navbar 樣式 */
    .navbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background-color: #333;
      top: 0;
      z-index: 1000;
      position: sticky;
      border-top-left-radius: 0 !important;
      border-top-right-radius: 0 !important;
      margin-bottom: 0px !important;
      min-width: 550px;
      /* min-height: 43px !important; */
    }

    /* ✅ 有用到：navbar 連結樣式 */
    .navbar a {
      color: #f2f2f2;
      text-align: center;
      padding: 10px 20px;
      text-decoration: none;
      font-size: 16px;
      transition: all 0.3s ease-in-out;
    }

    .navbar a:hover {
      background: #ddd;
      color: black;
      border-radius: 5px;
    }

    /* ✅ 有用到：navbar 選單 */
    .navbar-nav {
      list-style: none;
      margin: 0;
      padding: 0;
      height: 40px;
    }

    .navbar-nav .nav-item {
      list-style: none;
      margin: 11px;
    }

    .navbar-nav>li>a {
      padding-top: 11px;
      padding-bottom: 10px;
    }

    /* ❌ 掠過：navbar-toggler 已經不要用了 */
    .navbar-toggler {
      /* 掠過，因為你說不要漢堡了 */
      padding: 0.5rem 1rem;
      border: none;
      border-radius: 0.375rem;
      background-color: #f8f9fa;
      margin: 11px 11px;
      display: none;
    }

    /* ❌ 掠過：navbar-toggler-icon 也不用了 */
    .navbar-toggler-icon {
      width: 1.5em;
      height: 1.5em;
      background-size: 1.5em 1.5em;
    }

    /* ❌ 掠過：768px 小螢幕開啟漢堡（你不需要了） */
    @media (max-width: 768px) {
      .navbar-toggler {
        display: block;
      }
    }

    /* ✅ 有用到：dropdown 樣式 */
    .custom-dropdown-toggle {
      background-color: transparent;
      border: none;
      color: #f2f2f2;
      text-align: center;
      padding: 10px 20px;
      text-decoration: none;
      font-size: 16px;
      transition: all 0.3s ease-in-out;
    }

    .custom-dropdown-toggle:hover,
    .custom-dropdown-toggle:focus {
      background: #ddd;
      color: black;
      border-radius: 5px;
    }

    /* ✅ 有用到：下拉選單 */
    .dropdown-menu {
      display: none;
      flex-direction: column !important;
      padding: 0.5rem 0;
    }

    .dropdown-item {
      color: #424242 !important;
      display: block;
      padding: 0.25rem 1rem;
      white-space: normal;
    }

    .navbar-nav>li>.dropdown-menu {
      margin-top: 13px;
    }

    .dropdown-menu .dropdown-item:hover {
      background-color: #333 !important;
      color: #fff !important;
    }

    /* ✅ 有用到：body 去邊距 */
    body {
      margin: 0;
    }

    /* ✅ 有用到：1040px以下 navbar排直 */
    @media (max-width: 1040px) {
      .navbar {
        flex-direction: column;
        align-items: flex-start;
      }

      .navbar a {
        width: 100%;
        text-align: left;
        padding: 12px;
      }
    }

    /* ❌ 掠過：navbar-collapse（因為你不再用bootstrap collapse） */
    @media (max-width: 768px) {
      .navbar-collapse {
        width: 100%;
      }

      .navbar-collapse .ml-auto {
        margin-left: auto;
        margin-top: 10px;
        display: flex;
        justify-content: flex-end;
        width: 100%;
      }

      .navbar-collapse .ml-auto .nav-item {
        margin: 5px 0;
      }
    }

    /* ✅ 有用到：navbar品牌大小 */
    .navbar-brand {
      height: 40px;
      line-height: 20px;
    }

    /* ✅ 有用到：圖片 hover 放大效果 */
    .zoomout,
    .zoomout_fight {
      transition: all 0.2s linear;
    }

    .zoomout:hover {
      transform: scale(3.5);
    }

    .zoomout_fight:hover {
      transform: scale(2.5);
      z-index: 999;
      position: relative;
    }

    /* ❌ 掠過：.main（根本沒用到） */
    /* .main {
  padding: 16px;
  height: 30px;
} */

    /* ✅ 有用到：表單輸入框 */
    #ip_box,
    #select_box {
      vertical-align: middle;
      height: 38px;
    }

    #ip_box {
      width: 85px;
      border-radius: 0.25rem;
      padding: 8px;
    }

    /* ✅ 有用到：按鈕 */
    #but {
      border: 1px #585858 solid;
      width: 45px;
    }

    /* ✅ 有用到：特殊選擇器 */
    #select1 {
      filter: grayscale(50%);
      border: solid red;
      border-radius: 5px;
    }

    #select2 {
      filter: sepia(50%);
      border: solid blue;
      border-radius: 5px;
    }

    /* ✅ 有用到：Modal彈窗按鈕區 */
    .modal-footer {
      display: flex;
      justify-content: space-between;
    }

    /* ✅ 有用到：登出按鈕 */
    .logout-btn {
      margin: -10px 0px !important;
    }

    /* ✅ 有用到：fight 對戰區 */
    .fight-container {
      display: flex;
      align-items: center;
      gap: 8px;
      justify-content: center;
      padding: 3px 5px;
      border-radius: 11px;
    }

    /* ✅ 有用到：fight 小螢幕排列 */
    @media (max-width: 750px) {
      .fight-container {
        display: flex;
        flex-direction: row;
        gap: 2px;
        margin: 10px 0px;
      }

      .vs {
        margin-right: 52px;
      }
    }

    /* ✅ 有用到：隊伍區 */
    .team {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 6px;
      border: 2px solid #ccc;
      border-radius: 12px;
      background-color: #f9f9f9;
      box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.1);
      min-width: 200px;
    }

    /* ✅ 有用到：玩家名稱 */
    .player-name {
      font-weight: bold;
      font-size: 14px;
      color: #333;
      padding: 4px 6px;
      background-color: #e9ecef;
      border-radius: 8px;
    }

    .player-name:hover {
      background-color: #0056b3;
      color: white;
    }

    /* ✅ 有用到：角色卡片 */
    .characters {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 3px;
      position: relative;
    }

    .characters:hover {
      overflow: visible;
      z-index: 10;
    }

    .character-img {
      height: 120px;
      border-radius: 8px;
      border: 1px solid #ccc;
      object-fit: cover;
      margin-left: -20px;
      transition: transform 0.3s ease, margin 0.3s ease, z-index 0.3s;
    }

    .character-img:first-child {
      margin-left: 0;
    }

    .character-img:hover {
      transform: scale(1.3);
      z-index: 10;
    }

    /* .characters:hover .character-img {
      margin-left: -20px;
    } */

    .characters .character-img:hover {
      margin-left: 0px;
    }
  </style>
</head>

<body>
  <?php
  // 1. 設定好 realm（只留到主機或主機+子目錄，不含檔名）
  //    這裡我們以 /unlight 為你的應用目錄
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'];                  // e.g. service.uve.com.tw
  $base   = $scheme . '://' . $host . '/unlight';  // http://service.uve.com.tw/unlight

  $realm     = $base;
  $return_to = $base . '/steam_callback.php?debug=1';

  // 2. 準備傳給 Steam 的 query parameters
  $params = http_build_query([
    'openid.ns'        => 'http://specs.openid.net/auth/2.0',
    'openid.mode'      => 'checkid_setup',
    'openid.return_to' => $return_to,
    'openid.realm'     => $realm,
    'openid.identity'  => 'http://specs.openid.net/auth/2.0/identifier_select',
    'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
  ]);

  $loginUrl = "https://steamcommunity.com/openid/login?{$params}";
  ?>
  <!-- Modal -->
  <!-- Modal HTML 結構不變，只做樣式強化 -->
  <div class="modal fade" id="exampleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content rounded-lg shadow">
        <div class="modal-header bg-primary text-white border-0">

          <!-- Steam 登入 -->

          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
          <div class="mb-4 text-center">
            <a href="<?= htmlspecialchars($loginUrl) ?>"
              class="btn btn-dark px-4 py-2 rounded-pill">
              <i class="fab fa-steam mr-2"></i>用 Steam 登入
            </a>
          </div>
        </div>
        <form action="#" method="POST" novalidate>
          <div class="modal-body">
            <!-- <h5 class="modal-title" id="exampleModalLabel">
              <i class="fas fa-id-card-alt mr-2"></i> 輸入帳號
            </h5>
            <br> -->
            <!-- UL ID 輸入 -->
            <!-- <div class="input-group mb-3">
              <div class="input-group">
                <input type="text"
                  class="form-control rounded-left"
                  name="username"
                  placeholder="UL遊戲ID"
                  required>
                <div class="input-group-btn">
                  <button type="submit"
                    name="login"
                    class="btn btn-primary rounded-right">
                    <i class="fas fa-sign-in-alt mr-1"></i> 登入
                  </button>
                </div>
              </div>
            </div> -->
            <hr>
            <!-- 公告區塊 -->
            <div class="alert alert-info rounded-lg p-3">
              您的 SteamID：<code><?php echo isset($_SESSION['steam_id']) ? $_SESSION['steam_id'] : '尚未登入'; ?></code><br>

              <?php
              echo '
                <h6 class="alert-heading mb-2">
                <i class="fas fa-exclamation-circle mr-1"></i>登入Steam後即可綁定 Unlight ID 功能 : 觀看自身對戰紀錄、事件武器卡。
              </h6>';
              if (!empty($_SESSION['steam_id']) && $ack == 0) {
                echo '                
                <div class="input-group mb-3">
                <div class="input-group">
                  <input type="text"
                    class="form-control rounded-left"
                    name="username"
                    placeholder="UL遊戲ID"
                    required>
                  <div class="input-group-btn">
                    <button type="submit"
                      name="bind_ul"
                      class="btn btn-primary rounded-right">
                      <i class="fas fa-sign-in-alt mr-1"></i> 綁定
                    </button>
                  </div>
                </div>';
              }
              ?>

            </div>
            <?php
            if (!empty($_SESSION['steam_id']) && $ack == 0) {
              echo '<p class="mb-2">
              綁定完後請在 Discord 聯絡
              <a href="https://discord.gg/S4JU3qPCys" target="_blank">@哭啊我的電腦~30塊 @30dollar_computer_damn</a>,或來信:gbgbdavidlee@gmail.com
              申請綁定
            </p>';
            }

            ?>


          </div>

      </div>
      <!-- <div class="modal-footer border-0 justify-content-center">
            
          </div> -->
      </form>
    </div>
  </div>
  </div>

  <!-- 自訂少量 CSS 強化 -->
  <style>
    .modal-content {
      overflow: hidden;
    }

    .alert-info {
      background-color: #e9f7fd;
      border-color: #b5e2f7;
    }

    .btn-dark {
      background-color: #171a21;
      color: #fff;
    }

    .btn-dark:hover {
      background-color: #2a475e;
    }
  </style>

</body>

</html>