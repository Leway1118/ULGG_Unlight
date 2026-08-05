<?php
// config.php
// 只在這裡寫一次連線設定
$ip     = '192.168.50.3';
$dbname = 'leway_db';
$user   = 'root';
$pass   = 'Uve%12345';

try {
    $db = new PDO("mysql:host={$ip};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("資料庫連線失敗：{$e->getMessage()}");
}
