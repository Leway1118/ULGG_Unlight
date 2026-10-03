<?php
/**
 * hof_player_streak_count.php
 * 👤 最常連勝玩家（API）
 *
 * 定義：
 * - 統計「連勝 ≥ N 場」的出現次數
 * - 不顯示牌組
 * - 不掃 arena_unlight 原表
 * - 僅使用 v_arena_player_match_result
 */

session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = $db;

/* ===== 參數 ===== */
$limit = (int)($_GET['limit'] ?? 10);
$minStreak = (int)($_GET['min_streak'] ?? 5);
$order = $_GET['order'] ?? 'count'; // count | max
$orderSql = ($order === 'max')
  ? 'max_streak DESC, streak_count DESC'
  : 'streak_count DESC, max_streak DESC';

if ($limit <= 0 || $limit > 20) $limit = 10;
if ($minStreak < 3 || $minStreak > 50) $minStreak = 5;

/*
 * 核心邏輯說明：
 * 1. 先依 player_name + update_time 排序
 * 2. 用 MySQL 變數計算連勝段落
 * 3. 只保留連勝 >= min_streak
 * 4. 對玩家做彙總
 */
$sql = "
WITH ordered AS (
  SELECT
    player_name,
    update_time,
    is_win,
    @grp := IF(
      @prev_player = player_name AND is_win = 1,
      @grp,
      @grp + 1
    ) AS grp,
    @prev_player := player_name
  FROM v_arena_player_match_result
  JOIN (SELECT @grp := 0, @prev_player := '') vars
  WHERE player_name IS NOT NULL
  ORDER BY player_name, update_time
),
streaks AS (
  SELECT
    player_name,
    grp,
    COUNT(*) AS streak,
    MAX(update_time) AS last_date
  FROM ordered
  WHERE is_win = 1
  GROUP BY player_name, grp
  HAVING streak >= :minStreak
)
SELECT
  player_name,
  COUNT(*)        AS streak_count,
  MAX(streak)     AS max_streak,
  MAX(last_date)  AS last_date
FROM streaks
GROUP BY player_name
ORDER BY {$orderSql}
LIMIT :limit
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':minStreak', $minStreak, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

/* ===== Output ===== */
$data = [];
$rank = 1;

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $data[] = [
        'rank'         => $rank++,
        'player'       => $r['player_name'],
        'streak_count' => (int)$r['streak_count'],
        'max_streak'   => (int)$r['max_streak'],
        'last_date'    => substr($r['last_date'], 0, 10),
    ];
}

echo json_encode([
    'status' => 'ok',
    'meta' => [
        'min_streak' => $minStreak,
        'limit'      => $limit,
    ],
    'data' => $data,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
