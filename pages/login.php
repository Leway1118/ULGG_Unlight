<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// ★★★ Debug：確認 dotenv 是否載入成功 ★★★
/* echo "<pre>";
print_r($_ENV);
echo "</pre>";
exit;  // ← 診斷完再移除 */

require_once __DIR__ . '/../config.php';

session_start();

/* ============================
   1. 建立 DB 連線
============================ */
try {
  $db = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASSWORD'],  // ← 必須改成這個
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
  );
} catch (Exception $e) {
  die("DB 連線失敗：" . $e->getMessage());
}

/* ============================
   2. 若 Steam 已登入 → 自動查 UL ID
============================ */
if (!empty($_SESSION['steam_id'])) {

  $steamID = $_SESSION['steam_id'];

  $stmt = $db->prepare("
        SELECT username, nickname, ack 
        FROM game_user 
        WHERE steamID = ?
        LIMIT 1
    ");
  $stmt->execute([$steamID]);
  $row = $stmt->fetch();

  if ($row) {
    $_SESSION["username"] = $row["username"];
    $_SESSION["ack"]      = $row["ack"];
    $_SESSION["nickname"] = $row["nickname"];
  }
}

$pageTitle = "登入 Login";
$activeMenu = "";
ob_start();
?>

<div class="login-panel">

  <?php if (empty($_SESSION['steam_id'])): ?>

    <!-- ⭐ 未登入：顯示 Steam 登入按鈕 -->
    <h2>使用 Steam 登入 UL.GG</h2>

    <a class="btn-steam-login" href="../api/auth/steam_start.php">
      <i class="fab fa-steam"></i> 使用 Steam 登入
    </a>

  <?php else: ?>

    <!-- ⭐ 已登入：不再顯示登入按鈕，而是顯示登入者資訊 -->
    <div class="login-info">
      <p><b>Steam 已登入成功：</b></p>
      <p><b>Steam ID：</b> <?= htmlspecialchars($_SESSION['steam_id']) ?></p>

      <?php if (!empty($_SESSION['username'])): ?>
        <p><b>UL.GG 使用者：</b> <?= htmlspecialchars($_SESSION['username']) ?></p>
        <p style="margin-top:10px;color:#9fdaff;">系統將在 <b>2 秒後</b> 自動跳轉至首頁...</p>
        <a href="../pages/index.php" class="btn-primary" style="color:#e0e0e0;">若未跳轉，點此進入 UL.GG</a>

        <script>
          setTimeout(function() {
            window.location.href = "../pages/index.php";
          }, 2000);
        </script>

      <?php else: ?>
        <p style="color:#ff6b6b;">此 Steam 帳號尚未綁定 UL.GG</p>
      <?php endif; ?>

    </div>

  <?php endif; ?>

</div>


<style>
  .login-panel {
    max-width: 460px;
    margin: 50px auto;
    background: rgba(255, 255, 255, 0.08);
    padding: 32px;
    border-radius: 12px;
    text-align: center;
    color: #e0e0e0;
  }

  .btn-steam-login {
    display: inline-block;
    background: #171a21;
    color: white;
    padding: 12px 24px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 18px;
  }

  .btn-steam-login:hover {
    background: #2a475e;
  }

  .login-info {
    margin-top: 20px;
    font-size: 14px;
    background: rgba(0, 0, 0, 0.2);
    padding: 12px;
    border-radius: 6px;
  }
</style>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>