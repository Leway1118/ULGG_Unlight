<?php
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../config.php';
$pdo = $db;

$pageTitleText = 'BP 玩家競技詳情';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "bp_rank";

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
function signed_n($v)
{
  $v = (int)$v;
  return ($v > 0 ? '+' : '') . number_format($v);
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

$playerName = trim($_GET['name'] ?? '');
$bpTable = "ranking_bp_{$server}";
$bpHistoryTable = "ranking_bp_{$server}_history";
$hasBpHistoryTable = table_exists_safe($pdo, $bpHistoryTable);

$latestTs = fetch_col_safe($pdo, "SELECT MAX(ts) FROM `$bpTable`");

$todayBaseTs = $latestTs ? fetch_col_safe($pdo, "
  SELECT MIN(ts)
  FROM `$bpTable`
  WHERE DATE(ts) = DATE(?)
", [$latestTs]) : null;

$prevTs = $latestTs ? fetch_col_safe($pdo, "
  SELECT MAX(ts)
  FROM `$bpTable`
  WHERE ts < ?
", [$latestTs]) : null;

$compareTs = ($todayBaseTs && $todayBaseTs !== $latestTs) ? $todayBaseTs : $prevTs;
$compareModeText = ($todayBaseTs && $todayBaseTs !== $latestTs) ? '今日' : '前次快照';

$current = null;
$daily = null;
$dailyStat = null;
$growthRank = null;
$riseRank = null;
$lossRank = null;
$nearby = [];
$nextGap = null;
$recent = [];
$trendSourceText = '本賽季 BP';
$seasonStartTs = null;
$isNewEntry = false;
$seasonHistory = [];
$historyBestRank = null;
$historyTop5Count = 0;
$historyTop30Count = 0;

if ($playerName !== '' && $latestTs) {
  $current = fetch_one_safe($pdo, "
    SELECT rank_num, name, level, bp, win_ranked, lose_ranked, draw_ranked, ts
    FROM `$bpTable`
    WHERE ts = ? AND name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
    LIMIT 1
  ", [$latestTs, $playerName]);

  if ($current && $compareTs) {
    $daily = fetch_one_safe($pdo, "
      SELECT c.name, c.level, p.ts AS old_ts, c.ts AS now_ts,
        p.rank_num AS old_rank, c.rank_num AS now_rank,
        p.bp AS old_bp, c.bp AS now_bp,
        c.bp - p.bp AS bp_diff,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise,
        COALESCE(p.win_ranked, 0) AS old_win,
        COALESCE(c.win_ranked, 0) AS now_win,
        COALESCE(p.lose_ranked, 0) AS old_lose,
        COALESCE(c.lose_ranked, 0) AS now_lose,
        COALESCE(p.draw_ranked, 0) AS old_draw,
        COALESCE(c.draw_ranked, 0) AS now_draw,
        COALESCE(c.win_ranked, 0) - COALESCE(p.win_ranked, 0) AS win_gain,
        COALESCE(c.lose_ranked, 0) - COALESCE(p.lose_ranked, 0) AS lose_gain,
        COALESCE(c.draw_ranked, 0) - COALESCE(p.draw_ranked, 0) AS draw_gain,
        (
          COALESCE(c.win_ranked, 0) - COALESCE(p.win_ranked, 0) +
          COALESCE(c.lose_ranked, 0) - COALESCE(p.lose_ranked, 0) +
          COALESCE(c.draw_ranked, 0) - COALESCE(p.draw_ranked, 0)
        ) AS match_gain
      FROM `$bpTable` c
      JOIN `$bpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ?
      AND c.name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      LIMIT 1
    ", [$compareTs, $latestTs, $playerName]);

    $dailyStat = fetch_one_safe($pdo, "
      SELECT
        COUNT(*) AS compared_players,
        SUM(CASE WHEN c.bp > p.bp THEN 1 ELSE 0 END) AS up_players,
        SUM(CASE WHEN c.bp < p.bp THEN 1 ELSE 0 END) AS down_players,
        SUM(CASE WHEN c.bp = p.bp THEN 1 ELSE 0 END) AS same_players,
        COALESCE(SUM(CASE WHEN c.bp > p.bp THEN c.bp - p.bp ELSE 0 END), 0) AS total_bp_gain,
        COALESCE(SUM(CASE WHEN c.bp < p.bp THEN p.bp - c.bp ELSE 0 END), 0) AS total_bp_loss,
        COALESCE(MAX(c.bp - p.bp), 0) AS max_bp_gain,
        COALESCE(MIN(c.bp - p.bp), 0) AS max_bp_loss,
        ROUND(AVG(c.bp - p.bp), 1) AS avg_bp_diff
      FROM `$bpTable` c
      JOIN `$bpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ?
    ", [$compareTs, $latestTs]);

    $growthRows = fetch_all_safe($pdo, "
      SELECT c.name, c.rank_num AS now_rank, p.rank_num AS old_rank,
        c.bp AS now_bp, p.bp AS old_bp,
        c.bp - p.bp AS bp_diff,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$bpTable` c
      JOIN `$bpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ? AND c.bp > p.bp
      ORDER BY bp_diff DESC, c.rank_num ASC
    ", [$compareTs, $latestTs]);

    foreach ($growthRows as $idx => $row) {
      if ($row['name'] === $current['name']) {
        $growthRank = $idx + 1;
        break;
      }
    }

    $riseRows = fetch_all_safe($pdo, "
      SELECT c.name, c.rank_num AS now_rank, p.rank_num AS old_rank,
        c.bp AS now_bp, p.bp AS old_bp,
        c.bp - p.bp AS bp_diff,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$bpTable` c
      JOIN `$bpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ?
      AND CAST(p.rank_num AS SIGNED) > CAST(c.rank_num AS SIGNED)
      ORDER BY rank_rise DESC, bp_diff DESC
    ", [$compareTs, $latestTs]);

    foreach ($riseRows as $idx => $row) {
      if ($row['name'] === $current['name']) {
        $riseRank = $idx + 1;
        break;
      }
    }

    $lossRows = fetch_all_safe($pdo, "
      SELECT c.name, c.rank_num AS now_rank, p.rank_num AS old_rank,
        c.bp AS now_bp, p.bp AS old_bp,
        c.bp - p.bp AS bp_diff,
        CAST(p.rank_num AS SIGNED) - CAST(c.rank_num AS SIGNED) AS rank_rise
      FROM `$bpTable` c
      JOIN `$bpTable` p
        ON p.name COLLATE utf8mb4_general_ci = c.name COLLATE utf8mb4_general_ci
       AND p.ts = ?
      WHERE c.ts = ? AND c.bp < p.bp
      ORDER BY bp_diff ASC, c.rank_num ASC
    ", [$compareTs, $latestTs]);

    foreach ($lossRows as $idx => $row) {
      if ($row['name'] === $current['name']) {
        $lossRank = $idx + 1;
        break;
      }
    }

    $newCheck = fetch_one_safe($pdo, "
      SELECT p.name
      FROM `$bpTable` p
      WHERE p.ts = ?
      AND p.name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      LIMIT 1
    ", [$compareTs, $playerName]);
    $isNewEntry = !$newCheck;
  }

  $nearby = fetch_all_safe($pdo, "
    SELECT r.rank_num, r.name, r.level, r.bp, r.win_ranked, r.lose_ranked, r.draw_ranked,
      r.bp - target.bp AS bp_diff
    FROM `$bpTable` r
    JOIN (
      SELECT rank_num, bp
      FROM `$bpTable`
      WHERE ts = ? AND name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      LIMIT 1
    ) target
    WHERE r.ts = ?
    AND r.rank_num BETWEEN target.rank_num - 5 AND target.rank_num + 5
    ORDER BY r.rank_num ASC
  ", [$latestTs, $playerName, $latestTs]);

  $nextGap = fetch_one_safe($pdo, "
    SELECT target.rank_num AS target_rank, target.name AS target_name, target.bp AS target_bp,
      upper_player.rank_num AS upper_rank, upper_player.name AS upper_name, upper_player.bp AS upper_bp,
      upper_player.bp - target.bp AS bp_to_next_rank
    FROM (
      SELECT rank_num, name, bp
      FROM `$bpTable`
      WHERE ts = ? AND name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      LIMIT 1
    ) target
    LEFT JOIN `$bpTable` upper_player
      ON upper_player.ts = ?
     AND upper_player.rank_num = target.rank_num - 1
    LIMIT 1
  ", [$latestTs, $playerName, $latestTs]);

  $recent = [];

  if ($hasBpHistoryTable) {
    $seasonStartTs = fetch_col_safe($pdo, "
    SELECT MAX(ts)
    FROM `$bpHistoryTable`
  ");
  }

  if ($seasonStartTs) {
    // 只撈月結後，也就是本賽季的即時 BP 快照
    $recent = fetch_all_safe($pdo, "
    SELECT
      ts,
      rank_num,
      level,
      bp,
      win_ranked,
      lose_ranked,
      draw_ranked,
      'snapshot' AS node_type
    FROM `$bpTable`
    WHERE name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci
      AND ts > ?
    ORDER BY ts DESC
    LIMIT 11
  ", [$playerName, $seasonStartTs]);

    // 有本賽季資料時，補一個 1500 起點
    if (!empty($recent)) {
      $oldestSeasonRow = $recent[count($recent) - 1];

      $recent[] = [
        'ts' => $seasonStartTs,
        'rank_num' => $oldestSeasonRow['rank_num'],
        'level' => $oldestSeasonRow['level'],
        'bp' => 1500,
        'win_ranked' => 0,
        'lose_ranked' => 0,
        'draw_ranked' => 0,
        'node_type' => 'season_start',
      ];
    }

    $trendSourceText = '本賽季 BP';
  } else {
    // 沒有 history 表或沒有月結資料時，退回原本即時快照
    $recent = fetch_all_safe($pdo, "
    SELECT
      ts,
      rank_num,
      level,
      bp,
      win_ranked,
      lose_ranked,
      draw_ranked,
      'snapshot' AS node_type
    FROM `$bpTable`
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
if ($playerName !== '' && $hasBpHistoryTable) {
  $seasonHistory = fetch_all_safe($pdo, "
    WITH ranked_history AS (
      SELECT
        ts,
        name,
        level,
        bp,
        ROW_NUMBER() OVER (
          PARTITION BY ts
          ORDER BY bp DESC, name ASC
        ) AS final_rank
      FROM `$bpHistoryTable`
      WHERE name IS NOT NULL
        AND TRIM(name) <> ''
        AND bp IS NOT NULL
    )
    SELECT ts, final_rank, level, bp
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
$lossShare = 0;
$timesOfAvg = 0;
if ($daily && $dailyStat) {
  if ((int)$daily['bp_diff'] > 0) {
    $gainShare = ((int)$dailyStat['total_bp_gain'] > 0) ? ((int)$daily['bp_diff'] / (int)$dailyStat['total_bp_gain'] * 100) : 0;
  } elseif ((int)$daily['bp_diff'] < 0) {
    $lossShare = ((int)$dailyStat['total_bp_loss'] > 0) ? (abs((int)$daily['bp_diff']) / (int)$dailyStat['total_bp_loss'] * 100) : 0;
  }
  $timesOfAvg = ((float)$dailyStat['avg_bp_diff'] != 0) ? ((int)$daily['bp_diff'] / (float)$dailyStat['avg_bp_diff']) : 0;
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
  $bpValues = array_map(fn($r) => (int)$r['bp'], $trendRows);
  $rankValues = array_map(fn($r) => (int)$r['rank_num'], $trendRows);
  $minBp = min($bpValues);
  $maxBp = max($bpValues);
  $minRank = min($rankValues);
  $maxRank = max($rankValues);
  $countTrend = count($trendRows);
  $usableW = $chartW - $padX * 2;
  $usableH = $chartH - $padY * 2;

  foreach ($trendRows as $idx => $r) {
    $x = $countTrend <= 1 ? $padX + $usableW / 2 : $padX + ($idx / ($countTrend - 1)) * $usableW;

    $bpRange = max(1, $maxBp - $minBp);
    $bpRatio = ((int)$r['bp'] - $minBp) / $bpRange;
    $yBp = $chartH - $padY - ($bpRatio * $usableH);
    $trendPoints .= round($x, 2) . ',' . round($yBp, 2) . ' ';

    $rankRange = max(1, $maxRank - $minRank);
    $rankRatio = ($maxRank - (int)$r['rank_num']) / $rankRange;
    $yRank = $chartH - $padY - ($rankRatio * $usableH);
    $rankPoints .= round($x, 2) . ',' . round($yRank, 2) . ' ';

    $trendLabels[] = [
      'x' => round($x, 2),
      'bp_y' => round($yBp, 2),
      'rank_y' => round($yRank, 2),
      'ts' => $r['ts'],
      'rank' => (int)$r['rank_num'],
      'bp' => (int)$r['bp'],
      'node_type' => $r['node_type'] ?? 'snapshot',
    ];
  }
}
?>

<style>
  /* =========================
     BP Player Detail
  ========================= */
  .bpp-page {
    padding: 18px 0 32px;
  }

  .bpp-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .bpp-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid rgba(255, 255, 255, .18);
    background: #1f2937;
    color: #ddd6fe;
    border-radius: 999px;
    padding: 7px 13px;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
  }

  .bpp-back:hover {
    background: #7c3aed;
    color: #fff;
    text-decoration: none;
    border-color: #a78bfa;
  }

  .bpp-server {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .bpp-server a {
    display: inline-block;
    padding: 7px 13px;
    border-radius: 999px;
    background: #1f2937;
    color: #ddd6fe;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    border: 1px solid rgba(255, 255, 255, .18);
  }

  .bpp-server a.active {
    background: #7c3aed;
    color: #fff;
    border-color: #a78bfa;
  }

  .bpp-server a:hover {
    color: #fff;
    text-decoration: none;
    border-color: #a78bfa;
  }

  .bpp-hero {
    background: linear-gradient(135deg, #111827, #312e81);
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 16px;
    padding: 18px;
    color: #fff;
    margin-bottom: 14px;
    box-shadow: 0 8px 22px rgba(0, 0, 0, .22);
  }

  .bpp-label {
    display: inline-flex;
    border-radius: 999px;
    background: rgba(124, 58, 237, .35);
    border: 1px solid rgba(196, 181, 253, .3);
    color: #ede9fe;
    padding: 6px 11px;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 10px;
  }

  .bpp-title {
    font-size: 26px;
    font-weight: 900;
    margin-bottom: 6px;
    color: #fff;
    letter-spacing: .03em;
  }

  .bpp-sub {
    color: rgba(255, 255, 255, .76);
    font-size: 13px;
    line-height: 1.7;
  }

  .bpp-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
  }

  .bpp-pill {
    display: inline-flex;
    border-radius: 999px;
    padding: 7px 12px;
    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .12);
    color: #e5e7eb;
    font-size: 13px;
  }

  .bpp-note-box {
    background: #0f172a;
    border: 1px solid rgba(167, 139, 250, .32);
    border-radius: 14px;
    padding: 14px;
    color: #cbd5e1;
    font-size: 13px;
    line-height: 1.8;
    margin-bottom: 12px;
  }

  .bpp-note-box strong {
    color: #fff;
    font-weight: 900;
  }

  .bpp-highlight {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
  }

  .bpp-focus {
    background: linear-gradient(135deg, #0f172a, #1e1b4b);
    border: 1px solid rgba(167, 139, 250, .32);
    border-radius: 14px;
    padding: 14px;
    color: #e5e7eb;
  }

  .bpp-focus-title {
    color: #c4b5fd;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .bpp-focus-main {
    font-size: 26px;
    font-weight: 900;
    color: #facc15;
    margin-bottom: 5px;
    line-height: 1.1;
  }

  .bpp-focus-sub {
    color: #cbd5e1;
    font-size: 12px;
    line-height: 1.7;
  }

  .bpp-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
  }

  .bpp-card {
    background: #111827;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 12px;
    padding: 12px;
    color: #fff;
    box-shadow: none;
  }

  .bpp-card-label {
    color: #9ca3af;
    font-size: 12px;
    margin-bottom: 6px;
  }

  .bpp-card-value {
    font-size: 21px;
    font-weight: 900;
    line-height: 1.1;
    color: #fff;
  }

  .bpp-card-note {
    color: #a5b4fc;
    font-size: 11px;
    margin-top: 6px;
    line-height: 1.5;
  }

  .bpp-section {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 14px;
    padding: 14px;
    color: #e5e7eb;
    margin-bottom: 12px;
  }

  .bpp-section-title {
    font-weight: 900;
    font-size: 16px;
    color: #fff;
    margin: 0 0 6px;
  }

  .bpp-section-desc {
    color: #94a3b8;
    font-size: 12px;
    margin-bottom: 10px;
  }

  .bpp-table-wrap {
    width: 100%;
    overflow-x: auto;
  }

  .bpp-table {
    width: 100%;
    border-collapse: collapse;
  }

  .bpp-table th {
    background: #1f2937;
    color: #cbd5e1;
    font-size: 12px;
    text-align: left;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    white-space: nowrap;
  }

  .bpp-table td {
    color: #e5e7eb;
    font-size: 12px;
    padding: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    white-space: nowrap;
  }

  .bpp-table tbody tr:hover {
    background: rgba(255, 255, 255, .04);
  }

  .bpp-name {
    font-weight: 900;
    color: #fff;
  }

  .bpp-self {
    background: rgba(124, 58, 237, .18) !important;
  }

  .bpp-self .bpp-name {
    color: #ddd6fe;
  }

  .bpp-up {
    color: #facc15;
    font-weight: 900;
  }

  .bpp-gain {
    color: #86efac;
    font-weight: 900;
  }

  .bpp-danger {
    color: #f87171;
    font-weight: 900;
  }

  .bpp-same {
    color: #94a3b8;
    font-weight: 900;
  }

  .bpp-empty {
    padding: 18px;
    text-align: center;
    color: #94a3b8;
    background: rgba(255, 255, 255, .04);
    border-radius: 10px;
  }

  .bpp-trend-card {
    background: rgba(255, 255, 255, .04);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 18px;
    padding: 16px;
    overflow: hidden;
  }

  .bpp-trend-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }

  .bpp-trend-title {
    font-size: 15px;
    font-weight: 900;
    color: #e5e7eb;
  }

  .bpp-trend-sub {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 4px;
    line-height: 1.5;
  }

  .bpp-trend-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 12px;
    color: #cbd5e1;
  }

  .bpp-dot {
    display: inline-block;
    width: 9px;
    height: 9px;
    border-radius: 999px;
    margin-right: 5px;
    vertical-align: middle;
  }

  .bpp-dot.bp {
    background: #a78bfa;
  }

  .bpp-dot.rank {
    background: #facc15;
  }

  .bpp-chart-wrap {
    width: 100%;
    overflow-x: auto;
  }

  .bpp-chart {
    width: 100%;
    min-width: 760px;
    height: auto;
    display: block;
  }

  .bpp-axis {
    stroke: rgba(148, 163, 184, .28);
    stroke-width: 1;
  }

  .bpp-grid-line {
    stroke: rgba(148, 163, 184, .12);
    stroke-width: 1;
  }

  .bpp-line-bp {
    fill: none;
    stroke: #a78bfa;
    stroke-width: 4;
    stroke-linecap: round;
    stroke-linejoin: round;
  }

  .bpp-line-rank {
    fill: none;
    stroke: #facc15;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 7 7;
  }

  .bpp-point-bp {
    fill: #a78bfa;
    stroke: #0f172a;
    stroke-width: 2;
  }

  .bpp-point-rank {
    fill: #facc15;
    stroke: #0f172a;
    stroke-width: 2;
  }

  .bpp-chart-text {
    fill: #94a3b8;
    font-size: 12px;
    font-weight: 700;
  }

  .bpp-chart-value {
    fill: #e5e7eb;
    font-size: 12px;
    font-weight: 900;
  }

  .bpp-trend-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
  }

  .bpp-trend-mini {
    background: rgba(15, 23, 42, .55);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    padding: 12px;
  }

  .bpp-trend-mini-label {
    font-size: 12px;
    color: #94a3b8;
    margin-bottom: 6px;
  }

  .bpp-trend-mini-value {
    font-size: 18px;
    font-weight: 900;
    color: #e5e7eb;
  }

  .bpp-tooltip {
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

  .bpp-tooltip strong {
    color: #fff;
    font-size: 13px;
  }

  .bpp-chart-point {
    cursor: pointer;
  }

  .bpp-chart-point:hover {
    filter: drop-shadow(0 0 6px rgba(167, 139, 250, .9));
  }

  @media (max-width:1199px) {
    .bpp-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width:991px) {
    .bpp-trend-summary {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width:767px) {
    .bpp-topbar {
      align-items: flex-start;
      flex-direction: column;
    }

    .bpp-grid,
    .bpp-highlight {
      grid-template-columns: 1fr;
    }

    .bpp-table {
      min-width: 720px;
    }

    .bpp-title {
      font-size: 23px;
    }
  }

  @media (max-width:575px) {
    .bpp-trend-head {
      flex-direction: column;
    }

    .bpp-trend-summary {
      grid-template-columns: 1fr;
    }
  }

  .bpp-title-link {
    color: inherit;
    text-decoration: none;
  }

  .bpp-title-link:hover {
    color: #ddd6fe;
    text-decoration: underline;
  }

  /* =========================
     PLAYER_HISTORY_20260930
  ========================= */
  .bpp-history-summary {
    color: #94a3b8;
    font-size: 12px;
    margin-bottom: 10px;
    line-height: 1.7;
  }

  .bpp-history-rank {
    font-weight: 900;
    color: #fff;
  }

  .bpp-tier-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
  }

  .bpp-tier-top5 {
    color: #fde68a;
    background: rgba(250, 204, 21, .10);
    border: 1px solid rgba(250, 204, 21, .28);
  }

  .bpp-tier-top30 {
    color: #ddd6fe;
    background: rgba(167, 139, 250, .10);
    border: 1px solid rgba(167, 139, 250, .24);
  }

  .bpp-tier-other {
    color: #94a3b8;
    background: rgba(148, 163, 184, .06);
    border: 1px solid rgba(148, 163, 184, .14);
  }

  .bpp-history-more {
    margin-top: 10px;
    border-top: 1px solid rgba(255, 255, 255, .08);
    padding-top: 10px;
  }

  .bpp-history-more > summary {
    cursor: pointer;
    color: #c4b5fd;
    font-size: 12px;
    font-weight: 800;
  }

</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container bpp-page">
      <div class="bpp-topbar">
        <a class="bpp-back" href="/pages/bp_rank.php?server=<?= h($server) ?>">← 返回 BP 分析</a>
        <div class="bpp-server">
          <a href="?server=TW&name=<?= urlencode($playerName) ?>" class="<?= $server === 'TW' ? 'active' : '' ?>">TW 台服</a>
          <a href="?server=JP&name=<?= urlencode($playerName) ?>" class="<?= $server === 'JP' ? 'active' : '' ?>">JP 日服</a>
        </div>
      </div>

      <?php if ($playerName === ''): ?>
        <div class="bpp-section">
          <div class="bpp-empty">缺少玩家名稱，請使用 /pages/bp_player.php?server=TW&name=玩家名稱</div>
        </div>
      <?php elseif (!$current): ?>
        <div class="bpp-section">
          <div class="bpp-empty">目前最新 BP 榜找不到「<?= h($playerName) ?>」。</div>
        </div>
      <?php else: ?>
        <?php
        $curWin = (int)($current['win_ranked'] ?? 0);
        $curLose = (int)($current['lose_ranked'] ?? 0);
        $curDraw = (int)($current['draw_ranked'] ?? 0);
        $curTotal = $curWin + $curLose + $curDraw;
        $curRate = $curTotal > 0 ? round($curWin / $curTotal * 100, 1) : 0;
        ?>
        <div class="bpp-hero">
          <div class="bpp-label"><?= h($server) ?> BP 競技詳情</div>
          <?php
          $fightUrl = '/pages/fight.php?' . http_build_query([
            'player_name' => $current['name'],
            'server' => 'ALL',
          ], '', '&', PHP_QUERY_RFC3986);
          ?>
          <div class="bpp-title">
            <a href="<?= h($fightUrl) ?>" class="bpp-title-link">
              <?= h($current['name']) ?>
            </a>
          </div>
          <!-- <div class="bpp-sub">
            這裡觀察玩家 BP 排名、分數升降與排名賽戰績變化。BP 反映競技榜上的表現與競爭位置，適合用來追蹤近期衝榜、掉分與排名壓力。
          </div>
          <div class="bpp-meta">
            <span class="bpp-pill">目前排名 #<?= (int)$current['rank_num'] ?></span>
            <span class="bpp-pill">目前 BP <?= n($current['bp']) ?></span>
            <span class="bpp-pill">Lv <?= (int)$current['level'] ?></span>
            <span class="bpp-pill">勝率 <?= h($curRate) ?>%</span>
            <span class="bpp-pill">戰績 W<?= n($curWin) ?> / L<?= n($curLose) ?> / D<?= n($curDraw) ?></span>
            <span class="bpp-pill">最新更新 <?= h($current['ts']) ?></span>
          </div> -->
        </div>

        <div class="bpp-section">
          <h2 class="bpp-section-title">歷屆名次</h2>
          <div class="bpp-history-summary">
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
            <div class="bpp-table-wrap">
              <table class="bpp-table">
                <thead>
                  <tr>
                    <th>結算日</th>
                    <th>最終名次</th>
                    <th>BP</th>
                    <th>Level</th>
                    <th>獎勵區</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($historyRecent as $r): ?>
                    <?php
                      $historyRank = (int)$r['final_rank'];
                      $tierClass = $historyRank <= 5 ? 'bpp-tier-top5' : ($historyRank <= 30 ? 'bpp-tier-top30' : 'bpp-tier-other');
                      $tierText = $historyRank <= 5 ? 'TOP 5' : ($historyRank <= 30 ? 'TOP 30' : '#31+');
                    ?>
                    <tr>
                      <td title="<?= h($r['ts']) ?>"><?= h(date('Y-m-d', strtotime($r['ts']))) ?></td>
                      <td class="bpp-history-rank">#<?= $historyRank ?></td>
                      <td><?= n($r['bp']) ?></td>
                      <td>Lv <?= (int)$r['level'] ?></td>
                      <td><span class="bpp-tier-badge <?= h($tierClass) ?>"><?= h($tierText) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php if (count($seasonHistory) > 10): ?>
              <details class="bpp-history-more">
                <summary>查看更早 <?= n(count($seasonHistory) - 10) ?> 期</summary>
                <div class="bpp-table-wrap" style="margin-top:10px;">
                  <table class="bpp-table">
                    <thead>
                      <tr>
                        <th>結算日</th>
                        <th>最終名次</th>
                        <th>BP</th>
                        <th>Level</th>
                        <th>獎勵區</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach (array_slice($seasonHistory, 10) as $r): ?>
                        <?php
                          $historyRank = (int)$r['final_rank'];
                          $tierClass = $historyRank <= 5 ? 'bpp-tier-top5' : ($historyRank <= 30 ? 'bpp-tier-top30' : 'bpp-tier-other');
                          $tierText = $historyRank <= 5 ? 'TOP 5' : ($historyRank <= 30 ? 'TOP 30' : '#31+');
                        ?>
                        <tr>
                          <td title="<?= h($r['ts']) ?>"><?= h(date('Y-m-d', strtotime($r['ts']))) ?></td>
                          <td class="bpp-history-rank">#<?= $historyRank ?></td>
                          <td><?= n($r['bp']) ?></td>
                          <td>Lv <?= (int)$r['level'] ?></td>
                          <td><span class="bpp-tier-badge <?= h($tierClass) ?>"><?= h($tierText) ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </details>
            <?php endif; ?>
          <?php else: ?>
            <div class="bpp-empty">這名玩家目前沒有可用的歷屆月結資料。</div>
          <?php endif; ?>
        </div>

        <div class="bpp-section">
          <h2 class="bpp-section-title">目前排名附近</h2>
          <div class="bpp-section-desc">觀察玩家目前前後排名、BP 差距與勝率。</div>
          <?php if (empty($nearby)): ?>
            <div class="bpp-empty">沒有附近排名資料。</div>
          <?php else: ?>
            <div class="bpp-table-wrap">
              <table class="bpp-table">
                <thead>
                  <tr>
                    <th>排名</th>
                    <th>玩家</th>
                    <th>Level</th>
                    <th>BP</th>
                    <th>勝率</th>
                    <th>與此玩家差距</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($nearby as $r): ?>
                    <?php
                    $w = (int)($r['win_ranked'] ?? 0);
                    $l = (int)($r['lose_ranked'] ?? 0);
                    $d = (int)($r['draw_ranked'] ?? 0);
                    $t = $w + $l + $d;
                    $rate = $t > 0 ? round($w / $t * 100, 1) . '%' : '0%';
                    ?>
                    <tr class="<?= $r['name'] === $current['name'] ? 'bpp-self' : '' ?>">
                      <td>#<?= (int)$r['rank_num'] ?></td>
                      <td class="bpp-name"><?= h($r['name']) ?></td>
                      <td>Lv <?= (int)$r['level'] ?></td>
                      <td><?= n($r['bp']) ?></td>
                      <td><?= h($rate) ?></td>
                      <td>
                        <?php if ((int)$r['bp_diff'] > 0): ?>
                          <span class="bpp-danger">+<?= n($r['bp_diff']) ?></span>
                        <?php elseif ((int)$r['bp_diff'] < 0): ?>
                          <span class="bpp-gain"><?= n($r['bp_diff']) ?></span>
                        <?php else: ?>
                          <span class="bpp-same">0</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="bpp-section">
          <h2 class="bpp-section-title">BP / 排名趨勢</h2>
          <div class="bpp-section-desc">
            顯示此玩家本賽季 BP 與排名變化。趨勢從月結後的重置 BP 1500 作為起點開始，避免跨月結算後重新計分被誤判為掉分。
            紫線為 BP，金色虛線為排名表現，排名越高線越往上。
          </div>
          <?php if (empty($trendRows)): ?>
            <div class="bpp-empty">目前沒有本賽季 BP 快照資料。</div>
          <?php else: ?>
            <?php
            $firstTrend = $trendRows[0];
            $lastTrend = $trendRows[count($trendRows) - 1];
            $trendBpGain = (int)$lastTrend['bp'] - (int)$firstTrend['bp'];
            $trendRankRise = (int)$firstTrend['rank_num'] - (int)$lastTrend['rank_num'];
            ?>

            <div class="bpp-trend-card">
              <div class="bpp-trend-head">
                <div>
                  <div class="bpp-trend-title"><?= h($current['name']) ?> <?= h($trendSourceText) ?>趨勢</div>
                  <div class="bpp-trend-sub">
                    <?= h($firstTrend['ts']) ?> → <?= h($lastTrend['ts']) ?>
                  </div>
                </div>
                <div class="bpp-trend-legend">
                  <span><i class="bpp-dot bp"></i>BP</span>
                  <span><i class="bpp-dot rank"></i>排名</span>
                </div>
              </div>

              <div class="bpp-chart-wrap">
                <svg class="bpp-chart" viewBox="0 0 <?= (int)$chartW ?> <?= (int)$chartH ?>" role="img" aria-label="BP ranking trend chart">
                  <line class="bpp-axis" x1="<?= (int)$padX ?>" y1="<?= (int)($chartH - $padY) ?>" x2="<?= (int)($chartW - $padX) ?>" y2="<?= (int)($chartH - $padY) ?>"></line>
                  <line class="bpp-axis" x1="<?= (int)$padX ?>" y1="<?= (int)$padY ?>" x2="<?= (int)$padX ?>" y2="<?= (int)($chartH - $padY) ?>"></line>

                  <?php for ($g = 1; $g <= 4; $g++): ?>
                    <?php $gy = $padY + (($chartH - $padY * 2) / 4) * $g; ?>
                    <line class="bpp-grid-line" x1="<?= (int)$padX ?>" y1="<?= round($gy, 2) ?>" x2="<?= (int)($chartW - $padX) ?>" y2="<?= round($gy, 2) ?>"></line>
                  <?php endfor; ?>

                  <polyline class="bpp-line-bp" points="<?= h(trim($trendPoints)) ?>"></polyline>
                  <polyline class="bpp-line-rank" points="<?= h(trim($rankPoints)) ?>"></polyline>

                  <?php foreach ($trendLabels as $idx => $p): ?>
                    <circle
                      class="bpp-point-bp bpp-chart-point"
                      cx="<?= $p['x'] ?>"
                      cy="<?= $p['bp_y'] ?>"
                      r="<?= (($p['node_type'] ?? '') === 'season_start') ? 6 : ($idx === count($trendLabels) - 1 ? 6 : 4) ?>"
                      data-type="BP"
                      data-node-type="<?= h($p['node_type'] ?? 'snapshot') ?>"
                      data-ts="<?= h($p['ts']) ?>"
                      data-rank="<?= (int)$p['rank'] ?>"
                      data-bp="<?= n($p['bp']) ?>">
                      <title><?= h($p['ts']) ?>｜BP <?= n($p['bp']) ?>｜排名 #<?= (int)$p['rank'] ?></title>
                    </circle>

                    <circle
                      class="bpp-point-rank bpp-chart-point"
                      cx="<?= $p['x'] ?>"
                      cy="<?= $p['rank_y'] ?>"
                      r="4"
                      data-type="排名"
                      data-node-type="<?= h($p['node_type'] ?? 'snapshot') ?>"
                      data-ts="<?= h($p['ts']) ?>"
                      data-rank="<?= (int)$p['rank'] ?>"
                      data-bp="<?= n($p['bp']) ?>">
                      <title><?= h($p['ts']) ?>｜排名 #<?= (int)$p['rank'] ?>｜BP <?= n($p['bp']) ?></title>
                    </circle>
                  <?php endforeach; ?>

                  <?php if (!empty($trendLabels)): ?>
                    <?php $start = $trendLabels[0];
                    $end = $trendLabels[count($trendLabels) - 1]; ?>
                    <text class="bpp-chart-text" x="<?= (int)$padX ?>" y="<?= (int)($chartH - 8) ?>">起點</text>
                    <text class="bpp-chart-text" x="<?= (int)($chartW - $padX - 40) ?>" y="<?= (int)($chartH - 8) ?>">最新</text>
                    <text class="bpp-chart-value" x="<?= max(46, $end['x'] - 70) ?>" y="<?= max(18, $end['bp_y'] - 12) ?>">BP <?= n($end['bp']) ?></text>
                    <text class="bpp-chart-value" x="<?= max(46, $end['x'] - 70) ?>" y="<?= min($chartH - 18, $end['rank_y'] + 22) ?>">#<?= (int)$end['rank'] ?></text>
                  <?php endif; ?>
                </svg>
                <div id="bppTrendTooltip" class="bpp-tooltip"></div>
              </div>

              <div class="bpp-trend-summary">
                <div class="bpp-trend-mini">
                  <div class="bpp-trend-mini-label">賽季起始 BP</div>
                  <div class="bpp-trend-mini-value"><?= n($firstTrend['bp']) ?></div>
                </div>
                <div class="bpp-trend-mini">
                  <div class="bpp-trend-mini-label">最新 BP</div>
                  <div class="bpp-trend-mini-value"><?= n($lastTrend['bp']) ?></div>
                </div>
                <div class="bpp-trend-mini">
                  <div class="bpp-trend-mini-label">本賽季 BP 變化</div>
                  <div class="bpp-trend-mini-value <?= $trendBpGain >= 0 ? 'bpp-gain' : 'bpp-danger' ?>">
                    <?= $trendBpGain >= 0 ? '+' : '' ?><?= n($trendBpGain) ?>
                  </div>
                </div>
                <div class="bpp-trend-mini">
                  <div class="bpp-trend-mini-label">本賽季排名變化</div>
                  <div class="bpp-trend-mini-value <?= $trendRankRise >= 0 ? 'bpp-up' : 'bpp-danger' ?>">
                    <?= $trendRankRise >= 0 ? '▲' : '▼' ?><?= n(abs($trendRankRise)) ?>
                  </div>
                </div>
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
    var tooltip = document.getElementById('bppTrendTooltip');
    if (!tooltip) return;

    document.querySelectorAll('.bpp-chart-point').forEach(function(point) {
      point.addEventListener('mouseenter', function() {
        var type = this.getAttribute('data-type') || '';
        var nodeType = this.getAttribute('data-node-type') || '';
        var ts = this.getAttribute('data-ts') || '';

        var nodeLabel = '快照';
        if (nodeType === 'season_start') nodeLabel = '賽季起點';
        if (nodeType === 'snapshot') nodeLabel = '快照';
        var rank = this.getAttribute('data-rank') || '';
        var bp = this.getAttribute('data-bp') || '';

        tooltip.innerHTML =
          '<strong>' + type + ' ' + nodeLabel + '</strong><br>' +
          '時間：' + ts + '<br>' +
          '排名：#' + rank + '<br>' +
          'BP：' + bp;

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