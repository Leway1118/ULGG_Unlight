<?php
/**
 * Hall of Fame – Monthly Rank API (Final)
 * --------------------------------------
 * GET params:
 *   type   = bp | qp
 *   region = TW | JP
 *   ym     = YYYY-MM (optional, default latest)
 *   limit  = number (optional, default 10, max 100)
 */

require_once __DIR__ . '/../config.php';
$pdo = $db;
$defaultLimit = 10;
$maxLimit = 100;
/* ===============================
   1. 參數處理
================================ */

$type   = strtolower($_GET['type']   ?? 'bp');
$region = strtoupper($_GET['region'] ?? 'TW');
$ym     = $_GET['ym'] ?? null;
$limit  = (int)($_GET['limit'] ?? $defaultLimit);

if (!in_array($type, ['bp','qp'], true)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid type']);
  exit;
}

if (!in_array($region, ['TW','JP'], true)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid region']);
  exit;
}

if ($limit <= 0) {
  $limit = $defaultLimit;
}
if ($limit > $maxLimit) {
  $limit = $maxLimit;
}

/* ===============================
   2. 動態 table / 欄位
================================ */

$table = "ranking_{$type}_{$region}_history";
$field = $type; // bp or qp

/* ===============================
   3. 取得「可用月份清單」（月結快照）
================================ */

$monthsStmt = $pdo->query("
  SELECT
    DATE_FORMAT(ts,'%Y-%m') AS ym
  FROM {$table}
  GROUP BY ym
  ORDER BY ym
");

$months = $monthsStmt->fetchAll(PDO::FETCH_COLUMN);

if (!$months) {
  echo json_encode([
    'meta'   => compact('type','region','ym','limit'),
    'months' => [],
    'rank'   => []
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===============================
   4. 決定目標月份（預設最新）
================================ */

if (!$ym || !in_array($ym, $months, true)) {
  $ym = end($months);
}

/* ===============================
   5. 取得該月份「最新 ts」（保險）
================================ */

$tsStmt = $pdo->prepare("
  SELECT MAX(ts)
  FROM {$table}
  WHERE DATE_FORMAT(ts,'%Y-%m') = :ym
");
$tsStmt->execute([':ym' => $ym]);
$ts = $tsStmt->fetchColumn();

if (!$ts) {
  echo json_encode([
    'meta'   => compact('type','region','ym','limit'),
    'months' => $months,
    'rank'   => []
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===============================
   6. 取該月 Top N 排行
================================ */

$selectFields = [
  "rank_num AS `rank`",
  "name",
  "{$field} AS value"
];

// BP history 有排名賽戰績；QP history 沒有，避免硬 join 不同排行榜資料。
if ($type === 'bp') {
  $selectFields[] = "COALESCE(win_ranked, 0) AS win_ranked";
  $selectFields[] = "COALESCE(lose_ranked, 0) AS lose_ranked";
  $selectFields[] = "COALESCE(draw_ranked, 0) AS draw_ranked";
}

$sql = "
  SELECT
    " . implode(",\n    ", $selectFields) . "
  FROM {$table}
  WHERE ts = :ts
  ORDER BY rank_num ASC
  LIMIT {$limit}
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':ts' => $ts]);
$rank = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   7. 輸出 JSON
================================ */

echo json_encode([
  'meta' => [
    'type'   => $type,
    'region' => $region,
    'ym'     => $ym,
    'limit'  => $limit,
    'ts'     => $ts
  ],
  'months' => $months,
  'rank'   => $rank
], JSON_UNESCAPED_UNICODE);
