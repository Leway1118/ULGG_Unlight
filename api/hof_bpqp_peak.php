<?php

/**
 *  * hof_bpqp_peak.php
 * Hall of Fame – BP / QP Peak API
 * --------------------------------
 * GET params:
 *   type   = bp | qp
 *   region = TW | JP
 *   limit  = 10 (<=50)
 */

require_once __DIR__ . '/../config.php';
$pdo = $db;

/* ===============================
   1. 參數處理
================================ */

$type   = strtolower($_GET['type']   ?? 'bp');
$region = strtoupper($_GET['region'] ?? 'TW');
$limit  = (int)($_GET['limit'] ?? 10);

if (!in_array($type, ['bp', 'qp'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid type']);
    exit;
}

if (!in_array($region, ['TW', 'JP'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid region']);
    exit;
}

if ($limit <= 0 || $limit > 50) {
    $limit = 10;
}

/* ===============================
   2. 動態指定 table / 欄位
================================ */

$table = "ranking_{$type}_{$region}_history";
$field = $type; // bp or qp

/* ===============================
   3. 取「每位玩家歷史最高分」
   - 同分取最早達成 ts
================================ */

$sql = "
SELECT
  t.name,
  t.{$field} AS value,
  DATE(t.ts) AS date
FROM {$table} t
JOIN (
  SELECT
    name,
    MAX({$field}) AS peak
  FROM {$table}
  GROUP BY name
) p
  ON t.name = p.name
 AND t.{$field} = p.peak
ORDER BY
  t.{$field} DESC,
  t.ts ASC
LIMIT :limit
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   4. 整理排名
================================ */

$items = [];
$rank = 1;
foreach ($rows as $r) {
    $items[] = [
        'rank'  => $rank++,
        'name'  => $r['name'],
        'value' => (int)$r['value'],
        'date'  => $r['date']
    ];
}

/* ===============================
   5. 輸出
================================ */

echo json_encode([
    'meta' => [
        'type'   => $type,
        'region' => $region,
        'limit'  => $limit
    ],
    'items' => $items
], JSON_UNESCAPED_UNICODE);
