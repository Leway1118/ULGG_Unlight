#!/usr/bin/env bash
set -euo pipefail

TARGET_DIR="/var/www/html/unlight/api"
TARGET_FILE="$TARGET_DIR/unlight_helper_policy.php"
BACKUP_DIR="/var/www/html/unlight/_backup"

mkdir -p "$TARGET_DIR" "$BACKUP_DIR"

if [ -f "$TARGET_FILE" ]; then
  cp -a "$TARGET_FILE" "$BACKUP_DIR/unlight_helper_policy.$(date +%Y%m%d_%H%M%S).php.bak"
fi

cat > "$TARGET_FILE" <<'PHP'
<?php
declare(strict_types=1);

/*
 * UNLIGHT HELPER remote policy endpoint v1
 *
 * Emergency controls:
 *   1) Stop the whole Helper:
 *        'enabled' => false
 *
 *   2) Keep read-only features but stop all writes:
 *        'deck_write'    => false
 *        'shop_bulk_buy' => false
 *
 *   3) Stop only Shop purchasing:
 *        'shop_bulk_buy' => false
 *
 *   4) Force old clients into read-only mode:
 *        raise 'min_version'
 *
 * This endpoint is read-only. It does not receive or store Steam ID,
 * UNLIGHT player ID, deck data, GEM count, or purchase history.
 * Ordinary web-server access logs may still contain network metadata such
 * as source IP, according to the server's normal logging configuration.
 */

$policy = [
    'schema' => 1,
    'policy_revision' => 1,

    // false = all Helper features disabled on the next successful policy sync.
    'enabled' => true,

    // Clients below this version keep read-only access but all writes stop.
    'min_version' => '0.2.5',

    // If this endpoint is temporarily unreachable, a previously successful
    // policy may be reused for up to this many hours. After that: read-only.
    'max_cache_hours' => 24,

    'features' => [
        'deck_read' => true,
        'deck_write' => true,
        'shop_read' => true,
        'shop_bulk_buy' => true,
    ],

    'message' => 'Beta 測試中；已向官方詢問第三方工具使用規範。',
];

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($method === 'HEAD') {
    http_response_code(200);
    exit;
}

http_response_code(200);
echo json_encode(
    $policy,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

PHP

chmod 0644 "$TARGET_FILE"

echo "[PHP LINT]"
php -l "$TARGET_FILE"

echo
echo "[LOCAL GET]"
curl -fsS "http://127.0.0.1/unlight/api/unlight_helper_policy.php" || true

echo
echo
echo "[PUBLIC HTTPS GET]"
curl -fsS "https://ulgg.online/unlight/api/unlight_helper_policy.php"

echo
echo
echo "[DONE]"
echo "https://ulgg.online/unlight/api/unlight_helper_policy.php"
