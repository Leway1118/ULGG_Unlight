<?php
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../config.php';
$pdo = $db;

$pageTitleText = 'QP 玩家活躍詳情';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "qp_analysis";

ob_start();

function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function n($v)
{
  return number_format((int)$v);
}
function pct($v)
{
  return number_format((float)$v, 2);
}
function fetch_all_safe(PDO $pdo, string $sql, array $params = []): array
{
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}
function fetch_one_safe(PDO $pdo, string $sql, array $params = []): ?array
{
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}
function fetch_col_safe(PDO $pdo, string $sql, array $params = [])
{
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
  } catch (Throwable $e) {
    return null;
  }
}
function table_exists_safe(PDO $pdo, string $table): bool
{
  try {
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name = :table
    ");
    $stmt->execute([':table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$server = strtoupper($_GET['server'] ?? 'TW');
if (!in_array($server, ['TW', 'JP'], true)) $server = 'TW';

// TW_QP_PLAYER_NO_LEVEL_20260930_V2
// Protocol 1.4 TW ranking does not provide player level.
// JP historical data may still contain valid level.
$showPlayerLevel = ($server !== 'TW');

$playerName = trim($_GET['name'] ?? '');
$qpTable = "ranking_qp_{$server}";
$qpHisTable = "ranking_qp_{$server}_history";
$hasQpHistoryTable = table_exists_safe($pdo, $qpHisTable);

$latestTs = fetch_col_safe($pdo, "SELECT MAX(ts) FROM `$qpTable`");

$todayBaseTs = $latestTs ? fetch_col_safe($pdo, "
  SELECT MIN(ts)
  FROM `$qpTable`
  WHERE DATE(ts) = DATE(?)
", [$latestTs]) : null;

$prevTs = $latestTs ? fetch_col_safe($pdo, "
  SELECT MAX(ts)
  FROM `$qpTable`
  WHERE ts < ?
", [$latestTs]) : null;

$compareTs = ($todayBaseTs && $todayBaseTs !== $latestTs) ? $todayBaseTs : $prevTs;
$compareModeText = ($todayBaseTs && $todayBaseTs !== $latestTs) ? '今日' : '前次快照';

$current = null;
$daily = null;
$dailyStat = null;
$growthRank = null;
$riseRank = null;
$nearby = [];
$nextGap = null;
$recent = [];
$trendSourceText = '本月 QP';
$seasonStartTs = null;
$isNewEntry = false;
$seasonHistory = [];
$historyBestRank = null;
$historyTop5Count = 0;
$historyTop30Count = 0;

if ($playerName !== '' && $latestTs) {
  $current = fetch_one_safe($pdo, "
    SELECT rank_num, name, level, qp, ts
    FROM `$qpTable`
    WHERE ts = ? AND name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
    LIMIT 1
  ", [$latestTs, $playerName]);

  if ($current && $compareTs) {
    $daily = fetch_one_safe($pdo, "
      SELECT c.name, c.level, p.ts AS old_ts, c.ts AS now_ts,
        p.rank_num AS old_rank, c.rank_num AS now_rank,
        p.qp AS old_qp, c.qp AS now_qp,
        c.qp - p.qp AS qp_gain,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$qpTable` c
      JOIN `$qpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ?
      AND c.name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      LIMIT 1
      ", [$compareTs, $latestTs, $playerName]);

    $dailyStat = fetch_one_safe($pdo, "
      SELECT COUNT(*) AS active_players,
        COALESCE(SUM(c.qp - p.qp), 0) AS total_qp_gain,
        MAX(c.qp - p.qp) AS max_qp_gain,
        ROUND(AVG(c.qp - p.qp), 1) AS avg_qp_gain
      FROM `$qpTable` c
      JOIN `$qpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ? AND c.qp > p.qp
    ", [$compareTs, $latestTs]);

    $growthRows = fetch_all_safe($pdo, "
      SELECT c.name, c.rank_num AS now_rank, p.rank_num AS old_rank,
        c.qp AS now_qp, p.qp AS old_qp,
        c.qp - p.qp AS qp_gain,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$qpTable` c
      JOIN `$qpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ? AND c.qp > p.qp
      ORDER BY qp_gain DESC, c.rank_num ASC
    ", [$compareTs, $latestTs]);

    foreach ($growthRows as $idx => $row) {
      if ($row['name'] === $current['name']) {
        $growthRank = $idx + 1;
        break;
      }
    }

    $riseRows = fetch_all_safe($pdo, "
      SELECT c.name, c.rank_num AS now_rank, p.rank_num AS old_rank,
        c.qp AS now_qp, p.qp AS old_qp,
        c.qp - p.qp AS qp_gain,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$qpTable` c
      JOIN `$qpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ?
      AND CAST(p.rank_num AS SIGNED) > CAST(c.rank_num AS SIGNED)
      ORDER BY rank_rise DESC, qp_gain DESC
    ", [$compareTs, $latestTs]);

    foreach ($riseRows as $idx => $row) {
      if ($row['name'] === $current['name']) {
        $riseRank = $idx + 1;
        break;
      }
    }

    $newCheck = fetch_one_safe($pdo, "
      SELECT p.name
      FROM `$qpTable` p
      WHERE p.ts = ?
      AND p.name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      LIMIT 1
    ", [$compareTs, $playerName]);
    $isNewEntry = !$newCheck;
  }

  $nearby = fetch_all_safe($pdo, "
    SELECT r.rank_num, r.name, r.level, r.qp, r.qp - target.qp AS qp_diff
    FROM `$qpTable` r
    JOIN (
      SELECT rank_num, qp
      FROM `$qpTable`
      WHERE ts = ? AND name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      LIMIT 1
    ) target
    WHERE r.ts = ?
    AND r.rank_num BETWEEN target.rank_num - 5 AND target.rank_num + 5
    ORDER BY r.rank_num ASC
  ", [$latestTs, $playerName, $latestTs]);

  $nextGap = fetch_one_safe($pdo, "
    SELECT target.rank_num AS target_rank, target.name AS target_name, target.qp AS target_qp,
      upper_player.rank_num AS upper_rank, upper_player.name AS upper_name, upper_player.qp AS upper_qp,
      upper_player.qp - target.qp AS qp_to_next_rank
    FROM (
      SELECT rank_num, name, qp
      FROM `$qpTable`
      WHERE ts = ? AND name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      LIMIT 1
    ) target
    LEFT JOIN `$qpTable` upper_player
      ON upper_player.ts = ?
     AND upper_player.rank_num = target.rank_num - 1
    LIMIT 1
  ", [$latestTs, $playerName, $latestTs]);

  $recent = [];
  $trendSourceText = '本月 QP';

  if ($hasQpHistoryTable) {
    $seasonStartTs = fetch_col_safe($pdo, "
    SELECT MAX(ts)
    FROM `$qpHisTable`
  ");
  }

  if ($seasonStartTs) {
    // 只撈月結後，也就是本月/本賽季的 QP 快照
    $recent = fetch_all_safe($pdo, "
    SELECT
      ts,
      rank_num,
      level,
      qp,
      'snapshot' AS node_type
    FROM `$qpTable`
    WHERE name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      AND ts > ?
    ORDER BY ts DESC
    LIMIT 11
  ", [$playerName, $seasonStartTs]);

    // 有本月資料時，補一個 QP 0 起點
    if (!empty($recent)) {
      $oldestSeasonRow = $recent[count($recent) - 1];

      $recent[] = [
        'ts' => $seasonStartTs,
        'rank_num' => $oldestSeasonRow['rank_num'],
        'level' => $oldestSeasonRow['level'],
        'qp' => 0,
        'node_type' => 'season_start',
      ];
    }

    $trendSourceText = '本月 QP';
  } else {
    // 沒有 history 表或沒有月結資料時，退回原本即時快照
    $recent = fetch_all_safe($pdo, "
    SELECT
      ts,
      rank_num,
      level,
      qp,
      'snapshot' AS node_type
    FROM `$qpTable`
    WHERE name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
    ORDER BY ts DESC
    LIMIT 12
  ", [$playerName]);

    $trendSourceText = '即時快照';
  }
}


/* PLAYER_SEASON_HISTORY_20260930
 * 歷屆月結：沿用 rank 頁既有 history 表，依每個 ts 重新計算當期最終名次。
 */
if ($playerName !== '' && $hasQpHistoryTable) {
  $seasonHistory = fetch_all_safe($pdo, "
    WITH ranked_history AS (
      SELECT
        ts,
        name,
        level,
        qp,
        ROW_NUMBER() OVER (
          PARTITION BY ts
          ORDER BY qp DESC, name ASC
        ) AS final_rank
      FROM `$qpHisTable`
      WHERE name IS NOT NULL
        AND TRIM(name) <> ''
        AND qp IS NOT NULL
    )
    SELECT ts, final_rank, level, qp
    FROM ranked_history
    WHERE name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
    ORDER BY ts DESC
  ", [$playerName]);

  if (!empty($seasonHistory)) {
    $historyRanks = array_map(fn($r) => (int)$r['final_rank'], $seasonHistory);
    $historyBestRank = min($historyRanks);
    foreach ($historyRanks as $r) {
      if ($r <= 5) $historyTop5Count++;
      if ($r <= 30) $historyTop30Count++;
    }
  }
}

$gainShare = 0;
$timesOfAvg = 0;
if ($daily && $dailyStat) {
  $gainShare = ((int)$dailyStat['total_qp_gain'] > 0) ? ((int)$daily['qp_gain'] / (int)$dailyStat['total_qp_gain'] * 100) : 0;
  $timesOfAvg = ((float)$dailyStat['avg_qp_gain'] > 0) ? ((int)$daily['qp_gain'] / (float)$dailyStat['avg_qp_gain']) : 0;
}
$trendRows = array_reverse($recent);
$trendPoints = '';
$rankPoints = '';
$trendLabels = [];
$chartW = 900;
$chartH = 260;
$padX = 42;
$padY = 34;

if (!empty($trendRows)) {
  $qpValues = array_map(fn($r) => (int)$r['qp'], $trendRows);
  $rankValues = array_map(fn($r) => (int)$r['rank_num'], $trendRows);
  $minQp = min($qpValues);
  $maxQp = max($qpValues);
  $minRank = min($rankValues);
  $maxRank = max($rankValues);
  $countTrend = count($trendRows);
  $usableW = $chartW - $padX * 2;
  $usableH = $chartH - $padY * 2;

  foreach ($trendRows as $idx => $r) {
    $x = $countTrend <= 1 ? $padX + $usableW / 2 : $padX + ($idx / ($countTrend - 1)) * $usableW;

    $qpRange = max(1, $maxQp - $minQp);
    $qpRatio = ((int)$r['qp'] - $minQp) / $qpRange;
    $yQp = $chartH - $padY - ($qpRatio * $usableH);
    $trendPoints .= round($x, 2) . ',' . round($yQp, 2) . ' ';

    $rankRange = max(1, $maxRank - $minRank);
    $rankRatio = ($maxRank - (int)$r['rank_num']) / $rankRange;
    $yRank = $chartH - $padY - ($rankRatio * $usableH);
    $rankPoints .= round($x, 2) . ',' . round($yRank, 2) . ' ';

    $trendLabels[] = [
      'x' => round($x, 2),
      'qp_y' => round($yQp, 2),
      'rank_y' => round($yRank, 2),
      'ts' => $r['ts'],
      'rank' => (int)$r['rank_num'],
      'qp' => (int)$r['qp'],
      'node_type' => $r['node_type'] ?? 'snapshot',
    ];
  }
}
?>

<style>
  /* =========================
     QP Player Detail — match qp_rank dark style
  ========================= */
  .qpp-page {
    padding: 18px 0 32px;
  }

  .qpp-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .qpp-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid rgba(255, 255, 255, .18);
    background: #1f2937;
    color: #dbeafe;
    border-radius: 999px;
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
  }

  .qpp-back:hover {
    background: #2563eb;
    color: #fff;
    text-decoration: none;
    border-color: #60a5fa;
  }

  .qpp-server {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .qpp-server a {
    display: inline-block;
    padding: 7px 13px;
    border-radius: 999px;
    background: #1f2937;
    color: #dbeafe;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    border: 1px solid rgba(255, 255, 255, .18);
  }

  .qpp-server a.active {
    background: #2563eb;
    color: #fff;
    border-color: #60a5fa;
  }

  .qpp-server a:hover {
    color: #fff;
    text-decoration: none;
    border-color: #60a5fa;
  }

  .qpp-hero {
    background: linear-gradient(135deg, #111827, #24395f);
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 16px;
    padding: 18px;
    color: #fff;
    margin-bottom: 14px;
    box-shadow: 0 8px 22px rgba(0, 0, 0, .22);
  }

  .qpp-label {
    display: inline-flex;
    border-radius: 999px;
    background: rgba(37, 99, 235, .35);
    border: 1px solid rgba(147, 197, 253, .3);
    color: #dbeafe;
    padding: 6px 11px;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 10px;
  }

  .qpp-title {
    font-size: 26px;
    font-weight: 900;
    margin-bottom: 6px;
    color: #fff;
    letter-spacing: .03em;
  }

  .qpp-sub {
    color: rgba(255, 255, 255, .76);
    font-size: 13px;
    line-height: 1.7;
  }

  .qpp-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
  }

  .qpp-pill {
    display: inline-flex;
    border-radius: 999px;
    padding: 7px 12px;
    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .12);
    color: #e5e7eb;
    font-size: 13px;
  }

  .qpp-note-box {
    background: #0f172a;
    border: 1px solid rgba(96, 165, 250, .28);
    border-radius: 14px;
    padding: 14px;
    color: #cbd5e1;
    font-size: 13px;
    line-height: 1.8;
    margin-bottom: 12px;
    box-shadow: 0 6px 18px rgba(20, 30, 50, .08);
  }

  .qpp-note-box strong {
    color: #fff;
    font-weight: 900;
  }

  .qpp-highlight {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
  }

  .qpp-focus {
    background: linear-gradient(135deg, #0f172a, #1e293b);
    border: 1px solid rgba(96, 165, 250, .28);
    border-radius: 14px;
    padding: 14px;
    color: #e5e7eb;
    box-shadow: 0 6px 18px rgba(20, 30, 50, .08);
  }

  .qpp-focus-title {
    color: #93c5fd;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .qpp-focus-main {
    font-size: 26px;
    font-weight: 900;
    color: #86efac;
    margin-bottom: 5px;
    line-height: 1.1;
  }

  .qpp-focus-sub {
    color: #cbd5e1;
    font-size: 12px;
    line-height: 1.7;
  }

  .qpp-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
  }

  .qpp-card {
    background: #111827;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 12px;
    padding: 12px;
    color: #fff;
    box-shadow: none;
  }

  .qpp-card-label {
    color: #9ca3af;
    font-size: 12px;
    margin-bottom: 6px;
  }

  .qpp-card-value {
    font-size: 21px;
    font-weight: 900;
    line-height: 1.1;
    color: #fff;
  }

  .qpp-card-note {
    color: #94a3b8;
    font-size: 11px;
    margin-top: 6px;
    line-height: 1.5;
  }

  .qpp-section {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 14px;
    padding: 14px;
    color: #e5e7eb;
    margin-bottom: 12px;
    box-shadow: none;
  }

  .qpp-section-title {
    font-weight: 900;
    font-size: 16px;
    color: #fff;
    margin: 0 0 6px;
  }

  .qpp-section-desc {
    color: #94a3b8;
    font-size: 12px;
    margin-bottom: 10px;
  }

  .qpp-table-wrap {
    width: 100%;
    overflow-x: auto;
  }

  .qpp-table {
    width: 100%;
    border-collapse: collapse;
  }

  .qpp-table th {
    background: #1f2937;
    color: #cbd5e1;
    font-size: 12px;
    text-align: left;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    white-space: nowrap;
  }

  .qpp-table td {
    color: #e5e7eb;
    font-size: 12px;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    white-space: nowrap;
  }

  .qpp-table tbody tr:hover {
    background: rgba(255, 255, 255, .04);
  }

  .qpp-name {
    font-weight: 900;
    color: #fff;
  }

  .qpp-self {
    background: rgba(37, 99, 235, .18) !important;
  }

  .qpp-self .qpp-name {
    color: #bfdbfe;
  }

  .qpp-up {
    color: #86efac;
    font-weight: 900;
  }

  .qpp-gain {
    color: #93c5fd;
    font-weight: 900;
  }

  .qpp-danger {
    color: #fca5a5;
    font-weight: 900;
  }

  .qpp-empty {
    padding: 18px;
    text-align: center;
    color: #94a3b8;
    background: rgba(255, 255, 255, .04);
    border-radius: 10px;
  }

  @media (max-width:1199px) {
    .qpp-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width:767px) {
    .qpp-topbar {
      align-items: flex-start;
      flex-direction: column;
    }

    .qpp-grid,
    .qpp-highlight {
      grid-template-columns: 1fr;
    }

    .qpp-table {
      min-width: 650px;
    }

    .qpp-title {
      font-size: 23px;
    }
  }

  .qpp-trend-card {
    background: rgba(255, 255, 255, .04);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 18px;
    padding: 16px;
    overflow: hidden;
  }

  .qpp-trend-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }

  .qpp-trend-title {
    font-size: 15px;
    font-weight: 900;
    color: #e5e7eb;
  }

  .qpp-trend-sub {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 4px;
    line-height: 1.5;
  }

  .qpp-trend-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 12px;
    color: #cbd5e1;
  }

  .qpp-dot {
    display: inline-block;
    width: 9px;
    height: 9px;
    border-radius: 999px;
    margin-right: 5px;
    vertical-align: middle;
  }

  .qpp-dot.qp {
    background: #60a5fa;
  }

  .qpp-dot.rank {
    background: #34d399;
  }

  .qpp-chart-wrap {
    width: 100%;
    overflow-x: auto;
  }

  .qpp-chart {
    width: 100%;
    min-width: 760px;
    height: auto;
    display: block;
  }

  .qpp-axis {
    stroke: rgba(148, 163, 184, .28);
    stroke-width: 1;
  }

  .qpp-grid-line {
    stroke: rgba(148, 163, 184, .12);
    stroke-width: 1;
  }

  .qpp-line-qp {
    fill: none;
    stroke: #60a5fa;
    stroke-width: 4;
    stroke-linecap: round;
    stroke-linejoin: round;
  }

  .qpp-line-rank {
    fill: none;
    stroke: #34d399;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 7 7;
  }

  .qpp-point-qp {
    fill: #60a5fa;
    stroke: #0f172a;
    stroke-width: 2;
  }

  .qpp-point-rank {
    fill: #34d399;
    stroke: #0f172a;
    stroke-width: 2;
  }

  .qpp-chart-text {
    fill: #94a3b8;
    font-size: 12px;
    font-weight: 700;
  }

  .qpp-chart-value {
    fill: #e5e7eb;
    font-size: 12px;
    font-weight: 900;
  }

  .qpp-trend-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
  }

  .qpp-trend-mini {
    background: rgba(15, 23, 42, .55);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    padding: 12px;
  }

  .qpp-trend-mini-label {
    font-size: 12px;
    color: #94a3b8;
    margin-bottom: 6px;
  }

  .qpp-trend-mini-value {
    font-size: 18px;
    font-weight: 900;
    color: #e5e7eb;
  }

  @media (max-width:991px) {
    .qpp-trend-summary {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width:575px) {
    .qpp-trend-head {
      flex-direction: column;
    }

    .qpp-trend-summary {
      grid-template-columns: 1fr;
    }
  }

  .qpp-tooltip {
    position: fixed;
    z-index: 9999;
    pointer-events: none;
    background: rgba(15, 23, 42, .96);
    color: #e5e7eb;
    border: 1px solid rgba(255, 255, 255, .14);
    border-radius: 12px;
    padding: 10px 12px;
    font-size: 12px;
    line-height: 1.6;
    box-shadow: 0 10px 28px rgba(0, 0, 0, .35);
    transform: translate(12px, 12px);
    display: none;
    min-width: 160px;
  }

  .qpp-tooltip strong {
    color: #fff;
    font-size: 13px;
  }

  .qpp-chart-point {
    cursor: pointer;
  }

  .qpp-chart-point:hover {
    filter: drop-shadow(0 0 6px rgba(96, 165, 250, .9));
  }

  .qpp-title-link {
    color: inherit;
    text-decoration: none;
  }

  .qpp-title-link:hover {
    color: #bfdbfe;
    text-decoration: underline;
  }

  /* =========================
     PLAYER_HISTORY_20260930
  ========================= */
  .qpp-history-summary {
    color: #94a3b8;
    font-size: 12px;
    margin-bottom: 10px;
    line-height: 1.7;
  }

  .qpp-history-rank {
    font-weight: 900;
    color: #fff;
  }

  .qpp-tier-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
  }

  .qpp-tier-top5 {
    color: #a7f3d0;
    background: rgba(52, 211, 153, .09);
    border: 1px solid rgba(52, 211, 153, .24);
  }

  .qpp-tier-top30 {
    color: #bfdbfe;
    background: rgba(96, 165, 250, .09);
    border: 1px solid rgba(96, 165, 250, .23);
  }

  .qpp-tier-other {
    color: #94a3b8;
    background: rgba(148, 163, 184, .06);
    border: 1px solid rgba(148, 163, 184, .14);
  }

  .qpp-history-more {
    margin-top: 10px;
    border-top: 1px solid rgba(255, 255, 255, .08);
    padding-top: 10px;
  }

  .qpp-history-more > summary {
    cursor: pointer;
    color: #c4b5fd;
    font-size: 12px;
    font-weight: 800;
  }

</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container qpp-page">
      <div class="qpp-topbar">
        <a class="qpp-back" href="/pages/qp_rank.php?server=<?= h($server) ?>">← 返回 QP 分析</a>
        <div class="qpp-server">
          <a href="?server=TW&name=<?= urlencode($playerName) ?>" class="<?= $server === 'TW' ? 'active' : '' ?>">TW 台服</a>
          <a href="?server=JP&name=<?= urlencode($playerName) ?>" class="<?= $server === 'JP' ? 'active' : '' ?>">JP 日服</a>
        </div>
      </div>

      <?php if ($playerName === ''): ?>
        <div class="qpp-section">
          <div class="qpp-empty">缺少玩家名稱，請使用 /pages/qp_player.php?server=TW&name=玩家名稱</div>
        </div>
      <?php elseif (!$current): ?>
        <div class="qpp-section">
          <div class="qpp-empty">目前最新 QP 榜找不到「<?= h($playerName) ?>」。</div>
        </div>
      <?php else: ?>
        <div class="qpp-hero">
          <div class="qpp-label"><?= h($server) ?> QP 活躍詳情</div>
          <?php
          $fightUrl = '/pages/fight.php?' . http_build_query([
            'player_name' => $current['name'],
            'server' => 'ALL',
          ], '', '&', PHP_QUERY_RFC3986);
          ?>
          <div class="qpp-title">
            <a href="<?= h($fightUrl) ?>" class="qpp-title-link">
              <?= h($current['name']) ?>
            </a>
          </div>
          <!-- <div class="qpp-sub">
            這裡觀察的是玩家 QP 活躍度與近期投入程度，不代表對戰強度。重點是本次更新中玩家 QP、排名與全榜活躍狀況的相對變化。
          </div> -->
          <!-- <div class="qpp-meta">
            <span class="qpp-pill">目前排名 #<?= (int)$current['rank_num'] ?></span>
            <span class="qpp-pill">目前 QP <?= n($current['qp']) ?></span>
            <?php if ($showPlayerLevel): ?><span class="qpp-pill">Lv <?= (int)$current['level'] ?></span><?php endif; ?>
            <span class="qpp-pill">最新更新 <?= h($current['ts']) ?></span>
            <?php if (!empty($compareTs)): ?>
              <span class="qpp-pill">比較基準 <?= h($compareTs) ?> → <?= h($latestTs) ?></span>
            <?php endif; ?>
          </div> -->
        </div>

        <div class="qpp-section">
          <h2 class="qpp-section-title">歷屆名次</h2>
          <div class="qpp-history-summary">
            <?php if (empty($seasonHistory)): ?>
              尚無月結歷史資料。
            <?php else: ?>
              共 <?= n(count($seasonHistory)) ?> 期
              <?php if ($historyBestRank !== null): ?>｜最佳 #<?= (int)$historyBestRank ?><?php endif; ?>
              ｜Top 5 <?= n($historyTop5Count) ?> 次
              ｜Top 30 <?= n($historyTop30Count) ?> 次
            <?php endif; ?>
          </div>

          <?php if (!empty($seasonHistory)): ?>
            <?php $historyRecent = array_slice($seasonHistory, 0, 10); ?>
            <div class="qpp-table-wrap">
              <table class="qpp-table">
                <thead>
                  <tr>
                    <th>結算日</th>
                    <th>最終名次</th>
                    <th>QP</th>
                    <?php if ($showPlayerLevel): ?><th>Level</th><?php endif; ?>
                    <th>獎勵區</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($historyRecent as $r): ?>
                    <?php
                      $historyRank = (int)$r['final_rank'];
                      $tierClass = $historyRank <= 5 ? 'qpp-tier-top5' : ($historyRank <= 30 ? 'qpp-tier-top30' : 'qpp-tier-other');
                      $tierText = $historyRank <= 5 ? 'TOP 5' : ($historyRank <= 30 ? 'TOP 30' : '#31+');
                    ?>
                    <tr>
                      <td title="<?= h($r['ts']) ?>"><?= h(date('Y-m-d', strtotime($r['ts']))) ?></td>
                      <td class="qpp-history-rank">#<?= $historyRank ?></td>
                      <td><?= n($r['qp']) ?></td>
                      <?php if ($showPlayerLevel): ?><td>Lv <?= (int)$r['level'] ?></td><?php endif; ?>
                      <td><span class="qpp-tier-badge <?= h($tierClass) ?>"><?= h($tierText) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php if (count($seasonHistory) > 10): ?>
              <details class="qpp-history-more">
                <summary>查看更早 <?= n(count($seasonHistory) - 10) ?> 期</summary>
                <div class="qpp-table-wrap" style="margin-top:10px;">
                  <table class="qpp-table">
                    <thead>
                      <tr>
                        <th>結算日</th>
                        <th>最終名次</th>
                        <th>QP</th>
                        <?php if ($showPlayerLevel): ?><th>Level</th><?php endif; ?>
                        <th>獎勵區</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach (array_slice($seasonHistory, 10) as $r): ?>
                        <?php
                          $historyRank = (int)$r['final_rank'];
                          $tierClass = $historyRank <= 5 ? 'qpp-tier-top5' : ($historyRank <= 30 ? 'qpp-tier-top30' : 'qpp-tier-other');
                          $tierText = $historyRank <= 5 ? 'TOP 5' : ($historyRank <= 30 ? 'TOP 30' : '#31+');
                        ?>
                        <tr>
                          <td title="<?= h($r['ts']) ?>"><?= h(date('Y-m-d', strtotime($r['ts']))) ?></td>
                          <td class="qpp-history-rank">#<?= $historyRank ?></td>
                          <td><?= n($r['qp']) ?></td>
                          <?php if ($showPlayerLevel): ?><td>Lv <?= (int)$r['level'] ?></td><?php endif; ?>
                          <td><span class="qpp-tier-badge <?= h($tierClass) ?>"><?= h($tierText) ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </details>
            <?php endif; ?>
          <?php else: ?>
            <div class="qpp-empty">這名玩家目前沒有可用的歷屆月結資料。</div>
          <?php endif; ?>
        </div>

        <div class="qpp-section">
          <h2 class="qpp-section-title">目前排名附近</h2>
          <div class="qpp-section-desc">觀察玩家目前前後排名與 QP 差距。</div>
          <?php if (empty($nearby)): ?>
            <div class="qpp-empty">沒有附近排名資料。</div>
          <?php else: ?>
            <div class="qpp-table-wrap">
              <table class="qpp-table">
                <thead>
                  <tr>
                    <th>排名</th>
                    <th>玩家</th>
                    <?php if ($showPlayerLevel): ?><th>Level</th><?php endif; ?>
                    <th>QP</th>
                    <th>與此玩家差距</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($nearby as $r): ?>
                    <tr class="<?= $r['name'] === $current['name'] ? 'qpp-self' : '' ?>">
                      <td>#<?= (int)$r['rank_num'] ?></td>
                      <td class="qpp-name"><?= h($r['name']) ?></td>
                      <?php if ($showPlayerLevel): ?><td>Lv <?= (int)$r['level'] ?></td><?php endif; ?>
                      <td><?= n($r['qp']) ?></td>
                      <td>
                        <?php if ((int)$r['qp_diff'] > 0): ?>
                          <span class="qpp-danger">+<?= n($r['qp_diff']) ?></span>
                        <?php elseif ((int)$r['qp_diff'] < 0): ?>
                          <span class="qpp-up"><?= n($r['qp_diff']) ?></span>
                        <?php else: ?>
                          0
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="qpp-section">
          <h2 class="qpp-section-title">QP / 排名趨勢</h2>
          <div class="qpp-section-desc">
            顯示此玩家本月 QP 與排名變化。趨勢從月結後的 QP 0 作為起點開始，避免跨月結算後重新累積被誤判為掉分。
            藍線為 QP，綠色虛線為排名表現，排名越高線越往上。
          </div>
          <?php if (empty($trendRows)): ?>
            <div class="qpp-empty">目前沒有本月 QP 快照資料。</div>
          <?php else: ?>
            <?php
            $firstTrend = $trendRows[0];
            $lastTrend = $trendRows[count($trendRows) - 1];
            $trendQpGain = (int)$lastTrend['qp'] - (int)$firstTrend['qp'];
            $trendRankRise = (int)$firstTrend['rank_num'] - (int)$lastTrend['rank_num'];
            ?>

            <div class="qpp-trend-card">
              <div class="qpp-trend-head">
                <div>
                  <div class="qpp-trend-title"><?= h($current['name']) ?> <?= h($trendSourceText) ?>趨勢</div>
                  <div class="qpp-trend-sub">
                    <?= h($firstTrend['ts']) ?> → <?= h($lastTrend['ts']) ?>
                  </div>
                </div>
                <div class="qpp-trend-legend">
                  <span><i class="qpp-dot qp"></i>QP</span>
                  <span><i class="qpp-dot rank"></i>排名</span>
                </div>
              </div>

              <div class="qpp-chart-wrap">
                <svg class="qpp-chart" viewBox="0 0 <?= (int)$chartW ?> <?= (int)$chartH ?>" role="img" aria-label="QP ranking trend chart">
                  <line class="qpp-axis" x1="<?= (int)$padX ?>" y1="<?= (int)($chartH - $padY) ?>" x2="<?= (int)($chartW - $padX) ?>" y2="<?= (int)($chartH - $padY) ?>"></line>
                  <line class="qpp-axis" x1="<?= (int)$padX ?>" y1="<?= (int)$padY ?>" x2="<?= (int)$padX ?>" y2="<?= (int)($chartH - $padY) ?>"></line>

                  <?php for ($g = 1; $g <= 4; $g++): ?>
                    <?php $gy = $padY + (($chartH - $padY * 2) / 4) * $g; ?>
                    <line class="qpp-grid-line" x1="<?= (int)$padX ?>" y1="<?= round($gy, 2) ?>" x2="<?= (int)($chartW - $padX) ?>" y2="<?= round($gy, 2) ?>"></line>
                  <?php endfor; ?>

                  <polyline class="qpp-line-qp" points="<?= h(trim($trendPoints)) ?>"></polyline>
                  <polyline class="qpp-line-rank" points="<?= h(trim($rankPoints)) ?>"></polyline>

                  <?php foreach ($trendLabels as $idx => $p): ?>
                    <circle
                      class="qpp-point-qp qpp-chart-point"
                      cx="<?= $p['x'] ?>"
                      cy="<?= $p['qp_y'] ?>"
                      r="<?= (($p['node_type'] ?? '') === 'season_start') ? 6 : ($idx === count($trendLabels) - 1 ? 6 : 4) ?>"
                      data-type="QP"
                      data-node-type="<?= h($p['node_type'] ?? 'snapshot') ?>"
                      data-ts="<?= h($p['ts']) ?>"
                      data-rank="<?= (int)$p['rank'] ?>"
                      data-qp="<?= n($p['qp']) ?>">
                      <title><?= h($p['ts']) ?>｜QP <?= n($p['qp']) ?>｜排名 #<?= (int)$p['rank'] ?></title>
                    </circle>

                    <circle
                      class="qpp-point-rank qpp-chart-point"
                      cx="<?= $p['x'] ?>"
                      cy="<?= $p['rank_y'] ?>"
                      r="4"
                      data-type="排名"
                      data-node-type="<?= h($p['node_type'] ?? 'snapshot') ?>"
                      data-ts="<?= h($p['ts']) ?>"
                      data-rank="<?= (int)$p['rank'] ?>"
                      data-qp="<?= n($p['qp']) ?>">
                      <title><?= h($p['ts']) ?>｜排名 #<?= (int)$p['rank'] ?>｜QP <?= n($p['qp']) ?></title>
                    </circle>
                  <?php endforeach; ?>

                  <?php if (!empty($trendLabels)): ?>
                    <?php $start = $trendLabels[0];
                    $end = $trendLabels[count($trendLabels) - 1]; ?>
                    <text class="qpp-chart-text" x="<?= (int)$padX ?>" y="<?= (int)($chartH - 8) ?>">起點</text>
                    <text class="qpp-chart-text" x="<?= (int)($chartW - $padX - 40) ?>" y="<?= (int)($chartH - 8) ?>">最新</text>
                    <text class="qpp-chart-value" x="<?= max(46, $end['x'] - 70) ?>" y="<?= max(18, $end['qp_y'] - 12) ?>">QP <?= n($end['qp']) ?></text>
                    <text class="qpp-chart-value" x="<?= max(46, $end['x'] - 70) ?>" y="<?= min($chartH - 18, $end['rank_y'] + 22) ?>">#<?= (int)$end['rank'] ?></text>
                  <?php endif; ?>
                </svg>
                <div id="qppTrendTooltip" class="qpp-tooltip"></div>
              </div>

            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
  (function() {
    var tooltip = document.getElementById('qppTrendTooltip');
    if (!tooltip) return;

    document.querySelectorAll('.qpp-chart-point').forEach(function(point) {
      point.addEventListener('mouseenter', function(e) {
        var type = this.getAttribute('data-type') || '';
        var nodeType = this.getAttribute('data-node-type') || '';
        var ts = this.getAttribute('data-ts') || '';
        var rank = this.getAttribute('data-rank') || '';
        var qp = this.getAttribute('data-qp') || '';

        var nodeLabel = '快照';
        if (nodeType === 'season_start') nodeLabel = '月結起點';

        tooltip.innerHTML =
          '<strong>' + type + ' ' + nodeLabel + '</strong><br>' +
          '時間：' + ts + '<br>' +
          '排名：#' + rank + '<br>' +
          'QP：' + qp;

        tooltip.style.display = 'block';
      });

      point.addEventListener('mousemove', function(e) {
        tooltip.style.left = e.clientX + 'px';
        tooltip.style.top = e.clientY + 'px';
      });

      point.addEventListener('mouseleave', function() {
        tooltip.style.display = 'none';
      });
    });
  })();
</script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>