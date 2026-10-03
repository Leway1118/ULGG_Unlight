<?php

/**
 * hof_deck_streak_max.php
 * 🔥 最長連勝牌組（API 讀取版）
 * - 不計算
 * - 不掃歷史
 * - 只讀 CRON 結果
 */

session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = $db;

/* ===== 參數 ===== */
$limit = (int)($_GET['limit'] ?? 10);
if ($limit <= 0 || $limit > 20) $limit = 10;

/* ===== SQL（安全 JOIN，資料量極小） ===== */
$sql = "
SELECT
h.id,          -- ⭐ 補這行
  h.streak,
  h.period,

  l.id    AS leader_id,
  l.name  AS leader_name,
  l.level AS leader_level,
  l.ico   AS leader_ico,

  b1.id    AS back1_id,
  b1.name  AS back1_name,
  b1.level AS back1_level,
  b1.ico   AS back1_ico,

  b2.id    AS back2_id,
  b2.name  AS back2_name,
  b2.level AS back2_level,
  b2.ico   AS back2_ico

FROM hof_deck_streak_max h
JOIN unlight l  ON h.leader_id = l.id
JOIN unlight b1 ON h.back1_id  = b1.id
JOIN unlight b2 ON h.back2_id  = b2.id
ORDER BY h.streak DESC
LIMIT :limit
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

/* ===== Output ===== */
$data = [];
$rank = 1;

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sqlPlayers = "
  SELECT DISTINCT player_name
  FROM v_arena_player_match_result
  WHERE leader_id = :leader
    AND back1_id  = :back1
    AND back2_id  = :back2
    AND is_win = 1
    AND update_time LIKE CONCAT(:period, '%')
    AND player_name IS NOT NULL
";

    $stmtPlayers = $pdo->prepare($sqlPlayers);

    $stmtPlayers->execute([
        ':leader' => $r['leader_id'],
        ':back1'  => $r['back1_id'],
        ':back2'  => $r['back2_id'],
        ':period' => $r['period'], // 例如 2025-10
    ]);

    $players = $stmtPlayers->fetchAll(PDO::FETCH_COLUMN);

    $data[] = [
        'id'     => (int)$r['id'],   // ⭐ 就用 hof_deck_streak_max.id
        'rank'   => $rank++,
        'streak' => (int)$r['streak'],
        'period' => $r['period'],
        'players' => $players, // ⭐ 關鍵
        'deck'   => [
            'leader' => [
                'id'    => (int)$r['leader_id'],
                'level' => $r['leader_level'],
                'name'  => $r['leader_name'],
                'ico'   => $r['leader_ico'],
            ],
            'back1' => [
                'id'    => (int)$r['back1_id'],
                'level' => $r['back1_level'],
                'name'  => $r['back1_name'],
                'ico'   => $r['back1_ico'],
            ],
            'back2' => [
                'id'    => (int)$r['back2_id'],
                'level' => $r['back2_level'],
                'name'  => $r['back2_name'],
                'ico'   => $r['back2_ico'],
            ],
        ],
    ];
}

echo json_encode([
    'status' => 'ok',
    'data'   => $data,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
