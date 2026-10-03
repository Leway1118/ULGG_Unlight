<?php
require_once __DIR__ . '/../config.php';

/* =====================================
 * 登出（只移除本裝置 remember token）
 * ===================================== */

if (!empty($_COOKIE['ulgg_remember'])) {
    $stmt = $db->prepare("
      DELETE FROM user_remember_tokens
      WHERE token = ?
    ");
    $stmt->execute([$_COOKIE['ulgg_remember']]);
}

// 清 Cookie
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

header('Location: /pages/index.php');
exit;
