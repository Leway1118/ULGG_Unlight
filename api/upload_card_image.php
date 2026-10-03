<?php
/**
 * upload_card_image.php
 *
 * 功能：
 * - official：更新 unlight.ico / ico_back
 * - fanart  ：寫入 / 覆蓋 card_art_uploads
 *
 * 安全強化：
 * - 真實圖片驗證（getimagesize）
 * - 嚴格副檔名
 * - 大小限制
 * - 路徑鎖定
 */

session_start();
require_once __DIR__ . '/../config.php';
$pdo = $db;

header('Content-Type: application/json; charset=utf-8');

/* =========================================================
 * 0. 共用回傳
 * ======================================================= */
function fail($msg) {
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}

/* =========================================================
 * 1. 權限檢查
 * ======================================================= */
if (empty($_SESSION['ack']) || $_SESSION['ack'] < 2) {
  fail('必須登入才能上傳');
}

/* =========================================================
 * 2. 參數驗證
 * ======================================================= */
$cardId = (int)($_POST['card_id'] ?? 0);
$side   = $_POST['side'] ?? '';
$mode   = $_POST['mode'] ?? 'official';
$file   = $_FILES['image'] ?? null;

if ($cardId <= 0 || !in_array($side, ['front','back'], true)) {
  fail('invalid card or side');
}
if (!$file || !isset($file['tmp_name'])) {
  fail('no file');
}

/* =========================================================
 * 3. 卡片存在檢查
 * ======================================================= */
$stmt = $pdo->prepare("SELECT level FROM unlight WHERE id = ?");
$stmt->execute([$cardId]);
$level = $stmt->fetchColumn();

if (!$level) {
  fail('card not found');
}

/* =========================================================
 * 4. 上傳錯誤檢查
 * ======================================================= */
if ($file['error'] !== UPLOAD_ERR_OK) {
  fail('upload error');
}

/* =========================================================
 * 5. 副檔名 / 大小
 * ======================================================= */
$allowedExt = ['jpg','jpeg','png','webp'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExt, true)) {
  fail('invalid file type');
}

$MAX_SIZE = 1 * 1024 * 1024; // 5MB
if ($file['size'] > $MAX_SIZE) {
  fail('file too large (max 5MB)');
}

/* =========================================================
 * 6. 真實圖片驗證（關鍵防線）
 * ======================================================= */
if (@getimagesize($file['tmp_name']) === false) {
  fail('invalid image content');
}

/* =========================================================
 * 7. 路徑設定（完全鎖死）
 * ======================================================= */
$subDir = ($mode === 'fanart') ? 'fanart' : 'official';

$baseFs = "/var/www/html/unlight/assets/uploads/{$subDir}";
$baseDb = "{$subDir}";

if (!is_dir($baseFs)) {
  mkdir($baseFs, 0755, true);
}

/* =========================================================
 * 8. 檔名生成
 * ======================================================= */
if ($mode === 'fanart') {
  $filename = sprintf(
    '%d_%s_%s_%s.%s',
    $cardId,
    $level,
    $side,
    bin2hex(random_bytes(4)),
    $ext
  );
} else {
  $filename = $cardId . '_' . $level . ($side === 'back' ? '_back' : '') . '.' . $ext;
}

$targetFs = "{$baseFs}/{$filename}";
$targetDb = "{$baseDb}/{$filename}";

/* =========================================================
 * 9. 存檔
 * ======================================================= */
if (!move_uploaded_file($file['tmp_name'], $targetFs)) {
  fail('failed to save file');
}

/* =========================================================
 * 10. 寫入資料庫
 * ======================================================= */
if ($mode === 'fanart') {

  $userId = $_SESSION['user_id'] ?? 0;
  if ($userId <= 0) {
    @unlink($targetFs);
    fail('user not bound');
  }

  // 是否已有同卡同面
  $stmt = $pdo->prepare("
    SELECT id, image_path
    FROM card_art_uploads
    WHERE card_id = :card
      AND side = :side
      AND content_type = 'image'
      AND user_id = :uid
    LIMIT 1
  ");
  $stmt->execute([
    ':card' => $cardId,
    ':side' => $side,
    ':uid'  => $userId
  ]);
  $old = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($old) {
    // 刪舊圖
    $oldFs = "/var/www/html/unlight/assets/uploads/" . $old['image_path'];
    if (is_file($oldFs)) {
      @unlink($oldFs);
    }

    $stmt = $pdo->prepare("
      UPDATE card_art_uploads
      SET image_path = :path,
          status = 'pending',
          updated_at = NOW()
      WHERE id = :id
    ");
    $stmt->execute([
      ':path' => $targetDb,
      ':id'   => $old['id']
    ]);
  } else {
    $stmt = $pdo->prepare("
      INSERT INTO card_art_uploads
      (card_id, side, content_type, image_path, user_id, status)
      VALUES
      (:card, :side, 'image', :path, :uid, 'pending')
    ");
    $stmt->execute([
      ':card' => $cardId,
      ':side' => $side,
      ':path' => $targetDb,
      ':uid'  => $userId
    ]);
  }

} else {
  // official
  $field = ($side === 'front') ? 'ico' : 'ico_back';
  $stmt = $pdo->prepare("UPDATE unlight SET {$field} = ? WHERE id = ?");
  $stmt->execute([$targetDb, $cardId]);
}

/* =========================================================
 * 11. 回傳
 * ======================================================= */
echo json_encode([
  'ok'   => true,
  'mode' => $mode,
  'url'  => $targetDb
]);
