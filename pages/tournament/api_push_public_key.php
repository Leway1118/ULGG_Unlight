<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!appVapidConfigured()) {
  http_response_code(503);
  echo json_encode([
    'ok' => false,
    'status' => 'disabled',
    'message' => 'VAPID push is not configured.',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

echo json_encode([
  'ok' => true,
  'publicKey' => ULGG_VAPID_PUBLIC_KEY,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
