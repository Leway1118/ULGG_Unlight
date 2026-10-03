<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../_admin_gate.php';

header('Content-Type: application/json');

$name   = trim($_POST['player_name'] ?? '');
$region = $_POST['region'] ?? '';
$status = $_POST['status'] ?? 'watch';
$note   = trim($_POST['note'] ?? '');

if (
  $name === '' ||
  !in_array($region, ['TW','JP'], true) ||
  !in_array($status, ['watch','black'], true) ||
  $note === ''
) {
  echo json_encode(['error' => '參數錯誤']);
  exit;
}

$sql = "
INSERT INTO admin_player_watch
  (player_name, region, status, note, source, created_by)
VALUES
  (:name, :region, :status, :note, 'manual', :uid)
ON DUPLICATE KEY UPDATE
  status = VALUES(status),
  note   = VALUES(note),
  source = 'manual',
  updated_at = CURRENT_TIMESTAMP
";

$stmt = $db->prepare($sql);
$stmt->execute([
  ':name'   => $name,
  ':region' => $region,
  ':status' => $status,
  ':note'   => $note,
  ':uid'    => $_SESSION['user_id'] ?? 0
]);

echo json_encode(['ok' => 1]);
