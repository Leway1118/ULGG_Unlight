<?php

/**
 * hof_upset_top.php
 * 🐎 最強黑馬（低 BP 勝高 BP）
 *
 * 【重要語意】
 * win  = 1 → P1 輸（P2 勝）
 * lose = 1 → P1 贏（P2 輸）
 *
 * 黑馬定義：
 * - 勝者 BP < 敗者 BP
 * - 勝者 BP > 1500
 * - bp_diff = 敗者 BP − 勝者 BP（正數、有方向）
 */

session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = $db;

/* =====================
 * 參數
 * ===================== */
$region = strtoupper(trim($_GET['region'] ?? ''));
$limit  = (int)($_GET['limit'] ?? 10);

if ($limit <= 0 || $limit > 50) $limit = 10;
if (!in_array($region, ['TW', 'JP'], true)) $region = null;

/* =====================
 * SQL
 * ===================== */
$sql = "
SELECT
  DATE(a.update_time) AS fight_date,

  /* ========= 勝者 ========= */
  CASE
    WHEN a.win  = 1 THEN a.name_p2   -- P2 勝
    WHEN a.lose = 1 THEN a.name_p1   -- P1 勝
  END AS winner_id,

  CASE
    WHEN a.win  = 1 THEN a.bp_p2
    WHEN a.lose = 1 THEN a.bp_p1
  END AS winner_bp,

  /* ========= 敗者 ========= */
  CASE
    WHEN a.win  = 1 THEN a.bp_p1
    WHEN a.lose = 1 THEN a.bp_p2
  END AS loser_bp,

  /* ========= BP 差距（方向性） ========= */
  CASE
    WHEN a.win  = 1 THEN a.bp_p1 - a.bp_p2
    WHEN a.lose = 1 THEN a.bp_p2 - a.bp_p1
  END AS bp_diff,


  /* ===== 勝者牌組 ===== */
uw1.id AS w1_id, uw1.ico AS w1_ico, uw1.name AS w1_name, uw1.level AS w1_level,
uw2.id AS w2_id, uw2.ico AS w2_ico, uw2.name AS w2_name, uw2.level AS w2_level,
uw3.id AS w3_id, uw3.ico AS w3_ico, uw3.name AS w3_name, uw3.level AS w3_level,

/* ===== 敗者牌組 ===== */
ul1.id AS l1_id, ul1.ico AS l1_ico, ul1.name AS l1_name, ul1.level AS l1_level,
ul2.id AS l2_id, ul2.ico AS l2_ico, ul2.name AS l2_name, ul2.level AS l2_level,
ul3.id AS l3_id, ul3.ico AS l3_ico, ul3.name AS l3_name, ul3.level AS l3_level

FROM arena_unlight a

/* ===== 勝者角色 ===== */
JOIN unlight uw1 ON uw1.id = (
  CASE WHEN a.win = 1 THEN a.e1 ELSE a.u1 END
)
JOIN unlight uw2 ON uw2.id = (
  CASE WHEN a.win = 1 THEN a.e2 ELSE a.u2 END
)
JOIN unlight uw3 ON uw3.id = (
  CASE WHEN a.win = 1 THEN a.e3 ELSE a.u3 END
)

/* ===== 敗者角色 ===== */
JOIN unlight ul1 ON ul1.id = (
  CASE WHEN a.win = 1 THEN a.u1 ELSE a.e1 END
)
JOIN unlight ul2 ON ul2.id = (
  CASE WHEN a.win = 1 THEN a.u2 ELSE a.e2 END
)
JOIN unlight ul3 ON ul3.id = (
  CASE WHEN a.win = 1 THEN a.u3 ELSE a.e3 END
)

WHERE
  a.ack1 = 1
  AND a.ack2 = 1
  AND a.win + a.lose = 1

  /* ===== 黑馬條件：低 BP 勝高 BP ===== */
  AND (
    CASE
      WHEN a.win  = 1 THEN a.bp_p2
      WHEN a.lose = 1 THEN a.bp_p1
    END
  ) < (
    CASE
      WHEN a.win  = 1 THEN a.bp_p1
      WHEN a.lose = 1 THEN a.bp_p2
    END
  )

  /* ===== 勝者 BP 下限 ===== */
  AND (
    CASE
      WHEN a.win  = 1 THEN a.bp_p2
      WHEN a.lose = 1 THEN a.bp_p1
    END
  ) > 1500
";

/* 區域 */
if ($region !== null) {
  $sql .= " AND a.region = :region ";
}

/* 排序 */
$sql .= "
ORDER BY bp_diff DESC, winner_bp DESC
LIMIT :limit
";

$stmt = $pdo->prepare($sql);
if ($region !== null) {
  $stmt->bindValue(':region', $region, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

/* =====================
 * 輸出
 * ===================== */
$data = [];
$rank = 1;

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $data[] = [
    'rank'      => $rank++,
    'winner_id' => $r['winner_id'],
    'winner_bp' => (int)$r['winner_bp'],
    'loser_bp'  => (int)$r['loser_bp'],
    'bp_diff'   => (int)$r['bp_diff'],
    'date'      => $r['fight_date'],

    'winner_deck' => [
      [
        'id'    => (int)$r['w1_id'],
        'ico'   => $r['w1_ico'],
        'name'  => $r['w1_name'],
        'level' => $r['w1_level']
      ],
      [
        'id'    => (int)$r['w2_id'],
        'ico'   => $r['w2_ico'],
        'name'  => $r['w2_name'],
        'level' => $r['w2_level']
      ],
      [
        'id'    => (int)$r['w3_id'],
        'ico'   => $r['w3_ico'],
        'name'  => $r['w3_name'],
        'level' => $r['w3_level']
      ],
    ],


    'loser_deck' => [
      ['ico' => $r['l1_ico'], 'name' => $r['l1_name'], 'level' => $r['l1_level']],
      ['ico' => $r['l2_ico'], 'name' => $r['l2_name'], 'level' => $r['l2_level']],
      ['ico' => $r['l3_ico'], 'name' => $r['l3_name'], 'level' => $r['l3_level']],
    ],
  ];
}

echo json_encode([
  'status' => 'ok',
  'data'   => $data,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
