<?php

/**
 * character.php
 * Base Character Analysis Page
 *
 * URL:
 *   /pages/character.php?char_base={L1id}-{name}
 *
 * Responsibility:
 *   - L1–R5 merged analysis
 *   - entry to single-card analysis
 */
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定
require_once __DIR__ . '/../lib/_analysis_base.php';
$pdo = $db; // ⭐ 一定要這行

$pageTitleText = '角色分析 Analysis'; //手機
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網'; //桌機
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "character_analysis"; //.php

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* =========================================================
 * UL.GG 角色分析頁專用 Style
 * ========================================================= */

  /* ---------- 基本卡片 ---------- */
  .ul-card {
    background: #1b1e27;
    border: 1px solid #2a2e3a;
    border-radius: 8px;
    padding: 16px 18px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
  }

  /* ---------- 數字統計卡 ---------- */
  .stat-card {
    text-align: center;
    min-height: 108px;
    /* margin: 5px; */
  }

  .stat-title {
    font-size: 13px;
    color: #9aa4b2;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
  }

  .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #e6e9ef;
  }

  /* ---------- 頁面標題 ---------- */
  .page-title {
    font-size: 26px;
    font-weight: 700;
    color: #f2f4f8;
    letter-spacing: 1px;
  }

  /* ---------- 卡片標題 ---------- */
  .card-title {
    font-size: 16px;
    font-weight: 600;
    color: #f0f2f6;
    margin-bottom: 12px;
  }

  /* ---------- 圖表區 ---------- */
  .chart-area {
    width: 100%;
    min-height: 220px;
    background: #161922;
    border: 1px dashed #2f3443;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .chart-placeholder {
    color: #6f7687;
    font-size: 13px;
    letter-spacing: 0.5px;
  }

  /* ---------- 技能摘要 ---------- */
  .highlight-card {
    border-left: 4px solid #8fa3ff;
  }

  .skill-summary {
    padding-left: 18px;
    margin: 0;
  }

  .skill-summary li {
    color: #d6dae3;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 6px;
  }

  /* ---------- 玩家 Top 5 表格 ---------- */
  .table-dark {
    background-color: #161922;
  }

  .table-dark th {
    color: #aab1c3;
    font-size: 13px;
    border-bottom: 1px solid #2a2e3a;
  }

  .table-dark td {
    color: #e6e9ef;
    font-size: 14px;
    border-top: 1px solid #2a2e3a;
  }

  .table-dark tbody tr:hover {
    background-color: #1f2430;
  }

  /* ---------- 小字說明 ---------- */
  .text-muted.small {
    color: #8c93a6 !important;
  }

  /* ---------- 角色圖片 ---------- */
  .character-stand-img {
    max-height: 210px;
    filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.6));
  }

  /* ---------- RWD 微調 ---------- */
  @media (max-width: 768px) {
    .page-title {
      font-size: 22px;
    }

    .stat-value {
      font-size: 24px;
    }

    .chart-area {
      min-height: 180px;
    }
  }

  .table-dark td.rank-player {
    color: #f2c94c;
    font-weight: 600;
  }

  /* ===============================
 * UL.GG Dark Table (Soft Line)
 * =============================== */

  .table-dark {
    background-color: #151822;
    border-collapse: separate;
    border-spacing: 0;
  }

  /* 表頭 */
  .table-dark thead th {
    background-color: #1a1e2a;
    color: #9aa4b2;
    font-weight: 600;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  }

  /* 表身：關鍵在這 */
  .table-dark tbody td {
    color: #e0e3ea;
    border-top: 1px solid rgba(255, 255, 255, 0.035);
    /* 幾乎隱形 */
  }

  /* 第一列不要線（避免雙線） */
  .table-dark tbody tr:first-child td {
    border-top: none;
  }

  /* Stripe：極淡，幾乎不可察 */
  .table-striped>tbody>tr:nth-of-type(odd) {
    background-color: rgba(255, 255, 255, 0.015);
  }

  /* Hover：比 stripe 深一點即可 */
  .table-dark tbody tr:hover {
    background-color: rgba(255, 255, 255, 0.045);
  }

  .stat-sub {
    margin-top: 6px;
    font-size: 0.85rem;
    color: #9aa4b2;
    letter-spacing: 0.3px;
  }

  .stat-sub-rate {
    margin-left: 4px;
    color: #cfd3dc;
  }

  /* ===============================
 * 角色分析頁 - 吸頂資訊列
 * =============================== */
  /* .analysis-sticky-info {
    position: fixed;
    top: 56px;
    left: 0;
    right: 0;
    z-index: 999;
    background: #151822;
    padding: 6px 12px;
    backdrop-filter: blur(1px);
  } */


  .stat-value:hover {
    text-decoration: underline;
    opacity: 0.9;
  }

  /* ===============================
 * 單卡分析 CTA 卡面
 * =============================== */

  .card-cta {
    width: 84px;
    padding: 8px 6px;
    background: #161922;
    border: 1px solid #2a2e3a;
    border-radius: 8px;
    text-align: center;
    color: #e6e9ef;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }

  .card-cta:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.45);
  }

  .card-cta-ico {
    width: 48px;
    height: 48px;
    object-fit: contain;
    margin-bottom: 4px;
  }

  .card-cta-level {
    font-size: 13px;
    font-weight: 600;
    margin-top: 2px;
  }

  .card-cta-rate {
    font-size: 12px;
    color: #8fa3ff;
    margin-top: 2px;
  }

  .card-cta-rate.muted {
    color: #6f7687;
  }

  .card-album {
    display: flex;
    flex-wrap: wrap
  }

  /* =========================================================
 * 📱 UL.GG 角色分析頁｜Mobile Style（集中管理）
 * ========================================================= */
  @media (max-width: 768px) {

    /* ===============================
   * Edge-to-Edge 版面
   * =============================== */

    /* 移除 Bootstrap col 左右 padding（只限本頁） */
    .content-wrapper .container>.row>[class^="col-"],
    .content-wrapper .container>.row>[class*=" col-"] {
      padding-left: 8px;
      padding-right: 8px;
    }

    /* ===============================
   * 卡片密集化（重點）
   * =============================== */

    /* ⭐ 關鍵：ul-card 左右 padding 歸零 */
    .ul-card {
      padding-left: 5px !important;
      padding-right: 5px !important;
      border-radius: 5px;
    }

    /* 若卡片內需要保留內距，用 inner 包 */
    .ul-card>.card-inner {
      padding: 12px 14px;
    }

    /* 卡片間距略縮，畫面更緊湊 */
    .ul-card {
      margin-bottom: 10px;
    }

    /* ===============================
   * 標題與數字縮放
   * =============================== */

    .page-title {
      font-size: 20px;
      line-height: 1.3;
    }

    .stat-value {
      font-size: 22px;
    }

    .stat-sub {
      font-size: 12px;
    }

    /* ===============================
   * 圖表高度縮小
   * =============================== */

    .chart-area {
      min-height: 160px;
      border-radius: 0;
    }

    /* ===============================
   * CTA 卡片：塞更多張
   * =============================== */

    .card-cta {
      width: 62px;
      /* 原 84px → 更密 */
      padding: 6px 4px;
      border-radius: 6px;
    }

    .card-cta-ico {
      width: 40px;
      height: 40px;
    }

    .card-cta-level {
      font-size: 12px;
    }

    .card-cta-rate {
      font-size: 11px;
    }

    /* ===============================
   * 表格微調
   * =============================== */

    .table-dark th,
    .table-dark td {
      padding: 8px 10px;
      font-size: 13px;
    }

    /* ===============================
   * 吸頂資訊列（手機）
   * =============================== */

    /* .analysis-sticky-info {
      padding: 4px 10px;
      font-size: 12px;
    } */

  }

  /* =========================
     Controls
  ========================= */

  .ca-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  .ca-search {
    flex: 1;
    min-width: 220px;
    display: flex;
    gap: 8px;
    align-items: center;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: 12px;
    padding: 8px 10px;
  }

  .ca-search input {
    width: 100%;
    background: transparent;
    border: none;
    outline: none;
    color: #eaf2ff;
    font-size: 14px;
  }

  .ca-search .hint {
    color: #9aa4b2;
    font-size: 12px;
    white-space: nowrap;
  }

  .ca-sort {
    display: inline-flex;
    gap: 8px;
    align-items: center;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: 12px;
    padding: 8px 10px;
  }

  .ca-sort label {
    color: #9aa4b2;
    font-size: 12px;
    margin: 0;
    white-space: nowrap;
  }

  .ca-sort select {
    background: transparent;
    border: none;
    outline: none;
    color: #eaf2ff;
    font-size: 14px;
  }

  /* =========================
   Dark UI Select Fix
========================= */

  .ca-sort select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    background-color: rgba(0, 0, 0, 0.25);
    color: #eaf2ff;

    border: 1px solid rgba(180, 200, 255, 0.25);
    border-radius: 8px;

    padding: 6px 30px 6px 10px;
    font-size: 13px;
    font-weight: 700;

    cursor: pointer;
  }

  /* option 顏色（關鍵） */
  .ca-sort select option {
    background-color: #1f2433;
    color: #eaf2ff;
  }

  /* hover / focus */
  .ca-sort select:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(120, 170, 255, 0.35);
    border-color: rgba(120, 170, 255, 0.55);
  }

  /* 下拉箭頭（純 CSS） */
  .ca-sort {
    position: relative;
  }

  .ca-sort::after {
    content: '▾';
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #c9d2e3;
    font-size: 12px;
    pointer-events: none;
  }

  .ca-pill.muted {
    opacity: .45;
    border-color: rgba(180, 200, 255, .08);
  }



  .analysis-sticky-info {
    position: fixed;
    top: 0;
    /* mobile 用 0 */
    left: 0;
    right: 0;
    z-index: 999;

    background: #151822;
    border-bottom: 1px solid rgba(255, 255, 255, .08);

    /* 🔑 關鍵防塌 */
    min-height: 32px;
    padding: 6px 12px;
    line-height: 1.4;

    display: flex;
    align-items: center;

    opacity: 0;
    pointer-events: none;
    transform: translateY(-100%);
    transition: transform .18s ease, opacity .18s ease;
  }

  /* 顯示狀態 */
  .analysis-sticky-info.show {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
  }

  /* 桌機補 headbar */
  @media (min-width: 769px) {
    .analysis-sticky-info {
      top: 56px;
    }
  }
</style>

<?php
$charId = 0;
$charSlug = null;

if (!empty($_GET['char_base'])) {
  if (preg_match('/^(\d+)(?:-.+)?$/', $_GET['char_base'], $m)) {
    $charId   = (int)$m[1];     // L1 id
    $charSlug = $_GET['char_base'];
  }
}
// 向下相容舊連結（之後可移除）
if ($charId === 0 && !empty($_GET['char'])) {
  if (preg_match('/^(\d+)(?:-.+)?$/', $_GET['char'], $m)) {
    $charId = (int)$m[1];
  }
}


if ($charId > 0) {
  $stmt = $pdo->prepare("
    SELECT id, name, level, rarity, cost,
           HP, ATK, DEF, 
           ico, stand_ico, ico_back
    FROM unlight
    WHERE id = :id
    LIMIT 1
  ");
  $stmt->execute(['id' => $charId]);
  $char = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$char) {
  echo '<div class="alert alert-danger">角色資料不存在</div>';
  exit;
}
// 角色主 ID（以 L1 為基準）
$stmt = $pdo->prepare("
  SELECT id
  FROM unlight
  WHERE name = :name
    AND level = 'L1'
  LIMIT 1
");
$stmt->execute(['name' => $char['name']]);
$charRoot = $stmt->fetch(PDO::FETCH_ASSOC);

$charRootId = $charRoot['id'] ?? $charId;

// ⭐ 正確的 slug
$charSlug = $charRootId . '-' . urlencode($char['name']);



// ===============================
// META 分析範圍設定（統一使用 days）
// ===============================
$DAYS = 90; // 預設

if (isset($_GET['days'])) {
  $d = (int)$_GET['days'];
  if (in_array($d, [14, 30, 90], true)) {
    $DAYS = $d;
  }
}

$metaDays = $DAYS;

// SQL 時間條件
$sqlMetaWhere = '';
$sqlMetaParam = [];

if ($metaDays !== null) {
  $sqlMetaWhere = ' AND update_time >= NOW() - INTERVAL ? DAY ';
  $sqlMetaParam[] = $metaDays;
}

// 只有「對戰資料」能用的 META 條件

if ($metaDays !== null) {
  $sqlMetaWhereMatch = ' AND update_time >= NOW() - INTERVAL ? DAY ';
  $sqlMetaParamMatch[] = $metaDays;
}


// 史特靈 L1–R5 id 區間（目前你資料是連號）
$stmt = $pdo->prepare("
  SELECT id
  FROM unlight
  WHERE name = :name
  ORDER BY id ASC
");
$stmt->execute(['name' => $char['name']]);
$levelIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$levelIdList = array_values($levelIds);
$placeholders = implode(',', array_fill(0, count($levelIdList), '?'));

$sqlAllLevel = "
FROM arena_player_match_result
WHERE(
  leader_id IN ($placeholders)
  OR back1_id IN ($placeholders)
  OR back2_id IN ($placeholders)
)
$sqlMetaWhere";

/* $params3 = array_merge($levelIdList, $levelIdList, $levelIdList); */
$paramsAllLevel = array_merge(
  $levelIdList,
  $levelIdList,
  $levelIdList,
  $sqlMetaParam
);



if (!$char) {
  echo '<div class="alert alert-danger">角色資料不存在</div>';
  exit;
}
$charImg      = IMG_BASE . ($char['ico'] ?? 'no_image.png');
$charStandImg = IMG_BASE . ($char['stand_ico'] ?? $char['ico']);


$totalGames = 0;
$winRate = 0;
$frontRate = 0;

if ($charId > 0) {
  $sqlAllLevel = "
  FROM arena_player_match_result
  WHERE(
    leader_id IN ($placeholders)
    OR back1_id IN ($placeholders)
    OR back2_id IN ($placeholders)
    )
$sqlMetaWhere
";
  // ① 總出場數（全等級）
  $stmt = $pdo->prepare("SELECT COUNT(*) $sqlAllLevel");
  $stmt->execute($paramsAllLevel);
  $totalGames = (int)$stmt->fetchColumn();
}

// 初始化回傳給 JS 的資料
// 初始化
$levelUsageData = [];
$levelWinRateData = [];
$levelWinCountData = [];
$levelScoreData = [];

$bestIndex = 0;
$bestScore = -1;

$sqlPerLevel = "
FROM arena_player_match_result
WHERE (leader_id = ? OR back1_id = ? OR back2_id = ?)
$sqlMetaWhere
";

$stmt = $pdo->prepare("
  SELECT
    x.char_id,
    COUNT(*) AS total_cnt,
    SUM(x.is_win = 1) AS win_cnt
  FROM (
    SELECT 
      leader_id AS char_id,
      is_win
    FROM arena_player_match_result
    WHERE leader_id IN ($placeholders)
    $sqlMetaWhere

    UNION ALL

    SELECT 
      back1_id AS char_id,
      is_win
    FROM arena_player_match_result
    WHERE back1_id IN ($placeholders)
    $sqlMetaWhere

    UNION ALL

    SELECT 
      back2_id AS char_id,
      is_win
    FROM arena_player_match_result
    WHERE back2_id IN ($placeholders)
    $sqlMetaWhere
  ) x
  GROUP BY x.char_id
");

$params = array_merge(
  $levelIdList,
  $sqlMetaParam,
  $levelIdList,
  $sqlMetaParam,
  $levelIdList,
  $sqlMetaParam
);

$stmt->execute($params);

$levelStats = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $levelStats[(int)$row['char_id']] = [
    'total' => (int)$row['total_cnt'],
    'wins'  => (int)$row['win_cnt']
  ];
}


foreach ($levelIdList as $idx => $cid) {

  $total = $levelStats[$cid]['total'] ?? 0;
  $wins  = $levelStats[$cid]['wins'] ?? 0;

  $levelUsageData[]    = $total;
  $levelWinCountData[] = $wins;

  $levelWinRateData[] = $total > 0
    ? round($wins / $total * 100, 1)
    : 0;


  if ($total > 0) {

    $winRate = $wins / $total;
    $confidence = min(1, $total / 30);

    $score =
      $winRate * 100 *
      log10($total + 1) *
      $confidence;
  } else {

    $score = 0;
  }


  $score = round($score, 2);

  $levelScoreData[] = $score;


  if ($score > $bestScore) {
    $bestScore = $score;
    $bestIndex = $idx;
  }
}

// 給卡片用：最佳等級標籤
$levelLabels = ['L1', 'L2', 'L3', 'L4', 'L5', 'R1', 'R2', 'R3', 'R4', 'R5'];
$bestCardLabel = $levelLabels[$bestIndex] ?? '—';
$bestCardPlays = $levelUsageData[$bestIndex] ?? 0;
$bestCardWins  = $levelWinCountData[$bestIndex] ?? 0;
$bestCardWR    = $levelWinRateData[$bestIndex] ?? 0;

// ===============================
// 找出「表現最佳」單卡 ID
// ===============================
$bestCardId = null;

if (!empty($bestCardLabel)) {
  $stmt = $pdo->prepare("
    SELECT id
    FROM unlight
    WHERE name = :name
      AND level = :level
    LIMIT 1
  ");
  $stmt->execute([
    'name'  => $char['name'],
    'level' => $bestCardLabel
  ]);
  $bestCardId = $stmt->fetchColumn() ?: null;
}

// ===============================
// 整體勝率（加權）
// ===============================
$totalPlays = array_sum($levelUsageData);
$totalWins  = 0;

foreach ($levelUsageData as $i => $plays) {
  $rate = $levelWinRateData[$i] ?? null;
  if ($plays > 0 && $rate !== null) {
    $totalWins += $plays * ($rate / 100);
  }
}

$winRate = $totalPlays > 0
  ? round($totalWins / $totalPlays * 100, 1)
  : 0;
$showWinrate = $winRate;


// ===============================
// 站位分析資料
// ===============================

// 各等級前排使用率
$levelFrontRateData = [];

// 前排 / 後排 勝率
$frontWin = 0;
$frontTotal = 0;
$backWin = 0;
$backTotal = 0;

// 單一等級前排率
$stmtLevelFront = $pdo->prepare("
  SELECT
    SUM(leader_id = ?) AS front_cnt,
    COUNT(*) AS total_cnt
  FROM arena_player_match_result
  WHERE (leader_id = ? OR back1_id = ? OR back2_id = ?)
  $sqlMetaWhere
");

foreach ($levelIdList as $cid) {
  $params = array_merge([$cid, $cid, $cid, $cid], $sqlMetaParam);
  $stmtLevelFront->execute($params);
  // ⭐ 關鍵補這行
  $row = $stmtLevelFront->fetch(PDO::FETCH_ASSOC);
  $total = (int)$row['total_cnt'];
  $front = (int)$row['front_cnt'];

  $levelFrontRateData[] = $total > 0
    ? round($front / $total * 100, 1)
    : null;
}


// ===============================
// COST 區間分析（競技場區分）
// ===============================

// 顯示用 Label
$costLabels = [
  '區分1 (50–59)',
  '區分2 (60–69)',
  '區分3 (70–80)',
  '區分4 (90+)'
];

// 初始化資料
$costUsageData   = array_fill(0, 4, 0);
$costWinRateData = array_fill(0, 4, null);

// 全等級角色條件
$sqlCost = "
FROM arena_player_match_result
WHERE(
  leader_id IN ($placeholders)
  OR back1_id IN ($placeholders)
  OR back2_id IN ($placeholders))
$sqlMetaWhere
";

// 依 cost 彙總
$stmt = $pdo->prepare("
  SELECT
    cost,
    COUNT(*) AS total_cnt,
    SUM(is_win = 1) AS win_cnt
  $sqlCost
  GROUP BY cost
");

$stmt->execute($paramsAllLevel);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 暫存用
$tmpTotal = array_fill(0, 4, 0);
$tmpWin   = array_fill(0, 4, 0);

// 分組累加
foreach ($rows as $r) {
  $cost  = (int)$r['cost'];
  $total = (int)$r['total_cnt'];
  $win   = (int)$r['win_cnt'];

  if ($cost >= 50 && $cost <= 59) {
    $i = 0; // 區分1
  } elseif ($cost <= 69) {
    $i = 1; // 區分2
  } elseif ($cost <= 80) {
    $i = 2; // 區分3
  } else {
    $i = 3; // 區分4（90+）
  }

  $costUsageData[$i] += $total;
  $tmpTotal[$i]      += $total;
  $tmpWin[$i]        += $win;
}

// 計算勝率
for ($i = 0; $i < 4; $i++) {
  $costWinRateData[$i] = $tmpTotal[$i] > 0
    ? round($tmpWin[$i] / $tmpTotal[$i] * 100, 1)
    : null;
}
// ===============================
// COST 區間綜合分數（勝場 × LOG10(出場+1)）
// ===============================
$costScoreData = array_fill(0, 4, 0);

for ($i = 0; $i < 4; $i++) {
  $wins  = $tmpWin[$i];
  $plays = $tmpTotal[$i];

  if ($wins > 0 && $plays > 0) {
    if ($plays > 0) {
      $winRate = $wins / $plays;              // 0~1
      $confidence = min(1, $plays / 30);
      $costScoreData[$i] = round(
        $winRate * 100 * log10($plays + 1) * $confidence,
        2
      );
    } else {
      $costScoreData[$i] = 0;
    }
  } else {
    $costScoreData[$i] = 0;
  }
}

// ===============================
// COST 區間分析（出場次數｜該角色 L1–R5）
// ===============================

$costAllTotal = array_fill(0, 4, 0);

$sqlAllCost = "
SELECT cost, COUNT(*) AS total_cnt
FROM arena_player_match_result
WHERE (
  leader_id IN ($placeholders)
  OR back1_id IN ($placeholders)
  OR back2_id IN ($placeholders)
)
$sqlMetaWhere
GROUP BY cost
";

$stmt = $pdo->prepare($sqlAllCost);

// ⚠️ placeholders 出現 3 次
$params = array_merge(
  $levelIdList,
  $levelIdList,
  $levelIdList,
  $sqlMetaParam
);

$stmt->execute($params);
$rowsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rowsAll as $r) {
  $cost  = (int)$r['cost'];
  $total = (int)$r['total_cnt'];

  if ($cost >= 50 && $cost <= 59) {
    $i = 0;
  } elseif ($cost <= 69) {
    $i = 1;
  } elseif ($cost <= 80) {
    $i = 2;
  } else {
    $i = 3;
  }

  $costAllTotal[$i] += $total;
}


// ===============================
// COST 區間使用率（%）
// ===============================
$costUsageRateData = array_fill(0, 4, null);

for ($i = 0; $i < 4; $i++) {
  if ($costAllTotal[$i] > 0) {
    $costUsageRateData[$i] = round(
      $costUsageData[$i] / $costAllTotal[$i] * 100,
      1
    );
  }
}


/* 計算 COST 偏好區間 */
$costZoneMap = [
  0 => ['label' => '50–59', 'tag' => '低 COST'],
  1 => ['label' => '60–69', 'tag' => '中低 COST'],
  2 => ['label' => '70–80', 'tag' => '中高 COST'],
  3 => ['label' => '90+',   'tag' => '高 COST'],
];
$maxIndex = null;
$maxWinRate = -1;

$maxIndex = null;
$maxScore = -1;

foreach ($costScoreData as $i => $score) {
  if ($score > $maxScore) {
    $maxScore = $score;
    $maxIndex = $i;
  }
}
$costPrefLabel = $maxIndex !== null
  ? $costZoneMap[$maxIndex]['label']
  : '—';

$costPrefTag = $maxIndex !== null
  ? $costZoneMap[$maxIndex]['tag']
  : '未知';

$costPrefWinRate = $maxIndex !== null
  ? $costWinRateData[$maxIndex]
  : null;



// ⭐ 你少的就是這個
$costPrefScore = $maxIndex !== null
  ? $costScoreData[$maxIndex]
  : 0;

// ===============================
// 單卡 CTA 用：抓 L1–R5 各等級卡資料
// ===============================
$stmt = $pdo->prepare("
  SELECT id, level, ico
  FROM unlight
  WHERE name = :name
  ORDER BY id ASC
");
$stmt->execute(['name' => $char['name']]);

$cardMap = []; // level => ['id'=>..,'ico'=>..]
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $cardMap[$row['level']] = $row;
}

?>

<!-- Content Wrapper. Contains page content -->
<!-- Content Wrapper -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="text-muted small mt-1">
        分析範圍：近 <?= $metaDays ?? '全部' ?> 天（競技場 META）
      </div>

      <!-- 角色標頭 -->
      <div class="row mb-4">
        <div class="col-md-12 text-center">

          <img
            src="<?= htmlspecialchars($charStandImg) ?>"
            alt="<?= htmlspecialchars($char['name']) ?>"
            style="max-height:210px;"
            class="mb-2">

          <h2 class="page-title">
            <?= htmlspecialchars($char['name']) ?>
            <span class="text-muted">｜角色分析</span>
          </h2>
          <div class="analysis-sticky-info">
            <div class="text-muted small">
              分析範圍：
              <?= htmlspecialchars($char['name']) ?>（L1–R5 全等級）
            </div>
          </div>
        </div>
      </div>
      <div class="ca-controls">
        <div></div> <!-- 左邊保留空位 or 未來放搜尋 -->
        <div class="ca-sort">
          <label for="caDays">分析期間：</label>
          <select id="caDays" name="days">
            <option value="14" <?= $DAYS === 14 ? 'selected' : '' ?>>近 14 天</option>
            <option value="30" <?= $DAYS === 30 ? 'selected' : '' ?>>近 30 天</option>
            <option value="90" <?= $DAYS === 90 ? 'selected' : '' ?>>近 90 天</option>
          </select>
        </div>
      </div>

      <!-- Overview 數字卡片 -->
      <hr>
      <div class="text-center mt-2">
        ※ 以下統計為「<?= htmlspecialchars($char['name']) ?> 全等級（L1–R5）」資料
      </div>
      <div class="row">
        <div class="col-md-4 mb-3">
          <div class="ul-card stat-card">
            <div class="stat-title">表現最佳</div>

            <?php $bestSeoText = $bestCardLabel . $char['name']; // e.g. L5史特靈
            $bestSeoEncoded = urlencode($bestSeoText);
            if (!empty($bestCardId)): ?>
              <a
                href="/pages/analysis_character_card.php?char_id=<?= (int)$bestCardId ?>&char=<?= $bestSeoEncoded ?>&days=<?= (int)$metaDays ?>"
                class="stat-value text-decoration-none"
                style="color:inherit;"
                title="<?= htmlspecialchars($bestSeoText . ' 單卡分析') ?>">
                <?= htmlspecialchars($bestCardLabel) ?>↗
              </a>
            <?php else: ?>
              <span class="stat-value text-muted">—</span>
            <?php endif; ?>


            <div class="stat-sub">
              綜合分數 <?= number_format($bestScore, 2) ?>
              <span class="stat-sub-rate">
                ｜出場 <?= number_format($bestCardPlays) ?>
                ｜勝率 <?= number_format($bestCardWR, 1) ?>%
              </span>
            </div>
          </div>
        </div>


        <div class="col-md-4 mb-3">
          <div class="ul-card stat-card">
            <div class="stat-title">整體勝率</div>
            <div class="stat-value"><?= $showWinrate ?>%</div>

            <div class="stat-sub">
              加權平均
              <span class="stat-sub-rate">
                （依各等級出場次數）
              </span>
            </div>
          </div>
        </div>

        <div class="col-md-4 mb-3">
          <div class="ul-card stat-card">
            <div class="stat-title">COST 勝率最佳區間</div>

            <div class="stat-value">
              <?= htmlspecialchars($costPrefLabel) ?>
            </div>

            <div class="stat-sub">
              <?= htmlspecialchars($costPrefTag) ?>
              <span class="stat-sub-rate">
                ｜綜合分數 <?= number_format($costPrefScore, 2) ?>
                ｜勝率 <?= number_format($costPrefWinRate, 1) ?>%
              </span>
            </div>


          </div>
        </div>
      </div>


      <!-- 等級差異分析 -->
      <div class="row mb-4">
        <div class="col-md-12">
          <div class="ul-card">
            <h4 class="card-title">等級使用次數 & 勝率（L1–R5）</h4>

            <div class="chart-area">
              <canvas id="chart-level-usage-rate"></canvas>
            </div>

            <div class="text-muted small mt-2">
              左軸：出場次數｜右軸：勝率（%）
            </div>
          </div>
        </div>
      </div>

      <!-- 綜合分數分析 -->
      <div class="row mb-4">
        <div class="col-md-12">
          <div class="ul-card">
            <h4 class="card-title">
              等級綜合分數（L1–R5）
              <span class="text-muted small">｜勝場 × LOG10(出場+1)</span>
            </h4>

            <div class="chart-area">
              <canvas id="chart-level-score"></canvas>
            </div>

            <div class="text-muted small mt-2">
              ※ 綜合分數以勝率為主，並依出場數進行可信度修正<div class="text-center mt-4">
              </div>
            </div>
          </div>
        </div>
      </div>


      <!-- COST 環境分析 -->
      <div class="row mb-4">
        <div class="col-md-6">
          <div class="ul-card">
            <h4 class="card-title">各 COST 區間使用率</h4>
            <div class="chart-area">
              <canvas id="chart-cost-usage"></canvas>
            </div>
          </div>
        </div>


        <div class="col-md-6">
          <div class="ul-card">
            <h4 class="card-title">各 COST 區間勝率</h4>
            <div class="chart-area">
              <canvas id="chart-cost-winrate"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- 單卡分析 CTA -->
      <div class="row mb-4">
        <div class="col-md-12">
          <div class="ul-card">
            <h4 class="card-title">
              展開單卡分析
              <span class="text-muted small card-cta">｜選擇等級</span>
            </h4>

            <div class="d-flex flex-wrap justify-content-center gap-2 mt-3 card-album">

              <?php foreach ($levelLabels as $i => $label):

                // ⭐ 沒卡資料 or 沒 ico → 跳過
                if (
                  empty($cardMap[$label]) ||
                  empty($cardMap[$label]['ico'])
                ) {
                  continue;
                }

                // ⭐ 單卡資料（核心）
                $cardId  = (int)$cardMap[$label]['id'];
                $ico     = $cardMap[$label]['ico'];
                $winRate = $levelWinRateData[$i] ?? null;

                // ⭐ SEO 組字（重點）
                $charName   = $char['name'];          // 史特靈
                $seoText    = $label . $charName;     // L5史特靈
                $seoEncoded = urlencode($seoText);    // URL safe
              ?>

                <a
                  href="/pages/analysis_character_card.php?char_id=<?= $cardId ?>&char=<?= $seoEncoded ?>&days=<?= (int)$metaDays ?>"
                  class="card-cta text-decoration-none"
                  title="<?= htmlspecialchars("{$seoText} 單卡分析") ?>"
                  aria-label="<?= htmlspecialchars("{$seoText} 單卡分析") ?>">

                  <img
                    src="<?= IMG_BASE . htmlspecialchars($ico) ?>"
                    alt="<?= htmlspecialchars("{$seoText} 卡片") ?>"
                    class="card-cta-ico"
                    loading="lazy">

                  <div class="card-cta-level"><?= htmlspecialchars($label) ?></div>

                  <?php if ($winRate !== null && $winRate > 0): ?>
                    <div class="card-cta-rate"><?= $winRate ?>%</div>
                  <?php else: ?>
                    <div class="card-cta-rate muted">—</div>
                  <?php endif; ?>

                </a>

              <?php endforeach; ?>

            </div>






            <div class="text-muted small mt-3 text-center">
              點擊任一卡面，查看該等級的詳細勝率、COST 與站位分析
            </div>
          </div>
        </div>
      </div>

      <?php
      $backUrl = '/pages/character_analysis.php';
      $qs = $_GET;
      unset($qs['char'], $qs['char_base']); // 移除角色專用參數
      if (!empty($qs)) {
        $backUrl .= '?' . http_build_query($qs);
      }
      ?>
      <a href="/pages/character_analysis.php?days=<?= (int)$metaDays ?>">
        ← 回角色總覽
      </a>

      <!-- 技能摘要 -->
      <!-- <div class="row mb-4">
        <div class="col-md-12">
          <div class="ul-card highlight-card">
            <h4 class="card-title">技能特性摘要</h4>
            <ul class="skill-summary">
              <li>前排啟動型角色，技能效果需承傷條件支撐</li>
              <li>高等級（L5 / R）技能穩定度明顯提升</li>
              <li>在中高 COST 牌組中表現最佳</li>
            </ul>
          </div>
        </div>
      </div> -->

    </div>

    <!-- -container -->
  </section>
</div>

<!-- /.content-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

<!-- 出場次數（左軸）VS 勝率（右軸 %） -->
<script>
  (function() {
    const canvas = document.getElementById('chart-level-usage-rate');
    if (!canvas) {
      console.warn('[UL.GG] chart-level-usage-rate canvas not found');
      return;
    }

    const ctx = canvas.getContext('2d');

    const levelLabels = ['L1', 'L2', 'L3', 'L4', 'L5', 'R1', 'R2', 'R3', 'R4', 'R5'];

    // 🔹 出場次數（左軸）
    const levelUsageData = <?= json_encode($levelUsageData, JSON_NUMERIC_CHECK) ?>;

    // 🔹 勝率（右軸 %）
    const levelWinRateData = <?= json_encode($levelWinRateData, JSON_NUMERIC_CHECK) ?>;

    new Chart(ctx, {
      data: {
        labels: levelLabels,
        datasets: [{
            type: 'bar',
            label: '出場次數',
            data: levelUsageData,
            yAxisID: 'yCount',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: 4
          },
          {
            type: 'line',
            label: '勝率 (%)',
            data: levelWinRateData,
            yAxisID: 'yRate',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: 4,
            spanGaps: true // ⭐ 關鍵：允許 null 斷點連線
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          legend: {
            labels: {
              color: '#e6e9ef'
            }
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                if (context.raw === null) return null;
                if (context.dataset.yAxisID === 'yRate') {
                  return context.dataset.label + ': ' + context.raw + '%';
                }
                return context.dataset.label + ': ' + context.raw;
              }
            }
          }
        },
        scales: {
          x: {
            ticks: {
              color: '#cfd3dc'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            }
          },
          yCount: {
            type: 'linear',
            position: 'left',
            ticks: {
              color: '#9aa4b2'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            },
            title: {
              display: true,
              text: '出場次數',
              color: '#9aa4b2'
            }
          },
          yRate: {
            type: 'linear',
            position: 'right',
            min: 0,
            max: 100,
            ticks: {
              callback: (value) => value + '%',
              color: '#9aa4b2'
            },
            grid: {
              drawOnChartArea: false
            },
            title: {
              display: true,
              text: '勝率 (%)',
              color: '#9aa4b2'
            }
          }
        }
      }
    });
  })();
</script>






<!-- COST 勝率 -->
<script>
  (function() {
    const costLabels = <?= json_encode($costLabels) ?>;

    // ===============================
    // COST 出場次數
    // ===============================
    const canvasUsage = document.getElementById('chart-cost-usage');
    if (canvasUsage) {
      new Chart(canvasUsage.getContext('2d'), {
        type: 'bar',
        data: {
          labels: costLabels,
          datasets: [{
            label: '出場次數',
            data: <?= json_encode($costAllTotal, JSON_NUMERIC_CHECK) ?>,
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            tooltip: {
              callbacks: {
                label: ctx => `出場次數：${ctx.raw}`
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                color: '#9aa4b2'
              }
            },
            x: {
              ticks: {
                color: '#cfd3dc'
              }
            }
          }
        }
      });
    }
    // ===============================
    // COST 勝率
    // ===============================
    const canvasRate = document.getElementById('chart-cost-winrate');
    if (canvasRate) {
      new Chart(canvasRate.getContext('2d'), {
        type: 'bar',
        data: {
          labels: costLabels,
          datasets: [{
            label: '勝率 (%)',
            data: <?= json_encode($costWinRateData, JSON_NUMERIC_CHECK) ?>,
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              min: 0,
              max: 100,
              ticks: {
                callback: v => v + '%',
                color: '#9aa4b2'
              }
            },
            x: {
              ticks: {
                color: '#cfd3dc'
              }
            }
          }
        }
      });
    }
  })();
</script>



<!-- 綜合分數 -->
<script>
  (function() {
    const el = document.querySelector('.analysis-sticky-info');
    if (!el) return;

    window.addEventListener('scroll', () => {
      const y = window.scrollY;
      el.classList.toggle('show', y > 120);
      el.classList.toggle('is-stuck', y > 60);
    });
  })();
</script>


<script>
  (function() {
    const canvas = document.getElementById('chart-level-score');
    if (!canvas) {
      console.warn('[UL.GG] chart-level-score canvas not found');
      return;
    }

    const ctx = canvas.getContext('2d');

    const levelLabels = ['L1', 'L2', 'L3', 'L4', 'L5', 'R1', 'R2', 'R3', 'R4', 'R5'];
    const levelScoreData = <?= json_encode($levelScoreData, JSON_NUMERIC_CHECK) ?>;

    // 找最大值（高亮用）
    const maxScore = Math.max(...levelScoreData);

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: levelLabels,
        datasets: [{
          label: '綜合分數',
          data: levelScoreData,
          tension: 0.3,
          borderWidth: 2,
          pointRadius: levelScoreData.map(v => v === maxScore ? 6 : 4),
          pointBackgroundColor: levelScoreData.map(v =>
            v === maxScore ? '#f2c94c' : '#8fa3ff'
          ),
          pointBorderColor: '#151822'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            labels: {
              color: '#e6e9ef'
            }
          },
          tooltip: {
            callbacks: {
              label: ctx => `綜合分數：${ctx.raw}`
            }
          }
        },
        scales: {
          x: {
            ticks: {
              color: '#cfd3dc'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            }
          },
          y: {
            beginAtZero: true,
            ticks: {
              color: '#9aa4b2'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            },
            title: {
              display: true,
              text: '綜合分數',
              color: '#9aa4b2'
            }
          }
        }
      }
    });
  })();
</script>
<script>
  document.getElementById('caDays')?.addEventListener('change', function() {
    const url = new URL(window.location.href);
    url.searchParams.set('days', this.value);
    window.location.href = url.toString();
  });
</script>
<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>