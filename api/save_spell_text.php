<?php
session_start();
require_once __DIR__ . '/../config.php';
$pdo = $db;

header('Content-Type: application/json; charset=utf-8');

// 權限
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
  echo json_encode(['ok' => false, 'error' => 'not logged in']);
  exit;
}

$cardId = (int)($_POST['card_id'] ?? 0);
$spell  = trim($_POST['spell_text'] ?? '');

if ($cardId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'invalid card']);
  exit;
}

// 更新對應 fanart 記錄
$stmt = $pdo->prepare("
  UPDATE card_art_uploads
  SET spell_text = :spell,
      updated_at = NOW()
  WHERE card_id = :card
    AND side = 'front'
    AND content_type = 'image'
    AND user_id = :uid
  LIMIT 1
");

$stmt->execute([
  ':spell' => $spell,
  ':card'  => $cardId,
  ':uid'   => $userId
]);

if ($stmt->rowCount() === 0) {
  echo json_encode([
    'ok' => false,
    'error' => 'fanart record not found'
  ]);
  exit;
}

echo json_encode(['ok' => true]);
