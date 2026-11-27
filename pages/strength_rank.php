<?php
// ⚠️ 不要 session_start()，交給 config.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/_analysis_base.php';
// ⭐ 全站常數 define('IMG_BASE', '/assets/uploads/');
$pdo = $db;

$pageTitleText = '最強牌組排名｜強度排行';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "strength_rank";

ob_start();

// ⭐ 天數切換（預設改 30 天，比較適合強度排行）
$days = isset($_GET['days']) && in_array((int)$_GET['days'], [7, 14, 30], true)
  ? (int)$_GET['days']
  : 14;

// Weekly filter is opt-in; legacy range ranking remains the default.
$weeklyMode = isset($_GET['mode']) && $_GET['mode'] === 'weekly';
$weeklyRule = null;
if ($weeklyMode) {
  $weeklyRule = strLoadWeeklyRule($pdo);
}

$labels = [
  '50-59' => [
    'title' => '50–59 COST',
    'note'  => '低 COST 區間，樣本最多，正式榜門檻 5 場。',
  ],
  '60-69' => [
    'title' => '60–69 COST',
    'note'  => '中高 COST 區間，兼看穩定度與勝率。',
  ],
  '70-80' => [
    'title' => '70–80 COST',
    'note'  => '高 COST 區間，樣本較少，觀察榜可輔助判斷。',
  ],
  '90+' => [
    'title' => '90+ COST',
    'note'  => '無限制高 COST 區間，牌組量少，需留意樣本數。',
  ],
];
if ($weeklyMode) {
  $labels = $weeklyRule === null ? [] : strWeeklyLabels($weeklyRule);
}

/**
 * Read the active Alexandre APPROVED source as Ruleset.
 * Never present a stale or incomplete rule as this week's rule.
 */
function strLoadWeeklyRule(PDO $pdo, ?DateTimeImmutable $now = null): ?array
{
  $tz = new DateTimeZone('Asia/Taipei');
  $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
  // Rules take effect after the announced 10:00 maintenance.
  $asOf = $now->format('H:i') < '10:00' ? $now->modify('-1 day') : $now;
  try {
    $stmt = $pdo->prepare("SELECT id, effective_date, cost1, cost2, cost3, cost4, cost4_operator
      FROM quickmatch_weekly_cost
      WHERE ladder_zone_code = 'ALEXANDRE' AND import_status = 'APPROVED'
        AND matchmaking_enabled = 1
        AND effective_date <= :as_of
      ORDER BY effective_date DESC, id DESC LIMIT 1");
    $stmt->execute([':as_of' => $asOf->format('Y-m-d')]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rule || $rule['effective_date'] < $asOf->modify('-6 days')->format('Y-m-d')) return null;
    $costs = [];
    for ($i = 1; $i <= 4; $i++) {
      $cost = filter_var($rule['cost'.$i], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>999]]);
      if ($cost === false) return null;
      $costs[] = $cost;
      $rule['cost'.$i] = $cost;
    }
    $operator = strtoupper((string)$rule['cost4_operator']);
    if (!in_array($operator, ['EXACT','MIN'], true) || count(array_unique($costs)) !== 4) return null;
    if ($operator === 'MIN' && $costs[3] <= max(array_slice($costs, 0, 3))) return null;
    $rule['cost4_operator'] = $operator;
    return $rule;
  } catch (Throwable $e) {
    error_log('strength_rank: weekly COST unavailable');
    return null;
  }
}

function strWeeklyLabels(array $rule): array
{
  $labels = [];
  for ($i = 1; $i <= 4; $i++) {
    $min = $i === 4 && $rule['cost4_operator'] === 'MIN';
    $labels['weekly-'.$i] = [
      'title' => $rule['cost'.$i] . ($min ? '+' : '') . ' COST',
      'note' => $min ? '符合本週 COST 下限的牌組。' : '符合本週精確 COST 的牌組。',
    ];
  }
  return $labels;
}

function strRankHref(int $days, bool $weekly): string
{
  $params = ['days' => $days];
  if ($weekly) {
    $params['mode'] = 'weekly';
  }
  return '?' . http_build_query($params);
}

/**
 * 安全輸出
 */
function str_h($value): string
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 新版 Tier：正式榜才給 S/A/B/C，觀察中不給正式 Tier。
 */
function strTierByMetaScore(float $score, string $confidence): array
{
  if ($confidence === '觀察中') {
    return ['👁', 'tier-observe'];
  }

  if ($score >= 85) {
    return ['S', 'tier-s'];
  }

  if ($score >= 72) {
    return ['A', 'tier-a'];
  }

  if ($score >= 58) {
    return ['B', 'tier-b'];
  }

  return ['C', 'tier-c'];
}

/**
 * 依 arena_player_match_result 直接計算新版強度分。
 *
 * 評分：
 * - 勝率強度 50%：使用貝葉斯修正勝率，避免小樣本 100% 霸榜
 * - 樣本可信度 20%：10 場滿分
 * - 出現熱度 15%：30 場附近滿分
 * - BP 強度 15%：1450~1700 線性加分
 */
function strFetchMetaRankRows(PDO $pdo, int $days, ?array $weeklyRule = null): array
{
  $days = in_array($days, [7, 14, 30], true) ? $days : 30;

  // Match-time cost and current card cost must both fit the category.
  // This is a deck eligibility filter, not a claim that the matches were
  // played in a particular ladder zone or under the current week's rules.
  $costCase = "CASE
          WHEN a.cost BETWEEN 50 AND 59 THEN '50-59'
          WHEN a.cost BETWEEN 60 AND 69 THEN '60-69'
          WHEN a.cost BETWEEN 70 AND 80 THEN '70-80'
          WHEN a.cost >= 90 THEN '90+'
        END";
  $costJoins = '';
  $costMinimum = 'AND a.cost >= 50';
  if ($weeklyRule !== null) {
    $costCase = 'CASE';
    for ($i = 1; $i <= 4; $i++) {
      $cost = (int)$weeklyRule['cost'.$i];
      $op = $i === 4 && $weeklyRule['cost4_operator'] === 'MIN' ? '>=' : '=';
      $costCase .= " WHEN a.cost $op $cost AND (wc0.cost + wc1.cost + wc2.cost) $op $cost THEN 'weekly-$i'";
    }
    $costCase .= ' END';
    $costJoins = 'JOIN unlight wc0 ON wc0.id = a.leader_id
      JOIN unlight wc1 ON wc1.id = a.back1_id
      JOIN unlight wc2 ON wc2.id = a.back2_id';
    $costMinimum = '';
  }

  $sql = "
    WITH deck_stat AS (
      SELECT
        {$costCase} AS cost_range,

        a.team_key,
        MIN(a.leader_id) AS leader_id,
        MIN(a.back1_id) AS back1_id,
        MIN(a.back2_id) AS back2_id,
        ROUND(AVG(a.cost), 1) AS avg_cost,

        COUNT(*) AS matches,
        SUM(a.is_win = 1) AS wins,
        SUM(a.is_win = 0) AS losses,
        ROUND(SUM(a.is_win = 1) / COUNT(*) * 100, 1) AS raw_win_rate,

        ROUND(AVG(a.player_bp), 1) AS avg_player_bp,
        ROUND(AVG(o.player_bp), 1) AS avg_opponent_bp,
        ROUND(AVG((IFNULL(a.player_bp, 0) + IFNULL(o.player_bp, 0)) / 2), 0) AS avg_match_bp,

        MIN(a.update_time) AS first_seen,
        MAX(a.update_time) AS last_seen

      FROM arena_player_match_result a
      JOIN arena_player_match_result o
        ON o.match_id = a.match_id
       AND o.side <> a.side
      {$costJoins}

      WHERE a.update_time >= NOW() - INTERVAL {$days} DAY
        AND a.cost IS NOT NULL
        {$costMinimum}
        AND a.is_win IS NOT NULL
        AND o.player_bp IS NOT NULL

      GROUP BY cost_range, a.team_key
      HAVING cost_range IS NOT NULL
    ),

    scored_base AS (
      SELECT
        *,
        ROUND((wins + 4) / (matches + 8) * 100, 1) AS adjusted_win_rate
      FROM deck_stat
      WHERE matches >= 3
        AND raw_win_rate >= 50
    ),

    scored AS (
      SELECT
        *,
        GREATEST(0, LEAST(100, (adjusted_win_rate - 50) / 20 * 100)) AS win_score,
        GREATEST(0, LEAST(100, matches / 10 * 100)) AS sample_score,
        GREATEST(0, LEAST(100, LOG(matches + 1) / LOG(31) * 100)) AS usage_score,
        GREATEST(0, LEAST(100, (avg_match_bp - 1450) / 250 * 100)) AS bp_score
      FROM scored_base
    )

    SELECT
  cost_range,
  team_key,
  leader_id,
  back1_id,
  back2_id,
  avg_cost,

  (
    SELECT SUM(u.cost)
    FROM unlight u
    WHERE u.id IN (scored.leader_id, scored.back1_id, scored.back2_id)
  ) AS cost_sum,

  matches,
  wins,
  losses,
  raw_win_rate,
  adjusted_win_rate,
  avg_player_bp,
  avg_opponent_bp,
  avg_match_bp,
  ROUND(win_score, 1) AS win_score,
  ROUND(sample_score, 1) AS sample_score,
  ROUND(usage_score, 1) AS usage_score,
  ROUND(bp_score, 1) AS bp_score,
  ROUND(
    win_score * 0.50 +
    sample_score * 0.20 +
    usage_score * 0.15 +
    bp_score * 0.15
  , 1) AS meta_score,
  CASE
    WHEN matches >= 10 THEN '高資料'
    WHEN matches >= 5 THEN '一般'
    ELSE '觀察中'
  END AS confidence_label,
  first_seen,
  last_seen
FROM scored
ORDER BY
  CASE cost_range
    WHEN '50-59' THEN 1
    WHEN '60-69' THEN 2
    WHEN '70-80' THEN 3
    WHEN '90+' THEN 4
    WHEN 'weekly-1' THEN 1
    WHEN 'weekly-2' THEN 2
    WHEN 'weekly-3' THEN 3
    WHEN 'weekly-4' THEN 4
    ELSE 9
  END,
  meta_score DESC,
  matches DESC,
  adjusted_win_rate DESC
  ";

  $stmt = $pdo->query($sql);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 讀取角色卡資料。
 * arena_player_match_result 的 leader/back 欄位對應 unlight.id。
 */
function strGetCharCards(PDO $pdo, array $ids): array
{
  $ids = array_values(array_filter(array_map('intval', $ids)));
  if (!$ids) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("
    SELECT id, ico, name, level, cost
    FROM unlight
    WHERE id IN ($placeholders)
  ");
  $stmt->execute($ids);

  $map = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $map[(int)$row['id']] = $row;
  }

  $result = [];
  foreach ($ids as $id) {
    if (isset($map[$id])) {
      $result[] = $map[$id];
    } else {
      $result[] = [
        'id' => $id,
        'ico' => '',
        'name' => '#' . $id,
        'level' => '',
        'cost' => null,
      ];
    }
  }

  return $result;
}

/**
 * 將查詢結果整理成：
 * $rankData[cost_range]['official'][]
 * $rankData[cost_range]['watch'][]
 */
function strBuildRankData(PDO $pdo, int $days, array $labels, ?array $weeklyRule = null): array
{
  $rankData = [];
  foreach ($labels as $key => $_) {
    $rankData[$key] = [
      'official' => [],
      'watch' => [],
    ];
  }

  if (!$labels) return $rankData;
  $rows = strFetchMetaRankRows($pdo, $days, $weeklyRule);

  foreach ($rows as $row) {
    $costRange = (string)$row['cost_range'];
    if (!isset($rankData[$costRange])) {
      continue;
    }

    $ids = [
      (int)$row['leader_id'],
      (int)$row['back1_id'],
      (int)$row['back2_id'],
    ];

    $row['chars'] = strGetCharCards($pdo, $ids);
    $row['ids'] = $ids;

    if ((int)$row['matches'] >= 5) {
      if (count($rankData[$costRange]['official']) < 10) {
        $rankData[$costRange]['official'][] = $row;
      }
    } else {
      if (count($rankData[$costRange]['watch']) < 6) {
        $rankData[$costRange]['watch'][] = $row;
      }
    }
  }

  return $rankData;
}

/**
 * 牌組卡片
 */
function strRenderDeckCard(array $team, int $rankNo = 0): string
{
  $ids = $team['ids'] ?? [0, 0, 0];
  $chars = $team['chars'] ?? [];
  $confidence = (string)($team['confidence_label'] ?? '觀察中');
  [$tierText, $tierClass] = strTierByMetaScore((float)$team['meta_score'], $confidence);

  $href = '/pages/team_analysis.php?id1=' . (int)$ids[0] . '&id2=' . (int)$ids[1] . '&id3=' . (int)$ids[2];

  $charHtml = '';
  foreach ($chars as $i => $ch) {
    $icoFile = !empty($ch['ico'])
      ? IMG_BASE . $ch['ico']
      : IMG_BASE . 'ico/unknown.png';

    $alt = trim(($ch['level'] ?? '') . ' ' . ($ch['name'] ?? ''));

    $charHtml .= '
      <div class="char-card c' . (int)$i . '">
        <img src="' . str_h($icoFile) . '" alt="' . str_h($alt) . '" loading="lazy">
      </div>
    ';
  }

  $names = implode(' / ', array_map(function ($c) {
    return trim(($c['level'] ?? '') . ' ' . ($c['name'] ?? ''));
  }, $chars));

  if ($names === '') {
    $names = str_h((string)($team['team_key'] ?? '未知牌組'));
  }

  $rankBadge = $rankNo > 0 ? '<span class="rank-no">#' . (int)$rankNo . '</span>' : '';

  return '
  <a href="' . str_h($href) . '" class="deck-card deck-link">

    <div class="deck-card-top">
      ' . $rankBadge . '
      <span class="tier-badge ' . str_h($tierClass) . '">' . str_h($tierText) . '</span>
      <span class="confidence-badge confidence-' . str_h($confidence) . '">' . str_h($confidence) . '</span>
    </div>

    <div class="deck-chars">' . $charHtml . '</div>

    <div class="deck-name">' . str_h($names) . '</div>

    <div class="deck-score-row">
      <span>
        綜合分 <strong>' . str_h($team['meta_score']) . '</strong>
      </span>

      <span>
        勝率 <strong>' . str_h($team['raw_win_rate']) . '%</strong>
      </span>
    </div>

    <div class="deck-meta">
      COST ' . str_h($team['cost_sum'] ?? $team['avg_cost']) . '
      · 平均 BP ' . str_h($team['avg_match_bp']) . '
    </div>

  </a>
';
}

$rankData = strBuildRankData($pdo, $days, $labels, $weeklyRule);
?>

<style>
  /* ================================
     Strength Rank – Cost Meta Design
  ================================ */

  .str-block {
    background: #141414;
    border: 1px solid #1f1f1f;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
  }

  .str-title {
    font-size: 1.8rem;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .str-desc {
    color: #9aa4b2;
    font-size: 13px;
    line-height: 1.7;
    margin-bottom: 16px;
  }

  .str-desc strong {
    color: #e5e7eb;
  }

  .str-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
  }

  .str-tab {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 999px;
    background: #1f2937;
    color: #9ca3af;
    border: none;
    font-weight: 800;
    font-size: 13px;
    text-decoration: none;
    cursor: pointer;
  }

  .str-tab:hover {
    color: #fff;
    text-decoration: none;
  }

  .str-tab.active {
    background: #2563eb;
    color: #fff;
  }

  .str-info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 18px;
  }

  .str-info-card {
    background: rgba(15, 23, 42, .68);
    border: 1px solid rgba(255, 255, 255, .06);
    border-radius: 10px;
    padding: 10px 12px;
  }

  .str-info-label {
    color: #9aa4b2;
    font-size: 12px;
    margin-bottom: 4px;
  }

  .str-info-value {
    color: #fff;
    font-size: 18px;
    font-weight: 900;
  }

  .cost-section {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    background: linear-gradient(180deg, rgba(15, 23, 42, .8), rgba(10, 10, 10, .84));
    padding: 16px;
    margin-bottom: 18px;
  }

  .cost-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
  }

  .cost-title {
    font-size: 20px;
    font-weight: 900;
    color: #f8fafc;
    margin-bottom: 4px;
  }

  .cost-note {
    color: #9aa4b2;
    font-size: 12px;
    line-height: 1.6;
  }

  .cost-count {
    white-space: nowrap;
    color: #93c5fd;
    font-size: 12px;
    font-weight: 800;
    background: rgba(37, 99, 235, .15);
    border: 1px solid rgba(147, 197, 253, .18);
    padding: 5px 9px;
    border-radius: 999px;
  }

  .rank-subtitle {
    font-size: 14px;
    font-weight: 900;
    color: #e5e7eb;
    margin: 14px 0 8px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .rank-subtitle small {
    color: #9aa4b2;
    font-weight: 600;
  }

  .deck-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .deck-grid.is-watch {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .deck-card {
    display: block;
    background: rgba(15, 23, 42, .72);
    border-radius: 12px;
    padding: 10px 11px;
    font-size: 13px;
    border: 1px solid rgba(255, 255, 255, .07);
    color: inherit;
    text-decoration: none;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
  }

  .deck-card:hover {
    color: inherit;
    text-decoration: none;
    transform: translateY(-2px);
    border-color: rgba(147, 197, 253, .32);
    box-shadow: 0 8px 24px rgba(0, 0, 0, .35);
  }

  .deck-card-top {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 7px;
  }

  .rank-no {
    color: #93c5fd;
    font-size: 12px;
    font-weight: 900;
    margin-right: 2px;
  }

  .tier-badge,
  .confidence-badge {
    display: inline-block;
    padding: 2px 6px;
    font-size: 11px;
    font-weight: 900;
    border-radius: 6px;
  }

  .tier-s {
    background: #dc2626;
    color: #fff;
  }

  .tier-a {
    background: #f59e0b;
    color: #111;
  }

  .tier-b {
    background: #2563eb;
    color: #fff;
  }

  .tier-c {
    background: #6b7280;
    color: #fff;
  }

  .tier-observe {
    background: #475569;
    color: #dbeafe;
  }

  .confidence-高資料 {
    background: rgba(34, 197, 94, .18);
    color: #86efac;
  }

  .confidence-一般 {
    background: rgba(59, 130, 246, .18);
    color: #93c5fd;
  }

  .confidence-觀察中 {
    background: rgba(148, 163, 184, .16);
    color: #cbd5e1;
  }

  .deck-chars {
    position: relative;
    height: 70px;
    margin-bottom: 8px;
  }

  .char-card {
    position: absolute;
    top: 0;
    width: 70px;
    height: 70px;
    overflow: hidden;
    border-radius: 9px;
    background: #020617;
    border: 1px solid rgba(255, 255, 255, .15);
    transition: transform .12s ease;
  }

  .char-card img {
    width: 100%;
    height: 120%;
    object-fit: cover;
    object-position: top;
  }

  .char-card.c0 {
    left: 0;
    z-index: 1;
  }

  .char-card.c1 {
    left: 56px;
    z-index: 2;
  }

  .char-card.c2 {
    left: 112px;
    z-index: 3;
  }

  .deck-card:hover .char-card {
    transform: translateY(-2px);
  }

  .deck-name {
    color: #f8fafc;
    font-weight: 800;
    line-height: 1.45;
    margin-bottom: 7px;
  }

  .deck-score-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 10px;
    color: #cbd5e1;
    font-size: 12px;
    margin-bottom: 4px;
  }

  .deck-score-row strong {
    color: #fff;
  }

  .rate-help {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    cursor: help;
    border-bottom: 1px dotted rgba(232, 209, 158, .55);
  }

  .help-dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border-radius: 999px;
    background: rgba(232, 209, 158, .14);
    border: 1px solid rgba(232, 209, 158, .42);
    color: #f2deb2;
    font-size: 10px;
    font-weight: 900;
    line-height: 1;
  }

  .rate-help::after {
    content: attr(data-tooltip);
    position: absolute;
    left: 0;
    bottom: calc(100% + 8px);
    z-index: 20;
    width: min(280px, 78vw);
    padding: 9px 10px;
    border-radius: 10px;
    background: #020617;
    border: 1px solid rgba(232, 209, 158, .35);
    color: #e5e7eb;
    box-shadow: 0 12px 28px rgba(0, 0, 0, .42);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.55;
    white-space: normal;
    opacity: 0;
    pointer-events: none;
    transform: translateY(4px);
    transition: opacity .15s ease, transform .15s ease;
  }

  .rate-help:hover::after,
  .rate-help:focus::after {
    opacity: 1;
    transform: translateY(0);
  }

  .deck-meta {
    color: #9aa4b2;
    font-size: 12px;
    line-height: 1.55;
  }

  .empty-card {
    color: #94a3b8;
    border: 1px dashed rgba(148, 163, 184, .25);
    border-radius: 12px;
    padding: 14px;
    background: rgba(15, 23, 42, .45);
    font-size: 13px;
  }

  @media (max-width: 1199px) {
    .str-info-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .deck-grid,
    .deck-grid.is-watch {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 768px) {
    .str-block {
      padding: 14px;
    }

    .str-title {
      font-size: 1.45rem;
    }

    .str-info-grid,
    .deck-grid,
    .deck-grid.is-watch {
      grid-template-columns: 1fr;
    }

    .cost-head {
      display: block;
    }

    .cost-count {
      display: inline-block;
      margin-top: 8px;
    }

    .deck-chars {
      height: 56px;
    }

    .char-card {
      width: 56px;
      height: 56px;
      border-radius: 7px;
    }

    .char-card img {
      height: 125%;
    }

    .char-card.c1 {
      left: 45px;
    }

    .char-card.c2 {
      left: 90px;
    }
  }

  .str-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }


  .str-cost-tags {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }


  .str-cost-tag {
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 13px;
    text-decoration: none;

    background: rgba(255, 255, 255, .06);
    border: 1px solid rgba(255, 255, 255, .12);
    color: #cbd5e1;

    transition: .2s;
  }


  .str-weekly { margin: 14px 0 18px; }
  .str-weekly .str-desc { margin: 10px 0; }
  .str-cost-tag.active {
    background: #2563eb;
    border-color: #60a5fa;
    color: #fff;
  }
  .str-cost-tag:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }

  .str-cost-tag:hover {
    background: rgba(59, 130, 246, .18);
    border-color: #60a5fa;
    color: #fff;
  }
</style>

<!-- Content Wrapper -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-12">
          <div class="str-block">
            <div class="str-title">最強牌組排名｜強度排行</div>
            <div class="str-desc">
              依 <strong>COST 區間</strong> 統計近 <strong><?= (int)$days ?></strong> 天牌組表現；
              BP 不再分區，而是納入綜合評分。正式榜至少 5 場，3～4 場歸入觀察榜。
            </div>

            <div class="str-filter-bar">

              <div class="str-tabs">
                <a class="str-tab <?= $days === 7 ? 'active' : '' ?>" href="<?= str_h(strRankHref(7, $weeklyMode)) ?>">7 天</a>
                <a class="str-tab <?= $days === 14 ? 'active' : '' ?>" href="<?= str_h(strRankHref(14, $weeklyMode)) ?>">14 天</a>
                <a class="str-tab <?= $days === 30 ? 'active' : '' ?>" href="<?= str_h(strRankHref(30, $weeklyMode)) ?>">30 天</a>
              </div>


              <div class="str-cost-tags">

                <a class="str-cost-tag <?= $weeklyMode ? 'active' : '' ?>" href="<?= str_h(strRankHref($days, true)) ?>" <?= $weeklyMode ? 'aria-current="page"' : '' ?>>本週 COST 規則</a>
                <?php if ($weeklyMode): ?>
                  <a class="str-cost-tag" href="<?= str_h(strRankHref($days, false)) ?>">全部 COST 區間</a>
                <?php else: ?>
                  <?php foreach ($labels as $key => $label): ?>
                    <a class="str-cost-tag" href="#cost-<?= str_h($key) ?>">COST <?= str_h($key) ?></a>
                  <?php endforeach; ?>
                <?php endif; ?>

              </div>

            </div>

            <?php if ($weeklyMode): ?>
              <div class="str-weekly" aria-label="本週 COST 規則">
                <?php if ($weeklyRule): ?>
                  <p class="str-desc">生效日 <?= str_h($weeklyRule['effective_date']) ?> · 近 <?= (int)$days ?> 天歷史牌組；依本週 COST 與現行卡片 COST 篩選，非當週或分區對戰統計。</p>
                  <div class="str-cost-tags">
                    <?php foreach ($labels as $key => $label): ?>
                      <a class="str-cost-tag" href="#cost-<?= str_h($key) ?>"><?= str_h($label['title']) ?></a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <p class="empty-card" role="status">目前無有效且已核准的本週 COST 資料，請稍後再試或查看全部 COST 區間。</p>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="str-info-grid">
              <div class="str-info-card">
                <div class="str-info-label">排序依據</div>
                <div class="str-info-value">綜合分</div>
              </div>
              <div class="str-info-card">
                <div class="str-info-label">正式入榜</div>
                <div class="str-info-value">≥ 5 場</div>
              </div>
              <div class="str-info-card">
                <div class="str-info-label">觀察名單</div>
                <div class="str-info-value">3–4 場</div>
              </div>
            </div>

            <?php foreach ($labels as $costRange => $info): ?>
              <?php
              $officialTeams = $rankData[$costRange]['official'] ?? [];
              $watchTeams = $rankData[$costRange]['watch'] ?? [];
              ?>

              <section class="cost-section" id="cost-<?= str_h($costRange) ?>">
                <div class="cost-head">
                  <div>
                    <div class="cost-title"><?= str_h($info['title']) ?></div>
                    <div class="cost-note"><?= str_h($info['note']) ?></div>
                  </div>
                  <div class="cost-count">
                    正式 <?= count($officialTeams) ?> 組 · 觀察 <?= count($watchTeams) ?> 組
                  </div>
                </div>

                <div class="rank-subtitle">
                  🏆 正式強度排行
                  <small>至少 5 場，依綜合分排序</small>
                </div>

                <?php if (empty($officialTeams)): ?>
                  <div class="empty-card">目前此區間正式樣本不足。</div>
                <?php else: ?>
                  <div class="deck-grid">
                    <?php foreach ($officialTeams as $idx => $team): ?>
                      <?= strRenderDeckCard($team, $idx + 1) ?>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <div class="rank-subtitle">
                  🔎 潛力牌組觀察
                  <small>3～4 場，僅供參考</small>
                </div>

                <?php if (empty($watchTeams)): ?>
                  <div class="empty-card">目前沒有符合條件的觀察牌組。</div>
                <?php else: ?>
                  <div class="deck-grid is-watch">
                    <?php foreach ($watchTeams as $idx => $team): ?>
                      <?= strRenderDeckCard($team, 0) ?>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </section>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>