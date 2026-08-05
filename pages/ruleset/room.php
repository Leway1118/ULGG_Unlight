<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/_demo_data.php';

$pageTitleText = '自訂對戰房間';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = 'ruleset';

$matchParameter = $_GET['match'] ?? '';
$matchId = filter_var(
  $matchParameter,
  FILTER_VALIDATE_INT,
  [
    'options' => [
      'min_range' => 1,
    ],
  ]
);
$currentUserId = filter_var(
  $_SESSION['user_id'] ?? null,
  FILTER_VALIDATE_INT,
  [
    'options' => [
      'min_range' => 1,
    ],
  ]
);

$room = null;
$ruleset = null;

if ($matchId !== false && $currentUserId !== false) {
  $stmt = $db->prepare(
    <<<'SQL'
      SELECT
        m.id,
        m.rule_version_id,
        m.weekly_cost_id,
        m.cost_category,
        m.player1_user_id,
        m.player2_user_id,
        m.player1_steam_id,
        m.player2_steam_id,
        m.player1_game_name,
        m.player2_game_name,
        m.match_status,
        m.game_room_id,
        m.room_host_slot,
        m.game_room_password,
        m.game_room_password_updated_at,
        m.player1_deck_audit_json,
        m.player2_deck_audit_json,
        m.created_at,
        m.room_linked_at,
        m.deck_validated_at,
        m.finished_at,
        m.cancelled_at,

        w.cost1,
        w.cost2,
        w.cost3,
        w.cost4,
        w.cost4_operator,
        w.cost4_match_tolerance

        FROM custom_matches AS m

        LEFT JOIN quickmatch_weekly_cost AS w
          ON w.id = m.weekly_cost_id

        WHERE m.id = :match_id
          AND (
            m.player1_user_id = :current_user_id
            OR m.player2_user_id = :current_user_id
          )
        LIMIT 1
    SQL
  );

  $stmt->execute([
    ':match_id' => (int)$matchId,
    ':current_user_id' => (int)$currentUserId,
  ]);

  $match = $stmt->fetch(PDO::FETCH_ASSOC);

  if (is_array($match)) {
    $createdAt = (string)($match['created_at'] ?? '');
    $createdTimestamp = strtotime($createdAt);

    $waitingMinutes = $createdTimestamp !== false
      ? max(0, (int)floor((time() - $createdTimestamp) / 60))
      : 0;

    $statusLabels = [
      'WAITING_ROOM' => '等待遊戲房間',
      'WAITING_DECK' => '等待雙方牌組',
      'READY' => '牌組驗證完成',
      'FINISHED' => '比賽已結束',
      'CANCELLED' => '比賽已取消',
    ];
    $player1Name = (string)($match['player1_game_name'] ?? 'Player 1');
    $player2Name = (string)($match['player2_game_name'] ?? 'Player 2');

    $roomHostSlot = strtoupper(
      (string)($match['room_host_slot'] ?? 'PLAYER1')
    );

    $hostIsPlayer2 = $roomHostSlot === 'PLAYER2';

    $hostName = $hostIsPlayer2
      ? $player2Name
      : $player1Name;

    $guestName = $hostIsPlayer2
      ? $player1Name
      : $player2Name;
    $matchStatus = strtoupper(
      (string)($match['match_status'] ?? 'WAITING_ROOM')
    );

    $room = [
      'room_id' => 'match-' . (int)$match['id'],
      'match_id' => (int)$match['id'],
      'room_name' => 'ULGG Match #' . (int)$match['id'],

      'status' => $statusLabels[$matchStatus] ?? $matchStatus,
      'match_status' => $matchStatus,
      'waiting_minutes' => $waitingMinutes,

      'host' => $hostName,
      'guest' => $guestName,
      'room_host_slot' => $roomHostSlot,

      'player1_user_id' => (int)($match['player1_user_id'] ?? 0),
      'player2_user_id' => (int)($match['player2_user_id'] ?? 0),
      'player1_steam_id' => (string)($match['player1_steam_id'] ?? ''),
      'player2_steam_id' => (string)($match['player2_steam_id'] ?? ''),

      // 舊版 Demo 模板仍會讀取這些欄位。
      'cp' => 0,
      'region' => 'TW',
      'channel' => 'room2',
      'visibility' => '配對玩家限定',

      'created_at' => $createdAt,
      'room_linked_at' => $match['room_linked_at'] ?? null,
      'deck_validated_at' => $match['deck_validated_at'] ?? null,
      'finished_at' => $match['finished_at'] ?? null,
      'cancelled_at' => $match['cancelled_at'] ?? null,

      'game_room_id' =>
      (string)($match['game_room_id'] ?? ''),

      'game_room_password' =>
      (string)($match['game_room_password'] ?? ''),

      'game_room_password_updated_at' =>
      $match['game_room_password_updated_at'] ?? null,

      'is_password_owner' =>
      (int)$currentUserId
        === (int)($match['player1_user_id'] ?? 0),

      'ruleset_version_id' =>
      (string)($match['rule_version_id'] ?? ''),
      'weekly_cost_id' => (int)($match['weekly_cost_id'] ?? 0),
      'cost_category' => (int)($match['cost_category'] ?? 0),
    ];
    $player1Audit = json_decode(
      (string)($match['player1_deck_audit_json'] ?? ''),
      true
    );

    $player2Audit = json_decode(
      (string)($match['player2_deck_audit_json'] ?? ''),
      true
    );

    if (!is_array($player1Audit)) {
      $player1Audit = null;
    }

    if (!is_array($player2Audit)) {
      $player2Audit = null;
    }

    $hostAudit = $hostIsPlayer2
      ? $player2Audit
      : $player1Audit;

    $guestAudit = $hostIsPlayer2
      ? $player1Audit
      : $player2Audit;

    $room['host_audit'] = $hostAudit;
    $room['guest_audit'] = $guestAudit;
    $costCategory = (int)($match['cost_category'] ?? 0);

    $selectedCost = match ($costCategory) {
      1 => (int)($match['cost1'] ?? 0),
      2 => (int)($match['cost2'] ?? 0),
      3 => (int)($match['cost3'] ?? 0),
      4 => (int)($match['cost4'] ?? 0),
      default => 0,
    };

    $cost4Operator = strtoupper(
      (string)($match['cost4_operator'] ?? 'MIN')
    );

    $costTolerance = max(
      0,
      (int)($match['cost4_match_tolerance'] ?? 0)
    );

    if ($costCategory >= 1 && $costCategory <= 3) {
      $costRuleLabel = $selectedCost . ' COST';

      $costRuleDescription =
        '雙方牌組依本場鎖定規則重新計算後，'
        . '皆必須等於 '
        . $selectedCost
        . ' COST。';
    } elseif ($costCategory === 4) {
      $costRuleLabel =
        $selectedCost
        . ($cost4Operator === 'MIN' ? '+ COST' : ' COST');

      if ($cost4Operator === 'EXACT') {
        $costRuleDescription =
          '雙方牌組依本場鎖定規則重新計算後，'
          . '皆必須等於 '
          . $selectedCost
          . ' COST，且雙方差距不得超過 '
          . $costTolerance
          . '。';
      } else {
        $costRuleDescription =
          '雙方牌組依本場鎖定規則重新計算後，'
          . '皆必須至少為 '
          . $selectedCost
          . ' COST，且雙方差距不得超過 '
          . $costTolerance
          . '。';
      }
    } else {
      $costRuleLabel = '未設定';
      $costRuleDescription = '本場 COST 類別資料不完整。';
    }

    $room['cost_rule_label'] = $costRuleLabel;
    $room['cost_rule_description'] = $costRuleDescription;


    $roomRuleset = null;

    foreach ($rulesetVersions as $candidateRuleset) {
      if (
        (string)($candidateRuleset['rule_version_id'] ?? '')
        === (string)$room['ruleset_version_id']
      ) {
        $roomRuleset = $candidateRuleset;
        break;
      }
    }

    $roomFullRules = is_array($roomRuleset)
      ? ($roomRuleset['full_rules'] ?? $roomRuleset['rules'] ?? [])
      : [];
    $pageTitleText = $room['room_name'];
    $seoTitle = $pageTitleText . ' | ULGG 自訂對戰房間';
    $pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
  }
}

$escape = static fn(mixed $value): string =>
htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$tierLabels = [
  'OFFICIAL' => 'Official 官方',
  'COMMUNITY' => 'Community 社群',
];
$lifecycleLabels = [
  'PUBLISHED' => 'Published 已發布',
  'DRAFT' => 'Draft 草案',
  'RETIRED' => 'Retired 已封存',
];
$channelLabels = [
  'room2' => '迪特赫姆（Room 2）',
  'room4' => 'Room 4（展示）',
];

ob_start();
?>

<style>
  .ruleset-room-page {
    --ruleset-room-panel: rgba(31, 34, 45, .95);
    --ruleset-room-soft: rgba(255, 255, 255, .045);
    --ruleset-room-line: rgba(255, 255, 255, .1);
    --ruleset-room-text: #eef0f8;
    --ruleset-room-muted: #a7adbd;
    --ruleset-room-accent: #b8beff;
    color: var(--ruleset-room-text);
  }

  .ruleset-room-shell {
    width: 100%;
    padding: 24px 0 48px;
  }

  .ruleset-room-back {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 14px;
    color: #c8ccff;
    font-size: .84rem;
    font-weight: 750;
    text-decoration: none;
  }

  .ruleset-room-back:hover,
  .ruleset-room-back:focus {
    color: #fff;
    text-decoration: none;
  }

  .ruleset-room-panel {
    border: 1px solid var(--ruleset-room-line);
    border-radius: 13px;
    background: var(--ruleset-room-panel);
    box-shadow: 0 10px 26px rgba(0, 0, 0, .15);
  }

  .ruleset-room-demo-note {
    margin-bottom: 16px;
    padding: 10px 13px;
    border: 1px solid rgba(238, 200, 112, .28);
    border-radius: 10px;
    background: rgba(238, 200, 112, .08);
    color: #e8d49d;
    font-size: 1.5rem;
    line-height: 1.5;
  }

  .ruleset-room-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    padding: 22px 24px;
  }

  .ruleset-room-kicker,
  .ruleset-room-section-kicker {
    margin: 0 0 5px;
    color: var(--ruleset-room-accent);
    font-size: .73rem;
    font-weight: 800;
    letter-spacing: .09em;
    text-transform: uppercase;
  }

  .ruleset-room-title {
    margin: 0;
    color: #fff;
    font-size: clamp(1.55rem, 3.2vw, 2.2rem);
    font-weight: 850;
  }

  .ruleset-room-hero-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
  }

  .ruleset-room-status,
  .ruleset-room-badge {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 4px 9px;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 999px;
    font-size: .69rem;
    font-weight: 800;
  }

  .ruleset-room-status {
    gap: 6px;
    border-color: rgba(115, 210, 164, .28);
    background: rgba(115, 210, 164, .08);
    color: #93ddb7;
  }

  .ruleset-room-status::before {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    content: '';
  }

  .ruleset-room-waiting {
    color: var(--ruleset-room-muted);
    font-size: .79rem;
  }

  .ruleset-room-main {
    display: grid;
    gap: 18px;
    margin-top: 18px;
  }

  .ruleset-room-section {
    padding: 20px;
  }

  .ruleset-room-section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 16px;
  }

  .ruleset-room-section-title {
    margin: 0;
    color: #fff;
    font-size: 1.12rem;
    font-weight: 800;
  }

  .ruleset-room-section-description {
    margin: 5px 0 0;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
    line-height: 1.55;
  }

  .ruleset-room-players {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: stretch;
    gap: 14px;
  }

  .ruleset-room-player {
    display: flex;
    align-items: center;
    gap: 13px;
    min-width: 0;
    padding: 16px;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 10px;
    background: var(--ruleset-room-soft);
  }

  .ruleset-room-player.is-empty {
    border-style: dashed;
  }

  .ruleset-room-avatar {
    display: grid;
    flex: 0 0 44px;
    width: 44px;
    height: 44px;
    place-items: center;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 50%;
    background: rgba(184, 190, 255, .1);
    color: #d8dcff;
    font-weight: 850;
  }

  .ruleset-room-player-role {
    display: block;
    margin-bottom: 3px;
    color: var(--ruleset-room-muted);
    font-size: .69rem;
    font-weight: 750;
  }

  .ruleset-room-player-name {
    display: block;
    color: #fff;
    font-size: .98rem;
    font-weight: 800;
  }

  .ruleset-room-player-meta {
    display: block;
    margin-top: 3px;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
  }

  .ruleset-room-versus {
    display: grid;
    min-width: 40px;
    place-items: center;
    color: var(--ruleset-room-accent);
    font-size: .9rem;
    font-weight: 900;
  }

  .ruleset-room-ruleset-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
  }

  .ruleset-room-ruleset-name {
    margin: 0;
    color: #fff;
    font-size: 1.1rem;
    font-weight: 850;
  }

  .ruleset-room-ruleset-version {
    margin: 4px 0 0;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
  }

  .ruleset-room-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  .ruleset-room-badge-official {
    border-color: rgba(232, 196, 98, .3);
    color: #e7d08b;
  }

  .ruleset-room-badge-community {
    border-color: rgba(132, 171, 232, .3);
    color: #afc9ee;
  }

  .ruleset-room-badge-published {
    border-color: rgba(115, 210, 164, .28);
    color: #93ddb7;
  }

  .ruleset-room-rule-list {
    display: grid;
    gap: 8px;
    margin: 16px 0 0;
    padding: 0;
    list-style: none;
  }

  .ruleset-room-rule-list li {
    position: relative;
    padding-left: 15px;
    color: #d1d4e1;
    font-size: .82rem;
    line-height: 1.5;
  }

  .ruleset-room-rule-list li::before {
    position: absolute;
    top: .6em;
    left: 0;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--ruleset-room-accent);
    content: '';
  }

  .ruleset-room-lock-notice {
    margin-top: 15px;
    padding: 10px 12px;
    border-left: 3px solid rgba(184, 190, 255, .58);
    background: rgba(184, 190, 255, .07);
    color: #cdd1e4;
    font-size: 1.5rem;
    line-height: 1.55;
  }

  .ruleset-room-ruleset-actions {
    display: flex;
    margin-top: 13px;
  }

  .ruleset-room-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
  }

  .ruleset-room-info-list,
  .ruleset-room-client-list {
    display: grid;
    gap: 0;
    margin: 0;
  }

  .ruleset-room-info-row {
    display: grid;
    grid-template-columns: 115px minmax(0, 1fr);
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--ruleset-room-line);
  }

  .ruleset-room-info-row:last-child {
    border-bottom: 0;
  }

  .ruleset-room-info-row dt {
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
  }

  .ruleset-room-info-row dd {
    min-width: 0;
    margin: 0;
    overflow-wrap: anywhere;
    color: var(--ruleset-room-text);
    font-size: 1.5rem;
  }

  .ruleset-room-client-state {
    color: #d8c798;
    font-weight: 750;
  }

  .ruleset-room-technical {
    margin-top: 15px;
    border-top: 1px solid var(--ruleset-room-line);
    color: var(--ruleset-room-muted);
  }

  .ruleset-room-technical summary {
    padding: 13px 0 0;
    cursor: pointer;
    color: #c8ccff;
    font-size: .77rem;
    font-weight: 750;
  }

  .ruleset-room-technical-content {
    display: grid;
    gap: 7px;
    padding-top: 12px;
    font-size: .73rem;
  }

  .ruleset-room-code {
    overflow-wrap: anywhere;
    color: #dce0ef;
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  }

  .ruleset-room-join {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 22px;
  }

  .ruleset-room-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 9px 16px;
    border: 1px solid rgba(184, 190, 255, .46);
    border-radius: 8px;
    background: rgba(184, 190, 255, .14);
    color: #eef0ff;
    font-size: .84rem;
    font-weight: 800;
    text-decoration: none;
  }

  .ruleset-room-button:hover,
  .ruleset-room-button:focus {
    border-color: rgba(184, 190, 255, .78);
    background: rgba(184, 190, 255, .22);
    color: #fff;
    text-decoration: none;
  }

  .ruleset-room-join-result {
    margin-top: 13px;
    padding: 10px 12px;
    border: 1px solid rgba(238, 200, 112, .28);
    border-radius: 8px;
    background: rgba(238, 200, 112, .07);
    color: #e8d49d;
    font-size: 1.5rem;
    line-height: 1.5;
  }

  .ruleset-room-join-result[hidden] {
    display: none;
  }

  .ruleset-room-password-panel {
    display: grid;
    gap: 14px;
  }

  .ruleset-room-password-form {
    display: flex;
    align-items: stretch;
    gap: 10px;
  }

  .ruleset-room-password-input {
    width: 100%;
    min-width: 0;
    min-height: 44px;
    padding: 9px 12px;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 8px;
    outline: none;
    background: rgba(255, 255, 255, .055);
    color: #fff;
    font-size: 1rem;
    font-family:
      ui-monospace,
      SFMono-Regular,
      Consolas,
      monospace;
  }

  .ruleset-room-password-input:focus {
    border-color: rgba(184, 190, 255, .7);
    box-shadow: 0 0 0 3px rgba(184, 190, 255, .09);
  }

  .ruleset-room-password-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    min-height: 54px;
    padding: 12px 14px;
    border: 1px solid rgba(184, 190, 255, .3);
    border-radius: 9px;
    background: rgba(184, 190, 255, .08);
  }

  .ruleset-room-password-value {
    overflow-wrap: anywhere;
    color: #fff;
    font-size: 1.3rem;
    font-weight: 900;
    letter-spacing: .05em;
    font-family:
      ui-monospace,
      SFMono-Regular,
      Consolas,
      monospace;
  }

  .ruleset-room-password-message {
    margin: 0;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
    line-height: 1.55;
  }

  .ruleset-room-password-message.is-success {
    color: #93ddb7;
  }

  .ruleset-room-password-message.is-error {
    color: #efaaaa;
  }

  .ruleset-room-password-display[hidden],
  .ruleset-room-password-form[hidden] {
    display: none;
  }

  @media (max-width: 767px) {

    .ruleset-room-password-form,
    .ruleset-room-password-display {
      align-items: stretch;
      flex-direction: column;
    }

    .ruleset-room-password-input {
      min-height: 52px;
      font-size: 1.5rem;
    }

    .ruleset-room-password-value {
      font-size: 1.8rem;
      text-align: center;
    }
  }

  .ruleset-room-empty {
    max-width: 680px;
    margin: 40px auto;
    padding: 36px 24px;
    text-align: center;
  }

  .ruleset-room-empty-icon {
    display: block;
    margin-bottom: 12px;
    font-size: 2rem;
  }

  .ruleset-room-empty h1 {
    margin: 0;
    color: #fff;
    font-size: 1.5rem;
    font-weight: 850;
  }

  .ruleset-room-empty p {
    margin: 10px 0 20px;
    color: var(--ruleset-room-muted);
    line-height: 1.6;
  }

  @media (max-width: 767px) {
    .ruleset-room-shell {
      padding-top: 14px;
    }

    .ruleset-room-hero,
    .ruleset-room-section-header,
    .ruleset-room-ruleset-head,
    .ruleset-room-join {
      align-items: flex-start;
      flex-direction: column;
    }

    .ruleset-room-hero,
    .ruleset-room-section {
      padding: 17px;
    }

    .ruleset-room-players,
    .ruleset-room-grid {
      grid-template-columns: 1fr;
    }

    .ruleset-room-versus {
      min-height: 28px;
      justify-self: stretch;
      font-size: 1.62rem;
    }

    .ruleset-room-info-row {
      grid-template-columns: 1fr;
      gap: 3px;
    }

    .ruleset-room-button {
      width: 100%;
      min-height: 50px;
      font-size: 1.512rem;
    }

    .ruleset-room-back {
      font-size: 1.512rem;
    }

    .ruleset-room-kicker,
    .ruleset-room-section-kicker {
      font-size: 2.514rem;
    }

    .ruleset-room-title {
      font-size: 3.6rem;
    }

    .ruleset-room-status,
    .ruleset-room-badge {
      font-size: 1.242rem;
    }

    .ruleset-room-waiting {
      font-size: 1.422rem;
    }

    .ruleset-room-section-title {
      font-size: 2.016rem;
    }

    .ruleset-room-section-description {
      font-size: 1.44rem;
      line-height: 1.65;
    }

    .ruleset-room-player-role {
      font-size: 1.242rem;
    }

    .ruleset-room-player-name {
      font-size: 1.764rem;
    }

    .ruleset-room-player-meta {
      font-size: 1.35rem;
      line-height: 1.6;
    }

    .ruleset-room-ruleset-name {
      font-size: 1.98rem;
    }

    .ruleset-room-ruleset-version {
      font-size: 1.44rem;
    }

    .ruleset-room-rule-list li {
      font-size: 1.476rem;
      line-height: 1.65;
    }

    .ruleset-room-lock-notice {
      font-size: 1.404rem;
      line-height: 1.65;
    }

    .ruleset-room-technical summary {
      font-size: 1.386rem;
    }

    .ruleset-room-technical-content {
      font-size: 1.314rem;
    }

    .ruleset-room-info-row dt {
      font-size: 1.35rem;
    }

    .ruleset-room-info-row dd {
      font-size: 1.44rem;
    }

    .ruleset-cost-status {
      font-size: 1.35rem;
    }

    .ruleset-cost-message,
    .ruleset-cost-error {
      font-size: 1.404rem;
      line-height: 1.65;
    }

    .ruleset-cost-side-title h3 {
      font-size: 1.71rem;
    }

    .ruleset-cost-result-badge {
      font-size: 1.224rem;
    }

    .ruleset-cost-metric span {
      font-size: 1.224rem;
    }

    .ruleset-cost-metric strong {
      font-size: 1.656rem;
    }

    .ruleset-cost-card-name {
      font-size: 1.368rem;
    }

    .ruleset-cost-card-value {
      font-size: 1.44rem;
    }

    .ruleset-cost-summary-row {
      font-size: 1.35rem;
    }
  }

  .ruleset-room-full-rules {
    margin-top: 14px;
    padding: 18px;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 12px;
    background: var(--ruleset-room-soft);
  }

  .ruleset-room-full-rules[hidden] {
    display: none;
  }

  .ruleset-room-full-rules-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 12px;
  }

  .ruleset-room-full-rules-head h3 {
    margin: 0;
    color: #fff;
    font-size: 1.05rem;
  }

  .ruleset-room-rule-description {
    margin: 0 0 14px;
    color: var(--ruleset-room-muted);
    font-size: .82rem;
    line-height: 1.65;
  }

  .ruleset-room-full-rules .ruleset-room-rule-list {
    margin: 0;
  }

  .ruleset-room-button-secondary {
    border-color: var(--ruleset-room-line);
    background: var(--ruleset-room-soft);
    color: #d9dcec;
  }

  .ruleset-cost-panel {
    display: grid;
    gap: 16px;
  }

  .ruleset-cost-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }

  .ruleset-cost-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .ruleset-cost-actions[hidden] {
    display: none;
  }

  .ruleset-cost-status {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 5px 10px;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 999px;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
    font-weight: 800;
  }

  .ruleset-cost-status.is-ready {
    border-color: rgba(184, 190, 255, .32);
    background: rgba(184, 190, 255, .08);
    color: #d5d8ff;
  }

  .ruleset-cost-status.is-checking {
    border-color: rgba(238, 200, 112, .3);
    background: rgba(238, 200, 112, .08);
    color: #e8d49d;
  }

  .ruleset-cost-status.is-passed {
    border-color: rgba(115, 210, 164, .3);
    background: rgba(115, 210, 164, .08);
    color: #93ddb7;
  }

  .ruleset-cost-status.is-violation {
    border-color: rgba(232, 111, 111, .34);
    background: rgba(232, 111, 111, .08);
    color: #efaaaa;
  }

  .ruleset-cost-status.is-error {
    border-color: rgba(232, 111, 111, .34);
    background: rgba(232, 111, 111, .08);
    color: #efaaaa;
  }

  .ruleset-cost-results {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .ruleset-cost-side {
    padding: 15px;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 10px;
    background: var(--ruleset-room-soft);
  }

  .ruleset-cost-side.is-legal {
    border-color: rgba(115, 210, 164, .26);
  }

  .ruleset-cost-side.is-illegal {
    border-color: rgba(232, 111, 111, .3);
  }

  .ruleset-cost-side-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
  }

  .ruleset-cost-side-title h3 {
    margin: 0;
    color: #fff;
    font-size: 1.5rem;
    font-weight: 800;
  }

  .ruleset-cost-result-badge {
    display: inline-flex;
    padding: 4px 8px;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 999px;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
    font-weight: 850;
  }

  .ruleset-cost-result-badge.is-legal {
    color: #93ddb7;
  }

  .ruleset-cost-result-badge.is-illegal {
    color: #efaaaa;
  }

  .ruleset-cost-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
  }

  .ruleset-cost-metric {
    padding: 9px 10px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .035);
  }

  .ruleset-cost-metric span {
    display: block;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
  }

  .ruleset-cost-metric strong {
    display: block;
    margin-top: 3px;
    color: #fff;
    font-size: 1.5rem;
  }

  .ruleset-cost-message {
    margin: 0;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
    line-height: 1.55;
  }

  .ruleset-cost-error {
    padding: 10px 12px;
    border: 1px solid rgba(232, 111, 111, .3);
    border-radius: 8px;
    background: rgba(232, 111, 111, .07);
    color: #efaaaa;
    font-size: 1.5rem;
  }

  .ruleset-cost-error[hidden],
  .ruleset-cost-results[hidden] {
    display: none;
  }

  @media (max-width: 767px) {
    .ruleset-cost-toolbar {
      align-items: stretch;
      flex-direction: column;
    }

    .ruleset-cost-actions,
    .ruleset-cost-results {
      grid-template-columns: 1fr;
    }

    .ruleset-cost-actions {
      display: grid;
    }

    .ruleset-cost-metrics {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  .ruleset-cost-card-list {
    display: grid;
    gap: 7px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--ruleset-room-line);
  }

  .ruleset-cost-card-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    padding: 8px 10px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .035);
  }

  .ruleset-cost-card-name {
    min-width: 0;
    overflow-wrap: anywhere;
    color: #e4e6f1;
    font-size: 1.5rem;
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  }

  .ruleset-cost-card-value {
    color: #fff;
    font-size: 1.5rem;
    font-weight: 850;
  }

  .ruleset-cost-card-value.is-changed {
    color: #e8d49d;
  }

  .ruleset-cost-summary {
    display: grid;
    gap: 6px;
    margin-top: 4px;
    padding: 10px;
    border: 1px solid var(--ruleset-room-line);
    border-radius: 8px;
  }

  .ruleset-cost-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    color: var(--ruleset-room-muted);
    font-size: 1.5rem;
  }

  .ruleset-cost-summary-row strong {
    color: #fff;
  }

  .ruleset-cost-summary-row.is-total {
    padding-top: 6px;
    border-top: 1px solid var(--ruleset-room-line);
    color: #fff;
    font-weight: 850;
  }
</style>

<div class="content-wrapper ruleset-room-page">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="ruleset-room-shell">
        <?php if ($room === null): ?>
          <section class="ruleset-room-panel ruleset-room-empty">
            <span class="ruleset-room-empty-icon" aria-hidden="true">🚪</span>
            <h1>找不到此對戰房間</h1>
            <p>該房間可能已結束、已取消，或網址不正確。</p>
            <a class="ruleset-room-button" href="/pages/ruleset/index.php">
              返回導都協定廣場
            </a>
          </section>
        <?php else: ?>
          <a class="ruleset-room-back" href="/pages/ruleset/index.php">
            ← 返回導都協定廣場
          </a>



          <header class="ruleset-room-panel ruleset-room-hero">
            <div>
              <p class="ruleset-room-kicker">Custom Match Room</p>
              <h1 class="ruleset-room-title"><?= $escape($room['room_name']) ?></h1>
              <div class="ruleset-room-hero-meta">
                <span
                  class="ruleset-room-status"
                  id="ruleset-room-match-status">
                  <?= $escape($room['status']) ?>
                </span>

                <span
                  class="ruleset-room-waiting"
                  id="ruleset-room-waiting-time">
                  已等待 <?= (int)$room['waiting_minutes'] ?> 分鐘
                </span>
              </div>
            </div>
            <span class="ruleset-room-badge">正式配對房間</span>
          </header>

          <main class="ruleset-room-main">
            <section class="ruleset-room-panel ruleset-room-section" aria-labelledby="ruleset-room-players-title">
              <header class="ruleset-room-section-header">
                <div>
                  <p class="ruleset-room-section-kicker">Participants</p>
                  <h2 class="ruleset-room-section-title" id="ruleset-room-players-title">對戰席位</h2>
                </div>
              </header>
              <div class="ruleset-room-players">
                <article class="ruleset-room-player">
                  <span class="ruleset-room-avatar" aria-hidden="true">
                    <?= $escape(mb_substr($room['host'], 0, 1)) ?>
                  </span>
                  <div>
                    <span class="ruleset-room-player-role">房主</span>

                    <strong class="ruleset-room-player-name">
                      <?= $escape($room['host']) ?>
                    </strong>

                    <span class="ruleset-room-player-meta">
                      已配對 · 請建立遊戲自訂房
                    </span>
                  </div>
                </article>

                <div class="ruleset-room-versus" aria-hidden="true">VS</div>

                <article class="ruleset-room-player">
                  <span class="ruleset-room-avatar" aria-hidden="true">
                    <?= $escape(mb_substr($room['guest'], 0, 1)) ?>
                  </span>
                  <div>
                    <span class="ruleset-room-player-role">對手</span> <strong class="ruleset-room-player-name">
                      <?= $escape($room['guest']) ?>
                    </strong>
                    <span class="ruleset-room-player-meta">
                      已配對 · 請至遊戲自訂房尋找對手
                    </span>
                  </div>
                </article>
              </div>
            </section>

            <section
              class="ruleset-room-panel ruleset-room-section"
              aria-labelledby="ruleset-room-password-title">

              <header class="ruleset-room-section-header">
                <div>
                  <p class="ruleset-room-section-kicker">
                    Game Room Password
                  </p>

                  <h2
                    class="ruleset-room-section-title"
                    id="ruleset-room-password-title">
                    遊戲房間密碼
                  </h2>

                  <p class="ruleset-room-section-description">
                    房主請在遊戲建立自訂房並勾選「使用密碼」，
                    再將遊戲產生的密碼貼到這裡提供給對手。
                  </p>
                </div>
              </header>

              <div class="ruleset-room-password-panel">
                <?php if ($room['is_password_owner']): ?>
                  <form
                    class="ruleset-room-password-form"
                    id="ruleset-room-password-form">

                    <input
                      class="ruleset-room-password-input"
                      id="ruleset-room-password-input"
                      name="game_room_password"
                      type="text"
                      maxlength="64"
                      autocomplete="off"
                      spellcheck="false"
                      placeholder="貼上遊戲產生的房間密碼"
                      value="<?= $escape($room['game_room_password']) ?>">

                    <button
                      class="ruleset-room-button"
                      id="ruleset-room-password-save"
                      type="submit">
                      <?= $room['game_room_password'] !== ''
                        ? '更新密碼'
                        : '送出密碼' ?>
                    </button>
                  </form>
                <?php endif; ?>

                <div
                  class="ruleset-room-password-display"
                  id="ruleset-room-password-display"
                  <?= $room['game_room_password'] === ''
                    ? 'hidden'
                    : '' ?>>

                  <strong
                    class="ruleset-room-password-value"
                    id="ruleset-room-password-value">
                    <?= $escape($room['game_room_password']) ?>
                  </strong>

                  <button
                    class="ruleset-room-button ruleset-room-button-secondary"
                    id="ruleset-room-password-copy"
                    type="button">
                    複製密碼
                  </button>
                </div>

                <p
                  class="ruleset-room-password-message"
                  id="ruleset-room-password-message">
                  <?php if ($room['game_room_password'] !== ''): ?>
                    房間密碼已由房主提供。
                  <?php elseif ($room['is_password_owner']): ?>
                    請先在遊戲取得密碼，再貼到上方送出。
                  <?php else: ?>
                    等待房主提供遊戲房間密碼……
                  <?php endif; ?>
                </p>
              </div>
            </section>

            <section
              class="ruleset-room-panel ruleset-room-section"
              aria-labelledby="ruleset-room-ruleset-title">

              <div class="ruleset-room-ruleset-head">
                <div>
                  <p class="ruleset-room-section-kicker">
                    Locked Ruleset Version
                  </p>

                  <h2
                    class="ruleset-room-ruleset-name"
                    id="ruleset-room-ruleset-title">
                    ULGG 自定義 COST 規則
                  </h2>

                  <p class="ruleset-room-ruleset-version">
                    Rule Version：
                    <span class="ruleset-room-code">
                      <?= $escape($room['ruleset_version_id']) ?>
                    </span>
                  </p>
                </div>

                <div class="ruleset-room-badges">
                  <span class="ruleset-room-badge ruleset-room-badge-official">
                    <?= $escape($room['cost_rule_label']) ?>
                  </span>

                  <span class="ruleset-room-badge ruleset-room-badge-published">
                    本場已鎖定
                  </span>
                </div>
              </div>

              <div class="ruleset-room-lock-notice">
                <strong>本場 COST 規則</strong><br>
                <?= $escape($room['cost_rule_description']) ?>
              </div>

              <details class="ruleset-room-technical">
                <summary>查看技術資訊</summary>

                <div class="ruleset-room-technical-content">
                  <?php if ($roomFullRules !== []): ?>
                    <div class="ruleset-room-lock-notice">
                      <strong>完整規則</strong>

                      <ul class="ruleset-room-rule-list">
                        <?php foreach ($roomFullRules as $rule): ?>
                          <li><?= $escape($rule) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                  <span>
                    matchId：
                    <span class="ruleset-room-code">
                      <?= (int)$room['match_id'] ?>
                    </span>
                  </span>

                  <span>
                    ruleVersionId：
                    <span class="ruleset-room-code">
                      <?= $escape($room['ruleset_version_id']) ?>
                    </span>
                  </span>

                  <span>
                    weeklyCostId：
                    <span class="ruleset-room-code">
                      <?= (int)$room['weekly_cost_id'] ?>
                    </span>
                  </span>

                  <span>
                    costCategory：
                    <span class="ruleset-room-code">
                      <?= (int)$room['cost_category'] ?>
                    </span>
                  </span>
                </div>
              </details>
            </section>

            <section
              class="ruleset-room-panel ruleset-room-section"
              aria-labelledby="ruleset-cost-title">

              <header class="ruleset-room-section-header">
                <div>
                  <p class="ruleset-room-section-kicker">Deck Audit</p>
                  <h2
                    class="ruleset-room-section-title"
                    id="ruleset-cost-title">
                    COST 檢查
                  </h2>
                  <p class="ruleset-room-section-description">
                    系統會依綁定的遊戲房間 ID 讀取 Watcher snapshot，
                    並依鎖定版本重新計算雙方 COST。 </p>
                </div>

                <span
                  class="ruleset-cost-status is-ready"
                  id="ruleset-cost-status">
                  <?= !empty($room['game_room_id'])
                    ? '已綁定遊戲房間'
                    : '尚未綁定遊戲房間' ?>
                </span>
              </header>

              <div class="ruleset-cost-panel">
                <div class="ruleset-cost-toolbar">
                  <p
                    class="ruleset-cost-message"
                    id="ruleset-cost-room-message">
                    <?php if (!empty($room['game_room_id'])): ?>
                      已綁定遊戲房間：
                      <span class="ruleset-room-code">
                        <?= $escape($room['game_room_id']) ?>
                      </span>
                    <?php else: ?>
                      目前尚未綁定 Watcher 遊戲房間，無法執行 COST 檢查。
                    <?php endif; ?>
                  </p>

                  <div class="ruleset-cost-actions">
                    <div class="ruleset-cost-actions">
                      <span class="ruleset-cost-message">
                        牌組鎖定後由系統自動驗證
                      </span>
                    </div>
                  </div>
                </div>

                <div
                  class="ruleset-cost-results"
                  id="ruleset-cost-results"
                  hidden>

                  <article
                    class="ruleset-cost-side"
                    id="ruleset-cost-side-a">
                    <div class="ruleset-cost-side-title">
                      <h3 id="ruleset-cost-player-a">A 方</h3>
                      <span
                        class="ruleset-cost-result-badge"
                        id="ruleset-cost-badge-a">
                        —
                      </span>
                    </div>

                    <div class="ruleset-cost-metrics">
                      <div class="ruleset-cost-metric">
                        <span>原始 COST</span>
                        <strong id="ruleset-cost-original-a">—</strong>
                      </div>

                      <div class="ruleset-cost-metric">
                        <span>規則 COST</span>
                        <strong id="ruleset-cost-final-a">—</strong>
                      </div>

                      <div class="ruleset-cost-metric">
                        <span>超出</span>
                        <strong id="ruleset-cost-over-a">—</strong>
                      </div>
                    </div>
                    <div
                      class="ruleset-cost-card-list"
                      id="ruleset-cost-cards-a">
                    </div>
                  </article>

                  <article
                    class="ruleset-cost-side"
                    id="ruleset-cost-side-b">
                    <div class="ruleset-cost-side-title">
                      <h3 id="ruleset-cost-player-b">B 方</h3>
                      <span
                        class="ruleset-cost-result-badge"
                        id="ruleset-cost-badge-b">
                        —
                      </span>
                    </div>

                    <div class="ruleset-cost-metrics">
                      <div class="ruleset-cost-metric">
                        <span>原始 COST</span>
                        <strong id="ruleset-cost-original-b">—</strong>
                      </div>

                      <div class="ruleset-cost-metric">
                        <span>規則 COST</span>
                        <strong id="ruleset-cost-final-b">—</strong>
                      </div>

                      <div class="ruleset-cost-metric">
                        <span>超出</span>
                        <strong id="ruleset-cost-over-b">—</strong>
                      </div>
                    </div>
                    <div
                      class="ruleset-cost-card-list"
                      id="ruleset-cost-cards-b">
                    </div>
                  </article>
                </div>

                <div
                  class="ruleset-cost-error"
                  id="ruleset-cost-error"
                  role="alert"
                  hidden>
                </div>
                <div
                  class="ruleset-cost-actions"
                  id="ruleset-cost-complete-actions"
                  hidden>
                  <a
                    class="ruleset-room-button"
                    id="ruleset-room-return"
                    href="/pages/ruleset/index.php">
                    驗證完成 | 返回導都協定廣場
                  </a>
                </div>
              </div>
            </section>

            <div class="ruleset-room-grid">
              <section class="ruleset-room-panel ruleset-room-section" aria-labelledby="ruleset-room-info-title">
                <header class="ruleset-room-section-header">
                  <div>
                    <p class="ruleset-room-section-kicker">Room Information</p>
                    <h2 class="ruleset-room-section-title" id="ruleset-room-info-title">房間資訊</h2>
                  </div>
                </header>
                <dl class="ruleset-room-info-list">
                  <div class="ruleset-room-info-row">
                    <dt>伺服器</dt>
                    <dd><?= $escape($room['region']) ?></dd>
                  </div>
                  <div class="ruleset-room-info-row">
                    <dt>頻道</dt>
                    <dd><?= $escape($channelLabels[$room['channel']] ?? $room['channel']) ?></dd>
                  </div>
                  <div class="ruleset-room-info-row">
                    <dt>公開範圍</dt>
                    <dd><?= $escape($room['visibility']) ?></dd>
                  </div>
                  <div class="ruleset-room-info-row">
                    <dt>建立時間</dt>
                    <dd><?= $escape($room['created_at']) ?></dd>
                  </div>
                  <div class="ruleset-room-info-row">
                    <dt>房間 ID</dt>
                    <dd class="ruleset-room-code"><?= $escape($room['room_id']) ?></dd>
                  </div>
                  <div class="ruleset-room-info-row">
                    <dt>遊戲房間 ID</dt>
                    <dd
                      class="ruleset-room-code"
                      id="ruleset-room-game-room-id">
                      <?= !empty($room['game_room_id'])
                        ? $escape($room['game_room_id'])
                        : '尚未綁定' ?>
                    </dd>
                  </div>
                </dl>
              </section>

              <section class="ruleset-room-panel ruleset-room-section" aria-labelledby="ruleset-room-client-title">
                <header class="ruleset-room-section-header">
                  <div>
                    <p class="ruleset-room-section-kicker">ULR Companion</p>
                    <h2 class="ruleset-room-section-title" id="ruleset-room-client-title">Client 狀態</h2>
                    <p class="ruleset-room-section-description">
                      尚未連線不是錯誤；正式版將在開戰前作為 Ready Gate。
                    </p>
                  </div>
                </header>
                <dl class="ruleset-room-client-list">
                  <div class="ruleset-room-info-row">
                    <dt>玩家 1</dt>
                    <dd
                      class="ruleset-room-client-state"
                      id="ruleset-room-player1-state">
                      等待建立遊戲房間
                    </dd>
                  </div>

                  <div class="ruleset-room-info-row">
                    <dt>玩家 2</dt>
                    <dd id="ruleset-room-player2-state">
                      等待加入遊戲房間
                    </dd>
                  </div>

                  <div class="ruleset-room-info-row">
                    <dt>牌組驗證</dt>
                    <dd id="ruleset-room-validation-state">
                      等待遊戲房間連線
                    </dd>
                  </div>
                </dl>
              </section>
            </div>


          </main>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>

<?php if ($room !== null): ?>

  <script>
    (() => {
      'use strict';

      const joinButton = document.getElementById('ruleset-room-join');
      const joinResult = document.getElementById('ruleset-room-join-result');
      const rulesToggle = document.getElementById('ruleset-room-rules-toggle');
      const fullRules = document.getElementById('ruleset-room-full-rules');

      const costStatus = document.getElementById('ruleset-cost-status');
      const costResults = document.getElementById('ruleset-cost-results');
      const costError = document.getElementById('ruleset-cost-error');
      const costCompleteActions = document.getElementById(
        'ruleset-cost-complete-actions'
      );
      const returnButton = document.getElementById(
        'ruleset-room-return'
      );
      const passwordForm = document.getElementById(
        'ruleset-room-password-form'
      );

      const passwordInput = document.getElementById(
        'ruleset-room-password-input'
      );

      const passwordSaveButton = document.getElementById(
        'ruleset-room-password-save'
      );

      const passwordDisplay = document.getElementById(
        'ruleset-room-password-display'
      );

      const passwordValue = document.getElementById(
        'ruleset-room-password-value'
      );

      const passwordCopyButton = document.getElementById(
        'ruleset-room-password-copy'
      );

      const passwordMessage = document.getElementById(
        'ruleset-room-password-message'
      );

      let currentGameRoomPassword =
        <?= json_encode(
          $room['game_room_password'] ?? '',
          JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;
      passwordForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const password = String(
          passwordInput?.value || ''
        ).trim();

        if (password === '') {
          setPasswordMessage(
            '請貼上遊戲產生的房間密碼。',
            'error'
          );

          passwordInput?.focus();
          return;
        }

        if (passwordSaveButton) {
          passwordSaveButton.disabled = true;
          passwordSaveButton.textContent = '儲存中…';
        }

        setPasswordMessage('正在儲存房間密碼…');

        try {
          const response = await fetch(
            '/pages/ruleset/api/save_room_password.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                match_id: matchId,
                game_room_password: password
              })
            }
          );

          const raw = await response.text();

          if (raw.trim() === '') {
            throw new Error(
              `伺服器回傳空內容（HTTP ${response.status}）。`
            );
          }

          let result;

          try {
            result = JSON.parse(raw);
          } catch {
            throw new Error(
              `伺服器回傳非 JSON（HTTP ${response.status}）。`
            );
          }

          if (!response.ok || result?.ok !== true) {
            throw new Error(
              result?.error?.message ||
              `儲存失敗（HTTP ${response.status}）。`
            );
          }

          renderGameRoomPassword(
            result?.data?.game_room_password || password
          );

          setPasswordMessage(
            '房間密碼已送出，對手頁面會自動顯示。',
            'success'
          );

          if (passwordSaveButton) {
            passwordSaveButton.textContent = '更新密碼';
          }
        } catch (error) {
          setPasswordMessage(
            error instanceof Error ?
            error.message :
            '儲存房間密碼失敗。',
            'error'
          );
        } finally {
          if (passwordSaveButton) {
            passwordSaveButton.disabled = false;

            if (
              passwordSaveButton.textContent === '儲存中…'
            ) {
              passwordSaveButton.textContent =
                currentGameRoomPassword !== '' ?
                '更新密碼' :
                '送出密碼';
            }
          }
        }
      });
      passwordCopyButton?.addEventListener('click', async () => {
        if (currentGameRoomPassword === '') {
          setPasswordMessage(
            '房主尚未提供房間密碼。',
            'error'
          );

          return;
        }

        try {
          await navigator.clipboard.writeText(
            currentGameRoomPassword
          );

          setPasswordMessage(
            '房間密碼已複製。',
            'success'
          );

          passwordCopyButton.textContent = '已複製';

          window.setTimeout(() => {
            passwordCopyButton.textContent = '複製密碼';
          }, 1500);
        } catch {
          setPasswordMessage(
            '無法自動複製，請手動選取密碼。',
            'error'
          );
        }
      });
      returnButton?.addEventListener('click', () => {
        sessionStorage.setItem(
          'ulggDismissedMatchId',
          String(matchId)
        );
      });


      const costElements = {
        A: {
          side: document.getElementById('ruleset-cost-side-a'),
          player: document.getElementById('ruleset-cost-player-a'),
          badge: document.getElementById('ruleset-cost-badge-a'),
          original: document.getElementById('ruleset-cost-original-a'),
          final: document.getElementById('ruleset-cost-final-a'),
          over: document.getElementById('ruleset-cost-over-a'),
          cards: document.getElementById('ruleset-cost-cards-a')
        },
        B: {
          side: document.getElementById('ruleset-cost-side-b'),
          player: document.getElementById('ruleset-cost-player-b'),
          badge: document.getElementById('ruleset-cost-badge-b'),
          original: document.getElementById('ruleset-cost-original-b'),
          final: document.getElementById('ruleset-cost-final-b'),
          over: document.getElementById('ruleset-cost-over-b'),
          cards: document.getElementById('ruleset-cost-cards-b')
        }
      };
      const savedDeckAudit = {
        A: <?= json_encode(
              $room['host_audit'] ?? null,
              JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?>,

        B: <?= json_encode(
              $room['guest_audit'] ?? null,
              JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?>
      };
      const matchId =
        <?= json_encode(
          (int)($room['match_id'] ?? 0),
          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;

      let gameRoomId =
        <?= json_encode(
          $room['game_room_id'] ?? null,
          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;

      const matchStatusElement =
        document.getElementById('ruleset-room-match-status');

      const waitingTimeElement =
        document.getElementById('ruleset-room-waiting-time');

      const gameRoomIdElement =
        document.getElementById('ruleset-room-game-room-id');
      const costRoomMessage =
        document.getElementById('ruleset-cost-room-message');
      const player1StateElement =
        document.getElementById('ruleset-room-player1-state');

      const player2StateElement =
        document.getElementById('ruleset-room-player2-state');

      const validationStateElement =
        document.getElementById('ruleset-room-validation-state');

      let matchPollTimer = null;
      let terminalReloadScheduled = false;

      function setPasswordMessage(message, state = '') {
        if (!passwordMessage) {
          return;
        }

        passwordMessage.className =
          'ruleset-room-password-message';

        if (state !== '') {
          passwordMessage.classList.add(`is-${state}`);
        }

        passwordMessage.textContent = message;
      }

      function renderGameRoomPassword(password) {
        currentGameRoomPassword = String(password || '').trim();

        if (currentGameRoomPassword === '') {
          if (passwordDisplay) {
            passwordDisplay.hidden = true;
          }

          return;
        }

        if (passwordValue) {
          passwordValue.textContent = currentGameRoomPassword;
        }

        if (passwordDisplay) {
          passwordDisplay.hidden = false;
        }

        if (
          passwordInput &&
          document.activeElement !== passwordInput
        ) {
          passwordInput.value = currentGameRoomPassword;
        }
      }

      function reloadForTerminalAudit() {
        if (terminalReloadScheduled) {
          return;
        }

        terminalReloadScheduled = true;
        stopMatchPolling();

        window.setTimeout(() => {
          window.location.reload();
        }, 500);
      }


      joinButton?.addEventListener('click', () => {
        if (joinResult) {
          joinResult.hidden = false;
        }
      });

      rulesToggle?.addEventListener('click', () => {
        if (!fullRules) {
          return;
        }

        const willOpen = fullRules.hidden;

        fullRules.hidden = !willOpen;
        rulesToggle.setAttribute('aria-expanded', String(willOpen));
        rulesToggle.textContent = willOpen ?
          '收起完整規則' :
          '查看完整規則';

        if (willOpen) {
          fullRules.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
          });
        }
      });

      function buildSavedSide(sideKey, audit, playerName) {
        if (!audit || typeof audit !== 'object') {
          return null;
        }

        const finalCost = Number(audit.final_cost ?? 0);
        const targetCost = Number(audit.target_cost ?? 0);
        const playerLegal =
          audit.player_legal === true ||
          audit.legal === true;

        const overallLegal =
          Object.prototype.hasOwnProperty.call(audit, 'overall_legal') ?
          audit.overall_legal === true :
          playerLegal;

        return {
          player: {
            name: playerName
          },
          evaluation: {
            status: playerLegal ? 'LEGAL' : 'ILLEGAL',
            overall_legal: overallLegal,
            pair_legal: Object.prototype.hasOwnProperty.call(audit, 'pair_legal') ?
              audit.pair_legal === true : true,
            original_deck_cost: '—',
            final_cost: finalCost,
            cost_limit: targetCost,
            over_cost: Math.max(0, finalCost - targetCost),

            cards: Array.isArray(audit.cards) ?
              audit.cards : [],

            ruleset_character_cost: Number(
              audit.character_subtotal ?? 0
            ),

            difference_penalty: Number(
              audit.difference_penalty ??
              audit.penalty_cost ??
              0
            ),

            non_character_cost: Number(
              audit.non_character_cost ??
              audit.weapon_event_cost ??
              0
            )
          }
        };
      }

      function setCostStatus(text, stateClass) {
        if (!costStatus) {
          return;
        }

        costStatus.className = 'ruleset-cost-status';

        if (stateClass) {
          costStatus.classList.add(stateClass);
        }

        costStatus.textContent = text;
      }

      function resetCostResult() {
        if (costResults) {
          costResults.hidden = true;
        }

        if (costError) {
          costError.hidden = true;
          costError.textContent = '';
        }
        if (costCompleteActions) {
          costCompleteActions.hidden = true;
        }
      }

      /* 角色 COST 明細函式 */
      function renderCharacterCards(container, evaluation) {
        if (!container) {
          return;
        }

        container.replaceChildren();

        const cards = Array.isArray(evaluation?.cards) ?
          evaluation.cards : [];

        if (cards.length === 0) {
          const empty = document.createElement('p');
          empty.className = 'ruleset-cost-message';
          empty.textContent = '尚未取得角色牌 COST 資料。';
          container.appendChild(empty);
          return;
        }

        cards.forEach((card) => {
          const row = document.createElement('div');
          row.className = 'ruleset-cost-card-row';

          const name = document.createElement('span');
          name.className = 'ruleset-cost-card-name';
          const characterName = String(
            card.name ||
            card.card_id ||
            card.chara_id ||
            '未知角色'
          );

          const characterLevel = Number(card.level ?? 0);

          name.textContent = characterLevel > 0 ?
            `L${characterLevel} ${characterName}` :
            characterName;

          if (card.card_id) {
            name.title = String(card.card_id);
          }

          const value = document.createElement('strong');
          value.className = 'ruleset-cost-card-value';

          const baseCost = Number(card.base_cost ?? 0);
          const rulesetCost = Number(card.ruleset_cost ?? baseCost);

          value.textContent = `${rulesetCost} COST`;

          if (baseCost !== rulesetCost) {
            value.classList.add('is-changed');
            value.title = `原始 COST ${baseCost} → 規則 COST ${rulesetCost}`;
          }

          row.append(name, value);
          container.appendChild(row);
        });

        const characterSubtotal = Number(
          evaluation?.ruleset_character_cost ?? 0
        );

        const nonCharacterCost = Number(
          evaluation?.non_character_cost ?? 0
        );

        const finalCost = Number(
          evaluation?.final_cost ?? characterSubtotal
        );

        const differencePenalty = Number(
          evaluation?.difference_penalty ??
          Math.max(
            0,
            finalCost - characterSubtotal - nonCharacterCost
          )
        );

        const summary = document.createElement('div');
        summary.className = 'ruleset-cost-summary';

        const rows = [
          ['角色牌小計', characterSubtotal],
          ['差距加算', differencePenalty],
          ['其他 COST', nonCharacterCost],
          ['最終 COST', finalCost]
        ];

        rows.forEach(([label, value], index) => {
          const row = document.createElement('div');
          row.className = 'ruleset-cost-summary-row';

          if (index === rows.length - 1) {
            row.classList.add('is-total');
          }

          const labelElement = document.createElement('span');
          labelElement.textContent = label;

          const valueElement = document.createElement('strong');
          valueElement.textContent = String(value);

          row.append(labelElement, valueElement);
          summary.appendChild(row);
        });

        container.appendChild(summary);
      }

      function renderSide(sideKey, sideData) {
        const elements = costElements[sideKey];

        if (!elements || !sideData) {
          return;
        }

        const player = sideData.player || {};
        const evaluation = sideData.evaluation || {};
        const status = sideData.evaluation ?
          evaluation.status || 'ERROR' :
          'NOT_EVALUATED';
        const isLegal = status === 'LEGAL';

        elements.player.textContent =
          `${sideKey} 方 · ${player.name || '未知玩家'}`;

        elements.badge.textContent =
          status === 'NOT_EVALUATED' ?
          '尚未判定' :
          status;
        elements.original.textContent =
          evaluation.original_deck_cost ?? '—';
        elements.final.textContent =
          evaluation.final_cost ?? '—';

        elements.over.textContent =
          evaluation.over_cost ?? '—';

        renderCharacterCards(elements.cards, evaluation);

        elements.side.classList.remove(
          'is-legal',
          'is-illegal'
        );

        elements.badge.classList.remove(
          'is-legal',
          'is-illegal'
        );

        if (isLegal) {
          elements.side.classList.add('is-legal');
          elements.badge.classList.add('is-legal');
        } else if (status === 'ILLEGAL') {
          elements.side.classList.add('is-illegal');
          elements.badge.classList.add('is-illegal');
        }
      }

      function renderRoomEvaluation(result) {
        renderSide('A', result.sideA);
        renderSide('B', result.sideB);

        if (costResults) {
          costResults.hidden = false;
        }

        switch (result.overall_status) {
          case 'PASSED':
            setCostStatus('雙方皆通過', 'is-passed');
            break;

          case 'VIOLATION_FOUND':
            setCostStatus('發現違規牌組', 'is-violation');
            break;

          case 'INCOMPLETE': {
            const freshness =
              result?.source?.freshness?.status || 'UNKNOWN';

            if (freshness === 'STALE') {
              setCostStatus('Watcher 資料已過期', 'is-error');
            } else if (freshness === 'FUTURE_TIMESTAMP') {
              setCostStatus('Watcher 時間異常', 'is-error');
            } else {
              setCostStatus('牌組資料不足', 'is-error');
            }

            break;
          }

          default:
            setCostStatus('檢查結果異常', 'is-error');
            break;
        }
        if (
          costCompleteActions &&
          (
            result.overall_status === 'PASSED' ||
            result.overall_status === 'VIOLATION_FOUND'
          )
        ) {
          costCompleteActions.hidden = false;
        }

      }
      const matchStatusLabels = {
        WAITING_ROOM: '等待遊戲房間',
        WAITING_DECK: '等待雙方牌組',
        READY: '牌組驗證完成',
        CANCELLED: '比賽已取消',
        FINISHED: '比賽已結束'
      };

      function stopMatchPolling() {
        if (matchPollTimer !== null) {
          window.clearInterval(matchPollTimer);
          matchPollTimer = null;
        }
      }

      function renderMatchStatus(data) {
        const matchStatus = String(
          data?.match_status || 'WAITING_ROOM'
        ).toUpperCase();

        const returnedGameRoomId = String(
          data?.game_room_id || ''
        ).trim();
        const returnedGameRoomPassword = String(
          data?.game_room_password || ''
        ).trim();

        if (
          returnedGameRoomPassword !== '' &&
          returnedGameRoomPassword !== currentGameRoomPassword
        ) {
          renderGameRoomPassword(
            returnedGameRoomPassword
          );

          setPasswordMessage(
            '房主已提供遊戲房間密碼。',
            'success'
          );
        }

        if (returnedGameRoomId !== '') {
          gameRoomId = returnedGameRoomId;
        }

        if (matchStatusElement) {
          matchStatusElement.textContent =
            matchStatusLabels[matchStatus] || matchStatus;
        }

        if (gameRoomIdElement) {
          gameRoomIdElement.textContent =
            gameRoomId !== '' ? gameRoomId : '尚未綁定';
        }
        if (costRoomMessage) {
          costRoomMessage.textContent =
            gameRoomId !== '' ?
            `已綁定遊戲房間：${gameRoomId}` :
            '目前尚未綁定遊戲房間，無法執行 COST 檢查。';
        }


        switch (matchStatus) {
          case 'WAITING_ROOM':
            if (player1StateElement) {
              player1StateElement.textContent = '請至迪特赫姆建立自訂房';
            }

            if (player2StateElement) {
              player2StateElement.textContent = '等待玩家 1 建立房間';
            }

            if (validationStateElement) {
              validationStateElement.textContent = '等待遊戲房間連線';
            }

            setCostStatus('尚未綁定遊戲房間', 'is-ready');
            break;

          case 'WAITING_DECK':
            if (player1StateElement) {
              player1StateElement.textContent = '遊戲房間已確認';
            }

            if (player2StateElement) {
              player2StateElement.textContent = '遊戲房間已確認';
            }

            if (validationStateElement) {
              validationStateElement.textContent = '等待雙方鎖定牌組';
            }

            setCostStatus('已綁定遊戲房間', 'is-checking');
            break;

          case 'READY':
            if (player1StateElement) {
              player1StateElement.textContent = '已就緒';
            }

            if (player2StateElement) {
              player2StateElement.textContent = '已就緒';
            }

            if (validationStateElement) {
              validationStateElement.textContent = '牌組驗證通過';
            }

            setCostStatus('雙方牌組驗證通過', 'is-passed');

            /*
             * 若目前頁面尚未載入 Audit，
             * 重新載入一次，讓 PHP 從資料庫讀取正式結果。
             */
            if (!savedDeckAudit.A || !savedDeckAudit.B) {
              reloadForTerminalAudit();
            } else {
              stopMatchPolling();

              if (costCompleteActions) {
                costCompleteActions.hidden = false;
              }
            }

            break;

          case 'CANCELLED': {
            const cancellationCode = String(
              data?.cancellation_code || ''
            ).toUpperCase();

            const cancellationMessage = String(
              data?.cancellation_message || ''
            ).trim();

            if (player1StateElement) {
              player1StateElement.textContent = '配對已取消';
            }

            if (player2StateElement) {
              player2StateElement.textContent = '配對已取消';
            }

            if (validationStateElement) {
              validationStateElement.textContent =
                cancellationCode === 'ROOM_TIMEOUT' ?
                '5 分鐘內未建立遊戲房間' :
                cancellationCode === 'DECK_TIMEOUT' ?
                '10 分鐘內未完成牌組驗證' :
                '本場配對已取消';
            }

            setCostStatus(
              cancellationMessage ||
              '本場配對已取消，即將返回天梯。',
              'is-violation'
            );

            stopMatchPolling();

            if (
              cancellationCode === 'DECK_COST_MISMATCH' &&
              (!savedDeckAudit.A || !savedDeckAudit.B)
            ) {
              reloadForTerminalAudit();
              break;
            }

            if (
              cancellationCode === 'ROOM_TIMEOUT' ||
              cancellationCode === 'DECK_TIMEOUT'
            ) {
              window.setTimeout(() => {
                window.location.replace('/pages/ruleset/index.php');
              }, 2000);
            }

            break;
          }

          case 'FINISHED':
            if (validationStateElement) {
              validationStateElement.textContent = '比賽已結束';
            }

            setCostStatus('比賽已結束', 'is-passed');
            stopMatchPolling();
            break;
        }
      }

      async function fetchMatchStatus() {
        if (!Number.isInteger(matchId) || matchId <= 0) {
          stopMatchPolling();
          return;
        }

        try {
          const response = await fetch(
            `/pages/ruleset/api/match_status.php?match=${encodeURIComponent(matchId)}`, {
              method: 'GET',
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json'
              },
              cache: 'no-store'
            }
          );

          const result = await response.json();

          if (!response.ok || result?.ok !== true) {
            throw new Error(
              result?.error?.message || `HTTP ${response.status}`
            );
          }

          renderMatchStatus(result.data || {});
        } catch (error) {
          console.error('[ULGG match status]', error);

          if (validationStateElement) {
            validationStateElement.textContent = '狀態連線暫時失敗';
          }
        }
      }
      const savedSideA = buildSavedSide(
        'A',
        savedDeckAudit.A,
        <?= json_encode(
          $room['host'],
          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>
      );

      const savedSideB = buildSavedSide(
        'B',
        savedDeckAudit.B,
        <?= json_encode(
          $room['guest'],
          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>
      );

      if (savedSideA && savedSideB) {
        renderRoomEvaluation({
          sideA: savedSideA,
          sideB: savedSideB,
          overall_status: savedSideA.evaluation.overall_legal === true &&
            savedSideB.evaluation.overall_legal === true ?
            'PASSED' : 'VIOLATION_FOUND'
        });
      }
      fetchMatchStatus();

      matchPollTimer = window.setInterval(() => {
        fetchMatchStatus();
      }, 3000);


    })();
  </script>

<?php endif; ?>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>