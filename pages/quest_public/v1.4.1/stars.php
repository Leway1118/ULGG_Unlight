<?php
/** Legacy Stars URL: redirect to the embedded matrix. */
declare(strict_types=1);
$url = '/pages/quest_public/v1.4.1/index.php';
$duration = $_GET['duration'] ?? null;
if (is_string($duration) && ($duration === 'all' || (ctype_digit($duration) && (int)$duration <= 10))) {
    $url .= '?duration=' . rawurlencode($duration);
}
header('Location: ' . $url . '#stars', true, 302);
exit;
