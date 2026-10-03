<?php
/* This is add_black_user.php */
session_start();
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../_admin_gate.php';

header('Content-Type: application/json');

// 權限保護（保險）
if (empty($_SESSION['permission']) || $_SESSION['permission'] < 2) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$region   = $_POST['region'] ?? '';

if ($username === '' || !in_array($region, ['TW', 'JP'], true)) {
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

// 檢查是否已存在
$checkSql = "
    SELECT id, black_list
    FROM game_user
    WHERE username = :u AND region = :r
    LIMIT 1
";
$checkStmt = $db->prepare($checkSql);
$checkStmt->execute([
    ':u' => $username,
    ':r' => $region
]);
$user = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($user) {

    if ((int)$user['black_list'] === 2) {
        echo json_encode(['error' => 'User already in blacklist']);
        exit;
    }

    // 0 或 1 都直接升級成黑名單
    $updateSql = "
        UPDATE game_user
        SET black_list = 2,
            verify_note = 'Admin manual blacklist'
        WHERE id = :id
        LIMIT 1
    ";
    $stmt = $db->prepare($updateSql);
    $stmt->execute([':id' => $user['id']]);

    header('Location: /pages/admin/user_manage.php');
    exit;
}


// 不存在 → 新增黑名單帳號（佔位）
$insertSql = "
    INSERT INTO game_user
    (username, region, black_list, ack, apply, verify_note)
    VALUES
    (:u, :r, 2, 0, 0, 'Admin manual blacklist')
";
$stmt = $db->prepare($insertSql);
$stmt->execute([
    ':u' => $username,
    ':r' => $region
]);

// 成功後回會員管理頁
echo json_encode(['success' => true]);
exit;
