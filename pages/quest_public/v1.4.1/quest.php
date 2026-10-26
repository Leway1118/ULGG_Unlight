<?php
/** Legacy Quest-efficiency URL: retired from public navigation. */
declare(strict_types=1);
$window = $_GET['window'] ?? 'all';
$allowed = ['today', '7d', '30d', 'all'];
$url = '/pages/quest_public/v1.4.1/index.php';
if (is_string($window) && in_array($window, $allowed, true)) {
    $url .= '?window=' . rawurlencode($window);
}
header('Location: ' . $url, true, 302);
exit;
