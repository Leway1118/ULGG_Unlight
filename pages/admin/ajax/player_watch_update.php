<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../_admin_gate.php';

header('Content-Type: application/json');

$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$note   = trim($_POST['note'] ?? '');

if ($id <= 0 || !in_array($status, ['watch','black'], true)) {
  echo json_encode(['error'=>'參數錯誤']); exit;
}

$sql = "
UPDATE admin_player_watch
SET status = :status,
    note = :note,
    source = 'manual',
    updated_at = CURRENT_TIMESTAMP
WHERE id = :id
";

$stmt = $db->prepare($sql);
$stmt->execute([
  ':status' => $status,
  ':note'   => $note,
  ':id'     => $id
]);

echo json_encode(['ok'=>1]);
