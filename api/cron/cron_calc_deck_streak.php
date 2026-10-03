<?php
/**
 * cron_calc_deck_streak.php
 * 🕒 CLI CRON：計算最長連勝牌組（最終穩定版）
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

require_once __DIR__ . '/../../config.php';
$pdo = $db;

/* =========================
   設定
========================= */
$MIN_STREAK = 5;
$TOP_LIMIT  = 20;

/* =========================
   查詢（不 JOIN）
========================= */
$sql = "
SELECT
  update_time,
  leader_id,
  back1_id,
  back2_id,
  is_win
FROM v_arena_player_match_result
WHERE is_win IS NOT NULL
ORDER BY leader_id, back1_id, back2_id, update_time
";

$stmt = $pdo->prepare($sql);
$stmt->execute();

/* =========================
   連勝狀態機
========================= */
$current = [];
$best    = [];

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $key   = "{$r['leader_id']}-{$r['back1_id']}-{$r['back2_id']}";
    $isWin = (int)$r['is_win'];

    if ($isWin === 1) {
        $current[$key] = ($current[$key] ?? 0) + 1;

        if ($current[$key] >= $MIN_STREAK) {
            if (
                !isset($best[$key]) ||
                $current[$key] > $best[$key]['streak']
            ) {
                $best[$key] = [
                    'leader' => (int)$r['leader_id'],
                    'back1'  => (int)$r['back1_id'],
                    'back2'  => (int)$r['back2_id'],
                    'streak' => $current[$key],
                    'period' => date('Y-m', strtotime($r['update_time'])),
                ];
            }
        }
    } else {
        unset($current[$key]);
    }
}

/* =========================
   無資料 → 安全結束
========================= */
if (empty($best)) {
    echo "No deck streak data.\n";
    exit(0);
}

/* =========================
   排序 + Top N
========================= */
usort($best, fn($a, $b) => $b['streak'] <=> $a['streak']);
$best = array_slice($best, 0, $TOP_LIMIT);

/* =========================
   寫入資料庫（交易防呆）
========================= */
try {

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    $pdo->exec("TRUNCATE TABLE hof_deck_streak_max");

    $ins = $pdo->prepare("
      INSERT INTO hof_deck_streak_max
      (leader_id, back1_id, back2_id, streak, period)
      VALUES (:l, :b1, :b2, :s, :p)
    ");

    foreach ($best as $row) {
        $ins->execute([
            ':l'  => $row['leader'],
            ':b1' => $row['back1'],
            ':b2' => $row['back2'],
            ':s'  => $row['streak'],
            ':p'  => $row['period'],
        ]);
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    echo "Deck streak max updated: " . count($best) . PHP_EOL;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
