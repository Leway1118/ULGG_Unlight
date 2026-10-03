<?php

/** @var PDO $db */
require_once '../config.php';
date_default_timezone_set("Asia/Taipei");

/* ============================================================
   1) 評分公式（平滑勝率 + 場次加權）
============================================================ */
function calcPerformanceByTotalGames($total_games, $win_rate, $d)
{
  if ($total_games <= 0) return 0.0;

  // ---- 勝率平滑（避免 6-0 = 100%）----
  $priorGames = 20;
  $priorRate  = 0.5;

  $adj_win_rate = ($win_rate * $total_games + $priorRate * $priorGames)
    / ($total_games + $priorGames);  // 0.0 ~ 1.0

  // ---- logBase 根據 d（場次）自適應 ----
  $raw_log = $d * 4 + 9;
  $logBase = min(max($raw_log, 15), 45);

  if ($logBase <= 1) $logBase = 15;

  // ---- 場次加權（log scale）----
  $games_factor = log($total_games + 1) / log($logBase);
  $games_factor = max(0.3, min($games_factor, 2.0));

  // ---- 最終評分 ----
  $score = $adj_win_rate * $games_factor * 20;
  return round(min($score, 20.0), 1);
}

/* ============================================================
   2) 讀取期間參數
============================================================ */
$period = $_GET['period'] ?? '7d';
$page   = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;

$todayYmd = date("Y-m-d");  // 今天
$yesterdayYmd = date("Y-m-d", strtotime("-1 day")); // 昨天

switch ($period) {
  case 'today':
    $dateFilter = "DATE(update_time) = CURDATE()";
    $min_games = 1;
    break;

  case 'yesterday':
    $dateFilter = "DATE(update_time) = CURDATE() - INTERVAL 1 DAY";
    $min_games = 1;
    break;

  case '3d':
    $dateFilter = "update_time  >= NOW() - INTERVAL 3 DAY";
    $min_games = 3;
    break;

  case '7d':
    $dateFilter = "update_time  >= NOW() - INTERVAL 7 DAY";
    $min_games = 5;
    break;

  default: // 30 日
    $dateFilter = "update_time  >= NOW() - INTERVAL 30 DAY";
    $min_games = 5;
}



/* ============================================================
   3) SQL：彙整 3v3 deck（雙方合併，角色排序）
============================================================ */
$sql = "
WITH raw_teams AS (
    SELECT 
        e1 AS leader,
        LEAST(e2, e3) AS sub1,
        GREATEST(e2, e3) AS sub2,
        lose AS win,
        win  AS lose,
        cost,
        bp_p1 AS bp,
        update_time
    FROM arena_unlight
    WHERE ack1=1 AND ack2=1
      AND $dateFilter

    UNION ALL

    SELECT 
        u1 AS leader,
        LEAST(u2, u3) AS sub1,
        GREATEST(u2, u3) AS sub2,
        win,
        lose,
        cost,
        bp_p2 AS bp,
        update_time
    FROM arena_unlight
    WHERE ack1=1 AND ack2=1
      AND $dateFilter
),

team_summary AS (
    SELECT
        leader,
        sub1,
        sub2,
        SUM(win)  AS total_win,
        SUM(lose) AS total_lose,
        SUM(win + lose) AS total_games,
        ROUND(CASE WHEN SUM(win+lose)>0 THEN SUM(win)/SUM(win+lose)*100 ELSE 0 END, 1) AS win_rate,
        MAX(update_time) AS last_played
    FROM raw_teams
    GROUP BY leader, sub1, sub2
)

SELECT *
FROM team_summary
HAVING total_games >= :min_games
ORDER BY win_rate DESC, total_games DESC;

";

$stmt = $db->prepare($sql);
$stmt->execute([
  ':min_games'  => $min_games
]);

$allTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   4) 統一計算評分（score）並排序
============================================================ */
foreach ($allTeams as &$t) {
  $games = (int)$t['total_games'];
  $wr    = (float)$t['win_rate'];

  // 使用 d = total_games（最穩定、最合理）
  $t['score'] = calcPerformanceByTotalGames($games, $wr / 100, $games);
}
unset($t);

// ---- 排序：score → win_rate → total_games ----
usort($allTeams, function ($a, $b) {
  if ($a['score'] != $b['score']) return ($b['score'] <=> $a['score']);
  if ($a['win_rate'] != $b['win_rate']) return ($b['win_rate'] <=> $a['win_rate']);
  return ($b['total_games'] <=> $a['total_games']);
});

/* ============================================================
   5) 分頁
============================================================ */
$total    = count($allTeams);
$offset   = ($page - 1) * $perPage;
$teams    = array_slice($allTeams, $offset, $perPage);

/* ============================================================
   6) 查角色卡面資料
============================================================ */
$allCharIds = [];
foreach ($teams as $t) {
  $allCharIds[] = $t['leader'];
  $allCharIds[] = $t['sub1'];
  $allCharIds[] = $t['sub2'];
}
$allCharIds = array_unique($allCharIds);

$charMap = [];
if (!empty($allCharIds)) {
  $idList = implode(",", array_map("intval", $allCharIds));
  $sql = "SELECT id, name, level, ico FROM unlight WHERE id IN ($idList)";
  $rs = $db->query($sql);
  while ($c = $rs->fetch(PDO::FETCH_ASSOC)) {
    $charMap[$c['id']] = $c;
  }
}

function getCardInfo($map, $id)
{
  return $map[$id] ?? [
    'ico' => 'dist/img/no_card.png',
    'name' => "未知($id)",
    'level' => ''
  ];
}

/* ============================================================
   7) 如果只要求分頁按鈕
============================================================ */
if (isset($_GET['pagination'])) {

  $totalPages = max(1, ceil($total / 10));

  $html = "";

  if ($page > 1)
    $html .= "<span class='un-page-btn' onclick='loadPage(" . ($page - 1) . ")'>◀</span>";

  $html .= "<span class='un-page-current'>{$page} / {$totalPages}</span>";

  if ($page < $totalPages)
    $html .= "<span class='un-page-btn' onclick='loadPage(" . ($page + 1) . ")'>▶</span>";

  echo $html;
  exit;
}

/* ============================================================
   8) 以下：卡片疊放 CSS + HTML
============================================================ */
?>
<style>
  .deck-cards {
    position: relative;
    width: 300px;
    height: 150px;
    margin: 8px 0;
  }

  .deck-card {
    position: absolute;
    top: 0;
    width: 110px;
    height: 150px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 8px rgba(0, 0, 0, .45);
    transition: transform .15s ease, z-index .15s ease;
  }

  .deck-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .card-a {
    left: 0;
    z-index: 1;
  }

  .card-b {
    left: 80px;
    z-index: 2;
  }

  .card-c {
    left: 165px;
    z-index: 3;
  }

  .deck-card:hover {
    transform: translateY(-4px) scale(1.05);
    z-index: 10;
  }

  @media(max-width:480px) {
    .deck-cards {
      width: 230px;
      height: 120px;
    }

    .deck-card {
      width: 90px;
      height: 120px;
    }

    .card-b {
      left: 80px;
    }

    .card-c {
      left: 165px;
    }
  }
</style>

<div class="un-team-grid">
  <?php
  $rank = $offset + 1;

  foreach ($teams as $t):

    $c1 = $t['leader'];  // 排頭（不可排序）
    $c2 = $t['sub1'];    // B/C（已排序）
    $c3 = $t['sub2'];

    $card1 = getCardInfo($charMap, $c1);
    $card2 = getCardInfo($charMap, $c2);
    $card3 = getCardInfo($charMap, $c3);

    $ico1 = "../uploads/" . $card1['ico'];
    $ico2 = "../uploads/" . $card2['ico'];
    $ico3 = "../uploads/" . $card3['ico'];

    $wr    = $t['win_rate'];
    $games = $t['total_games'];
    $wins  = $t['total_win'];
    $lose  = $t['total_lose'];
    $score = $t['score'];

    if ($wr < 50) $wrClass = "low";
    elseif ($wr < 65) $wrClass = "mid";
    else $wrClass = "high";
  ?>
    <div class="un-team-card">

      <div class="un-team-header">
        <div class="un-team-rank">#<?= $rank ?></div>
        <div class="un-team-chip">
          <?= $card1['level'] . $card1['name'] ?> /
          <?= $card2['level'] . $card2['name'] ?> /
          <?= $card3['level'] . $card3['name'] ?>
        </div>
      </div>

      <div class="un-team-body">

        <div class="deck-cards">
          <div class="deck-card card-a"><img src="<?= $ico1 ?>"></div>
          <div class="deck-card card-b"><img src="<?= $ico2 ?>"></div>
          <div class="deck-card card-c"><img src="<?= $ico3 ?>"></div>
        </div>

        <div class="un-team-meta">
          <div class="un-team-meta-row">
            <span class="un-winrate-badge <?= $wrClass ?>">勝率 <?= $wr ?>%</span>
            <span class="un-meta-pill">出場 <?= $games ?> 場</span>
          </div>
          <div class="un-team-meta-row">
            <span class="un-meta-pill" style="background:#263238;border-color:#90a4ae;">
              評分：<?= $score ?> / 20
            </span>
          </div>
          <div class="un-team-meta-row">
            戰績：<span class="un-stat-value"><?= $wins ?> 勝 / <?= $lose ?> 敗</span>
          </div>
        </div>

      </div>

      <div class="un-team-footer">
        <form action="fight.php" method="POST" style="margin:0;">
          <input type="hidden" name="e1" value="<?= $c1 ?>">
          <input type="hidden" name="e2" value="<?= $c2 ?>">
          <input type="hidden" name="e3" value="<?= $c3 ?>">
          <input type="hidden" name="check" value="1">
          <button class="un-more-btn">查看詳細對戰</button>
        </form>
      </div>

    </div>

  <?php
    $rank++;
  endforeach;
  ?>
</div>