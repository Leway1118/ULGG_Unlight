<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/_demo_data.php';
$pdo = $db;

$pageTitleText = 'ULGG 自定義天梯';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = 'ruleset';

$escape = static fn(mixed $value): string =>
htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

/*
 * MVP：只啟用一條 ULGG 自定義天梯。
 * 目前先從共用 Demo Data 取第一個 Published + Official 版本。
 * 後續接資料庫時，只需要替換這段資料來源，不必重做頁面結構。
 */
$activeRuleset = null;

foreach ($rulesetVersions as $candidateRuleset) {
  if (
    ($candidateRuleset['tier'] ?? '') === 'OFFICIAL'
    && ($candidateRuleset['lifecycle_status'] ?? '') === 'PUBLISHED'
  ) {
    $activeRuleset = $candidateRuleset;
    break;
  }
}

/*
 * MVP 暫定 Alexandre 本週 COST。
 * 下一階段改由 quickmatch_weekly_cost 讀取最新 APPROVED 資料。
 */
$weeklyCost = [
  'ladder_zone_code' => '自訂對戰區 : 迪特赫姆 / 布萊德',
  'effective_date' => '2026-07-28',
  'source_title' => 'Steam Weekly Ranking COST',
  'categories' => [
    [
      'key' => '1',
      'label' => '56',
      'description' => '雙方牌組重新計算後皆須為 56 COST。',
    ],
    [
      'key' => '2',
      'label' => '69',
      'description' => '雙方牌組重新計算後皆須為 69 COST。',
    ],
    [
      'key' => '3',
      'label' => '71',
      'description' => '雙方牌組重新計算後皆須為 71 COST。',
    ],
    [
      'key' => '4',
      'label' => '90+',
      'description' => '雙方皆須至少 90 COST，且配對後差距不得超過 5。',
    ],
  ],
];

$summaryRules = array_slice($activeRuleset['rules'] ?? [], 0, 3);
$fullRules = $activeRuleset['full_rules'] ?? $activeRuleset['rules'] ?? [];

/*
 * CP 排行榜
 * 每月為獨立賽季，玩家首次參賽從 1500 CP 開始。
 */
$currentCpSeason = date('Y-m');
$cpLeaderboard = [];
$cpLeaderboardError = null;

try {
  $cpStmt = $pdo->prepare("
    SELECT
      user_id,
      game_name,
      cp,
      wins,
      draws,
      losses,
      matches,
      updated_at
    FROM custom_match_cp_rating
    WHERE season_month = :season_month
    ORDER BY
      cp DESC,
      wins DESC,
      losses ASC,
      matches DESC,
      updated_at ASC,
      user_id ASC
    LIMIT 100
  ");

  $cpStmt->execute([
    ':season_month' => $currentCpSeason,
  ]);

  $cpRows = $cpStmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($cpRows as $index => $row) {
    $wins = (int)($row['wins'] ?? 0);
    $draws = (int)($row['draws'] ?? 0);
    $losses = (int)($row['losses'] ?? 0);
    $matches = (int)($row['matches'] ?? 0);
    $cp = (int)($row['cp'] ?? 1500);

    /*
     * 勝率目前採純勝場／總場次。
     * 平手列入總場次，但不算勝場。
     */
    $winRate = $matches > 0
      ? ($wins / $matches) * 100
      : 0.0;

    /*
     * 目前沒有每週 CP 快照，因此「變動」先顯示
     * 本月相對起始 1500 CP 的淨變化。
     */
    $cpChange = $cp - 1500;

    $cpLeaderboard[] = [
      'rank' => $index + 1,
      'user_id' => (int)($row['user_id'] ?? 0),
      'player_name' => (string)($row['game_name'] ?? ''),
      'cp' => $cp,
      'wins' => $wins,
      'draws' => $draws,
      'losses' => $losses,
      'matches' => $matches,
      'win_rate' => $winRate,
      'cp_change' => $cpChange,
    ];
  }
} catch (Throwable $error) {
  $cpLeaderboardError = $error->getMessage();

  error_log(
    '[ULGG CP leaderboard] '
      . $error->getMessage()
  );
}
/*
 * 最近完成的 ULGG 自定義天梯對戰。
 *
 * 公開頁面不查詢玩家名稱與 user_id，
 * 避免名稱只是在畫面隱藏、實際仍出現在 HTML。
 */
$customMatchHistory = [];
$customMatchHistoryError = null;

try {
  $historyStmt = $pdo->prepare("
    SELECT
      c.id,
      c.ulgg_match_id,
      c.rule_version_id,
      c.ruleset_cost_p1,
      c.ruleset_cost_p2,
      c.win,
      c.lose,
      c.tie,
      c.update_time,

      p1c1.ico AS p1_ico_1,
      p1c2.ico AS p1_ico_2,
      p1c3.ico AS p1_ico_3,

      p2c1.ico AS p2_ico_1,
      p2c2.ico AS p2_ico_2,
      p2c3.ico AS p2_ico_3

    FROM arena_unlight_custom AS c

    LEFT JOIN unlight AS p1c1
      ON p1c1.id = c.e1

    LEFT JOIN unlight AS p1c2
      ON p1c2.id = c.e2

    LEFT JOIN unlight AS p1c3
      ON p1c3.id = c.e3

    LEFT JOIN unlight AS p2c1
      ON p2c1.id = c.u1

    LEFT JOIN unlight AS p2c2
      ON p2c2.id = c.u2

    LEFT JOIN unlight AS p2c3
      ON p2c3.id = c.u3

    WHERE (
        c.win = 1
        OR c.lose = 1
        OR c.tie = 1
      )

    ORDER BY
      c.update_time DESC,
      c.id DESC

    LIMIT 20
  ");

  $historyStmt->execute();

  $customMatchHistory = $historyStmt->fetchAll(
    PDO::FETCH_ASSOC
  );
} catch (Throwable $error) {
  $customMatchHistoryError = $error->getMessage();

  error_log(
    '[ULGG custom match history] '
      . $error->getMessage()
  );
}
ob_start();
?>

<style>
  .ruleset-ladder-page {
    --ladder-panel: rgba(31, 34, 45, .95);
    --ladder-soft: rgba(255, 255, 255, .045);
    --ladder-line: rgba(255, 255, 255, .1);
    --ladder-text: #eef0f8;
    --ladder-muted: #a7adbd;
    --ladder-accent: #b8beff;
    color: var(--ladder-text);
  }

  .ruleset-ladder-shell {
    width: 100%;
    padding: 24px 0 48px;
  }

  .ruleset-ladder-panel {
    border: 1px solid var(--ladder-line);
    border-radius: 14px;
    background: var(--ladder-panel);
    box-shadow: 0 12px 30px rgba(0, 0, 0, .16);
  }

  .ruleset-ladder-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 22px;
    padding: 22px 24px;
  }

  .ruleset-ladder-kicker {
    margin: 0 0 5px;
    color: var(--ladder-accent);
    font-size: .74rem;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
  }

  .ruleset-ladder-title {
    margin: 0;
    color: #fff;
    font-size: clamp(1.7rem, 3vw, 2.4rem);
    font-weight: 850;
  }

  .ruleset-ladder-subtitle {
    max-width: 760px;
    margin: 7px 0 0;
    color: var(--ladder-muted);
    line-height: 1.65;
  }

  .ruleset-ladder-demo-note {
    max-width: 350px;
    padding: 10px 13px;
    border: 1px solid rgba(238, 200, 112, .28);
    border-radius: 10px;
    background: rgba(238, 200, 112, .08);
    color: #e8d49d;
    font-size: .8rem;
    line-height: 1.55;
  }

  .ruleset-ladder-main {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(320px, .65fr);
    gap: 18px;
    margin-top: 18px;
  }

  .ruleset-ladder-section {
    padding: 20px;
  }

  .ruleset-ladder-section+.ruleset-ladder-section {
    margin-top: 18px;
  }

  .ruleset-ladder-section-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
  }

  .ruleset-ladder-section-title {
    margin: 0;
    color: #fff;
    font-size: 1.15rem;
    font-weight: 820;
  }

  .ruleset-ladder-section-description {
    margin: 5px 0 0;
    color: var(--ladder-muted);
    font-size: .82rem;
    line-height: 1.55;
  }

  .ruleset-ladder-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
  }

  .ruleset-ladder-badge {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 4px 9px;
    border: 1px solid var(--ladder-line);
    border-radius: 999px;
    color: var(--ladder-muted);
    font-size: .69rem;
    font-weight: 800;
  }

  .ruleset-ladder-badge.is-official {
    border-color: rgba(232, 196, 98, .3);
    color: #e7d08b;
  }

  .ruleset-ladder-badge.is-published {
    border-color: rgba(115, 210, 164, .28);
    color: #93ddb7;
  }

  .ruleset-ladder-rule-name {
    margin: 0;
    color: #fff;
    font-size: 1.15rem;
    font-weight: 850;
  }

  .ruleset-ladder-rule-version {
    margin: 4px 0 0;
    color: var(--ladder-muted);
    font-size: .81rem;
  }

  .ruleset-ladder-rule-list {
    display: grid;
    gap: 8px;
    margin: 15px 0 0;
    padding: 0;
    list-style: none;
  }

  .ruleset-ladder-rule-list li {
    position: relative;
    padding-left: 15px;
    color: #d1d4e1;
    font-size: .82rem;
    line-height: 1.55;
  }

  .ruleset-ladder-rule-list li::before {
    position: absolute;
    top: .62em;
    left: 0;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--ladder-accent);
    content: '';
  }

  .ruleset-ladder-cost-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
  }

  .ruleset-ladder-cost-option {
    position: relative;
  }

  .ruleset-ladder-cost-option input {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
  }

  .ruleset-ladder-cost-card {
    display: flex;
    min-height: 150px;
    flex-direction: column;
    justify-content: space-between;
    gap: 12px;
    padding: 15px;
    border: 1px solid var(--ladder-line);
    border-radius: 11px;
    background: var(--ladder-soft);
    cursor: pointer;
    transition: border-color .16s ease, background .16s ease, transform .16s ease;
  }

  .ruleset-ladder-cost-card:hover {
    border-color: rgba(184, 190, 255, .42);
    transform: translateY(-1px);
  }

  .ruleset-ladder-cost-option input:checked+.ruleset-ladder-cost-card {
    border-color: rgba(184, 190, 255, .78);
    background: rgba(184, 190, 255, .14);
    box-shadow: 0 0 0 3px rgba(184, 190, 255, .09);
  }

  .ruleset-ladder-cost-label {
    color: #fff;
    font-size: 2rem;
    font-weight: 900;
  }

  .ruleset-ladder-cost-description {
    color: var(--ladder-muted);
    font-size: .74rem;
    line-height: 1.5;
  }

  .ruleset-ladder-ranking {
    display: grid;
    gap: 8px;
  }

  .ruleset-ladder-ranking-head,
  .ruleset-ladder-ranking-row {
    display: grid;
    grid-template-columns:
      54px minmax(150px, 1fr) 90px 110px 90px;
    align-items: center;
    gap: 12px;
  }

  .ruleset-ladder-ranking-head {
    padding: 0 13px 7px;
    color: var(--ladder-muted);
    font-size: .7rem;
    font-weight: 800;
  }

  .ruleset-ladder-ranking-row {
    min-height: 58px;
    padding: 10px 13px;
    border: 1px solid var(--ladder-line);
    border-radius: 10px;
    background: var(--ladder-soft);
  }

  .ruleset-ladder-ranking-row.is-top-three {
    border-color: rgba(232, 196, 98, .22);
    background: rgba(232, 196, 98, .055);
  }

  .ruleset-ladder-ranking-rank {
    color: var(--ladder-muted);
    font-size: .86rem;
    font-weight: 900;
    text-align: center;
  }

  .ruleset-ladder-ranking-row.is-top-three .ruleset-ladder-ranking-rank {
    color: #e7d08b;
  }

  .ruleset-ladder-ranking-player {
    min-width: 0;
  }

  .ruleset-ladder-ranking-name {
    display: block;
    overflow: hidden;
    color: #fff;
    font-size: .9rem;
    font-weight: 820;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ruleset-ladder-ranking-record {
    display: block;
    margin-top: 3px;
    color: var(--ladder-muted);
    font-size: .7rem;
  }

  .ruleset-ladder-ranking-cp {
    color: #fff;
    font-size: 1rem;
    font-weight: 900;
    text-align: right;
  }

  .ruleset-ladder-ranking-win-rate {
    color: #d5d8e8;
    font-size: .8rem;
    font-weight: 750;
    text-align: right;
  }

  .ruleset-ladder-ranking-trend {
    font-size: .77rem;
    font-weight: 820;
    text-align: right;
  }

  .ruleset-ladder-ranking-trend.is-up {
    color: #93ddb7;
  }

  .ruleset-ladder-ranking-trend.is-down {
    color: #efaaaa;
  }

  .ruleset-ladder-ranking-trend.is-same {
    color: var(--ladder-muted);
  }

  .ruleset-ladder-ranking-note {
    margin: 13px 0 0;
    color: var(--ladder-muted);
    font-size: .74rem;
    line-height: 1.55;
  }

  .ruleset-ladder-match-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--ladder-line);
  }

  .ruleset-ladder-match-note {
    margin: 0;
    color: var(--ladder-muted);
    font-size: 1.5rem;
    line-height: 1.55;
  }

  .ruleset-ladder-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 9px 18px;
    border: 1px solid rgba(184, 190, 255, .5);
    border-radius: 9px;
    background: rgba(184, 190, 255, .16);
    color: #f1f2ff;
    font-size: .86rem;
    font-weight: 820;
    cursor: pointer;
  }

  .ruleset-ladder-button:hover,
  .ruleset-ladder-button:focus {
    border-color: rgba(184, 190, 255, .82);
    background: rgba(184, 190, 255, .23);
  }

  .ruleset-ladder-button:disabled {
    cursor: not-allowed;
    opacity: .45;
  }

  .ruleset-ladder-cancel-button {
    display: none;
    min-height: 42px;
    padding: 9px 16px;
    border: 1px solid rgba(255, 135, 135, .48);
    border-radius: 9px;
    background: rgba(255, 110, 110, .12);
    color: #ffd9d9;
    font-size: .86rem;
    font-weight: 820;
    cursor: pointer;
  }

  .ruleset-ladder-cancel-button:hover,
  .ruleset-ladder-cancel-button:focus {
    border-color: rgba(255, 150, 150, .82);
    background: rgba(255, 110, 110, .2);
    color: #fff;
  }

  .ruleset-ladder-cancel-button.is-visible {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .ruleset-ladder-cancel-button:disabled {
    cursor: not-allowed;
    opacity: .5;
  }

  .ruleset-ladder-status {
    display: grid;
    gap: 12px;
  }

  .ruleset-ladder-status-card {
    padding: 14px;
    border: 1px solid var(--ladder-line);
    border-radius: 10px;
    background: var(--ladder-soft);
  }

  .ruleset-ladder-status-label {
    color: var(--ladder-muted);
    font-size: .71rem;
    font-weight: 800;
  }

  .ruleset-ladder-status-value {
    display: block;
    margin-top: 4px;
    color: #fff;
    font-size: .95rem;
    font-weight: 820;
  }

  .ruleset-ladder-flow {
    display: grid;
    gap: 9px;
    margin: 0;
    padding: 0;
    list-style: none;
    counter-reset: ladder-flow;
  }

  .ruleset-ladder-flow li {
    position: relative;
    padding: 11px 12px 11px 42px;
    border: 1px solid var(--ladder-line);
    border-radius: 9px;
    background: var(--ladder-soft);
    color: #d4d7e3;
    font-size: 1.5rem;
    line-height: 1.5;
    counter-increment: ladder-flow;
  }

  .ruleset-ladder-flow li::before {
    position: absolute;
    top: 10px;
    left: 11px;
    display: grid;
    width: 22px;
    height: 22px;
    place-items: center;
    border-radius: 50%;
    background: rgba(184, 190, 255, .14);
    color: #dfe1ff;
    font-size: .68rem;
    font-weight: 850;
    content: counter(ladder-flow);
  }

  .ruleset-ladder-result {
    margin-top: 14px;
    padding: 11px 13px;
    border: 1px solid rgba(238, 200, 112, .28);
    border-radius: 9px;
    background: rgba(238, 200, 112, .08);
    color: #e8d49d;
    font-size: .8rem;
    line-height: 1.55;
  }

  .ruleset-ladder-result[hidden] {
    display: none;
  }

  .ruleset-ladder-full-rules {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--ladder-line);
  }

  .ruleset-ladder-full-rules[hidden] {
    display: none;
  }

  .ruleset-ladder-code {
    overflow-wrap: anywhere;
    color: #dce0ef;
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  }

  @media (max-width: 1050px) {
    .ruleset-ladder-main {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 767px) {
    .ruleset-ladder-ranking-head {
      display: none;
    }

    .ruleset-ladder-ranking-row {
      grid-template-columns: 48px minmax(0, 1fr) auto;
      gap: 10px;
      min-height: 76px;
      padding: 13px;
    }

    .ruleset-ladder-ranking-rank {
      font-size: 1.35rem;
    }

    .ruleset-ladder-ranking-name {
      font-size: 1.44rem;
    }

    .ruleset-ladder-ranking-record {
      font-size: 1.14rem;
    }

    .ruleset-ladder-ranking-cp {
      font-size: 1.55rem;
    }

    .ruleset-ladder-ranking-win-rate {
      grid-column: 2;
      font-size: 1.15rem;
      text-align: left;
    }

    .ruleset-ladder-ranking-trend {
      grid-column: 3;
      font-size: 1.15rem;
    }

    .ruleset-ladder-ranking-note {
      font-size: 1.2rem;
      line-height: 1.65;
    }

    .ruleset-ladder-shell {
      padding-top: 14px;
    }

    .ruleset-ladder-header,
    .ruleset-ladder-section-head,
    .ruleset-ladder-match-actions {
      align-items: flex-start;
      flex-direction: column;
    }

    .ruleset-ladder-header,
    .ruleset-ladder-section {
      padding: 17px;
    }

    .ruleset-ladder-demo-note {
      width: 100%;
      max-width: none;
    }

    .ruleset-ladder-cost-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ruleset-ladder-button,
    .ruleset-ladder-cancel-button {
      width: 100%;
      min-height: 50px;
      font-size: 1.5rem;
    }

    .ruleset-ladder-title {
      font-size: 3rem;
    }

    .ruleset-ladder-subtitle,
    .ruleset-ladder-section-description,
    .ruleset-ladder-match-note,
    .ruleset-ladder-rule-list li,
    .ruleset-ladder-flow li,
    .ruleset-ladder-result,
    .ruleset-ladder-demo-note {
      font-size: 1.44rem;
      line-height: 1.7;
    }

    .ruleset-ladder-section-title,
    .ruleset-ladder-rule-name {
      font-size: 1.95rem;
    }

    .ruleset-ladder-kicker,
    .ruleset-ladder-badge,
    .ruleset-ladder-status-label,
    .ruleset-ladder-rule-version {
      font-size: 1.29rem;
    }

    .ruleset-ladder-cost-label {
      font-size: 2.7rem;
    }

    .ruleset-ladder-cost-description {
      font-size: 1.38rem;
      line-height: 1.65;
    }

    .ruleset-ladder-cost-card {
      min-height: 168px;
      padding: 18px;
    }

    .ruleset-ladder-download {
      align-items: stretch;
      flex-direction: column;
    }

    .ruleset-ladder-download-button {
      width: 100%;
    }
  }

  @media (max-width: 440px) {
    .ruleset-ladder-cost-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .ruleset-ladder-cost-card {
      min-height: 150px;
      padding: 14px;
    }

    .ruleset-ladder-cost-label {
      font-size: 2.2rem;
    }

    .ruleset-ladder-cost-description {
      font-size: 1.1rem;
      line-height: 1.5;
    }
  }

  .ruleset-ladder-download {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin: 18px 0 22px;
  }

  .ruleset-ladder-download-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 10px 18px;
    border: 1px solid rgba(212, 175, 55, 0.65);
    border-radius: 8px;
    background: linear-gradient(135deg,
        rgba(212, 175, 55, 0.22),
        rgba(212, 175, 55, 0.08));
    color: #f2d77e;
    font-weight: 700;
    text-decoration: none;
    transition:
      transform 0.18s ease,
      border-color 0.18s ease,
      background 0.18s ease;
  }

  .ruleset-ladder-download-button:hover {
    transform: translateY(-1px);
    border-color: #f2d77e;
    background: rgba(212, 175, 55, 0.28);
  }

  .ruleset-ladder-download-note {
    color: rgba(255, 255, 255, 0.68);
    font-size: 0.9rem;
  }

  .ruleset-ladder-push-button {
    flex: 0 0 auto;
    min-height: 38px;
    padding: 8px 13px;
    border: 1px solid rgba(184, 190, 255, .38);
    border-radius: 999px;
    background: rgba(184, 190, 255, .12);
    color: #dfe2ff;
    cursor: pointer;
    font-size: 1.5rem;
    font-weight: 850;
    transition:
      transform .16s ease,
      border-color .16s ease,
      background .16s ease;
  }

  .ruleset-ladder-push-button:hover {
    transform: translateY(-1px);
    border-color: rgba(184, 190, 255, .7);
    background: rgba(184, 190, 255, .2);
  }

  .ruleset-ladder-push-button.is-subscribed {
    color: #a9efca;
    border-color: rgba(115, 210, 164, .4);
    background: rgba(115, 210, 164, .12);
  }

  .ruleset-ladder-push-button.is-disabled {
    cursor: not-allowed;
    opacity: .55;
  }

  @media (max-width: 768px) {
    .ruleset-ladder-section-head {
      flex-direction: column;
    }

    .ruleset-ladder-push-button {
      width: 100%;
    }
  }

  /* =========================
     Custom Match History
  ========================= */

  .ruleset-ladder-history {
    display: grid;
    gap: 11px;
  }

  .ruleset-ladder-history-card {
    display: grid;
    grid-template-columns:
      minmax(0, 1fr) 150px minmax(0, 1fr);
    align-items: center;
    gap: 18px;
    min-height: 104px;
    padding: 15px 17px;
    border: 1px solid var(--ladder-line);
    border-radius: 12px;
    background:
      linear-gradient(180deg,
        rgba(255, 255, 255, .055),
        rgba(255, 255, 255, .025));
  }

  .ruleset-ladder-history-side {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }

  .ruleset-ladder-history-side.is-right {
    justify-content: flex-end;
  }

  .ruleset-ladder-history-player {
    min-width: 82px;
  }

  .ruleset-ladder-history-side.is-right .ruleset-ladder-history-player {
    text-align: right;
  }

  .ruleset-ladder-history-name {
    display: block;
    color: #f2f3fa;
    font-size: .84rem;
    font-weight: 820;
  }

  .ruleset-ladder-history-cost {
    display: block;
    margin-top: 3px;
    color: var(--ladder-muted);
    font-size: .7rem;
  }

  .ruleset-ladder-history-deck {
    display: flex;
    gap: 5px;
    flex: 0 0 auto;
  }

  .ruleset-ladder-history-char {
    width: 45px;
    height: 60px;
    border: 1px solid rgba(255, 255, 255, .15);
    border-radius: 6px;
    background: rgba(255, 255, 255, .04);
    object-fit: cover;
  }

  .ruleset-ladder-history-placeholder {
    display: block;
    width: 45px;
    height: 60px;
    border: 1px dashed rgba(255, 255, 255, .16);
    border-radius: 6px;
    background: rgba(255, 255, 255, .025);
  }

  .ruleset-ladder-history-side {
    padding: 10px;
    border: 1px solid transparent;
    border-radius: 10px;
    transition:
      border-color .16s ease,
      background .16s ease,
      box-shadow .16s ease;
  }

  .ruleset-ladder-history-side.is-winner {
    border-color: rgba(115, 210, 164, .4);
    background: rgba(115, 210, 164, .09);
    box-shadow: inset 0 0 18px rgba(115, 210, 164, .05);
  }

  .ruleset-ladder-history-side.is-loser {
    border-color: rgba(239, 170, 170, .18);
    background: rgba(239, 170, 170, .035);
    opacity: .82;
  }

  .ruleset-ladder-history-side.is-draw {
    border-color: rgba(184, 190, 255, .25);
    background: rgba(184, 190, 255, .06);
  }

  .ruleset-ladder-history-player-heading {
    display: flex;
    align-items: center;
    gap: 7px;
  }

  .ruleset-ladder-history-side.is-right .ruleset-ladder-history-player-heading {
    justify-content: flex-end;
  }

  .ruleset-ladder-history-side-result {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    min-height: 23px;
    padding: 2px 7px;
    border-radius: 999px;
    font-size: .67rem;
    font-weight: 900;
    line-height: 1;
  }

  .ruleset-ladder-history-side-result.is-win {
    border: 1px solid rgba(115, 210, 164, .48);
    background: rgba(115, 210, 164, .16);
    color: #9be4bf;
  }

  .ruleset-ladder-history-side-result.is-lose {
    border: 1px solid rgba(239, 170, 170, .35);
    background: rgba(239, 170, 170, .1);
    color: #efaaaa;
  }

  .ruleset-ladder-history-side-result.is-draw {
    border: 1px solid rgba(184, 190, 255, .35);
    background: rgba(184, 190, 255, .1);
    color: #dfe1ff;
  }

  .ruleset-ladder-history-vs {
    display: block;
    color: rgba(255, 255, 255, .58);
    font-size: 1rem;
    font-weight: 900;
    letter-spacing: .12em;
  }

  .ruleset-ladder-history-center {
    text-align: center;
  }



  .ruleset-ladder-history-meta {
    display: block;
    margin-top: 7px;
    color: var(--ladder-muted);
    font-size: .68rem;
    line-height: 1.5;
  }

  .ruleset-ladder-history-empty {
    padding: 18px;
    border: 1px dashed var(--ladder-line);
    border-radius: 10px;
    color: var(--ladder-muted);
    text-align: center;
  }

  @media (max-width: 767px) {
    .ruleset-ladder-history-card {
      grid-template-columns: 1fr;
      gap: 14px;
      padding: 16px;
    }

    .ruleset-ladder-history-side,
    .ruleset-ladder-history-side.is-right {
      justify-content: space-between;
    }

    .ruleset-ladder-history-side.is-right {
      flex-direction: row-reverse;
    }

    .ruleset-ladder-history-side.is-right .ruleset-ladder-history-player {
      text-align: left;
    }

    .ruleset-ladder-history-center {
      padding: 8px 0;
      border-top: 1px solid var(--ladder-line);
      border-bottom: 1px solid var(--ladder-line);
    }

    .ruleset-ladder-history-name {
      font-size: 1.35rem;
    }

    .ruleset-ladder-history-cost,
    .ruleset-ladder-history-meta {
      font-size: 1.08rem;
    }

    .ruleset-ladder-history-result {
      min-height: 42px;
      padding: 7px 15px;
      font-size: 1.25rem;
    }

    .ruleset-ladder-history-char,
    .ruleset-ladder-history-placeholder {
      width: 52px;
      height: 70px;
    }

    .ruleset-ladder-history-side-result {
      min-width: 38px;
      min-height: 32px;
      padding: 4px 10px;
      font-size: 1.05rem;
    }

    .ruleset-ladder-history-vs {
      font-size: 1.3rem;
    }

    .ruleset-ladder-history-side.is-right .ruleset-ladder-history-player-heading {
      justify-content: flex-start;
    }
  }
</style>

<div class="content-wrapper ruleset-ladder-page">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="ruleset-ladder-shell">
        <header class="ruleset-ladder-panel ruleset-ladder-header">
          <div>
            <p class="ruleset-ladder-kicker">ULGG Custom Ladder</p>
            <h1 class="ruleset-ladder-title">ULGG 自定義天梯</h1>
            <p class="ruleset-ladder-subtitle">
              選擇本週官方 COST 類別後進入盲配。配對完成後，系統才會讀取雙方實際牌組，
              並依鎖定的 規則表 規則重新計算 COST。
            </p>
          </div>

          <div class="ruleset-ladder-demo-note" role="note">
            <strong>🧪 MVP 排隊測試</strong><br>
            已接入正式排隊 API。玩家需先完成網站與 Steam 登入，配對後再進行牌組驗證。
          </div>
        </header>

        <?php if ($activeRuleset === null): ?>
          <section class="ruleset-ladder-panel ruleset-ladder-section">
            <h2 class="ruleset-ladder-section-title">目前沒有可用規則</h2>
            <p class="ruleset-ladder-section-description">
              系統找不到 Published + Official 的 Ruleset Version，因此暫時無法開始配對。
            </p>
          </section>
        <?php else: ?>
          <div class="ruleset-ladder-main">
            <div>
              <section class="ruleset-ladder-panel ruleset-ladder-section" aria-labelledby="weekly-cost-title">
                <div class="ruleset-ladder-section-head">
                  <div>
                    <p class="ruleset-ladder-kicker">Weekly COST</p>
                    <h2 class="ruleset-ladder-section-title" id="weekly-cost-title">
                      選擇本週 COST
                    </h2>

                    <p class="ruleset-ladder-section-description">
                      <?= $escape($weeklyCost['ladder_zone_code']) ?> ·
                      生效日 <?= $escape($weeklyCost['effective_date']) ?> 10:00 (UTC+08:00) 維修後。
                    </p>
                  </div>

                  <?php if (!empty($_SESSION['user_id'])): ?>
                    <button
                      type="button"
                      class="ruleset-ladder-push-button"
                      id="rulesetLadderPushButton">
                      🔔 開啟配對推播
                    </button>
                  <?php endif; ?>
                </div>

                <form id="ruleset-ladder-match-form" novalidate>
                  <div class="ruleset-ladder-cost-grid">
                    <?php foreach ($weeklyCost['categories'] as $category): ?>
                      <label class="ruleset-ladder-cost-option">
                        <input
                          type="radio"
                          name="cost_category"
                          value="<?= $escape($category['key']) ?>"
                          data-cost-label="<?= $escape($category['label']) ?>">
                        <span class="ruleset-ladder-cost-card">
                          <strong class="ruleset-ladder-cost-label"><?= $escape($category['label']) ?></strong>
                          <span class="ruleset-ladder-cost-description">
                            <?= $escape($category['description']) ?>
                          </span>
                        </span>
                      </label>
                    <?php endforeach; ?>
                  </div>

                  <div class="ruleset-ladder-match-actions">
                    <p class="ruleset-ladder-match-note" id="ruleset-ladder-selection-note">
                      請先選擇一個 COST 類別。
                    </p>

                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                      <button
                        class="ruleset-ladder-button"
                        id="ruleset-ladder-start"
                        type="button"
                        disabled>
                        開始配對
                      </button>
                      <button
                        class="ruleset-ladder-cancel-button"
                        id="ruleset-ladder-cancel"
                        type="button">
                        取消排隊
                      </button>
                    </div>
                  </div>

                  <div
                    class="ruleset-ladder-result"
                    id="ruleset-ladder-result"
                    role="status"
                    hidden>
                  </div>
                </form>
              </section>

              <!-- <section
                class="ruleset-ladder-panel ruleset-ladder-section"
                aria-labelledby="cp-ranking-title">

                <div class="ruleset-ladder-section-head">
                  <div>
                    <p class="ruleset-ladder-kicker">CP Ranking</p>

                    <h2
                      class="ruleset-ladder-section-title"
                      id="cp-ranking-title">
                      ULGG CP 排行榜
                    </h2>

                    <p class="ruleset-ladder-section-description">
                      <?= $escape($currentCpSeason) ?> 賽季排行。
                    </p>
                  </div>

                  <div class="ruleset-ladder-badges">
                    <span class="ruleset-ladder-badge">
                      <?= $escape($currentCpSeason) ?>
                    </span>

                    <span class="ruleset-ladder-badge is-published">
                      本月排行
                    </span>
                  </div>
                </div>

                <div class="ruleset-ladder-ranking">
                  <div
                    class="ruleset-ladder-ranking-head"
                    aria-hidden="true">
                    <span>排名</span>
                    <span>玩家</span>
                    <span style="text-align:right;">CP</span>
                    <span style="text-align:right;">勝率</span>
                    <span style="text-align:right;">本月</span>
                  </div>

                  <?php if ($cpLeaderboardError !== null): ?>
                    <div class="ruleset-ladder-status-card">
                      <span class="ruleset-ladder-status-value">
                        CP 排行榜暫時無法讀取。
                      </span>
                    </div>

                  <?php elseif ($cpLeaderboard === []): ?>
                    <div class="ruleset-ladder-status-card">
                      <span class="ruleset-ladder-status-value">
                        尚無已完成的自定義天梯對戰。
                      </span>
                    </div>

                  <?php else: ?>
                    <?php foreach ($cpLeaderboard as $entry): ?>
                      <?php
                      $rank = (int)$entry['rank'];
                      $cpChange = (int)$entry['cp_change'];

                      if ($cpChange > 0) {
                        $trendClass = 'is-up';
                        $trendSymbol = '▲';
                      } elseif ($cpChange < 0) {
                        $trendClass = 'is-down';
                        $trendSymbol = '▼';
                      } else {
                        $trendClass = 'is-same';
                        $trendSymbol = '—';
                      }
                      ?>

                      <article
                        class="ruleset-ladder-ranking-row
        <?= $rank <= 3 ? 'is-top-three' : '' ?>">

                        <span class="ruleset-ladder-ranking-rank">
                          #<?= $rank ?>
                        </span>

                        <div class="ruleset-ladder-ranking-player">
                          <strong class="ruleset-ladder-ranking-name">
                            <?= $escape(
                              $entry['player_name'] !== ''
                                ? $entry['player_name']
                                : '未知玩家'
                            ) ?>
                          </strong>

                          <span class="ruleset-ladder-ranking-record">
                            <?= (int)$entry['wins'] ?> 勝
                            ·
                            <?= (int)$entry['draws'] ?> 平
                            ·
                            <?= (int)$entry['losses'] ?> 敗
                          </span>
                        </div>

                        <strong class="ruleset-ladder-ranking-cp">
                          <?= number_format((int)$entry['cp']) ?>
                        </strong>

                        <span class="ruleset-ladder-ranking-win-rate">
                          <?= number_format(
                            (float)$entry['win_rate'],
                            1
                          ) ?>%
                        </span>

                        <span
                          class="ruleset-ladder-ranking-trend
          <?= $escape($trendClass) ?>">

                          <?= $escape($trendSymbol) ?>

                          <?php if ($cpChange !== 0): ?>
                            <?= number_format(abs($cpChange)) ?>
                          <?php endif; ?>
                        </span>
                      </article>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>

                <p class="ruleset-ladder-ranking-note">
                  排名依 CP 由高至低排列；同分時依勝場、敗場與場次排序。
                </p>
              </section> -->

              <section class="ruleset-ladder-panel ruleset-ladder-section" aria-labelledby="active-ruleset-title">
                <div class="ruleset-ladder-section-head">
                  <div>
                    <p class="ruleset-ladder-kicker">Active Ruleset</p>
                    <h2 class="ruleset-ladder-rule-name" id="active-ruleset-title">
                      <?= $escape($activeRuleset['name_zh']) ?>
                    </h2>
                    <p class="ruleset-ladder-rule-version">
                      <?= $escape($activeRuleset['name']) ?> · Version <?= $escape($activeRuleset['version']) ?>
                    </p>
                  </div>

                  <div class="ruleset-ladder-badges">
                    <span class="ruleset-ladder-badge is-official">Official 官方</span>
                    <span class="ruleset-ladder-badge is-published">Published 已發布</span>
                  </div>
                </div>

                <ul class="ruleset-ladder-rule-list">
                  <?php foreach ($summaryRules as $rule): ?>
                    <li><?= $escape($rule) ?></li>
                  <?php endforeach; ?>
                </ul>

                <div class="ruleset-ladder-full-rules" id="ruleset-ladder-full-rules" hidden>
                  <ul class="ruleset-ladder-rule-list">
                    <?php foreach ($fullRules as $rule): ?>
                      <li><?= $escape($rule) ?></li>
                    <?php endforeach; ?>
                  </ul>

                  <p class="ruleset-ladder-section-description">
                    Rule Version：
                    <span class="ruleset-ladder-code"><?= $escape($activeRuleset['rule_version_id']) ?></span><br>
                    Content Hash：
                    <span class="ruleset-ladder-code"><?= $escape($activeRuleset['content_hash']) ?></span>
                  </p>
                </div>

                <div class="ruleset-ladder-match-actions">
                  <p class="ruleset-ladder-match-note">
                    此版本在本天梯中固定使用；後續規則變更需發布新版本。
                  </p>
                  <button
                    class="ruleset-ladder-button"
                    id="ruleset-ladder-rules-toggle"
                    type="button"
                    aria-expanded="false"
                    aria-controls="ruleset-ladder-full-rules">
                    查看完整規則
                  </button>
                </div>
              </section>

              <section
                class="ruleset-ladder-panel ruleset-ladder-section"
                aria-labelledby="custom-match-history-title">

                <div class="ruleset-ladder-section-head">
                  <div>
                    <p class="ruleset-ladder-kicker">
                      Match History
                    </p>

                    <h2
                      class="ruleset-ladder-section-title"
                      id="custom-match-history-title">
                      歷史對戰紀錄
                    </h2>

                    <p class="ruleset-ladder-section-description">
                      顯示最近完成的自定義天梯對戰。
                      為保護玩家隱私，公開頁面不顯示玩家名稱。
                    </p>
                  </div>

                  <div class="ruleset-ladder-badges">
                    <span class="ruleset-ladder-badge">
                      最近 20 場
                    </span>

                    <span class="ruleset-ladder-badge is-published">
                      名稱已隱藏
                    </span>
                  </div>
                </div>

                <?php if ($customMatchHistoryError !== null): ?>

                  <div class="ruleset-ladder-history-empty">
                    歷史對戰紀錄暫時無法讀取。
                  </div>

                <?php elseif ($customMatchHistory === []): ?>

                  <div class="ruleset-ladder-history-empty">
                    尚無已完成的自定義天梯對戰。
                  </div>

                <?php else: ?>

                  <div class="ruleset-ladder-history">
                    <?php foreach ($customMatchHistory as $match): ?>
                      <?php
                      $p1Won = (int)($match['lose'] ?? 0) === 1;
                      $p2Won = (int)($match['win'] ?? 0) === 1;
                      $isDraw = (int)($match['tie'] ?? 0) === 1;

                      if ($p1Won) {
                        $p1ResultText = '勝';
                        $p1ResultClass = 'is-win';
                        $p1SideClass = 'is-winner';

                        $p2ResultText = '負';
                        $p2ResultClass = 'is-lose';
                        $p2SideClass = 'is-loser';

                        $cardResultClass = 'is-p1-winner';
                      } elseif ($p2Won) {
                        $p1ResultText = '負';
                        $p1ResultClass = 'is-lose';
                        $p1SideClass = 'is-loser';

                        $p2ResultText = '勝';
                        $p2ResultClass = 'is-win';
                        $p2SideClass = 'is-winner';

                        $cardResultClass = 'is-p2-winner';
                      } else {
                        $p1ResultText = '平';
                        $p1ResultClass = 'is-draw';
                        $p1SideClass = 'is-draw';

                        $p2ResultText = '平';
                        $p2ResultClass = 'is-draw';
                        $p2SideClass = 'is-draw';

                        $cardResultClass = 'is-draw';
                      }

                      $p1Icons = [
                        $match['p1_ico_1'] ?? null,
                        $match['p1_ico_2'] ?? null,
                        $match['p1_ico_3'] ?? null,
                      ];

                      $p2Icons = [
                        $match['p2_ico_1'] ?? null,
                        $match['p2_ico_2'] ?? null,
                        $match['p2_ico_3'] ?? null,
                      ];

                      $rawMatchTime = (string)(
                        $match['update_time'] ?? ''
                      );

                      $displayMatchTime = $rawMatchTime !== ''
                        ? date(
                          'm-d H:i',
                          strtotime($rawMatchTime)
                        )
                        : '時間未知';
                      ?>

                      <article
                        class="ruleset-ladder-history-card
                          <?= $escape($cardResultClass) ?>">

                        <div
                          class="ruleset-ladder-history-side
    <?= $escape($p1SideClass) ?>">
                          <div class="ruleset-ladder-history-deck">
                            <?php foreach ($p1Icons as $icon): ?>
                              <?php if (!empty($icon)): ?>
                                <img
                                  class="ruleset-ladder-history-char"
                                  src="<?= IMG_BASE . $escape($icon) ?>"
                                  alt="左方角色"
                                  loading="lazy">
                              <?php else: ?>
                                <span
                                  class="ruleset-ladder-history-placeholder"
                                  aria-hidden="true">
                                </span>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          </div>

                          <div class="ruleset-ladder-history-player">
                            <div class="ruleset-ladder-history-player-heading">
                              <strong class="ruleset-ladder-history-name">
                                匿名玩家 A
                              </strong>

                              <span
                                class="ruleset-ladder-history-side-result
      <?= $escape($p1ResultClass) ?>">
                                <?= $escape($p1ResultText) ?>
                              </span>
                            </div>

                            <span class="ruleset-ladder-history-cost">
                              COST
                              <?= $match['ruleset_cost_p1'] !== null
                                ? (int)$match['ruleset_cost_p1']
                                : '—' ?>
                            </span>
                          </div>
                        </div>

                        <div class="ruleset-ladder-history-center">
                          <strong class="ruleset-ladder-history-vs">
                            VS
                          </strong>

                          <span class="ruleset-ladder-history-meta">
                            <?= $escape($displayMatchTime) ?><br>
                            Match #<?= (int)$match['ulgg_match_id'] ?>
                          </span>
                        </div>

                        <div
                          class="ruleset-ladder-history-side is-right
    <?= $escape($p2SideClass) ?>">

                          <div class="ruleset-ladder-history-player">
                            <div class="ruleset-ladder-history-player-heading">
                              <strong class="ruleset-ladder-history-name">
                                匿名玩家 B
                              </strong>

                              <span
                                class="ruleset-ladder-history-side-result
      <?= $escape($p2ResultClass) ?>">
                                <?= $escape($p2ResultText) ?>
                              </span>
                            </div>

                            <span class="ruleset-ladder-history-cost">
                              COST
                              <?= $match['ruleset_cost_p2'] !== null
                                ? (int)$match['ruleset_cost_p2']
                                : '—' ?>
                            </span>
                          </div>

                          <div class="ruleset-ladder-history-deck">
                            <?php foreach ($p2Icons as $icon): ?>
                              <?php if (!empty($icon)): ?>
                                <img
                                  class="ruleset-ladder-history-char"
                                  src="<?= IMG_BASE . $escape($icon) ?>"
                                  alt="右方角色"
                                  loading="lazy">
                              <?php else: ?>
                                <span
                                  class="ruleset-ladder-history-placeholder"
                                  aria-hidden="true">
                                </span>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          </div>
                        </div>

                      </article>
                    <?php endforeach; ?>
                  </div>

                <?php endif; ?>

                <p class="ruleset-ladder-ranking-note">
                  對戰紀錄僅公開牌組、規則 COST、結果與時間；
                  玩家名稱及會員資料不會送至此頁。
                </p>
              </section>


            </div>

            <aside>


              <section
                class="ruleset-ladder-panel ruleset-ladder-section"
                aria-labelledby="ladder-flow-title">
                <div class="ruleset-ladder-section-head">
                  <div>
                    <p class="ruleset-ladder-kicker">Match Flow</p>
                    <h2
                      class="ruleset-ladder-section-title"
                      id="ladder-flow-title">
                      補丁使用流程
                    </h2>
                  </div>
                </div>

                <p>
                  ※補丁只會修正玩家遊戲畫面上顯示的 COST，
                  不會修改或影響官方天梯實際使用的 COST。
                </p>

                <div class="ruleset-ladder-download">
                  <a
                    class="ruleset-ladder-download-button"
                    href="/src/script/cdp/cdp-v0.0.1.zip"
                    download="cdp-v0.0.1.zip">
                    下載 自訂 COST 補丁 v0.0.1
                  </a>

                  <span class="ruleset-ladder-download-note">
                    下載後解壓縮，執行資料夾內的 app.exe。
                  </span>
                </div>

                <ol class="ruleset-ladder-flow">
                  <li>
                    在 Steam 遊戲庫中找到 Unlight，開啟「內容／一般」，
                    並在啟動選項中貼上
                    <span class="ruleset-ladder-code">
                      --remote-debugging-port=59222
                    </span>。
                  </li>

                  <li>
                    啟動 Unlight，看到維修娘畫面後開啟補丁工具
                    <span class="ruleset-ladder-code">app.exe</span>，
                    並確認遊戲內 COST 顯示已完成更新。
                  </li>
                </ol>
              </section>

              <section class="ruleset-ladder-panel ruleset-ladder-section" aria-labelledby="ladder-flow-title">
                <div class="ruleset-ladder-section-head">
                  <div>
                    <p class="ruleset-ladder-kicker">Match Flow</p>
                    <h2 class="ruleset-ladder-section-title" id="ladder-flow-title">配對流程</h2>
                  </div>
                </div>

                <ol class="ruleset-ladder-flow">
                  <li>
                    選擇本週要參加的 COST 類別。
                  </li>

                  <li>
                    點擊開始配對，由系統自動盲配相同 COST 類別的對手。
                  </li>

                  <li>
                    配對成立後，頁面會自動跳轉至對戰房間。
                  </li>

                  <li>
                    雙方進入遊戲後，系統會自動讀取牌組，
                    並依本場鎖定的規則版本計算 COST。
                  </li>

                  <li>
                    雙方牌組皆符合規定即可開始對戰；
                    若任一方未通過驗證，本場配對將取消。
                  </li>
                </ol>
              </section>


            </aside>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>

<?php if ($activeRuleset !== null): ?>
  <script>
    (() => {
      'use strict';

      const form = document.getElementById('ruleset-ladder-match-form');
      const radios = Array.from(form?.querySelectorAll('input[name="cost_category"]') || []);
      const startButton = document.getElementById('ruleset-ladder-start');
      const cancelButton = document.getElementById('ruleset-ladder-cancel');
      const selectionNote = document.getElementById('ruleset-ladder-selection-note');
      const result = document.getElementById('ruleset-ladder-result');
      const rulesToggle = document.getElementById('ruleset-ladder-rules-toggle');
      const fullRules = document.getElementById('ruleset-ladder-full-rules');

      const selectedCostRadio = () => radios.find(radio => radio.checked) || null;

      const updateSelection = () => {
        const selected = selectedCostRadio();

        startButton.disabled = selected === null;
        result.hidden = true;

        selectionNote.textContent = selected ?
          `已選擇 ${selected.dataset.costLabel} COST，將進入同類別盲配。` :
          '請先選擇一個 COST 類別。';
      };

      radios.forEach(radio => {
        radio.addEventListener('change', updateSelection);
      });

      const joinQueueEndpoint = '/pages/ruleset/api/join_queue.php';
      const cancelQueueEndpoint = '/pages/ruleset/api/cancel_queue.php';
      const queueStatusEndpoint = '/pages/ruleset/api/queue_status.php';

      let queuePollTimer = null;
      let waitingAnimationTimer = null;
      let waitingDotCount = 0;

      const showResult = (message) => {
        result.textContent = message;
        result.hidden = false;
      };

      const readResponseJson = async (response) => {
        const raw = await response.text();

        if (raw.trim() === '') {
          throw new Error(`伺服器回傳空內容（HTTP ${response.status}）。`);
        }

        try {
          return JSON.parse(raw);
        } catch (error) {
          throw new Error(`伺服器回傳無法解析的內容（HTTP ${response.status}）。`);
        }
      };

      const stopWaitingAnimation = () => {
        if (waitingAnimationTimer !== null) {
          window.clearInterval(waitingAnimationTimer);
          waitingAnimationTimer = null;
        }
        waitingDotCount = 0;
      };

      const startWaitingAnimation = () => {
        stopWaitingAnimation();

        const renderWaitingText = () => {
          waitingDotCount = (waitingDotCount % 3) + 1;
          startButton.textContent = `等待配對中${'.'.repeat(waitingDotCount)}`;
        };

        renderWaitingText();
        waitingAnimationTimer = window.setInterval(renderWaitingText, 1000);
      };

      const stopQueuePolling = () => {
        if (queuePollTimer !== null) {
          window.clearInterval(queuePollTimer);
          queuePollTimer = null;
        }
      };

      const matchStatusLabel = (status) => {
        const labels = {
          WAITING_ROOM: '等待遊戲房間',
          WAITING_DECK: '等待牌組資料',
          READY: '牌組驗證完成',
          CANCELLED: '比賽已取消',
          FINISHED: '比賽已結束'
        };

        return labels[String(status || '').toUpperCase()] || '準備中';
      };

      const applyQueueStatus = (queue) => {
        const queueStatus = String(queue?.queue_status || '').toUpperCase();
        const costLabel =
          queue?.cost_label ||
          selectedCostRadio()?.dataset.costLabel ||
          '?';

        if (queueStatus === 'MATCHED') {
          stopWaitingAnimation();

          const matchId = Number.parseInt(
            queue?.ulgg_match_id ?? queue?.custom_match_id,
            10
          );
          const dismissedMatchId = Number.parseInt(
            sessionStorage.getItem('ulggDismissedMatchId') || '',
            10
          );

          if (
            Number.isInteger(matchId) &&
            matchId > 0 &&
            matchId === dismissedMatchId
          ) {
            return;
          }
          const matchStatus = String(queue?.match_status || 'WAITING_ROOM').toUpperCase();
          if (Number.isInteger(matchId) && matchId > 0) {
            stopQueuePolling();

            window.location.replace(
              `/pages/ruleset/room.php?match=${encodeURIComponent(matchId)}`
            );
            return;
          }


          const matchIdText = Number.isInteger(matchId) && matchId > 0 ?
            `比賽編號：#${matchId}。` :
            '';
          const statusText = matchStatusLabel(matchStatus);

          showResult(
            `配對成功！已找到對手。` +
            matchIdText +
            `目前狀態：${statusText}。`
          );

          startButton.disabled = true;
          startButton.textContent = '已配對';
          cancelButton?.classList.remove('is-visible');

          if (['WAITING_ROOM', 'WAITING_DECK'].includes(matchStatus)) {
            startQueuePolling();
          } else {
            stopQueuePolling();
          }
          return;
        }

        if (queueStatus === 'QUEUED') {
          showResult(
            `已進入 ${costLabel} COST 等待池。` +
            '目前正在搜尋對手，系統會自動更新配對狀態。'
          );

          startButton.disabled = true;
          cancelButton?.classList.add('is-visible');
          cancelButton.disabled = false;

          if (waitingAnimationTimer === null) {
            startWaitingAnimation();
          }
          return;
        }

        if (queueStatus === 'IDLE') {
          stopQueuePolling();
          stopWaitingAnimation();
          startButton.disabled = selectedCostRadio() === null;
          startButton.textContent = '開始配對';
          cancelButton?.classList.remove('is-visible');
          cancelButton.disabled = false;
        }
      };

      const fetchQueueStatus = async ({
        silent = false
      } = {}) => {
        try {
          const response = await fetch(queueStatusEndpoint, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json'
            },
            cache: 'no-store'
          });

          const payload = await readResponseJson(response);

          if (!response.ok || payload?.ok !== true) {
            throw new Error(
              payload?.error?.message ||
              `讀取排隊狀態失敗（HTTP ${response.status}）。`
            );
          }

          applyQueueStatus(payload.data || {});
          return payload.data || {};
        } catch (error) {
          if (!silent) {
            const message = error instanceof Error ?
              error.message :
              '讀取排隊狀態時發生未知錯誤。';
            showResult(message);
          }
          return null;
        }
      };

      const startQueuePolling = () => {
        if (queuePollTimer !== null) {
          return;
        }

        queuePollTimer = window.setInterval(() => {
          fetchQueueStatus({
            silent: true
          });
        }, 3000);
      };

      startButton?.addEventListener('click', async () => {
        const selected = selectedCostRadio();

        if (!selected || startButton.disabled) {
          updateSelection();
          return;
        }

        const originalText = startButton.textContent;
        startButton.disabled = true;
        startButton.textContent = '排隊處理中…';
        showResult(`正在加入 ${selected.dataset.costLabel} COST 等待池…`);

        try {
          const response = await fetch(joinQueueEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              cost_category: Number.parseInt(selected.value, 10)
            })
          });

          const payload = await readResponseJson(response);

          if (!response.ok || payload?.ok !== true) {
            const message =
              payload?.error?.message ||
              `加入排隊失敗（HTTP ${response.status}）。`;

            throw new Error(message);
          }

          const queue = {
            ...(payload.data || {}),
            cost_label: selected.dataset.costLabel
          };

          applyQueueStatus(queue);

          if (String(queue.queue_status || '').toUpperCase() === 'QUEUED') {
            startQueuePolling();
          }
        } catch (error) {
          const message = error instanceof Error ?
            error.message :
            '加入排隊時發生未知錯誤。';

          stopQueuePolling();
          stopWaitingAnimation();
          showResult(message);
          startButton.disabled = false;
          startButton.textContent = originalText;
        }
      });

      cancelButton?.addEventListener('click', async () => {
        if (cancelButton.disabled) {
          return;
        }

        cancelButton.disabled = true;
        cancelButton.textContent = '取消中…';

        try {
          const response = await fetch(cancelQueueEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json'
            }
          });

          const payload = await readResponseJson(response);

          if (!response.ok || payload?.ok !== true) {
            throw new Error(
              payload?.error?.message ||
              `取消排隊失敗（HTTP ${response.status}）。`
            );
          }

          stopQueuePolling();
          stopWaitingAnimation();

          cancelButton.classList.remove('is-visible');
          cancelButton.disabled = false;
          cancelButton.textContent = '取消排隊';

          startButton.textContent = '開始配對';
          startButton.disabled = selectedCostRadio() === null;

          showResult('已取消排隊，可重新選擇 COST 類別。');
        } catch (error) {
          const message = error instanceof Error ?
            error.message :
            '取消排隊時發生未知錯誤。';

          showResult(message);
          cancelButton.disabled = false;
          cancelButton.textContent = '取消排隊';
        }
      });

      rulesToggle?.addEventListener('click', () => {
        if (!fullRules) {
          return;
        }

        const willOpen = fullRules.hidden;
        fullRules.hidden = !willOpen;
        rulesToggle.setAttribute('aria-expanded', String(willOpen));
        rulesToggle.textContent = willOpen ? '收起完整規則' : '查看完整規則';
      });

      updateSelection();

      fetchQueueStatus({
        silent: true
      }).then((queue) => {
        if (String(queue?.queue_status || '').toUpperCase() === 'QUEUED') {
          startQueuePolling();
        }
      });
    })();
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const pushButton = document.getElementById('rulesetLadderPushButton');

      if (!pushButton) {
        return;
      }

      function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
          .replace(/-/g, '+')
          .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; i++) {
          outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
      }

      async function getPublicKey() {
        const response = await fetch(
          '/pages/tournament/api_push_public_key.php', {
            cache: 'no-store',
            headers: {
              Accept: 'application/json'
            }
          }
        );

        const data = await response.json();

        if (!response.ok || !data.ok || !data.publicKey) {
          throw new Error('無法取得推播金鑰。');
        }

        return data.publicKey;
      }

      async function saveSubscription(subscription) {
        const response = await fetch(
          '/pages/ruleset/api_push_subscribe.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json'
            },
            body: JSON.stringify({
              subscription: subscription.toJSON()
            })
          }
        );

        const raw = await response.text();

        if (raw.trim() === '') {
          throw new Error(
            `推播訂閱 API 回傳空內容（HTTP ${response.status}）。`
          );
        }

        let data;

        try {
          data = JSON.parse(raw);
        } catch (error) {
          console.error('api_push_subscribe raw response:', raw);

          throw new Error(
            `推播訂閱 API 回傳非 JSON（HTTP ${response.status}）。`
          );
        }

        if (!response.ok || data?.ok !== true) {
          throw new Error(
            data?.message ||
            data?.error?.message ||
            `推播訂閱失敗（HTTP ${response.status}）。`
          );
        }

        return data;
      }

      async function updateButtonState() {
        if (
          !('serviceWorker' in navigator) ||
          !('PushManager' in window)
        ) {
          pushButton.textContent = '此瀏覽器不支援推播';
          pushButton.disabled = true;
          pushButton.classList.add('is-disabled');
          return;
        }

        const registration = await navigator.serviceWorker.register(
          '/service-worker.js'
        );

        const subscription =
          await registration.pushManager.getSubscription();

        if (!subscription) {
          pushButton.textContent = '🔔 開啟配對推播';
          pushButton.classList.remove('is-subscribed');
          return;
        }

        /*
         * 瀏覽器已有 Subscription，不代表它已綁定目前登入帳號。
         * 將現有 Subscription 再送至後端，由後端綁定目前 user_id。
         */
        await saveSubscription(subscription);

        pushButton.textContent = '✅ 已開啟配對推播';
        pushButton.classList.add('is-subscribed');
      }

      pushButton.addEventListener('click', async function() {
        try {
          pushButton.disabled = true;
          pushButton.textContent = '處理中...';

          if (
            !('serviceWorker' in navigator) ||
            !('PushManager' in window)
          ) {
            throw new Error('此瀏覽器不支援 Web Push。');
          }

          const permission = await Notification.requestPermission();

          if (permission !== 'granted') {
            throw new Error('你尚未允許通知權限。');
          }

          const registration = await navigator.serviceWorker.register(
            '/service-worker.js'
          );

          let subscription =
            await registration.pushManager.getSubscription();

          if (!subscription) {
            const publicKey = await getPublicKey();

            subscription = await registration.pushManager.subscribe({
              userVisibleOnly: true,
              applicationServerKey: urlBase64ToUint8Array(publicKey)
            });
          }

          await saveSubscription(subscription);

          pushButton.textContent = '✅ 已開啟配對推播';
          pushButton.classList.add('is-subscribed');

          alert('已開啟配對成功通知。');
        } catch (error) {
          pushButton.textContent = '🔔 開啟配對推播';
          alert(error.message || '推播設定失敗。');
        } finally {
          pushButton.disabled = false;
        }
      });

      updateButtonState().catch(function() {
        pushButton.textContent = '🔔 開啟配對推播';
      });
    });
  </script>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>