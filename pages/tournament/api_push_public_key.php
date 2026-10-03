<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo json_encode([
  'ok' => true,
  'publicKey' => ULGG_VAPID_PUBLIC_KEY,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);