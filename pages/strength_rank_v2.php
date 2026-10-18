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
  : 30;

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
    return ['觀察', 'tier-observe'];
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
function strFetchMetaRankRows(PDO $pdo, int $days): array
{
  $days = in_array($days, [7, 14, 30], true) ? $days : 30;

  $sql = "
    WITH deck_stat AS (
      SELECT
        CASE
          WHEN a.cost BETWEEN 50 AND 59 THEN '50-59'
          WHEN a.cost BETWEEN 60 AND 69 THEN '60-69'
          WHEN a.cost BETWEEN 70 AND 80 THEN '70-80'
          WHEN a.cost >= 90 THEN '90+'
        END AS cost_range,

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
        ROUND(AVG((IFNULL(a.player_bp, 0) + IFNULL(o.player_bp, 0)) / 2), 1) AS avg_match_bp,

        MIN(a.update_time) AS first_seen,
        MAX(a.update_time) AS last_seen

      FROM arena_player_match_result a
      JOIN arena_player_match_result o
        ON o.match_id = a.match_id
       AND o.side <> a.side

      WHERE a.update_time >= NOW() - INTERVAL {$days} DAY
        AND a.cost IS NOT NULL
        AND a.cost >= 50
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
        WHEN matches >= 10 THEN '高可信'
        WHEN matches >= 5 THEN '可信'
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
function strBuildRankData(PDO $pdo, int $days, array $labels): array
{
  $rankData = [];
  foreach ($labels as $key => $_) {
    $rankData[$key] = [
      'official' => [],
      'watch' => [],
    ];
  }

  $rows = strFetchMetaRankRows($pdo, $days);

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
        <span>綜合分 <strong>' . str_h($team['meta_score']) . '</strong></span>
        <span>修正勝率 <strong>' . str_h($team['adjusted_win_rate']) . '%</strong></span>
      </div>

      <div class="deck-meta">
        原始勝率 ' . str_h($team['raw_win_rate']) . '% · ' . (int)$team['matches'] . ' 場 ' . (int)$team['wins'] . ' 勝 ' . (int)$team['losses'] . ' 敗
      </div>
      <div class="deck-meta">
        平均 BP ' . str_h($team['avg_match_bp']) . ' · 使用者 ' . str_h($team['avg_player_bp']) . ' / 對手 ' . str_h($team['avg_opponent_bp']) . '
      </div>
    </a>
  ';
}

$rankData = strBuildRankData($pdo, $days, $labels);
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
    grid-template-columns: repeat(4, minmax(0, 1fr));
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

  .tier-s { background: #dc2626; color: #fff; }
  .tier-a { background: #f59e0b; color: #111; }
  .tier-b { background: #2563eb; color: #fff; }
  .tier-c { background: #6b7280; color: #fff; }
  .tier-observe { background: #475569; color: #dbeafe; }

  .confidence-高可信 { background: rgba(34, 197, 94, .18); color: #86efac; }
  .confidence-可信 { background: rgba(59, 130, 246, .18); color: #93c5fd; }
  .confidence-觀察中 { background: rgba(148, 163, 184, .16); color: #cbd5e1; }

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

  .char-card.c0 { left: 0; z-index: 1; }
  .char-card.c1 { left: 56px; z-index: 2; }
  .char-card.c2 { left: 112px; z-index: 3; }

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

    .char-card.c1 { left: 45px; }
    .char-card.c2 { left: 90px; }
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

            <div class="str-tabs">
              <a class="str-tab <?= $days === 7 ? 'active' : '' ?>" href="?days=7">7 天</a>
              <a class="str-tab <?= $days === 14 ? 'active' : '' ?>" href="?days=14">14 天</a>
              <a class="str-tab <?= $days === 30 ? 'active' : '' ?>" href="?days=30">30 天</a>
            </div>

            <div class="str-info-grid">
              <div class="str-info-card">
                <div class="str-info-label">排序依據</div>
                <div class="str-info-value">綜合分</div>
              </div>
              <div class="str-info-card">
                <div class="str-info-label">勝率校正</div>
                <div class="str-info-value">Bayesian Win-Rate Calibration</div>
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
