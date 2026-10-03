<?php
session_start();
require_once __DIR__ . '/../../config.php';
$pdo = $db;

// 🔐 權限控管
if (($_SESSION['permission'] ?? 0) < 3) {
  http_response_code(403);
  exit('Forbidden');
}

$pageTitleText = '支付 QR 管理';
$pageTitleFull = '支付 QR 管理 | UL.GG 後台';
$activeMenu = "payment_qr"; //.php
ob_start();
?> <style>
  /* ========================= Admin Payment QR Page ========================= */
  .admin-card {
    background: linear-gradient(180deg, #1c1f2a, #171a24);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .45);
  }

  .admin-card h3 {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 6px;
  }

  .admin-desc {
    font-size: 13px;
    color: #9aa4b2;
    margin-bottom: 16px;
  }

  .qr-preview {
    background: #0f172a;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    position: relative;
  }

  .qr-preview img {
    width: 220px;
    height: 220px;
    object-fit: contain;
    /* ⭐ 關鍵 */
    background: #020617;
    border-radius: 10px;
    border: 2px dashed rgba(255, 255, 255, .25);
  }


  .qr-badge {
    position: absolute;
    right: 14px;
    bottom: 2px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    font-weight: 900;
    font-size: 16px;
    line-height: 36px;
    text-align: center;
    box-shadow: 0 4px 10px rgba(0, 0, 0, .45);
  }

  .badge-pxp {
    background: #facc15;
    color: #111;
  }

  .badge-jko {
    background: #e60012;
    color: #fff;
  }

  .upload-box {
    margin-top: 16px;
    padding: 14px;
    border-radius: 10px;
    border: 1px dashed rgba(255, 255, 255, .25);
    background: rgba(255, 255, 255, .03);
  }

  .upload-box label {
    font-size: 13px;
    color: #cfd5ff;
    margin-bottom: 6px;
    display: block;
  }

  .upload-box input[type="file"] {
    width: 100%;
    font-size: 13px;
    color: #ccc;
  }

  .upload-hint {
    font-size: 12px;
    color: #9aa4b2;
    margin-top: 6px;
  }

  /* 管理提示 */
  .admin-note {
    font-size: 12px;
    color: #a3a3a3;
    border-top: 1px dashed rgba(255, 255, 255, .15);
    padding-top: 10px;
    margin-top: 20px;
  }
</style>

<?php
/* =========================
上傳處理
========================= */
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['donator_submit'])) {
  $payType = $_POST['pay_type'] ?? null;

  if (!in_array($payType, ['pxpay', 'jkopay'], true)) {
    $errors[] = '支付類型錯誤';
  }

  if (empty($_FILES['qr_file']['name'])) {
    $errors[] = '請選擇圖片';
  }

  if (!$errors) {
    $file = $_FILES['qr_file'];

    if ($file['size'] > 1024 * 1024) {
      $errors[] = '檔案不可超過 1MB';
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
      $errors[] = '僅允許 PNG / JPG';
    }
  }

  if (!$errors) {
    $dir = __DIR__ . '/../../assets/pay/';
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    $filename = $payType . '_' . date('Ymd_His') . '.' . $ext;
    $target = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
      $errors[] = '檔案上傳失敗';
    } else {
      $pdo->beginTransaction();

      // 🔥 舊的設為 inactive
      $stmt = $pdo->prepare(
        "UPDATE payment_qr SET is_active = 0 WHERE pay_type = ?"
      );
      $stmt->execute([$payType]);

      // 新的寫入
      $stmt = $pdo->prepare(
        "INSERT INTO payment_qr (pay_type, file_path, created_by)
VALUES (?, ?, ?)"
      );
      $stmt->execute([
        $payType,
        '/assets/pay/' . $filename,
        $_SESSION['username'] ?? null
      ]);

      $pdo->commit();
      $success = 'QR Code 已更新';
    }
  }
}

/* =========================
撈目前啟用的 QR
========================= */
function getActiveQR(PDO $pdo, string $type): ?string
{
  $stmt = $pdo->prepare(
    "SELECT file_path FROM payment_qr
WHERE pay_type = ? AND is_active = 1
ORDER BY created_at DESC
LIMIT 1"
  );
  $stmt->execute([$type]);
  return $stmt->fetchColumn() ?: null;
}

$pxpayQR = getActiveQR($pdo, 'pxpay');
$jkopayQR = getActiveQR($pdo, 'jkopay');
/* =========================
新增 Donator
========================= */
$donatorErrors = [];
$donatorSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['donator_submit'])) {

  $name = trim($_POST['donator_name'] ?? '');
  $amount = (int)($_POST['donator_amount'] ?? 0);
  $note = trim($_POST['donator_note'] ?? '');
  $isPublic = isset($_POST['donator_public']) ? 1 : 0;

  if ($name === '') {
    $donatorErrors[] = '請輸入贊助者名稱';
  }

  if ($amount <= 0) {
    $donatorErrors[] = '金額需大於 0';
  }

  if (!$donatorErrors) {
    $stmt = $pdo->prepare("
      INSERT INTO donators (name, amount, note, is_public)
      VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$name, $amount, $note ?: null, $isPublic]);

    $donatorSuccess = 'Donator 已新增';
  }
}

?>

<!-- ===== 你的原本 CSS 原封不動貼在這 ===== -->
<!--（略，直接用你剛貼的那份）-->

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="col-md-8 mx-auto" style="float:none; margin:0 auto;">
        <div class="admin-card">
          <h3>❤️ 新增贊助者（Donator）</h3>
          <div class="admin-desc">
            新增後將依金額排序顯示於前台「感謝贊助者」頁面（僅顯示姓名）
          </div>

          <?php if ($donatorErrors): ?>
            <div class="alert alert-danger">
              <?= implode('<br>', $donatorErrors) ?>
            </div>
          <?php elseif ($donatorSuccess): ?>
            <div class="alert alert-success">
              <?= htmlspecialchars($donatorSuccess) ?>
            </div>
          <?php endif; ?>

          <form method="post" class="upload-box">
            <label>贊助者名稱（前台顯示）</label>
            <input
              type="text"
              name="donator_name"
              class="form-control"
              placeholder="例如：匿名〇〇 / 王小明"
              required>

            <label style="margin-top:10px;">贊助金額（僅排序用，不對外顯示）</label>
            <input
              type="number"
              name="donator_amount"
              class="form-control"
              min="1"
              required>

            <label style="margin-top:10px;">備註（僅後台）</label>
            <input
              type="text"
              name="donator_note"
              class="form-control"
              placeholder="例如：2025/12 支持">

            <label style="margin-top:10px;">
              <input type="checkbox" name="donator_public" checked>
              前台顯示
            </label>

            <button
              type="submit"
              name="donator_submit"
              class="btn btn-success btn-sm mt-2">
              新增 Donator
            </button>
          </form>

          <div class="admin-note">
            • 前台僅顯示姓名，金額僅用於排序<br>
            • 建議使用「匿名〇〇」避免隱私爭議<br>
            • 若需隱藏，取消勾選「前台顯示」
          </div>
        </div>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <?= implode('<br>', $errors) ?>
          </div>
        <?php elseif ($success): ?>
          <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
          </div>
        <?php endif; ?>

        <!-- 全支付 -->
        <div class="admin-card">
          <h3>🟡 全支付（PxPay）</h3>

          <div class="qr-preview">
            <?php if ($pxpayQR): ?>
              <img src="<?= htmlspecialchars($pxpayQR) ?>">
            <?php else: ?>
              <div class="text-muted">尚未上傳</div>
            <?php endif; ?>
            <div class="qr-badge badge-pxp">全</div>
          </div>

          <form method="post" enctype="multipart/form-data" class="upload-box">
            <input type="hidden" name="pay_type" value="pxpay">
            <label>上傳新的全支付 QR</label>
            <input type="file" name="qr_file" required>
            <button class="btn btn-warning btn-sm mt-2">更新</button>
          </form>
        </div>

        <!-- 街口 -->
        <div class="admin-card">
          <h3>🔴 街口支付（JKO Pay）</h3>

          <div class="qr-preview">
            <?php if ($jkopayQR): ?>
              <img src="<?= htmlspecialchars($jkopayQR) ?>">
            <?php else: ?>
              <div class="text-muted">尚未上傳</div>
            <?php endif; ?>
            <div class="qr-badge badge-jko">街</div>
          </div>

          <form method="post" enctype="multipart/form-data" class="upload-box">
            <input type="hidden" name="pay_type" value="jkopay">
            <label>上傳新的街口支付 QR</label>
            <input type="file" name="qr_file" required>
            <button class="btn btn-danger btn-sm mt-2">更新</button>
          </form>
        </div>

      </div>
    </div>
  </section>
</div>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
