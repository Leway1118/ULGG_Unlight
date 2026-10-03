<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/_analysis_base.php'; // ⭐ 必須包含時間區間設定
$pdo = $db;

/* ini_set('display_errors', 1);
error_reporting(E_ALL); */
$pageTitleText = '隊伍分析 Team Analysis';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "team_analysis";

ob_start();
// ⭐ 分析時間區間（統一用 30 天，可之後擴充 14 / 30 切換）
$metaDays = 30;
?>

<style>
  /* ================================
   Team Analysis – UL.GG Style
================================ */

  /* 區塊容器 */
  .ta-block {
    background: #141414;
    /* 與 UL.GG 主體一致 */
    border-radius: 10px;
    padding: 18px 18px 20px;
    margin-bottom: 20px;
    border: 1px solid #1f1f1f;
    /* 非搶眼邊框 */
  }

  /* .ta-block+.ta-block {
    margin-top: 24px;
  } */

  /* 區塊標題 */
  .ta-title {
    font-weight: 700;
    /* font-size: 1.05rem; */
    margin-bottom: 14px;
    color: #d6dcff;
    /* UL.GG 藍紫系 */
    letter-spacing: 0.3px;
  }

  /* 次要說明文字 */
  .ta-muted {
    color: #8b8b8b;
    /* font-size: 0.85rem; */
  }

  /* 管理者專用區塊 */
  .ta-admin-only {
    background: #1a1414;
    border: 1px solid #3a1f1f;
    box-shadow: inset 0 0 0 1px rgba(255, 100, 100, .15);
  }

  /* 管理者區塊標題微調 */
  .ta-admin-only .ta-title {
    color: #ff9b9b;
  }


  .team-char-card {
    width: 30%;
  }

  .team-char-img {
    width: 100%;
    max-width: 110px;
    border-radius: 6px;
    border: 1px solid #333;
  }

  .team-char-name {
    margin-top: 6px;
    font-weight: 600;
    /* font-size: 0.95rem; */
  }

  .team-char-meta {
    /* font-size: 0.85rem; */
    color: #aaa;
  }

  .team-summary {
    padding-left: 10px;
  }

  .team-summary-title {
    font-weight: 700;
    /* font-size: 1.1rem; */
    margin-bottom: 8px;
  }

  /* .team-summary-cost {
    font-size: 1rem;
  } */

  .team-char-row {

    display: flex;
    flex-direction: row;
  }

  .team-char-portrait {
    width: 32%;
  }

  .team-char-img-box {
    /* width: 56%; */
    height: 220px;
    /* ⭐ 統一高度 */
    /* background: #111; */
    border-radius: 6px;
    border: 1px solid #333;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .team-char-img-portrait {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    /* ⭐ 關鍵 */
  }

  .ta-metric-row {
    margin-top: 10px;

    display: flex;
    flex-direction: row;
    margin: auto;
  }

  .ta-metric-card {
    background: #121212;
    border: 1px solid #333;
    border-radius: 8px;
    padding: 14px 10px;
    text-align: center;
    margin-bottom: 12px;
  }

  .ta-metric-label {
    /* font-size: 0.85rem; */
    color: #aaa;
    margin-bottom: 6px;
  }

  .ta-metric-value {
    /* font-size: 1.6rem; */
    font-weight: 700;
    color: #fff;
    line-height: 1.2;
  }

  .ta-metric-sub {
    /* font-size: 0.75rem; */
    color: #777;
    margin-top: 4px;
  }

  /* 勝敗顏色 */
  .ta-metric-value .win {
    color: #2ecc71;
  }

  .ta-metric-value .lose {
    color: #e74c3c;
  }

  /* 勝率卡片語意色 */
  .ta-metric-winrate {
    border-color: #2ecc71;
    background: rgba(46, 204, 113, 0.08);
  }



  .fight-card {
    align-items: flex-start;
    display: flex;
    align-items: center;
    justify-content: space-between;

    background: linear-gradient(180deg, #1c1f26, #121418);
    border: 1px solid #2a2d36;
    border-radius: 12px;

    padding: 14px 16px;
    margin-bottom: 14px;
    transition: box-shadow .2s ease, transform .15s ease;
  }

  .fight-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, .35);
  }

  .fight-tie {
    box-shadow: inset 0 0 0 1px rgba(180, 180, 180, .4);
  }

  .fight-tie .fight-result,
  .fight-tie .fight-player span {
    color: #aaa;
  }


  /* 勝 / 負 */
  .fight-win {
    box-shadow: inset 0 0 0 1px rgba(46, 204, 113, .6);
  }

  .fight-lose {
    box-shadow: inset 0 0 0 1px rgba(231, 76, 60, .6);
  }

  .fight-team {
    display: flex;
    flex-direction: row;
    gap: 6px;
  }

  .fight-team-left {
    width: 35%;
    min-width: 0;
    /* ⭐ 關鍵：允許 flex item 收縮 */
    overflow: hidden;
  }

  .fight-team-right {
    width: 30%;
    /* opacity: .6; */
    min-width: 0;
    /* ⭐ 關鍵：允許 flex item 收縮 */
    overflow: hidden;
  }

  .fight-team-cards {
    display: flex;
    gap: 6px;
  }

  .fight-char-ico {
    width: 48px;
    height: auto;
    border-radius: 4px;
    border: 1px solid #444;
  }

  .fight-char-placeholder {
    width: 48px;
    height: 64px;
    background: #222;
    border-radius: 4px;
    border: 1px dashed #444;
  }

  .fight-player {
    font-weight: 600;
    /* font-size: .9rem; */
  }

  .fight-bp {
    /* font-size: .85rem; */
    color: #9fb3ff;
  }

  /* 中央 */
  .fight-center {
    text-align: center;
    width: 20%;
  }

  .fight-result {
    /* font-size: 1.4rem; */
    font-weight: 800;
  }

  .fight-win .fight-result {
    color: #2ecc71;
  }

  .fight-lose .fight-result {
    color: #e74c3c;
  }

  .fight-meta {
    /* font-size: .8rem; */
    color: #aaa;
    margin-top: 6px;
    opacity: .85;
  }

  @media (max-width: 991px) {

    .team-char-img-box {
      height: 180px;
    }

    /* .team-char-name {
      font-size: 0.9rem;
    }

    .ta-metric-value {
      font-size: 1.4rem;
    } */

    .fight-char-ico {
      width: 42px;
    }

    .fight-team-left,
    .fight-team-right {
      width: 40%;
    }

    .fight-center {
      width: 20%;
    }
  }

  @media (max-width: 767px) {

    .team-char-row {
      display: flex;
      justify-content: center;
      gap: 0;
    }

    .team-char-portrait {
      width: 34%;
      margin-left: -6%;
    }

    .team-char-portrait:first-child {
      margin-left: 0;
    }

    .team-char-img-box {
      height: 170px;
    }

    .team-char-name,
    .team-char-meta {
      text-align: center;
      line-height: 1.2;
    }
  }


  @media (max-width: 767px) {

    .ta-metric-row {
      display: flex;
      flex-wrap: nowrap;
      /* gap: 6px; */
    }

    .ta-metric-row .ta-metric-card {
      padding: 10px 6px;
    }

    .ta-metric-value {
      line-height: 1.1;
    }

    .ta-metric-row>div {
      width: 50%;
    }

    .ta-metric-card {
      min-height: 92px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    /* 勝率與穩定度：固定 2x2 */
    .metric-2x2 {
      flex-wrap: wrap;
    }

    .metric-2x2>div {
      width: 50%;
    }
  }

  @media (max-width: 767px) {

    .fight-card {
      flex-direction: column;
      gap: 10px;
      padding: 12px;
    }

    .fight-team-left,
    .fight-team-right,
    .fight-center {
      width: 100%;
      text-align: center;
    }

    .fight-team-cards {
      justify-content: center;
    }

    /* .fight-team-right {
      opacity: .85;
    } */

    .fight-char-ico {
      width: 40px;
    }

    /* .fight-bp {
      font-size: .8rem;
    } */

    .fight-meta {
      /* font-size: .75rem; */
      line-height: 1.4;
    }
  }

  @media (max-width: 360px) {

    .fight-char-ico {
      width: 36px;
    }

    /* .ta-title {
      font-size: 1rem;
    }

    .ta-metric-value {
      font-size: 1.3rem;
    } */
  }

  /* =========================
   Card Flip (Front / Back)
========================= */

  .flip-wrap {
    perspective: 1000px;
  }

  .flip-card {
    position: relative;
    width: 100%;
    height: 100%;
    transform-style: preserve-3d;
    transition: transform .6s ease;
  }

  .flip-wrap:hover .flip-card {
    transform: rotateY(180deg);
  }

  /* 手機用 click */
  .flip-wrap.is-flipped .flip-card {
    transform: rotateY(180deg);
  }

  .flip-face {
    position: absolute;
    inset: 0;
    backface-visibility: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .flip-face.back {
    transform: rotateY(180deg);
  }

  .ta-event-card {
    width: 100px;
    padding: 10px 4px;
    background: #121212;
    border: 1px solid #2a2d36;
    border-radius: 10px;
  }

  .ta-event-card img.event-ico {
    width: 56px;
    height: auto;
    margin-bottom: 6px;
  }

  .ta-event-name {
    /* font-size: .85rem; */
    font-weight: 600;
  }

  .ta-event-meta {
    /* font-size: .75rem; */
    color: #aaa;
    margin-top: 2px;
  }

  .ta-event-score {
    /* font-size: .75rem; */
    color: #2ecc71;
    margin-top: 2px;
  }

  .ta-event-cards {
    display: flex;
    flex-wrap: wrap;
  }

  /* 對手區塊左右切 */
  .fight-opponent {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
  }

  /* 左：玩家資訊 */
  .fight-opponent-info {
    text-align: left;
    min-width: 0;
  }

  /* 右：牌組 */
  .fight-opponent-cards {
    display: flex;
    gap: 6px;
    justify-content: flex-end;
  }

  /* 手機版微調 */
  @media (max-width: 767px) {

    .fight-opponent {
      flex-direction: row;
      /* ❗不變直欄 */
    }

    .fight-opponent-info {
      text-align: left;
    }

    .fight-opponent-cards {
      justify-content: flex-end;
    }
  }

  .fight-player-info {
    display: flex;
    flex-direction: column;
    justify-content: center;
    /* ⭐ 垂直置中關鍵 */
    min-height: 48px;
    /* 對齊角色卡高度（可微調） */
  }

  .fight-card-counter {
    cursor: pointer;
  }

  .fight-card-counter:hover {
    box-shadow:
      0 0 0 1px rgba(255, 107, 107, .6),
      0 6px 18px rgba(0, 0, 0, .45);
  }

  .fight-card-counter:hover::after {
    content: "🔍 查看此克制隊伍分析";
    position: absolute;
    right: 12px;
    bottom: 10px;
    font-size: 12px;
    color: #ff6b6b;
    opacity: .9;
  }

  /* =========================
   Base Stat Compare
========================= */

  /* 基本比較卡 */
  .ta-metric-card.compare {
    position: relative;
    transition: all .18s ease;
  }

  /* 數值較高的一側 */
  .ta-metric-card.compare.is-better {
    background: rgba(255, 255, 255, 0.06);
    border-color: rgba(255, 255, 255, 0.35);
    /* transform: scale(1.04); */
  }

  /* 數值較低的一側 */
  .ta-metric-card.compare.is-worse {
    opacity: .75;
  }

  /* 中性 */
  .ta-metric-card.compare.is-equal {
    opacity: .9;
  }

  /* 小標提示 */
  .ta-metric-card.compare::after {
    position: absolute;
    top: 6px;
    right: 8px;
    font-size: 11px;
    color: #bbb;
    opacity: .85;
  }

  .ta-metric-card.compare.is-better::after {
    content: "↑";
    top: -10px;
    right: 4px;
  }

  .ta-metric-card.compare.is-worse::after {
    content: "↓";
    top: -10px;
    right: 4px;
  }

  .ta-metric-card.compare.is-equal::after {
    content: "=";
    top: -10px;
    right: 4px;
  }

  .fight-team-link:hover .fight-char-ico {
    transform: scale(1.05);
    box-shadow: 0 0 8px rgba(120, 170, 255, .6);
  }

  .chart-wrap {
    height: 260px;
    /* 你要的實際高度 */
    position: relative;
  }

  /* =========================
   BP Rank Compact List
========================= */

  .bp-rank-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .bp-rank-row {
    display: grid;
    grid-template-columns: 1.4fr 0.6fr 1fr;
    align-items: center;
    padding: 8px 10px;
    border-radius: 8px;
    background: #121418;
    border: 1px solid #1f2430;
    font-size: 14px;
  }

  .bp-rank-row:hover {
    background: #161a22;
  }

  .bp-rank-player {
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .bp-rank-no {
    margin-right: 6px;
    opacity: .9;
  }

  .bp-rank-gain {
    text-align: center;
    font-weight: 800;
    color: #4dabf7;
  }

  .bp-rank-meta {
    text-align: right;
    color: #9aa4b2;
    font-size: 12px;
  }

  /* Mobile */
  @media (max-width: 767px) {
    .bp-rank-row {
      grid-template-columns: 1fr auto;
      grid-template-areas:
        "player gain"
        "meta meta";
      row-gap: 4px;
    }

    .bp-rank-player {
      grid-area: player;
    }

    .bp-rank-gain {
      grid-area: gain;
    }

    .bp-rank-meta {
      grid-area: meta;
      text-align: left;
    }
  }
</style>
<?php
function renderPlayerName(string $name, array $blacklistedUsers): string
{
  $safeName = htmlspecialchars($name);

  if (isset($blacklistedUsers[$name])) {
    return '<span class="blacklist-name">' . $safeName . '</span>';
  }

  return $safeName;
}

//參數設定
// === ① GET（明確指定隊伍，最高優先）===
if (
  isset($_GET['id1'], $_GET['id2'], $_GET['id3']) &&
  $_GET['id1'] !== '' &&
  $_GET['id2'] !== '' &&
  $_GET['id3'] !== ''
) {
  $id1 = (int)$_GET['id1'];
  $id2 = (int)$_GET['id2'];
  $id3 = (int)$_GET['id3'];

  // ⭐ 同步覆蓋 SESSION（避免下頁又錯）
  $_SESSION['id1'] = $id1;
  $_SESSION['id2'] = $id2;
  $_SESSION['id3'] = $id3;
} else {

  // === ② POST（calculator）===
  $id1 = (int)($_POST['id1'] ?? 0);
  $id2 = (int)($_POST['id2'] ?? 0);
  $id3 = (int)($_POST['id3'] ?? 0);

  // === ③ SESSION（fallback）===
  if (!$id1 || !$id2 || !$id3) {
    $id1 = (int)($_SESSION['id1'] ?? 0);
    $id2 = (int)($_SESSION['id2'] ?? 0);
    $id3 = (int)($_SESSION['id3'] ?? 0);
  }
}


$charIds = array_filter([$id1, $id2, $id3]);

if (count($charIds) !== 3) {
  die('隊伍角色資料不完整');
}
//A區
$inSql = implode(',', array_fill(0, count($charIds), '?'));

$sql = "SELECT
  id,
  name,
  level,
  cost,
  ico,
  ico_back,
  rarity,
  skill1_code,
  skill2_code,
  skill3_code,
  skill4_code
FROM unlight
WHERE id IN ($inSql)

";

$stmt = $pdo->prepare($sql);
$stmt->execute($charIds);

$chars = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $chars[$row['id']] = $row;
}

/* 照原順序排列（重要） */
$missingCharIds = [];

foreach ([$id1, $id2, $id3] as $cid) {
  if (!isset($chars[$cid])) {
    $missingCharIds[] = $cid;
  }
}

if (!empty($missingCharIds)) {

  if (isset($_GET['id1'], $_GET['id2'], $_GET['id3'])) {
    $source = 'GET';
  } elseif (
    isset($_POST['id1']) ||
    isset($_POST['id2']) ||
    isset($_POST['id3'])
  ) {
    $source = 'POST';
  } else {
    $source = 'SESSION';
  }

  error_log(
    '[team_analysis] invalid character ids'
    . '; source=' . $source
    . '; requested=' . implode(',', [$id1, $id2, $id3])
    . '; missing=' . implode(',', $missingCharIds)
  );

  unset($_SESSION['id1'], $_SESSION['id2'], $_SESSION['id3']);

  http_response_code(404);
  die('隊伍角色資料不存在');
}

$teamChars = [
  $chars[$id1],
  $chars[$id2],
  $chars[$id3],
];

$totalCost = array_sum(array_column($teamChars, 'cost'));
$costs = array_column($teamChars, 'cost');

$punishResult = calcTeamCostPunish($costs);

$costPunish     = $punishResult['punish'];
$totalCostFinal = $punishResult['total_cost'];
$punishDetail   = $punishResult['detail'];

// === B 區：勝率與穩定度資料 ===

// leader 視為 id1（與 calculator / team_analysis 一致）
$leaderId = $id1;

// 後排排序（一定要）
$backs = [$id2, $id3];
sort($backs, SORT_NUMERIC);

$teamKey = $leaderId . '-' . $backs[0] . '-' . $backs[1];
$sqlB = "
  SELECT
    COUNT(*)                          AS matches,
    SUM(is_win = 1) AS wins,
SUM(is_win = 0) AS losses,
SUM(is_win IS NULL OR is_win = -1) AS ties,
    ROUND(AVG(is_win) * 100, 1)       AS win_rate
  FROM arena_player_match_result
  WHERE team_key = :team_key
";

$stmtB = $pdo->prepare($sqlB);
$stmtB->execute([
  ':team_key' => $teamKey,
]);

$stat = $stmtB->fetch(PDO::FETCH_ASSOC);

// 防呆（避免 NULL）
$matches = (int)($stat['matches'] ?? 0);
$wins    = (int)($stat['wins'] ?? 0);
$losses = (int)($stat['losses'] ?? 0);
$winRate = $stat['win_rate'] !== null
  ? (float)$stat['win_rate']
  : 0.0;
function getStabilityStars(int $matches): array
{
  if ($matches >= 300) return ['★★★★★', '非常穩定'];
  if ($matches >= 150) return ['★★★★☆', '穩定'];
  if ($matches >= 50)  return ['★★★☆☆', '參考'];
  if ($matches >= 20)  return ['★★☆☆☆', '偏少'];
  return ['★☆☆☆☆', '樣本不足'];
}

[$stabilityStars, $stabilityLabel] = getStabilityStars($matches);

// === 隊伍最佳事件卡 ===

// ① 該隊伍 base win rate
$teamBaseWinRate = $winRate > 0 ? $winRate : 40.0;

// ② 構築排行用 cost 條件
$teamCostSql = '1=1';

// ③ 取得隊伍構築推薦事件卡
$teamBestEvents = getTeamBuildEventRanking(
  $pdo,
  $teamKey,
  $metaDays,
  $teamCostSql,
  $teamBaseWinRate,
  6,
  3
);



// === B-1. BP × 勝率分佈資料（關鍵缺失） ===
$sqlBpWin = "
SELECT
  FLOOR(
    CASE
      WHEN side = 'P1' THEN a.bp_p1
      ELSE a.bp_p2
    END / 50
  ) * 50 AS bp_bucket,

  COUNT(*) AS matches,
  ROUND(AVG(is_win) * 100, 1) AS win_rate

FROM arena_player_match_result m
JOIN arena_unlight a
  ON a.id = m.match_id

WHERE
  m.team_key = :team_key
  AND m.is_win IS NOT NULL
GROUP BY bp_bucket
HAVING matches >= 1
ORDER BY bp_bucket
";

$stmtBpWin = $pdo->prepare($sqlBpWin);
$stmtBpWin->execute([
  ':team_key' => $teamKey,
]);

$bpWinStats = $stmtBpWin->fetchAll(PDO::FETCH_ASSOC);

// === B-2. 使用此牌組爬到最高 BP 的玩家 ===
$sqlRank = "
  SELECT
  m.player_name,
  COUNT(*) AS matches,
  MIN(
    CASE
      WHEN m.side = 'P1' THEN a.bp_p1
      ELSE a.bp_p2
    END
  ) AS bp_min,
  MAX(
    CASE
      WHEN m.side = 'P1' THEN a.bp_p1
      ELSE a.bp_p2
    END
  ) AS bp_max,
  (MAX(
    CASE
      WHEN m.side = 'P1' THEN a.bp_p1
      ELSE a.bp_p2
    END
  ) - MIN(
    CASE
      WHEN m.side = 'P1' THEN a.bp_p1
      ELSE a.bp_p2
    END
  )) AS bp_gain,
  ROUND(AVG(m.is_win) * 100, 1) AS win_rate
FROM arena_player_match_result m
JOIN arena_unlight a
  ON a.id = m.match_id
WHERE
  m.team_key = :team_key
  AND m.is_win IS NOT NULL
  AND a.update_time >= NOW() - INTERVAL 30 DAY
GROUP BY m.player_name
HAVING
matches>2 AND
  bp_gain > 40
ORDER BY
  bp_gain DESC,
  matches DESC
LIMIT 10
";

$stmtRank = $pdo->prepare($sqlRank);
$stmtRank->execute([
  ':team_key' => $teamKey,
]);

$bpRankings = $stmtRank->fetchAll(PDO::FETCH_ASSOC);


// === C 區：克制隊伍（Hard Counters） ===
// 改用 AJAX 載入，避免頁面開啟時直接重查 SQL
$counterTeams = [];

// === D 區：蒐集隊伍技能 ===
$skillCodes = [];

foreach ($teamChars as $char) {
  foreach (['skill1_code', 'skill2_code', 'skill3_code', 'skill4_code'] as $k) {
    if (!empty($char[$k])) {
      $skillCodes[] = $char[$k];
    }
  }
}

$skillCodes = array_values(array_unique($skillCodes));
$archetypeRule = [

  '爆發型' => [
    'tags' => ['直傷', '傷害+', '傷害×', 'ATK+', 'ATK×', '殺戮', '致死', '臨界'],
    'groups' => ['damage', 'atk', 'burst'],
  ],

  '控制型' => [
    'tags' => ['暈眩', '麻痺', '封印', '咒縛', '行動⊘', '行動Ø', '移動Ø', '距離⇄', '角色⇄', '陷阱'],
    'groups' => ['control', 'move', 'trap'],
  ],

  '耐久型' => [
    'tags' => ['HP+', '再生', '不死', '不死Ⅱ', '傷害-', '傷害Ø', 'DEF+', 'DEF×', 'DEF↑', '隊伍'],
    'groups' => ['hp', 'def', 'sustain', 'team'],
  ],

  '資源型' => [
    'tags' => ['手牌+', '手牌←', '手牌-', '手牌上限+', '手牌上限-', '墓地', '牌堆', '事件卡'],
    'groups' => ['resource'],
  ],

  '成長型' => [
    'tags' => ['成長', '聖痕', '臨界', '能力低下', '混沌', '棍術', '狂戰士'],
    'groups' => ['growth'],
  ],

  '風險型' => [
    'tags' => ['HP-', '自壞', '操想', '效果?', '隨機'],
    'groups' => ['risk'],
  ],
];

$teamTags = [];
$teamTagTypes = [];

if (!empty($skillCodes)) {

  $inSkillSql = implode(',', array_fill(0, count($skillCodes), '?'));

  $sqlD = "
    SELECT
      d.tag,
      d.tag_group,
      COUNT(*) AS cnt
    FROM unlight_skill_tag t
    JOIN unlight_skill_tag_dictionary d
      ON d.tag = t.tag
    WHERE t.skill_code IN ($inSkillSql)
    GROUP BY d.tag, d.tag_group
  ";

  $stmtD = $pdo->prepare($sqlD);
  $stmtD->execute($skillCodes);

  while ($row = $stmtD->fetch(PDO::FETCH_ASSOC)) {
    $teamTags[$row['tag']] = (int)$row['cnt'];
    $teamTagTypes[$row['tag']] = $row['tag_group'];
  }

  // 依數量由大到小排序
  arsort($teamTags);

  // ⭐ 只顯示出現次數 > 1 的技能標籤
  $cnt_num = 1;
  $teamTags = array_filter($teamTags, fn($cnt) => $cnt > $cnt_num);
}
$archetypeScore = [];

foreach ($archetypeRule as $name => $rule) {
  $score = 0;

  foreach ($teamTags as $tag => $cnt) {
    $group = $teamTagTypes[$tag] ?? null;

    if (
      in_array($tag, $rule['tags'], true)
      && in_array($group, $rule['groups'], true)
    ) {
      $score += $cnt;
    }
  }

  if ($score > 0) {
    $archetypeScore[$name] = $score;
  }
}

arsort($archetypeScore);

$mainArchetype = array_key_first($archetypeScore);
$subArchetypes = array_slice(array_keys($archetypeScore), 1, 2);

//E&F 白質比較
$sqlE = "
  SELECT
    ROUND(AVG(HP), 1)  AS avg_hp,
    ROUND(AVG(ATK), 1) AS avg_atk,
    ROUND(AVG(DEF), 1) AS avg_def
  FROM unlight
  WHERE id IN ($inSql)
";

$stmtE = $pdo->prepare($sqlE);
$stmtE->execute($charIds);
$baseStats = $stmtE->fetch(PDO::FETCH_ASSOC);

$avgHP  = (float)($baseStats['avg_hp']  ?? 0);
$avgATK = (float)($baseStats['avg_atk'] ?? 0);
$avgDEF = (float)($baseStats['avg_def'] ?? 0);

$sqlF = "
  SELECT
    ROUND(AVG(u.HP), 1)  AS avg_hp,
    ROUND(AVG(u.ATK), 1) AS avg_atk,
    ROUND(AVG(u.DEF), 1) AS avg_def
  FROM arena_player_match_result self
  JOIN arena_player_match_result opp
    ON opp.match_id = self.match_id
   AND opp.side <> self.side
  JOIN unlight u
    ON u.id IN (opp.leader_id, opp.back1_id, opp.back2_id)
  WHERE self.team_key = :team_key
";

$stmtF = $pdo->prepare($sqlF);
$stmtF->execute([
  ':team_key' => $teamKey,
]);

$oppStats = $stmtF->fetch(PDO::FETCH_ASSOC);

$oppHP  = (float)($oppStats['avg_hp']  ?? 0);
$oppATK = (float)($oppStats['avg_atk'] ?? 0);
$oppDEF = (float)($oppStats['avg_def'] ?? 0);

$compare = [
  'hp'  => $avgHP <=> $oppHP,
  'atk' => $avgATK <=> $oppATK,
  'def' => $avgDEF <=> $oppDEF,
];


//I區
// === I 區：對戰歷史（Admin Only） ===
$sqlI = "SELECT
  self.match_id,
  self.update_time,
  self.player_name        AS self_player,
  opp.player_name         AS opp_player,
  self.side,
  self.cost,
  self.is_win,
  a.stage,
  a.bp_p1,
  a.bp_p2
FROM arena_player_match_result self
JOIN arena_player_match_result opp
  ON opp.match_id = self.match_id
 AND opp.side <> self.side
JOIN arena_unlight a
  ON a.id = self.match_id
WHERE self.team_key = :team_key
ORDER BY self.update_time DESC
LIMIT 50
";

$stmtI = $pdo->prepare($sqlI);
$stmtI->execute([
  ':team_key' => $teamKey,
]);

$matchHistory = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 權限判斷
$isAdmin = ($_SESSION['permission'] ?? 0) >= 3;

//I區對手
$matchIds = array_column($matchHistory, 'match_id');

$opponentTeams = [];

if (!empty($matchIds)) {

  $inMatchSql = implode(',', array_fill(0, count($matchIds), '?'));

  $sqlOpp = "
    SELECT
      m.match_id,
      m.side,
      m.leader_id,
      m.back1_id,
      m.back2_id,
      u.id,
      u.ico
    FROM arena_player_match_result m
    JOIN unlight u
      ON u.id IN (m.leader_id, m.back1_id, m.back2_id)
    WHERE m.match_id IN ($inMatchSql)
  ";

  $stmtOpp = $pdo->prepare($sqlOpp);
  $stmtOpp->execute($matchIds);

  while ($row = $stmtOpp->fetch(PDO::FETCH_ASSOC)) {
    $opponentTeams[$row['match_id']][$row['side']][] = $row;
  }
}

// === I-1. 收集對戰中出現的所有玩家名稱 ===
$playerNames = [];

foreach ($matchHistory as $m) {
  if (!empty($m['self_player'])) {
    $playerNames[] = $m['self_player'];
  }
  if (!empty($m['opp_player'])) {
    $playerNames[] = $m['opp_player'];
  }
}

$playerNames = array_values(array_unique($playerNames));

// === I-2. 查詢黑名單玩家 ===
$blacklistedUsers = [];

if (!empty($playerNames)) {

  $inPlayerSql = implode(',', array_fill(0, count($playerNames), '?'));

  $sqlBL = "
    SELECT username
    FROM game_user
    WHERE username IN ($inPlayerSql)
      AND black_list = 2
  ";

  $stmtBL = $pdo->prepare($sqlBL);
  $stmtBL->execute($playerNames);

  while ($row = $stmtBL->fetch(PDO::FETCH_ASSOC)) {
    $blacklistedUsers[$row['username']] = true;
  }
}
$bpLabels = [];
$bpWinRates = [];
$bpMatches = [];

foreach ($bpWinStats as $r) {
  $label = $r['bp_bucket'] . '–' . ($r['bp_bucket'] + 49);
  $bpLabels[]   = $label;
  $bpWinRates[] = (float)$r['win_rate'];
  $bpMatches[]  = (int)$r['matches'];
}

?>

<!-- Content Wrapper -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">

      <!-- A. 隊伍概覽 -->
      <div class="row">
        <div class="col-md-12 ta-block">

          <div class="ta-title">隊伍概覽（Team Overview）</div>

          <div class="row align-items-center">

            <!-- 左：角色立繪 -->
            <div class="col-md-9">
              <div class="d-flex justify-content-between team-char-row">

                <?php foreach ($teamChars as $char): ?>
                  <div class="team-char-portrait text-center">
                    <div class="team-char-img-box flip-wrap"
                      onclick="this.classList.toggle('is-flipped')">

                      <div class="flip-card">

                        <!-- 正面 -->
                        <div class="flip-face front">
                          <img
                            src="<?= IMG_BASE . $char['ico'] ?>"
                            alt="<?= htmlspecialchars($char['name']) ?>"
                            class="team-char-img-portrait">
                        </div>

                        <!-- 背面 -->
                        <div class="flip-face back">
                          <img
                            src="<?= IMG_BASE . ($char['ico_back'] ?? $char['ico']) ?>"
                            alt="<?= htmlspecialchars($char['name']) ?> back"
                            class="team-char-img-portrait">
                        </div>

                      </div>
                    </div>


                    <div class="team-char-name">
                      <a
                        href="https://ulgg.online/pages/analysis_character_card.php?char_id=<?= (int)$char['id'] ?>&char=<?= urlencode($char['level'] . $char['name']) ?>&tab=skill"
                        class="char-link"
                        title="查看角色分析">
                        <?= htmlspecialchars($char['level']) ?>
                        <?= htmlspecialchars($char['name']) ?>
                      </a>
                    </div>


                    <div class="team-char-meta">
                      Cost <?= (int)$char['cost'] ?>
                    </div>
                  </div>
                <?php endforeach; ?>

              </div>
            </div>

            <!-- 右：隊伍摘要 -->
            <div class="col-md-3">
              <div class="team-summary">
                <div class="team-summary-title">隊伍摘要</div>

                <div class="team-summary-cost">
                  總 Cost：
                  <strong>
                    <?= $totalCost ?>
                    <?php if ($costPunish > 0): ?>
                      + <span style="color:#ff6b6b;"><?= $costPunish ?></span>
                      = <?= $totalCostFinal ?>
                    <?php endif; ?>
                  </strong>
                </div>
                <div class="mt-2">
                  <a
                    href="/pages/fight.php?e1=<?= (int)$id1 ?>&amp;e2=<?= (int)$id2 ?>&amp;e3=<?= (int)$id3 ?>"
                    class="btn btn-sm btn-outline-primary">
                    ⚔️ 對戰紀錄
                  </a>
                </div>
                <?php if ($isAdmin && !empty($punishDetail)): ?>
                  <div class="ta-muted mt-2">
                    COST 懲罰來源：
                    <?php foreach ($punishDetail as $d): ?>
                      <div>
                        <?= $d['a'] ?> vs <?= $d['b'] ?>
                        （差 <?= $d['diff'] ?>）→ +<?= $d['punish'] ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

          </div>

        </div>
      </div>



      <!-- B. 勝率與穩定度 -->
      <div class="row">
        <div class="col-md-12 ta-block">

          <div class="ta-title">勝率與穩定度（Performance Summary）</div>

          <div class="row ta-metric-row metric-2x2">

            <!-- 對戰場次 -->
            <div class="col-md-3 col-6">
              <div class="ta-metric-card">
                <div class="ta-metric-label">對戰場次</div>
                <div class="ta-metric-value"><?= number_format($matches) ?></div>
                <div class="ta-metric-sub">有效樣本數</div>
              </div>
            </div>

            <!-- 勝 / 敗 -->
            <div class="col-md-3 col-6">
              <div class="ta-metric-card">
                <div class="ta-metric-label">勝 / 敗</div>
                <div class="ta-metric-value">
                  <span class="win"><?= $wins ?></span>
                  /
                  <span class="lose"><?= $losses ?></span>
                </div>
                <div class="ta-metric-sub">不含平手</div>
              </div>
            </div>

            <!-- 勝率 -->
            <div class="col-md-3 col-6">
              <div class="ta-metric-card ta-metric-winrate">
                <div class="ta-metric-label">歷史勝率</div>
                <div class="ta-metric-value"><?= $winRate ?>%</div>
                <div class="ta-metric-sub">勝率表現</div>
              </div>
            </div>

            <!-- 穩定度 -->
            <div class="col-md-3 col-6">
              <div class="ta-metric-card">
                <div class="ta-metric-label">穩定度</div>
                <div class="ta-metric-value"><?= $stabilityStars ?></div>
                <div class="ta-metric-sub"><?= htmlspecialchars($stabilityLabel) ?></div>
              </div>
            </div>

          </div>

        </div>
      </div>

      <?php if (!empty($bpLabels)): ?>
        <div class="row">

          <!-- 左：BP × 勝率 -->
          <div class="col-md-6 ta-block">

            <div class="ta-title">BP 與勝率分佈（BP vs Win Rate）</div>
            <div class="ta-muted mb-2">
              顯示此隊伍在不同 BP 區間的平均勝率（已過濾樣本不足）
            </div>

            <div class="chart-wrap">
              <canvas id="bpWinChart"></canvas>
            </div>

          </div>

          <!-- 右：隊伍流派分析 -->
          <div class="col-md-6 ta-block">

            <div class="ta-title">隊伍流派分析（Archetype & Tags）</div>

            <!-- D-0. 技能 Tag 統計 -->
            <div class="ta-block mb-3">

              <div class="ta-title">主要技能標籤統計（Tag Overview）</div>

              <?php if (empty($teamTags)): ?>
                <div class="ta-muted">此隊伍尚無技能標籤資料</div>
              <?php else: ?>

                <div class="ul-skill-tags">
                  <?php foreach ($teamTags as $tag => $cnt): ?>
                    <a
                      class="skill-tag"
                      href="/pages/skill_by_tag.php?tag=<?= urlencode($tag) ?>"
                      title="查看使用技能標籤：<?= htmlspecialchars($tag) ?>">
                      <?= htmlspecialchars($tag) ?>
                      <strong style="margin-left:4px;">×<?= $cnt ?></strong>
                    </a>
                  <?php endforeach; ?>
                </div>

              <?php endif; ?>

            </div>

            <?php if (empty($archetypeScore)): ?>
              <div class="ta-muted">此隊伍尚無足夠技能標籤可判定流派</div>
            <?php endif; ?>

          </div>

        </div>
      <?php endif; ?>




      <!-- E + F 並排 -->
      <div class="row">
        <!-- 我方 -->
        <div class="col-md-6 ta-block">
          <div class="ta-title">隊伍白質輪廓（Base Stats）</div>

          <div class="row ta-metric-row">
            <div class="col-4">
              <div class="ta-metric-card compare
          <?= $compare['hp'] > 0 ? 'is-better' : ($compare['hp'] < 0 ? 'is-worse' : 'is-equal') ?>">
                <div class="ta-metric-label">平均 HP</div>
                <div class="ta-metric-value"><?= $avgHP ?></div>
              </div>
            </div>

            <div class="col-4">
              <div class="ta-metric-card compare
          <?= $compare['atk'] > 0 ? 'is-better' : ($compare['atk'] < 0 ? 'is-worse' : 'is-equal') ?>">
                <div class="ta-metric-label">平均 ATK</div>
                <div class="ta-metric-value"><?= $avgATK ?></div>
              </div>
            </div>

            <div class="col-4">
              <div class="ta-metric-card compare
          <?= $compare['def'] > 0 ? 'is-better' : ($compare['def'] < 0 ? 'is-worse' : 'is-equal') ?>">
                <div class="ta-metric-label">平均 DEF</div>
                <div class="ta-metric-value"><?= $avgDEF ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- 對手 -->
        <div class="col-md-6 ta-block">
          <div class="ta-title">對戰對伍平均白質（Opponent Profile）</div>

          <?php if ($matches === 0): ?>
            <div class="ta-muted">此隊伍尚無對戰紀錄</div>
          <?php else: ?>

            <div class="row ta-metric-row">
              <div class="col-4">
                <div class="ta-metric-card compare
            <?= $compare['hp'] < 0 ? 'is-better' : ($compare['hp'] > 0 ? 'is-worse' : 'is-equal') ?>">
                  <div class="ta-metric-label">對手平均 HP</div>
                  <div class="ta-metric-value"><?= $oppHP ?></div>
                </div>
              </div>

              <div class="col-4">
                <div class="ta-metric-card compare
            <?= $compare['atk'] < 0 ? 'is-better' : ($compare['atk'] > 0 ? 'is-worse' : 'is-equal') ?>">
                  <div class="ta-metric-label">對手平均 ATK</div>
                  <div class="ta-metric-value"><?= $oppATK ?></div>
                </div>
              </div>

              <div class="col-4">
                <div class="ta-metric-card compare
            <?= $compare['def'] < 0 ? 'is-better' : ($compare['def'] > 0 ? 'is-worse' : 'is-equal') ?>">
                  <div class="ta-metric-label">對手平均 DEF</div>
                  <div class="ta-metric-value"><?= $oppDEF ?></div>
                </div>
              </div>
            </div>

          <?php endif; ?>
        </div>
      </div>


      <!-- 隊伍最佳事件卡 -->
      <div class="row">
        <div class="col-md-12 ta-block">

          <div class="ta-title">
            隊伍常用事件卡（Team Best Events）
          </div>

          <?php if (empty($teamBestEvents)): ?>
            <div class="ta-muted">
              尚未觀測到明顯提升此隊伍勝率的事件卡
            </div>
          <?php else: ?>

            <div class="ta-event-cards d-flex flex-wrap gap-3">

              <?php foreach ($teamBestEvents as $ev): ?>
                <div class="ta-event-card text-center">

                  <?php if (!empty($ev['ico'])): ?>
                    <img
                      src="<?= IMG_BASE . htmlspecialchars($ev['ico']) ?>"
                      alt="<?= htmlspecialchars($ev['name']) ?>"
                      class="event-ico"
                      loading="lazy">
                  <?php endif; ?>

                  <div class="ta-event-name">
                    <?= htmlspecialchars($ev['name']) ?>
                  </div>

                  <div class="ta-event-meta">
                    勝率 <?= $ev['win_rate'] ?>%
                    <br> <?= (int)$ev['matches'] ?> 場
                  </div>

                  <div class="ta-event-score">
                    +<?= $ev['score'] ?>
                  </div>

                </div>
              <?php endforeach; ?>

            </div>

          <?php endif; ?>

        </div>
      </div>




      <!-- C. 克制隊伍 -->
      <div class="row">
        <div class="col-md-12 ta-block">

          <div class="ta-title">克制隊伍（Hard Counters）</div>

          <div id="counterTeamsBox"
            data-team-key="<?= htmlspecialchars($teamKey) ?>"
            data-days="<?= (int)$metaDays ?>">
            <div class="ta-muted">克制隊伍分析載入中...</div>
          </div>

        </div>
      </div>

      <div class="row">
        <div class="col-md-12 ta-block">

          <div class="ta-title d-flex justify-content-between align-items-center">
            <span>BP 區間變化榜（ 只顯示 +40 ↑ ）</span>
            <span class="ta-muted">
              統計玩家「使用過此隊伍的期間」BP 最低 → 最高變化
            </span>
          </div>


          <div class="bp-rank-list">

            <?php foreach ($bpRankings as $i => $r): ?>
              <div class="bp-rank-row">

                <!-- 名次 + 玩家 -->
                <div class="bp-rank-player">
                  <span class="bp-rank-no">
                    <?= $i < 3 ? ['🥇', '🥈', '🥉'][$i] : '#' . ($i + 1) ?>
                  </span>
                  <a
                    href="/pages/fight.php?player_name=<?= urlencode($r['player_name']) ?>" class="bp-player-link">
                    <?= renderPlayerName($r['player_name'], $blacklistedUsers) ?>
                  </a>
                </div>

                <!-- BP 成長 -->
                <div class="bp-rank-gain">
                  +<?= (int)$r['bp_gain'] ?>
                </div>

                <!-- 補充資訊 -->
                <div class="bp-rank-meta">
                  <?= (int)$r['bp_min'] ?>→<?= (int)$r['bp_max'] ?>

                  · <?= $r['win_rate'] ?>%
                </div>

              </div>
            <?php endforeach; ?>

          </div>
        </div>
      </div>


      <!-- I. 對戰歷史（管理者） -->
      <div class="row">
        <div class="col-md-12 ta-block ta-admin-only">

          <div class="ta-title">對戰歷史紀錄</div>
          <div class="ta-muted mb-3">
            顯示最近對戰紀錄
          </div>

          <?php foreach ($matchHistory as $m): ?>

            <?php
            $isWin = is_null($m['is_win']) ? null : (int)$m['is_win'];

            if ($isWin === 1) {
              $selfResult = 'win';
            } elseif ($isWin === 0) {
              $selfResult = 'lose';
            } else {
              $selfResult = 'tie';
            }

            switch ($selfResult) {
              case 'win':
                $oppResult = 'lose';
                break;
              case 'lose':
                $oppResult = 'win';
                break;
              default:
                $oppResult = 'tie';
            }
            $resultTextMap = [
              'win'  => '勝',
              'lose' => '負',
              'tie'  => '平',
            ];

            $resultClassMap = [
              'win'  => 'fight-win',
              'lose' => 'fight-lose',
              'tie'  => 'fight-tie', // 可選
            ];

            $resultColorMap = [
              'win'  => '#2ecc71',
              'lose' => '#e74c3c',
              'tie'  => '#aaa',
            ];

            /* $resultText = $isWin ? '勝' : '負';
            $resultClass = $isWin ? 'fight-win' : 'fight-lose'; */

            // 玩家名稱
            $playerName = $isAdmin ? $m['self_player'] : '匿名玩家';

            ?>

            <div class="fight-card <?= $resultClassMap[$selfResult] ?>">

              <!-- 左：我方隊伍 -->
              <div class="fight-team fight-team-left <?= $resultClassMap[$selfResult] ?>">

                <div class="fight-team-cards">
                  <?php foreach ($teamChars as $char): ?>
                    <img src="<?= IMG_BASE . $char['ico'] ?>" class="fight-char-ico">
                  <?php endforeach; ?>
                </div>
                <div class="fight-player-info">
                  <div class="fight-player">
                    <?php if ($isAdmin): ?>
                      <a
                        href="/pages/fight.php?player_name=<?= urlencode($playerName) ?>"
                        class="fight-player-link"
                        title="查看 <?= htmlspecialchars($playerName) ?> 的對戰紀錄">
                        <?= renderPlayerName($playerName, $blacklistedUsers) ?>
                      </a>
                    <?php else: ?>
                      匿名玩家
                    <?php endif; ?>

                    <span style="color:<?= $resultColorMap[$selfResult] ?>">
                      <?= $resultTextMap[$selfResult] ?>
                    </span>
                  </div>


                  <?php
                  $selfBp = ($m['side'] === 'P1')
                    ? (int)$m['bp_p1']
                    : (int)$m['bp_p2'];
                  ?>

                  <div class="fight-bp">
                    <?= $isAdmin
                      ? 'BP ' . $selfBp
                      : 'BP ' . maskBp($selfBp)
                    ?>
                  </div>
                </div>

              </div>

              <!-- 中央資訊 -->
              <?php
              $stageId   = (int)$m['stage'];
              $stageName = $stages[$stageId] ?? ('未知地圖 #' . $stageId);
              ?>

              <div class="fight-center">
                <div class="fight-meta">
                  <?= htmlspecialchars($m['update_time']) ?><br>
                  <?= htmlspecialchars($stageName) ?><br>
                  Cost <?= (int)$m['cost'] ?>
                </div>
              </div>


              <!-- 右：對手隊伍（暫時用灰卡，可之後補） -->
              <?php
              // 對手 side（跟自己相反）
              $oppSide = ($m['side'] === 'P1') ? 'P2' : 'P1';
              $oppChars = $opponentTeams[$m['match_id']][$oppSide] ?? [];

              $oppBp = ($oppSide === 'P1') ? $m['bp_p1'] : $m['bp_p2'];

              /* $oppWinText  = $oppWin ? '勝' : '負';
              $oppWinColor = $oppWin ? '#2ecc71' : '#e74c3c'; */

              $oppPlayerName = $isAdmin ? $m['opp_player'] : '匿名玩家';


              ?>
              <?php
              // ① 先把角色資料用 id 當 key
              $oppMap = [];
              foreach ($oppChars as $oc) {
                $oppMap[(int)$oc['id']] = $oc;
              }

              // ② 用 DB 明確欄位決定順序（關鍵）
              $oppLeader = (int)$oppChars[0]['leader_id'];
              $oppBack1  = (int)$oppChars[0]['back1_id'];
              $oppBack2  = (int)$oppChars[0]['back2_id'];

              $displayIds = [$oppLeader, $oppBack1, $oppBack2];

              // ③ 正確產生 URL（仍可另外做排序用於 key）
              $oppTeamUrl = "/pages/team_analysis.php"
                . "?id1={$oppLeader}"
                . "&id2={$oppBack1}"
                . "&id3={$oppBack2}";
              ?>

              <div class="fight-team fight-team-right <?= $resultClassMap[$oppResult] ?> fight-opponent">

                <!-- 右：玩家資訊 -->
                <div class="fight-opponent-info">
                  <div class="fight-player">
                    <?php if ($isAdmin): ?>
                      <a
                        href="/pages/fight.php?player_name=<?= urlencode($oppPlayerName) ?>"
                        class="fight-player-link"
                        title="查看 <?= htmlspecialchars($oppPlayerName) ?> 的對戰紀錄">
                        <?= renderPlayerName($oppPlayerName, $blacklistedUsers) ?>
                      </a>
                    <?php else: ?>
                      匿名玩家
                    <?php endif; ?>

                    <span style="color:<?= $resultColorMap[$oppResult] ?>">
                      <?= $resultTextMap[$oppResult] ?>
                    </span>
                  </div>


                  <div class="fight-bp">
                    BP <?= $oppBp ?>
                  </div>
                </div>

                <!-- 右：牌組 -->
                <div class="fight-opponent-cards fight-team-cards">
                  <?php if (count($oppChars) === 3): ?>

                    <a href="<?= htmlspecialchars($oppTeamUrl) ?>"
                      class="fight-team-link"
                      title="查看此對手隊伍分析"
                      style="display:flex;gap:6px;">

                      <?php foreach ($displayIds as $cid): ?>
                        <?php if (isset($oppMap[$cid])): ?>
                          <img
                            src="<?= IMG_BASE . $oppMap[$cid]['ico'] ?>"
                            class="fight-char-ico"
                            alt="對手角色">
                        <?php endif; ?>
                      <?php endforeach; ?>

                    </a>

                  <?php else: ?>
                    <div class="fight-char-placeholder"></div>
                    <div class="fight-char-placeholder"></div>
                    <div class="fight-char-placeholder"></div>
                  <?php endif; ?>
                </div>



              </div>




            </div>

          <?php endforeach; ?>

        </div>
      </div>


    </div>
  </section>
</div>

<script>
  const IMG_PLACEHOLDER = "<?= IMG_BASE ?>placeholder.png";
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  <?php if (!empty($bpLabels)): ?>

    const bpLabels = <?= json_encode($bpLabels) ?>;
    const bpWinRates = <?= json_encode($bpWinRates) ?>;
    const bpMatches = <?= json_encode($bpMatches) ?>;

    const ctx = document.getElementById('bpWinChart');

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: bpLabels,
        datasets: [{
          label: '平均勝率 (%)',
          data: bpWinRates,
          tension: 0.35,
          borderColor: '#4dabf7',
          backgroundColor: 'rgba(77,171,247,0.15)',
          fill: true,
          pointRadius: 4,
          pointHoverRadius: 6,
        }]
      },
      options: {
        responsive: true,
        options: {
          responsive: true,
          maintainAspectRatio: false,
        },
        plugins: {
          tooltip: {
            callbacks: {
              afterLabel: (ctx) => {
                return '場次：' + bpMatches[ctx.dataIndex];
              }
            }
          },
          legend: {
            labels: {
              color: '#ccc'
            }
          }
        },
        scales: {
          x: {
            ticks: {
              color: '#aaa'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            }
          },
          y: {
            min: 0,
            max: 100,
            ticks: {
              color: '#aaa',
              callback: v => v + '%'
            },
            grid: {
              color: 'rgba(255,255,255,0.08)'
            }
          }
        }
      }
    });

  <?php endif; ?>
</script>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    const box = document.getElementById('counterTeamsBox');
    if (!box) return;

    const teamKey = box.dataset.teamKey;
    const days = box.dataset.days || 30;

    fetch('/pages/api/team_counters.php?team_key=' + encodeURIComponent(teamKey) + '&days=' + encodeURIComponent(days))
      .then(r => r.text())
      .then(html => {
        box.innerHTML = html;
      })
      .catch(() => {
        box.innerHTML = '<div class="ta-muted">克制隊伍分析載入失敗，請稍後再試。</div>';
      });
  });
</script>
<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>