<?php
require_once __DIR__ . '/../config.php';

/* =====================================
 * 登出所有裝置（包含自己）
 * ===================================== */

if (empty($_SESSION['user_id'])) {
    header('Location: /pages/index.php');
    exit;
}

// 刪除該使用者所有 remember tokens
$stmt = $db->prepare("
  DELETE FROM user_remember_tokens
  WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);

// 清除本裝置 cookie
setcookie(
    'ulgg_remember',
    '',
    [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]
);

// 清 Session
session_destroy();

// 導回首頁（或登入頁）
header('Location: /pages/index.php');
exit;
