<?php
session_start();
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../_admin_gate.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  echo json_encode(['error' => 'Invalid ID']);
  exit;
}

$sql = "SELECT
  username,
  friend_code,
  verify_image,
  verify_note,
  email
FROM game_user
WHERE id = :id
LIMIT 1
";

$stmt = $db->prepare($sql);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
  echo json_encode(['error' => '資料不存在']);
  exit;
}

// 如果你的 verify_image 是相對路徑，這裡可以補 /
// $row['verify_image'] = '/uploads/' . $row['verify_image'];

echo json_encode([
  'username'     => $row['username'],
  'friend_code'  => $row['friend_code'],
  'verify_image' => $row['verify_image'],
  'verify_note'  => $row['verify_note'],
  'email'        => $row['email'],
]);
