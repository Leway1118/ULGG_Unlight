<?php
/* =========================================================
 * 全站初始化（Bootstrap）
 * ========================================================= */

// ⭐ 時區（一定要最前）
date_default_timezone_set('Asia/Taipei');

// ⭐ 錯誤顯示（正式環境可關）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ⭐ Composer Autoload
require_once __DIR__ . '/vendor/autoload.php';

// ⭐ 載入 .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// ⭐ 讀取 DB 設定
$dbHost = $_ENV["DB_HOST"] ?? null;
$dbUser = $_ENV["DB_USER"] ?? null;
$dbPass = $_ENV["DB_PASSWORD"] ?? null;
$dbName = $_ENV["DB_NAME"] ?? null;
$dbPort = $_ENV["DB_PORT"] ?? 3306;

if (!$dbHost || !$dbUser || !$dbName) {
    die("❌ 無法讀取資料庫設定: .env 可能沒載入成功");
}

// ⭐ 建立 DB 連線
try {
    $db = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("DB 連線失敗：" . $e->getMessage());
}

// ⭐ 全站常數
define('IMG_BASE', '/assets/uploads/');
define('APP_ROOT', __DIR__);