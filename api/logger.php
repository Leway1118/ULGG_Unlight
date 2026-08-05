<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config.php";   // your PDO $db

// ------------------------------
// 1. 取得訪客資訊
// ------------------------------
function getClientIp()
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP']; 
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function detectBotType($ua)
{
    $ua = strtolower($ua);
    $bots = [
        'googlebot' => 'Googlebot',
        'bingbot'   => 'Bingbot',
        'gptbot'    => 'GPTBot',
        'duckduck'  => 'DuckDuckBot',
        'spider'    => 'Spider',
        'crawler'   => 'Crawler',
        'curl'      => 'Curl',
        'python'    => 'Python Script'
    ];
    foreach ($bots as $k => $v) {
        if (strpos($ua, $k) !== false) return $v;
    }
    return null;
}

function getGeo($ip)
{
    if ($ip === "127.0.0.1") return ["Local", "Local"];

    $api = "http://ip-api.com/json/{$ip}?fields=status,country,city";
    $json = @file_get_contents($api);
    $d = json_decode($json, true);

    if ($d['status'] !== "success") return [null, null];

    return [$d['country'], $d['city']];
}

// ------------------------------
// 2. Log 參數準備
// ------------------------------
$ip = getClientIp();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$page = $_SERVER['REQUEST_URI'];
$ref = $_SERVER['HTTP_REFERER'] ?? null;
$botType = detectBotType($ua);
$isBot = $botType ? 1 : 0;
list($country, $city) = getGeo($ip);

// Session ID（每訪客固定）
if (!isset($_SESSION['visitor_sid'])) {
    $_SESSION['visitor_sid'] = bin2hex(random_bytes(16));
}
$sessionId = $_SESSION['visitor_sid'];

// ------------------------------
// 3. 寫入 MySQL
// ------------------------------
$stmt = $db->prepare("
    INSERT INTO visitors 
    (ip, user_agent, page, referrer, session_id, visited_at, is_bot, bot_type, country, city)
    VALUES (:ip, :ua, :page, :ref, :sid, NOW(), :is_bot, :bot_type, :country, :city)
");
$stmt->execute([
    ':ip'       => $ip,
    ':ua'       => $ua,
    ':page'     => $page,
    ':ref'      => $ref,
    ':sid'      => $sessionId,
    ':is_bot'   => $isBot,
    ':bot_type' => $botType,
    ':country'  => $country,
    ':city'     => $city
]);
