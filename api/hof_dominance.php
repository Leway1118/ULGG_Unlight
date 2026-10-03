<?php

/**
 * Hall of Fame – Dominance (Long-term King)
 * ----------------------------------------
 * 統計「日快照中，排名第一的累積天數」
 *
 * GET params:
 *  - type   : bp | qp
 *  - region : TW | JP
 *  - limit  : default $default
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
$pdo = $db;
$default = 5;

/* ===============================
   參數處理
=============================== */

$type   = strtolower($_GET['type'] ?? 'bp');
$region = strtoupper($_GET['region'] ?? 'TW');
$limit  = (int)($_GET['limit'] ?? $default);

if (!in_array($type, ['bp', 'qp'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid type']);
    exit;
}

if (!in_array($region, ['TW', 'JP'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid region']);
    exit;
}

if ($limit <= 0 || $limit > 100) {
    $limit = $default;
}

/* ===============================
   資料表對應（日快照）
=============================== */

$tableMap = [
    'bp_TW' => 'ranking_bp_TW',
    'bp_JP' => 'ranking_bp_JP',
    'qp_TW' => 'ranking_qp_TW',
    'qp_JP' => 'ranking_qp_JP',
];

$key = "{$type}_{$region}";
$table = $tableMap[$key] ?? null;

if (!$table) {
    http_response_code(500);
    echo json_encode(['error' => 'table mapping failed']);
    exit;
}

/* ===============================
   SQL：第一名累積天數
=============================== */

$sql = "SELECT
  name,
  COUNT(*) AS days,
  MIN(DATE(ts)) AS first_date,
  MAX(DATE(ts)) AS last_date
FROM {$table}
WHERE rank_num = 1
  AND name IS NOT NULL
GROUP BY name
ORDER BY days DESC
LIMIT :limit

";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   組裝回傳資料
=============================== */

$items = [];
$rank = 1;

foreach ($rows as $row) {
    $items[] = [
        'rank'       => $rank++,
        'name'       => $row['name'],
        'days'       => (int)$row['days'],
        'first_date' => $row['first_date'],
        'last_date'  => $row['last_date'],
    ];
}
while (count($items) < $limit) {
    $items[] = [
        'rank'       => $rank++,
        'name'       => '— 從缺 —',
        'days'       => 0,
        'first_date' => null,
        'last_date'  => null,
    ];
}


/* ===============================
   Response
=============================== */

echo json_encode([
    'type'   => $type,
    'region' => $region,
    'items'  => $items,
], JSON_UNESCAPED_UNICODE);
