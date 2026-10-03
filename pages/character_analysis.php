<?php
/* character_analysis.php */
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定
require_once __DIR__ . '/../lib/_analysis_base.php';
$pdo = $db; // ⭐ 一定要這行

$seoTitle = '角色分析總覽 Character Analysis | UL.GG 戰績網 UNLIGHT 戰術研究中心'; // 瀏覽器標題
$activeMenu = "character_analysis"; // .php
$pageTitleFull = '角色分析總覽 Character Analysis | UL.GG 戰績網'; // 桌機
$pageTitleText = '角色分析總覽'; // 手機

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* =========================
     Character Analysis - Layout
  ========================= */

  .ca-hero {
    padding: 18px 16px;
    border-radius: 14px;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.03));
    border: 1px solid rgba(180, 200, 255, 0.12);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
    margin-bottom: 16px;
  }

  .ca-hero-title {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: .3px;
    margin: 0 0 6px;
    color: #fff;
  }

  .ca-hero-sub {
    font-size: 13px;
    color: #c9d2e3;
    opacity: .9;
    margin: 0;
    line-height: 1.6;
  }

  .ca-card {
    border-radius: 14px;
    background: rgba(25, 27, 35, 0.75);
    border: 1px solid rgba(180, 200, 255, 0.10);
    box-shadow: 0 10px 26px rgba(0, 0, 0, 0.35);
    overflow: hidden;
    margin-bottom: 16px;
  }

  .ca-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 14px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.02));
  }

  .ca-card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 800;
    color: #e8eefc;
    margin: 0;
    font-size: 14px;
    letter-spacing: .2px;
    white-space: nowrap;
  }

  .ca-badge {
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid rgba(180, 200, 255, 0.18);
    color: #c9d2e3;
    background: rgba(255, 255, 255, 0.04);
  }

  .ca-card-body {
    padding: 14px;
  }

  /* =========================
   Mobile UI Tighten
========================= */
  @media (max-width: 576px) {
    .ca-card .ca-card-body {
      padding: 0px;
    }

    /* 樣本 */
    .ca-table th:nth-child(6),
    .ca-table td:nth-child(6),

    /* 操作 */
    .ca-table th:nth-child(7),
    .ca-table td:nth-child(7) {
      display: none;
    }
  }


  /* =========================
     Pie + Info
  ========================= */

  .ca-pie-wrap {
    display: grid;
    grid-template-columns: 1.2fr .8fr;
    gap: 14px;
    align-items: stretch;
  }

  @media (max-width: 992px) {
    .ca-pie-wrap {
      grid-template-columns: 1fr;
    }
  }

  .ca-pie-canvas {
    position: relative;
    height: 280px;
    border-radius: 12px;
    padding: 10px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.06);
  }

  .ca-note {
    border-radius: 12px;
    padding: 12px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.06);
    color: #c9d2e3;
    font-size: 13px;
    line-height: 1.65;
  }

  .ca-note .muted {
    color: #9aa4b2;
  }

  .ca-kpis {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 10px;
  }

  .ca-kpi {
    border-radius: 12px;
    padding: 10px 12px;
    background: rgba(0, 0, 0, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.06);
  }

  .ca-kpi .label {
    font-size: 12px;
    color: #9aa4b2;
    margin-bottom: 4px;
  }

  .ca-kpi .value {
    font-size: 16px;
    font-weight: 800;
    color: #eaf2ff;
    letter-spacing: .2px;
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
     Table
  ========================= */

  .ca-table {
    width: 100%;
    border-collapse: collapse;
    overflow: hidden;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.06);
  }

  .ca-table thead th {
    font-size: 12px;
    color: #9aa4b2;
    font-weight: 700;
    letter-spacing: .2px;
    text-align: left;
    padding: 12px 12px;
    background: rgba(255, 255, 255, 0.03);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    white-space: nowrap;
  }

  .ca-table tbody td {
    padding: 12px 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    color: #eaf2ff;
    font-size: 13px;
    vertical-align: middle;
  }

  .ca-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.03);
  }

  .ca-name a {
    color: #eaf2ff;
    text-decoration: none;
    font-weight: 800;
  }

  .ca-name a:hover {
    text-decoration: underline;
  }

  .ca-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    border: 1px solid rgba(180, 200, 255, 0.14);
    background: rgba(255, 255, 255, 0.03);
    color: #c9d2e3;
    white-space: nowrap;
  }

  .ca-pill.low {
    border-color: rgba(255, 180, 120, 0.25);
    background: rgba(255, 180, 120, 0.06);
    color: #ffd3b3;
  }

  .btn-ca {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 800;
    font-size: 13px;
    color: #fff;
    background: linear-gradient(180deg, #3a3f52, #2b2f40);
    border: 1px solid rgba(180, 200, 255, 0.22);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.35);
    transition: transform .15s ease, box-shadow .15s ease;
    white-space: nowrap;
  }

  .btn-ca:hover {
    transform: translateY(-1px);
    box-shadow: 0 0 10px rgba(120, 170, 255, 0.25), 0 10px 18px rgba(0, 0, 0, 0.55);
  }

  /* Mobile: table to scroll */
  .ca-table-scroll {
    overflow-x: auto;
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

  /* ===============================
 * UL.GG 角色入口 Chip（非 hover 版）
 * =============================== */

  .ca-entry {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    padding: 4px 10px;
    border-radius: 999px;

    background: rgba(143, 163, 255, 0.12);
    border: 1px solid rgba(143, 163, 255, 0.45);

    color: #e6e9ef;
    font-weight: 600;
    font-size: 13px;

    text-decoration: none;
  }

  /* 箭頭＝「這不是純文字」的關鍵 */
  .ca-entry-ico {
    font-size: 12px;
    color: #8fa3ff;
  }

  .ca-pie-with-cards {
    position: relative;
  }

  .ca-pie-cards {
    position: absolute;
    inset: 0;
    pointer-events: none;
    /* canvas 可點 */
  }

  .ca-pie-card {
    position: absolute;
    width: 56px;
    height: 78px;
    border-radius: 8px;
    overflow: hidden;

    border: 1px solid rgba(255, 255, 255, .25);
    background: #111;
    box-shadow: 0 8px 18px rgba(0, 0, 0, .6);

    cursor: pointer;
    pointer-events: auto;
    /* 卡面可點 */
    transition: transform .15s ease;
  }

  .ca-pie-card:hover {
    transform: translateY(-4px) scale(1.04);
  }

  .ca-pie-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  /* =========================
   HOT Pill Responsive
========================= */

  /* 桌機預設 */
  .ca-pill.ca-hot {
    background: linear-gradient(180deg, #3b82f6, #2563eb);
    color: #fff;
    border-color: rgba(59, 130, 246, .65);
    font-weight: 800;
    box-shadow: 0 0 0 1px rgba(59, 130, 246, .35),
      0 0 10px rgba(59, 130, 246, .35);
  }

  /* 手機：icon-only */
  @media (max-width: 576px) {
    .ca-pill.ca-hot {
      padding: 4px 6px;
      font-size: 14px;
      line-height: 1;
      border-radius: 999px;
    }

    .ca-pill {
      padding: 4px 4px;
    }


    /* 把 HOT 文字藏掉，只留 🔥 */
    .ca-pill.ca-hot {
      text-indent: -9999px;
      position: relative;
      width: 28px;
      height: 28px;
    }

    .ca-pill.ca-hot::after {
      content: '🔥';
      text-indent: 0;
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
    }
  }

  .dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 0px !important;
  }
</style>

<?php
// ===============================
// META 分析範圍設定（統一使用 days）
// ===============================
$allowedDays = [14, 30, 90];
$DAYS = 14; // 預設

$rawDays = $_GET['days'] ?? $_GET['day'] ?? null;

if (in_array((int)$rawDays, $allowedDays, true)) {
  $DAYS = (int)$rawDays;
}

$metaDays = $DAYS;


$timeWhere = "
  AND update_time >= DATE_SUB(NOW(), INTERVAL {$DAYS} DAY)
";
/* =========================
   Character Analysis - Data
   一人 × 一場 × 一角色
========================= */

$TOP_N = 8;
$SAMPLE_LOW_TEAM = match ($DAYS) {
  14 => 60,   // 原 30 場 × 2
  30 => 120,  // 原 60 場 × 2
  default => 200,
};


/* ---------- 1) 總對戰場次（顯示用） ---------- */
$sqlTotalMatches = "
  SELECT COUNT(*)
  FROM arena_unlight
  WHERE ack1 = 1
    AND ack2 = 1
    AND (win + lose + tie) = 1
    {$timeWhere}
";

$totalMatches = (int)$pdo->query($sqlTotalMatches)->fetchColumn();
$totalTeams   = $totalMatches * 2;

/* ---------- 2) 角色使用統計（核心 SQL） ---------- */
$sql = "WITH player_match AS (
  -- P1 side
  SELECT
    a.id AS match_id,
    'P1' AS side,
    a.e1 AS c1, a.e2 AS c2, a.e3 AS c3,
    CASE
      WHEN a.lose = 1 THEN 1
      WHEN a.win  = 1 THEN 0
      ELSE NULL
    END AS is_win
  FROM arena_unlight a
  WHERE a.ack1 = 1
    AND a.ack2 = 1
    AND (a.win + a.lose + a.tie) = 1
    AND a.update_time >= DATE_SUB(NOW(), INTERVAL {$DAYS} DAY)

  UNION ALL

  -- P2 side
  SELECT
    a.id AS match_id,
    'P2' AS side,
    a.u1 AS c1, a.u2 AS c2, a.u3 AS c3,
    CASE
      WHEN a.win  = 1 THEN 1
      WHEN a.lose = 1 THEN 0
      ELSE NULL
    END AS is_win
  FROM arena_unlight a
  WHERE a.ack1 = 1
    AND a.ack2 = 1
    AND (a.win + a.lose + a.tie) = 1
    AND a.update_time >= DATE_SUB(NOW(), INTERVAL {$DAYS} DAY)
),
player_char AS (
  SELECT match_id, side, is_win, c1 AS char_id FROM player_match
  UNION ALL
  SELECT match_id, side, is_win, c2 FROM player_match
  UNION ALL
  SELECT match_id, side, is_win, c3 FROM player_match
)

SELECT
  u.id AS base_id,
  u.name AS char_name,
  u.ico  AS ico,

  COUNT(*) AS games,  -- 出場次數（appearance）角色 slot 出現次數（隊伍 × 角色位）
  COUNT(DISTINCT CONCAT(pc.match_id, '-', pc.side)) AS team_cnt,


  SUM(pc.is_win = 1) AS win_cnt,
  SUM(pc.is_win = 0) AS lose_cnt,
  SUM(pc.is_win IS NULL) AS tie_cnt,

  ROUND(
    SUM(pc.is_win = 1) * 100.0 /
    NULLIF(SUM(pc.is_win IN (0,1)), 0),
    1
  ) AS win_rate,

  ROUND(
    SUM(pc.is_win = 1) * LOG10(COUNT(*) + 1),
    4
  ) AS recommend_score

FROM player_char pc
JOIN unlight u
  ON u.id = (FLOOR((pc.char_id - 1) / 10) * 10 + 1)
GROUP BY u.id, u.name
ORDER BY recommend_score DESC
";


$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {
  $r['team_rate'] = $totalTeams > 0
    ? round($r['team_cnt'] * 100 / $totalTeams, 1)
    : 0;
}
unset($r);

/* ---------- 1.5) 資料時間區間 ---------- */
$sqlTimeRange = "
  SELECT
    MIN(update_time) AS start_time,
    MAX(update_time) AS end_time
  FROM arena_unlight
  WHERE ack1 = 1
    AND ack2 = 1
    AND (win + lose + tie) = 1
    {$timeWhere}
";
$timeRange = $pdo->query($sqlTimeRange)->fetch(PDO::FETCH_ASSOC);

$dataStart = $timeRange['start_time'] ?? null;
$dataEnd   = $timeRange['end_time'] ?? null;



/* ---------- 3) 出場率 & 樣本標記 ---------- */
$totalGames = array_sum(array_column($rows, 'games'));

foreach ($rows as &$r) {
  $games    = (int)($r['games'] ?? 0);
  $teamCnt  = (int)($r['team_cnt'] ?? 0);
  $baseId   = (int)($r['base_id'] ?? 0);
  $charName = (string)($r['char_name'] ?? '');

  $r['pick_rate'] = $totalGames > 0
    ? round($games * 100 / $totalGames, 1)
    : 0;

  $r['sample_low'] = ($teamCnt < $SAMPLE_LOW_TEAM);

  $r['char_key'] = $baseId . '-' . urlencode($charName);

  
}
unset($r);

/* 熱度變化 */
$compareDays = match ($DAYS) {
  14 => 30,
  30 => 90,
  default => null,
};
$compareStats = [];

if ($compareDays) {
  $sqlCompare = "
    SELECT
      u.id AS base_id,
      COUNT(*) AS games
    FROM (
      SELECT a.id, a.e1 AS char_id FROM arena_unlight a
      WHERE ack1=1 AND ack2=1 AND (win+lose+tie)=1
        AND a.update_time >= DATE_SUB(NOW(), INTERVAL {$compareDays} DAY)
      UNION ALL
      SELECT a.id, a.e2 FROM arena_unlight a WHERE ack1=1 AND ack2=1 AND (win+lose+tie)=1
        AND a.update_time >= DATE_SUB(NOW(), INTERVAL {$compareDays} DAY)
      UNION ALL
      SELECT a.id, a.e3 FROM arena_unlight a WHERE ack1=1 AND ack2=1 AND (win+lose+tie)=1
        AND a.update_time >= DATE_SUB(NOW(), INTERVAL {$compareDays} DAY)
      UNION ALL
      SELECT a.id, a.u1 FROM arena_unlight a WHERE ack1=1 AND ack2=1 AND (win+lose+tie)=1
        AND a.update_time >= DATE_SUB(NOW(), INTERVAL {$compareDays} DAY)
      UNION ALL
      SELECT a.id, a.u2 FROM arena_unlight a WHERE ack1=1 AND ack2=1 AND (win+lose+tie)=1
        AND a.update_time >= DATE_SUB(NOW(), INTERVAL {$compareDays} DAY)
      UNION ALL
      SELECT a.id, a.u3 FROM arena_unlight a WHERE ack1=1 AND ack2=1 AND (win+lose+tie)=1
        AND a.update_time >= DATE_SUB(NOW(), INTERVAL {$compareDays} DAY)
    ) t
    JOIN unlight u ON u.id = (FLOOR((t.char_id - 1)/10)*10+1)
    GROUP BY u.id
  ";

  foreach ($pdo->query($sqlCompare) as $r) {
    $compareStats[$r['base_id']] = (int)$r['games'];
  }
}

/* ---------- 3.5) 熱度變化（Heat Trend） ---------- */
$prevTotalGames = array_sum($compareStats);

foreach ($rows as &$r) {
  $prevGames = $compareStats[$r['base_id']] ?? 0;

  $prevRate = $prevTotalGames > 0
    ? round($prevGames * 100 / $prevTotalGames, 1)
    : 0;

  $delta = round($r['pick_rate'] - $prevRate, 1);
  $r['delta'] = $delta;

  if ($DAYS === 14 && $r['games'] >= 30 && $delta >= 1.0) {
    $r['heat'] = 'hot';
  } elseif ($delta >= 1.5) {
    $r['heat'] = 'up';
  } elseif ($delta <= -1.5) {
    $r['heat'] = 'down';
  } elseif ($delta >= 0.8) {
    $r['heat'] = 'warm';
  } else {
    $r['heat'] = 'flat';
  }
}
unset($r);


/* ---------- 4) 圓餅圖 Top N + 其他 ---------- */


$othersRate = 0;

foreach ($rows as $i => $r) {
  if ($i < $TOP_N) {
    $pieLabels[] = $r['char_name'];
    $pieRates[]  = $r['pick_rate'];
    $pieKeys[]   = $r['char_key'];
    $pieIcos[]   = IMG_BASE . $r['ico'];
  } else {
    $othersRate += $r['pick_rate'];
  }
}

if ($othersRate > 0) {
  $pieLabels[] = '其他';
  $pieRates[]  = round($othersRate, 1);
  $pieKeys[]   = 'others';
}


?>


<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">

      <!-- Hero -->
      <div class="ca-hero">
        <div class="ca-hero-title">🧠 角色分析總覽 <small style="font-weight:700; opacity:.85;">Character Analysis</small></div>
        <p class="ca-hero-sub">
          本頁僅顯示 <b>L1–R5 合併</b> 的整體數據（勝率 / 出場數），提供快速視覺回饋與入口導覽。
          <!-- <span class="muted">（熱度變化：第二版加入）</span> -->
        </p>
      </div>

      <div class="row">

        <!-- Main column -->
        <div class="col-md-12">

          <!-- Pie Card -->
          <div class="ca-card">
            <div class="ca-card-header">
              <h3 class="ca-card-title">🥧 角色出場分布（L1–R5 合併）</h3>
              <span class="ca-badge">Top 8 + 其他</span>
            </div>
            <div class="ca-card-body">
              <div class="ca-pie-wrap">
                <div class="ca-pie-canvas ca-pie-with-cards">
                  <canvas id="chart-character-pie"></canvas>

                  <!-- 角色卡面 overlay -->
                  <div class="ca-pie-cards" id="pieCards"></div>
                </div>

                <div class="ca-note">
                  <div style="font-weight:800; margin-bottom:6px;">這張圖在看什麼？</div>
                  <div class="muted">
                    以「出場佔比」呈現環境分布。勝率請看下方表格，點角色可進入單體分析頁。
                  </div>

                  <div class="ca-kpis">
                    <div class="ca-kpi">
                      <div class="label">📊 有效樣本數</div>
                      <div class="value"><?= number_format($totalTeams) ?> 隊</div>
                    </div>
                    <div class="ca-kpi">
                      <div class="label">✅ 顯示範圍</div>
                      <div class="value">L1–R5 合併</div>
                    </div>
                  </div>

                  <div style="margin-top:10px; font-size:12px; color:#9aa4b2;">
                    ※ 樣本偏低的角色在表格會標示提醒。
                  </div>
                  <?php if ($dataStart && $dataEnd): ?>
                    <div style="margin-top:10px; font-size:12px; color:#9aa4b2; line-height:1.6;">
                      📅 資料期間：
                      <div style="margin-top:6px; font-size:12px; color:#9aa4b2;">
                        ⏱ 分析區間：近 <?= $DAYS ?> 天
                      </div>
                      <span style="color:#c9d2e3;">
                        (<?= date('Y-m-d H:i', strtotime($dataStart)) ?>
                        ～
                        <?= date('Y-m-d H:i', strtotime($dataEnd)) ?>)
                      </span>
                    </div>
                  <?php endif; ?>

                </div>
              </div>
            </div>
          </div>

          <!-- Table Card -->
          <div class="ca-card">
            <div class="ca-card-header">
              <h3 class="ca-card-title">
                📋 角色總覽（勝率 + 出場數）
                <span style="font-size:12px;font-weight:500;color:#9aa4b2;margin-left:8px;">
                  ※ 出場數以隊伍為單位，單場雙方同角將計為 2
                </span>
              </h3>
              <div class="text-muted small mt-1">
                🔥 HOT：近 14 天內出場數達標，且出場率相較前一期明顯上升的角色
              </div>
            </div>
            <div class="ca-card-body">

              <!-- Controls -->
              <div class="ca-controls">
                <div class="ca-search">
                  <span style="opacity:.85;">🔍</span>
                  <input id="caSearch" type="text" placeholder="搜尋角色名稱..." autocomplete="off">
                  <span class="hint">Enter / 即時篩選</span>
                </div>

                <div class="ca-sort">
                  <label for="caSort">排序：</label>
                  <select id="caSort">
                    <option value="pick">推薦（預設）</option>
                    <option value="pick_desc">推薦（高 → 低）</option>
                    <option value="games_desc">出場數（高 → 低）</option>
                    <option value="wr_desc">勝率（高 → 低）</option>
                  </select>
                </div>
                <div class="ca-sort">
                  <label for="caDays">分析期間：</label>
                  <select id="caDays" name="days">
                    <option value="14" <?= $DAYS === 14 ? 'selected' : '' ?>>近 14 天</option>
                    <option value="30" <?= $DAYS === 30 ? 'selected' : '' ?>>近 30 天</option>
                    <option value="90" <?= $DAYS === 90 ? 'selected' : '' ?>>近 90 天</option>
                  </select>
                </div>

              </div>

              <!-- Table -->
              <div class="ca-table-scroll">
                <table class="ca-table" id="caTable">
                  <thead>
                    <tr>
                      <th style="width: 25%;">角色</th>
                      <th style="width: 15%;">出場數</th>
                      <th style="width: 15%;">出場率</th>
                      <th style="width: 15%;">勝率</th>
                      <th style="width: 10%;">熱度</th>
                      <th style="width: 10%;">樣本</th>
                      <th style="width: 10%;">操作</th>
                      <th style="display:none;">推薦分數</th>


                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r): ?>
                      <tr
                        data-games="<?= (int)$r['games'] ?>"
                        data-wr="<?= is_null($r['win_rate']) ? -1 : (float)$r['win_rate'] ?>">
                        <td class="ca-name">
                          <a
                            href="/pages/character.php?char_base=<?= htmlspecialchars($r['char_key']) ?>&days=<?= $DAYS ?>"
                            class="ca-link"
                            title="查看 <?= htmlspecialchars($r['char_name']) ?> 的角色分析">
                            <?= htmlspecialchars($r['char_name']) ?>
                            <span class="ca-link-ico">↗</span>
                          </a>
                        </td>

                        <td><?= number_format($r['team_cnt']) ?></td>

                        <td title="在 <?= number_format($totalTeams) ?> 個隊伍中，有 <?= number_format($r['team_cnt']) ?> 隊使用該角色">
                          <?= number_format($r['team_rate'], 1) ?>%
                        </td>


                        <td>
                          <?= is_null($r['win_rate']) ? '—' : number_format($r['win_rate'], 1) . '%' ?>
                        </td>
                        <td>
                          <?php if ($DAYS === 14 && $r['heat'] === 'hot'): ?>
                            <span class="ca-pill ca-hot">🔥 HOT</span>
                          <?php elseif ($r['heat'] === 'up'): ?>
                            <span class="ca-pill"
                              title="出場率變化：<?= $r['delta'] >= 0 ? '+' : '' ?><?= $r['delta'] ?>%">
                              ▲ 上升
                            </span>

                          <?php elseif ($r['heat'] === 'down'): ?>
                            <span class="ca-pill" style="color:#f87171;">▼ 下降</span>

                          <?php else: ?>
                            <span class="ca-pill muted">
                              <?= $r['delta'] >= 0 ? '+' : '' ?><?= $r['delta'] ?>%
                            </span>
                          <?php endif; ?>
                        </td>

                        <td>
                          <?php if ($r['sample_low']): ?>
                            <span class="ca-pill low">偏低</span>
                          <?php else: ?>
                            <span class="ca-pill">充足</span>
                          <?php endif; ?>
                        </td>

                        <td>
                          <a class="btn-ca"
                            href="/pages/character.php?char_base=<?= htmlspecialchars($r['char_key']) ?>&days=<?= $DAYS ?>">
                            查看分析
                          </a>
                        </td>
                        <td style="display:none;"><?= $r['recommend_score'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>

                </table>
              </div>

            </div>
          </div>

        </div>
        <!-- /.col -->

      </div>
      <!-- /.row -->

    </div>
    <!-- /.container -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<!-- DataTables CSS -->
<link rel="stylesheet"
  href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- jQuery（如果你站上已經有，就不用再加） -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>


<script>
  $(function() {
    const table = $('#caTable').DataTable({
      pageLength: 15,
      lengthChange: false,
      order: [
        [7, 'desc']
      ], // hidden 的推薦分數

      pagingType: 'simple_numbers',
      language: {
        search: '',
        zeroRecords: '找不到符合的角色',
        info: '顯示 _START_–_END_ / 共 _TOTAL_ 名角色',
        paginate: {
          previous: '‹',
          next: '›'
        }
      },
      columnDefs: [{
          targets: [7],
          visible: false
        },
        {
          orderable: false,
          targets: [4, 5, 6]
        },
        {
          targets: [5, 6], // 樣本、操作
          visible: window.innerWidth > 576
        }
      ]

    });
    $(window).on('resize', function() {
      const isMobile = window.innerWidth <= 576;
      table.column(5).visible(!isMobile);
      table.column(6).visible(!isMobile);
    });

    // 搜尋
    $('#caSearch').on('keyup', function() {
      table.search(this.value).draw();
    });

    // 排序選單
    $('#caSort').on('change', function() {
      switch (this.value) {
        case 'wr_desc':
          table.order([3, 'desc']).draw();
          break;
        case 'games_desc':
          table.order([1, 'desc']).draw();
          break;
        case 'pick_desc':
          table.order([2, 'desc']).draw();
          break;
        default:
          table.draw();

      }
    });
  });
</script>

<!-- Swipe → 換頁 -->
<script>
  (function() {
    const tableWrap = document.querySelector('.ca-table-scroll');
    if (!tableWrap) return;

    let startX = 0;
    let startY = 0;

    tableWrap.addEventListener('touchstart', function(e) {
      const t = e.touches[0];
      startX = t.clientX;
      startY = t.clientY;
    }, {
      passive: true
    });

    tableWrap.addEventListener('touchend', function(e) {
      const t = e.changedTouches[0];
      const dx = t.clientX - startX;
      const dy = t.clientY - startY;

      // 避免上下滑誤判
      if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy)) return;

      const api = $('#caTable').DataTable();
      const pageInfo = api.page.info();
      let changed = false;

      if (dx < 0 && pageInfo.page < pageInfo.pages - 1) {
        // 👈 左滑 → 下一頁
        api.page('next').draw('page');
        changed = true;
      } else if (dx > 0 && pageInfo.page > 0) {
        // 👉 右滑 → 上一頁
        api.page('previous').draw('page');
        changed = true;
      }

      // ✅ 只有「真的換頁成功」才震動
      if (changed && navigator.vibrate) {
        navigator.vibrate(15);
      }
    }, {
      passive: true
    });
  })();
</script>



<script>
  /* =========================
     Chart.js Pie (data later)
  ========================= */

  // 之後你會從 PHP 帶入：
  // const pieLabels = <?= json_encode($pieLabels) ?>;
  // const pieRates  = <?= json_encode($pieRates) ?>;
  // const pieKeys   = <?= json_encode($pieKeys) ?>;

  const pieLabels = <?= json_encode($pieLabels, JSON_UNESCAPED_UNICODE) ?>;
  const pieRates = <?= json_encode($pieRates, JSON_NUMERIC_CHECK) ?>;
  const pieKeys = <?= json_encode($pieKeys, JSON_UNESCAPED_UNICODE) ?>;

  const ctxPie = document.getElementById('chart-character-pie');
  const pieIcos = <?= json_encode($pieIcos) ?>;
  const cardWrap = document.getElementById('pieCards');
  const rect = cardWrap.parentElement.getBoundingClientRect();
  const CENTER_X = rect.width / 2 - 50;
  const CENTER_Y = rect.height / 2;
  const RADIUS = Math.min(rect.width, rect.height) / 2 - 40;
  if (pieIcos.length && cardWrap) {
    pieIcos.forEach((src, i) => {
      // 取得目前頁面的 days（沒有就給預設）
      const urlParams = new URLSearchParams(window.location.search);
      const CURRENT_DAYS = urlParams.get('days') || '30'; // ⭐ 預設天數自行調整
      const angle = (2 * Math.PI / pieIcos.length) * i - Math.PI / 2;

      const x = CENTER_X + RADIUS * Math.cos(angle) - 20;
      const y = CENTER_Y + RADIUS * Math.sin(angle) - 39;

      const card = document.createElement('div');
      card.className = 'ca-pie-card';
      card.style.left = `${x}px`;
      card.style.top = `${y}px`;

      card.innerHTML = `<img src="${src}">`;

      card.onclick = () => {
        const key = pieKeys[i];
        if (key && key !== 'others') {
          location.href =
            `/pages/character.php?char_base=${encodeURIComponent(key)}&days=${CURRENT_DAYS}`;
        }
      };

      cardWrap.appendChild(card);
    });
  }

  const pieColors = [
    'rgba(110, 120, 255, 0.78)',
    'rgba(255, 118, 118, 0.76)',
    'rgba(246, 196,  83, 0.78)',
    'rgba(102, 187, 106, 0.72)',
    'rgba(171, 136, 255, 0.74)',
    'rgba(38, 198, 218, 0.72)',
    'rgba(255, 167, 38, 0.72)',
    'rgba(189, 189, 189, 0.65)',
    'rgba(120, 130, 150, 0.45)'
  ];
  if (ctxPie && window.Chart) {
    new Chart(ctxPie, {
      type: 'pie',
      data: {
        labels: pieLabels,
        datasets: [{
          data: pieRates,
          borderWidth: 1,
          borderColor: 'rgba(0,0,0,0.55)',
          backgroundColor: pieColors.slice(0, pieRates.length),
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'right',
            labels: {
              color: '#e5e7eb',
              boxWidth: 14
            }
          },
          tooltip: {
            callbacks: {
              label: function(c) {
                return `${c.label}: ${c.parsed}%`;
              }
            }
          }
        },
        onClick: function(evt, elements) {
          if (!elements || elements.length === 0) return;
          const idx = elements[0].index;
          const key = pieKeys[idx] || null;
          if (!key || key === 'others') return;
          location.href =
            `/pages/character.php?char_base=${encodeURIComponent(key)}&days=${CURRENT_DAYS}`;
        }
      }
    });
  }



  /* =========================
     Sort (MVP placeholder)
     - 之後接你真正的排序欄位 data-*
  ========================= */
  const caSort = document.getElementById('caSort');
  if (caSort && caTable) {
    caSort.addEventListener('change', () => {
      // TODO: 第二步再做（或直接用 DataTables）
      // 入口頁 v1 可先不做真正排序，只做 UI（避免返工）
      // 你若要，我下一步可幫你補完整排序 JS（依 data-games / data-wr）
    });
  }
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