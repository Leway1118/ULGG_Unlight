<?php
/**
 * ------------------------------------------------------------
 * Admin User Status Controller
 * ------------------------------------------------------------
 *
 * File: user_block.php
 *
 * 功能說明：
 * ------------------------------------------------------------
 * 後台管理用 API，用於變更會員的黑名單 / 觀察狀態。
 * 透過 AJAX 呼叫，僅限管理員使用。
 *
 * 支援動作（action）：
 * ------------------------------------------------------------
 * - observe  → 設為觀察名單（black_list = 1）
 * - block    → 設為黑名單（black_list = 2）
 * - unblock  → 回復正常狀態（black_list = 0）
 *
 * 行為規則：
 * ------------------------------------------------------------
 * - 不強制填寫原因（note 可為空）
 * - 黑名單、觀察、正常 三種狀態可互相切換
 * - 由前端依目前狀態顯示對應按鈕
 *
 * 資料表：
 * ------------------------------------------------------------
 * - game_user.black_list
 *     0 = 正常
 *     1 = 觀察中
 *     2 = 黑名單
 *
 * 回傳格式：
 * ------------------------------------------------------------
 * JSON
 * { *   success: true * }
 * 或
 * { *   error: "錯誤訊息" * }
 * * 安全性：
 * ------------------------------------------------------------
 * - 需通過 _admin_gate.php 權限檢查
 * - 僅允許 POST 呼叫
 *
 * 最後更新：
 * ------------------------------------------------------------
 * 2025-12-27
 *
 * ------------------------------------------------------------
 */

session_start();
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../_admin_gate.php';

header('Content-Type: application/json');

$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$note   = trim($_POST['note'] ?? ''); // 目前不用

if ($id <= 0) {
  echo json_encode(['error' => 'Invalid ID']);
  exit;
}

if (!in_array($action, ['observe', 'block', 'unblock'], true)) {
  echo json_encode(['error' => 'Invalid action']);
  exit;
}

/* 讀取目前狀態 */
$stmt = $db->prepare("SELECT black_list FROM game_user WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$current = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current) {
  echo json_encode(['error' => 'User not found']);
  exit;
}

/* action → black_list */
switch ($action) {
  case 'observe':
    $newBl = 1;
    break;
  case 'block':
    $newBl = 2;
    break;
  case 'unblock':
    $newBl = 0;
    break;
}

/* 更新 */
$stmt = $db->prepare("
  UPDATE game_user
  SET black_list = :bl
  WHERE id = :id
  LIMIT 1
");

$stmt->execute([
  ':id' => $id,
  ':bl' => $newBl
]);

echo json_encode(['success' => true]);
