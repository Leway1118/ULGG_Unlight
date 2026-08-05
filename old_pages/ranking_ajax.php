<?php
/** @var PDO $db */
require_once 'config.php';
date_default_timezone_set("Asia/Taipei");

/**
 * ------------------------------------------------------
 * 1) 讀取參數
 * ------------------------------------------------------
 */
$period  = $_GET['period'] ?? '7d';
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;

/**
 * ------------------------------------------------------
 * 2) 期間計算
 * ------------------------------------------------------
 */
$today = date("Y-m-d");

switch ($period) {
  case '3d':
    $startDate = date("Y-m-d", strtotime("-2 days"));
    break;

  case '30d':
    $startDate = date("Y-m-d", strtotime("-29 days"));
    break;

  case '7d':
  default:
    $startDate = date("Y-m-d", strtotime("-6 days"));
    $period = '7d';
    break;
}

$endDate = $today . " 23:59:59";

/**
 * ------------------------------------------------------
 * 3) SQL – 彙整 3v3 牌組
 * ------------------------------------------------------
 */
$sql = "
WITH raw_teams AS (
    SELECT 
        LEAST(e1, LEAST(e2,e3)) AS c1,
        LEAST(GREATEST(e1,e2), GREATEST(LEAST(e1,e2),e3)) AS c2,
        GREATEST(e1, GREATEST(e2,e3)) AS c3,
        lose AS win,
        win  AS lose,
        cost,
        bp_p1 AS bp,
        update_time
    FROM arena_unlight
    WHERE ack1=1 AND ack2=1
      AND update_time BETWEEN :start_date AND :end_date

    UNION ALL

    SELECT 
        LEAST(u1, LEAST(u2,u3)),
        LEAST(GREATEST(u1,u2), GREATEST(LEAST(u1,u2),u3)),
        GREATEST(u1, GREATEST(u2,u3)),
        win,
        lose,
        cost,
        bp_p2 AS bp,
        update_time
    FROM arena_unlight
    WHERE ack1=1 AND ack2=1
      AND update_time BETWEEN :start_date AND :end_date
),

team_summary AS (
    SELECT
        c1, c2, c3,
        SUM(win)  AS total_win,
        SUM(lose) AS total_lose,
        SUM(win + lose) AS total_games,
        ROUND(CASE WHEN SUM(win+lose)>0 THEN SUM(win)/SUM(win+lose)*100 ELSE 0 END, 1) AS win_rate,
        MAX(update_time) AS last_played
    FROM raw_teams
    GROUP BY c1,c2,c3
)

SELECT *
FROM team_summary
WHERE total_games >= 5
ORDER BY win_rate DESC, total_games DESC
";

$stmt = $db->prepare($sql);
$stmt->execute([
  ':start_date' => $startDate . " 00:00:00",
  ':end_date'   => $endDate
]);

$allTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total    = count($allTeams);

$offset   = ($page - 1) * $perPage;
$teams    = array_slice($allTeams, $offset, $perPage);

/**
 * ------------------------------------------------------
 * 4) 查詢卡片資料
 * ------------------------------------------------------
 */
$allCharIds = [];
foreach ($teams as $t) {
  $allCharIds[] = $t['c1'];
  $allCharIds[] = $t['c2'];
  $allCharIds[] = $t['c3'];
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

/**
 * ------------------------------------------------------
 * 5) 分頁回傳（只有上一頁/下一頁）
 * ------------------------------------------------------
 */
if (isset($_GET['pagination'])) {

  $totalPages = max(1, ceil($total / 10));

  $html = "";

  if ($page > 1) {
    $html .= "<span class='un-page-btn' onclick='loadPage(" . ($page - 1) . ")'>◀</span>";
  }

  $html .= "<span class='un-page-current'>{$page} / {$totalPages}</span>";

  if ($page < $totalPages) {
    $html .= "<span class='un-page-btn' onclick='loadPage(" . ($page + 1) . ")'>▶</span>";
  }

  echo $html;
  exit;
}

/**
 * ------------------------------------------------------
 * 6) 卡片區域主內容（含 card 重疊 CSS）
 * ------------------------------------------------------
 */
?>
<style>
  /* ============================
   Deck Card（A 最底 → C 最上）
============================ */
  .deck-cards {
    position: relative;
    width: 300px;
    height: 150px;
    margin: 8px 0;
  }

  /* 基本卡片 */
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

  /* 👉 A 最底、B 中間、C 最上 */
  .card-a {
    left: 0;
    z-index: 1;
    /* 最底 */
  }

  .card-b {
    left: 80px;
    z-index: 2;
    /* 中間 */
  }

  .card-c {
    left: 165px;
    z-index: 3;
    /* 最上 */
  }

  /* hover → 上浮強調 */
  .deck-card:hover {
    transform: translateY(-4px) scale(1.05);
    z-index: 10;
  }

  /* 手機 RWD */
  @media (max-width:480px) {
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

    $c1 = $t['c1'];
    $c2 = $t['c2'];
    $c3 = $t['c3'];

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

        <!-- 卡片疊放 -->
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
            <span class="un-stat-label">
              戰績：
              <span class="un-stat-value"><?= $wins ?> 勝 / <?= $lose ?> 敗</span>
            </span>
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