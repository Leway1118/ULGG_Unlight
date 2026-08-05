<?php
// 開發用：把所有錯誤顯示出來
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/config.php';

// 自動尋找 Composer autoload
$autoloadFiles = [
  __DIR__ . '/vendor/autoload.php',
  __DIR__ . '/../vendor/autoload.php',
  __DIR__ . '/../../vendor/autoload.php'
];
$loaded = false;
foreach ($autoloadFiles as $file) {
  if (file_exists($file)) {
    require $file;
    $loaded = true;
    break;
  }
}
if (!$loaded) {
  die('<strong>Error:</strong> Composer autoload.php not found.');
}

use LightOpenID;

session_start();
$back = $_SESSION['return_after_steam'] ?? '/unlight/ranking.php';

// 建立 LightOpenID
$host   = $_SERVER['HTTP_HOST'];
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = "$scheme://$host/unlight";
$openid = new LightOpenID($host);
$openid->realm     = $base;
$openid->returnUrl = "$base/steam_callback.php?debug=1";
?>
<!DOCTYPE html>
<html lang="zh-TW">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Steam 綁定回調結果</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    .countdown-container {
      text-align: center;
      margin: 2rem 0;
    }
    .countdown-number {
      display: inline-block;
      font-size: 3rem;
      font-weight: bold;
      padding: 0.5rem 1rem;
      border-radius: 0.25rem;
      background-color: #007bff;
      color: #fff;
      width: 4rem;
      line-height: 1;
    }
    .countdown-text {
      font-size: 1.25rem;
      margin: 0.5rem 0;
    }
  </style>
</head>

<body class="bg-light">
  <div class="container py-5">
    <div class="card shadow-sm">
      <div class="card-header bg-primary text-white text-center">
        <h4 class="mb-0">Steam 登入綁定</h4>
      </div>
      <div class="card-body">
        <?php
        try {
          if (!$openid->mode) {
            // 尚未開始驗證
            $openid->identity = 'http://specs.openid.net/auth/2.0/identifier_select';
            header('Location: ' . $openid->authUrl());
            exit;
          }
          if ($openid->mode === 'cancel') {
            echo '<div class="alert alert-warning">使用者取消了 Steam 登入。</div>';
          } else {
            if ($openid->validate()) {
              // 取得並顯示 SteamID
              preg_match('/\/id\/(\d+)$/', $openid->identity, $m);
              $steamID = $m[1] ?? '';
              echo '<div class="alert alert-success">';
              echo '✅ <strong>Steam 驗證成功！</strong><br>';
              echo "您的 SteamID：<code>{$steamID}</code>";
              echo '</div>';

              // 檢查並建立或取得會員
              $check = $db->prepare('SELECT `username`,`ack`,`apply` FROM `game_user` WHERE `steamID` = :steamID');
              $check->execute([':steamID' => $steamID]);

              if ($check->rowCount() === 0) {
                // 新用戶：預設 ack = 0
                $username = '訪客_' . $steamID;
                $ack = 0;

                $insert = $db->prepare(
                  'INSERT INTO `game_user` (`username`, `steamID`, `update_time`, `ack`) 
                VALUES (:username, :steamID, NOW(), :ack)'
                );
                $insert->execute([
                  ':username' => $username,
                  ':steamID'  => $steamID,
                  ':ack'      => $ack
                ]);

                echo "<p class=\"mt-3\">🆕 新用戶已建立，使用者名稱為 <strong>{$username}</strong></p>";
                echo '<!-- 公告區塊 -->
                  <div class="alert alert-info rounded-lg p-3">
                    <h6 class="alert-heading mb-2">
                      <i class="fas fa-exclamation-circle mr-1"></i>綁定 Unlight ID 功能 : 觀看自身對戰紀錄、組合、戰績、事件武器卡
                    </h6>
                    
                    <form action="ranking.php" method="POST" novalidate>
                      <div class="input-group mb-3">
                        <div class="input-group">
                          <input type="text"
                            class="form-control rounded-left" style="max-width: 250px;"
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
                        </div>
                      </div>                    
                    </form>
                    <p class="mb-2">
                      為防止他人盜用，請先在 Discord 聯絡
                      <a href="https://discord.gg/Crtu58bd" target="_blank">@30dollar_computer_damn</a>,或來信:gbgbdavidlee@gmail.com
                      並提供：
                    </p>
                    <ol class="pl-4 mb-2">
                      <li>Unlight ID 及 Steam ID：<code>' . htmlspecialchars($_SESSION['steam_id']) . '</code></li>
                      <li>最新一場對戰截圖（含截圖時間）</li>
                      <li>該場戰鬥的事件卡表截圖</li>
                    </ol>
                    <small class="text-muted">
                      確認後即可「綁定Unlight帳號」。
                    </small>
                  </div>';
              } else {
                // 舊用戶：取出 username 與 ack
                $row = $check->fetch(PDO::FETCH_ASSOC);
                $username = $row['username'];
                $ack      = (int)$row['ack'];
                $apply    = (int)$row['apply'];
                //print "apply=$apply";
                if ($ack) {
                  echo "<p class=\"mt-3\">👋 歡迎回來，會員：<strong>{$username}</strong></p>";
                } else {
                  if ($apply) {

                    echo "<p class=\"mt-3\">👋 歡迎回來，來賓：<strong>{$username}，您已綁定，尚未審核。</strong></p>";
                    echo '<!-- 公告區塊 -->
                    <div class="alert alert-info rounded-lg p-3">
                      <h6 class="alert-heading mb-2">
                        <i class="fas fa-exclamation-circle mr-1"></i>綁定 Unlight ID 功能 : 觀看自身對戰紀錄、組合、戰績、事件武器卡
                      </h6>
                      <p class="mb-2">
                        審核請在 Discord 聯絡
                        <a href="https://discord.gg/Crtu58bd" target="_blank">@30dollar_computer_damn</a>,或來信:gbgbdavidlee@gmail.com
                        <br>為防止他人綁定非自身帳號，綁定前請提供：
                      </p>
                      <ol class="pl-4 mb-2">
                        <li>Unlight ID 及 Steam ID：<code>' . htmlspecialchars($_SESSION['steam_id']) . '</code></li>
                        <li>最新一場對戰截圖（含截圖時間）</li>
                        <li>該場戰鬥的事件卡表截圖</li>
                      </ol>
                      <small class="text-muted">
                        確認後即可「綁定Unlight帳號」。
                      </small>
                    </div>';
                  } else {

                    echo "<p class=\"mt-3\">👋 歡迎回來，來賓：<strong>{$username}</strong></p>";
                    echo '<!-- 公告區塊 -->
                    <div class="alert alert-info rounded-lg p-3">
                      <h6 class="alert-heading mb-2">
                        <i class="fas fa-exclamation-circle mr-1"></i>綁定 Unlight ID 功能 : 觀看對戰紀錄、事件武器卡。
                      </h6>
                      <p class="mb-2">
                        請在 Discord 聯絡
                      <a href="https://discord.gg/S4JU3qPCys" target="_blank">@哭啊我的電腦~30塊 @30dollar_computer_damn</a>,或來信:gbgbdavidlee@gmail.com
                        申請綁定
                      </p>
                    </div>';
                    /* echo '<!-- 公告區塊 -->
                    <div class="alert alert-info rounded-lg p-3">
                      <h6 class="alert-heading mb-2">
                        <i class="fas fa-exclamation-circle mr-1"></i>綁定 Unlight ID 功能 : 觀看自身對戰紀錄、組合、戰績、事件武器卡
                      </h6>
                      <p class="mb-2">
                        請在 Discord 聯絡
                      <a href="https://discord.gg/S4JU3qPCys" target="_blank">tag @哭啊我的電腦~30塊 @30dollar_computer_damn</a>,或來信:gbgbdavidlee@gmail.com
                        申請綁定<br>為防止他人綁定非自身帳號，綁定前請提供：
                      </p>
                      <ol class="pl-4 mb-2">
                        <li>Unlight ID 及 Steam ID：<code>' . htmlspecialchars($_SESSION['steam_id']) . '</code></li>
                        <li>最新一場對戰截圖（含截圖時間）</li>
                        <li>該場戰鬥的事件卡表截圖</li>
                      </ol>
                      <small class="text-muted">
                        確認後即可「綁定Unlight帳號」。
                      </small>
                    </div>'; */
                  }
                }
              }

              // 儲存 SESSION
              $_SESSION['username'] = $username;
              $_SESSION['steam_id'] = $steamID;
              $_SESSION['ack']      = $ack;
              if ($ack == 0 && $apply = 1) {
                $_SESSION['username'] = $username . '(待審)';
              }
            } else {
              echo '<div class="alert alert-danger">❌ Steam 驗證失敗</div>';
            }
          }
        } catch (ErrorException $e) {
          echo '<div class="alert alert-danger">OpenID 發生錯誤：' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>
        <div class="countdown-container">
      <p class="countdown-text">將於</p>
      <p><span id="countdown" class="countdown-number">2</span></p>
      <p class="countdown-text">秒後自動跳轉<br>或點擊下方按鈕立即回到原頁</p>
    </div>
      </div>
      <div class="card-footer text-center">
        <a href="<?= htmlspecialchars($back, ENT_QUOTES) ?>"
          class="btn btn-outline-primary">
          立即返回
        </a>
      </div>
      <script>
        (function() {
          // 倒數秒數
          let remaining = 2;
          const backUrl = <?= json_encode($back) ?>;
          const elCount = document.getElementById('countdown');

          // 每秒更新一次
          const timer = setInterval(function() {
            remaining--;
            if (elCount) {
              elCount.textContent = remaining;
            }
            if (remaining <= 0) {
              clearInterval(timer);
              window.location.href = backUrl;
            }
          }, 1000);
        })();
      </script>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>