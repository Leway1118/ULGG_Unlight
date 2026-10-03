<?php

/**
 * UL.GG CUP 八強戰報版型
 * 放置位置：report_views/top8.php
 *
 * 可使用外層已提供變數：
 * $report, $reportSections, $tournamentId, $isAdmin, $currentPlayerId
 */

if (!function_exists('top8_e')) {
  function top8_e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('top8_percent')) {
  function top8_percent(float $value, int $digits = 1): string
  {
    return number_format($value, $digits) . '%';
  }
}

if (!function_exists('top8_section_text')) {
  function top8_section_text(array $sections, string $key, string $fallback = ''): string
  {
    if (!isset($sections[$key])) {
      return $fallback;
    }

    $section = $sections[$key];

    foreach (['content_html', 'section_content', 'content', 'body'] as $field) {
      if (!empty($section[$field])) {
        return (string)$section[$field];
      }
    }

    return $fallback;
  }
}

if (!function_exists('top8_render_decks')) {
  function top8_render_decks(
    int $playerId,
    array $deckDataByPlayer,
    array $deckUsageByPlayer,
    string $bannedDeck,
    string $banStatus
  ): void {
    $playerDecks = $deckDataByPlayer[$playerId] ?? [];

    foreach (['A', 'B', 'C'] as $deckCode) {
      $deck = $playerDecks[$deckCode] ?? null;
      $usage = $deckUsageByPlayer[$playerId][$deckCode] ?? [
        'picks' => 0,
        'wins' => 0,
        'losses' => 0,
      ];
      $isUsed = (int)$usage['picks'] > 0;
      $isBanned = $banStatus === 'confirmed' && $deckCode === $bannedDeck;

      $class = 'top8-deck-row';
      if ($isBanned) {
        $class .= ' is-banned';
      }
?>
      <div class="<?= top8_e($class) ?>">
        <div class="top8-deck-row-head">
          <div class="top8-deck-title">
            <strong>牌組 <?= top8_e($deckCode) ?></strong>

            <?php if ($isBanned): ?>
              <span class="top8-deck-ban-label">已 BAN</span>
            <?php elseif ($isUsed): ?>
              <span class="top8-deck-used-label">已出場</span>
            <?php else: ?>
              <span class="top8-deck-unused-label">尚未出場</span>
            <?php endif; ?>
          </div>

          <span class="top8-deck-usage-score">
            <?php if ($isUsed): ?>
              <?= (int)$usage['wins'] ?>勝<?= (int)$usage['losses'] ?>敗
            <?php else: ?>
              未使用
            <?php endif; ?>
          </span>
        </div>

        <div class="top8-deck-cards">
          <?php if ($deck && !empty($deck['cards'])): ?>
            <?php foreach ($deck['cards'] as $card): ?>
              <?php
              $cardId = (int)($card['id'] ?? 0);
              $cardName = trim((string)($card['level'] ?? '') . ' ' . (string)($card['name'] ?? ''));
              $cardIco = trim((string)($card['ico'] ?? ''));
              $imageUrl = $cardIco !== '' ? IMG_BASE . $cardIco : '';
              ?>
              <div
                class="top8-mini-card <?= $imageUrl === '' ? 'is-image-error' : '' ?>"
                title="<?= top8_e($cardName) ?>">
                <?php if ($imageUrl !== ''): ?>
                  <img
                    src="<?= top8_e($imageUrl) ?>"
                    alt="<?= top8_e($cardName) ?>"
                    loading="lazy"
                    onerror="this.parentElement.classList.add('is-image-error'); this.remove();">
                <?php endif; ?>

                <div class="top8-mini-card-caption">
                  <span><?= top8_e($card['level'] ?? '') ?></span>
                  <strong><?= top8_e($card['name'] ?? ('角色 #' . $cardId)) ?></strong>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="top8-deck-empty">無牌組資料</div>
          <?php endif; ?>
        </div>
      </div>
<?php
    }
  }
}

// 八強戰前狀態觀察：僅使用本屆已完成的實際對戰資料。
$players = [
  1 => [
    'seed' => 1,
    'name' => 'ASAPbaby',
    'matches' => 2,
    'games' => '4 勝 0 敗',
    'game_rate' => 100.0,
    'sweeps' => 2,
    'used_decks' => 3,
    'style' => '壓制完成型',
    'icon' => '🔥',
    'tone' => 'gold',
    'summary' => '兩輪皆以 2–0 晉級，是目前唯一零失局且三副牌皆取得正式賽勝場的選手。',
    'strength' => '樣本較完整、三副皆有勝場，牌組運用已獲得實戰驗證。',
    'focus' => '八強將面對同樣完整使用三副牌的花間與風，系列賽節奏值得關注。',
  ],
  4 => [
    'seed' => 4,
    'name' => '打牌靠賽輕鬆',
    'matches' => 1,
    'games' => '2 勝 0 敗',
    'game_rate' => 100.0,
    'sweeps' => 1,
    'used_decks' => 2,
    'style' => '小樣本不敗型',
    'icon' => '⚡',
    'tone' => 'orange',
    'summary' => '唯一一場實戰以 A、C 兩副不同牌組各取一勝，已亮相的牌組保持全勝。',
    'strength' => '兩副不同牌組均能完成勝場，單場狀態相當亮眼。',
    'focus' => '目前樣本只有兩局；尚未出場的 B 組已在八強遭到 BAN。',
  ],
  5 => [
    'seed' => 5,
    'name' => '花間與風',
    'matches' => 2,
    'games' => '4 勝 1 敗',
    'game_rate' => 80.0,
    'sweeps' => 1,
    'used_decks' => 3,
    'style' => '多變運用型',
    'icon' => '🃏',
    'tone' => 'purple',
    'summary' => '三副牌都已在正式賽使用，五個小局分布在 A、B、C，選擇最為分散。',
    'strength' => '三副牌皆具正式賽經驗，系列賽中的切換選項完整。',
    'focus' => 'B 組目前一勝一敗，但樣本仍少，不宜視為固定弱點。',
  ],
  7 => [
    'seed' => 7,
    'name' => '羅馬',
    'matches' => 1,
    'games' => '2 勝 1 敗',
    'game_rate' => 66.7,
    'sweeps' => 0,
    'used_decks' => 2,
    'style' => '逆轉調整型',
    'icon' => '🔄',
    'tone' => 'blue',
    'summary' => '上一輪首局落敗後連勝兩局，並在決勝局切換至 A 組完成讓一追二。',
    'strength' => '已展現落後局面下的牌組調整與決勝局執行力。',
    'focus' => 'C 組尚未正式出場，八強將面對兩輪僅失一局的十六夜蝶。',
  ],
  8 => [
    'seed' => 8,
    'name' => '嗷嗷龜',
    'matches' => 1,
    'games' => '2 勝 1 敗',
    'game_rate' => 66.7,
    'sweeps' => 0,
    'used_decks' => 2,
    'style' => '逆轉調整型',
    'icon' => '🐢',
    'tone' => 'green',
    'summary' => '唯一實戰以 2–1 晉級，A 組失利後仍再次使用並拿下決勝局。',
    'strength' => '系列賽中敢於重新使用剛落敗的牌組，展現調整與執行韌性。',
    'focus' => '仍有一副牌組尚未在正式賽亮相，八強階段的牌組安排值得關注。',
  ],
  13 => [
    'seed' => 13,
    'name' => '我去賣身^q^',
    'matches' => 1,
    'games' => '2 勝 1 敗',
    'game_rate' => 66.7,
    'sweeps' => 0,
    'used_decks' => 2,
    'style' => '逆轉調整型',
    'icon' => '🎯',
    'tone' => 'blue',
    'summary' => '首局 B 組失利後改用 C 組扳平，決勝局再回切 B 組完成逆轉。',
    'strength' => '換牌後能再次回到原牌組取勝，系列賽判斷具故事性。',
    'focus' => 'A 組尚未正式使用，八強將與同樣完成讓一追二的嗷嗷龜交手。',
  ],
  18 => [
    'seed' => 18,
    'name' => '海底窮人',
    'matches' => 1,
    'games' => '2 勝 0 敗',
    'game_rate' => 100.0,
    'sweeps' => 1,
    'used_decks' => 2,
    'style' => '小樣本不敗型',
    'icon' => '🌊',
    'tone' => 'blue',
    'summary' => '唯一一場實戰使用 B、C 兩副不同牌組完成 2–0，兩副皆取得勝場。',
    'strength' => '已證明兩副牌都能完成系列賽所需勝場。',
    'focus' => '目前只有兩局樣本；尚未出場的 A 組已在八強遭到 BAN。',
  ],
  23 => [
    'seed' => 23,
    'name' => '十六夜蝶',
    'matches' => 2,
    'games' => '4 勝 1 敗',
    'game_rate' => 80.0,
    'sweeps' => 1,
    'used_decks' => 2,
    'style' => '穩定競爭型',
    'icon' => '🦋',
    'tone' => 'purple',
    'summary' => '兩輪皆成功晉級，A 組兩戰全勝，B 組則在失利後於決勝局再次取勝。',
    'strength' => '兩場實戰僅失一局，現有兩副牌都已取得正式賽勝場。',
    'focus' => 'C 組尚未正式亮相，對上羅馬時將考驗穩定度與逆轉韌性的碰撞。',
  ],
];

$playersBySeed = array_values($players);
usort($playersBySeed, static fn(array $a, array $b): int => $a['seed'] <=> $b['seed']);

$deckUsageByPlayer = [
  1  => ['A' => ['picks' => 1, 'wins' => 1, 'losses' => 0], 'B' => ['picks' => 1, 'wins' => 1, 'losses' => 0], 'C' => ['picks' => 2, 'wins' => 2, 'losses' => 0]],
  4  => ['A' => ['picks' => 1, 'wins' => 1, 'losses' => 0], 'B' => ['picks' => 0, 'wins' => 0, 'losses' => 0], 'C' => ['picks' => 1, 'wins' => 1, 'losses' => 0]],
  5  => ['A' => ['picks' => 1, 'wins' => 1, 'losses' => 0], 'B' => ['picks' => 2, 'wins' => 1, 'losses' => 1], 'C' => ['picks' => 2, 'wins' => 2, 'losses' => 0]],
  7  => ['A' => ['picks' => 1, 'wins' => 1, 'losses' => 0], 'B' => ['picks' => 2, 'wins' => 1, 'losses' => 1], 'C' => ['picks' => 0, 'wins' => 0, 'losses' => 0]],
  8  => ['A' => ['picks' => 2, 'wins' => 1, 'losses' => 1], 'B' => ['picks' => 1, 'wins' => 1, 'losses' => 0], 'C' => ['picks' => 0, 'wins' => 0, 'losses' => 0]],
  13 => ['A' => ['picks' => 0, 'wins' => 0, 'losses' => 0], 'B' => ['picks' => 2, 'wins' => 1, 'losses' => 1], 'C' => ['picks' => 1, 'wins' => 1, 'losses' => 0]],
  18 => ['A' => ['picks' => 0, 'wins' => 0, 'losses' => 0], 'B' => ['picks' => 1, 'wins' => 1, 'losses' => 0], 'C' => ['picks' => 1, 'wins' => 1, 'losses' => 0]],
  23 => ['A' => ['picks' => 2, 'wins' => 2, 'losses' => 0], 'B' => ['picks' => 3, 'wins' => 2, 'losses' => 1], 'C' => ['picks' => 0, 'wins' => 0, 'losses' => 0]],
];

$matchups = [
  [
    'code' => 'R3-1',
    'left_id' => 1,
    'left' => 'ASAPbaby',
    'right_id' => 5,
    'right' => '花間與風',
    'tag' => '完整牌組池對決',
    'headline' => '零失局壓制力，迎戰三副牌多變運用',
    'summary' => '兩人都是八強中已完整使用 A、B、C 三副牌的選手。ASAPbaby 目前四局全勝；花間與風則在五個小局中分散使用三副牌。',
    'key' => '一方以穩定壓制推進，一方以牌組切換增加變化，將是八強最具代表性的完整牌組池對決。',
    'left_ban' => '',
    'right_ban' => '',
    'ban_status' => 'pending',
  ],
  [
    'code' => 'R3-2',
    'left_id' => 23,
    'left' => '十六夜蝶',
    'right_id' => 7,
    'right' => '羅馬',
    'tag' => '穩定度 vs 逆轉力',
    'headline' => '兩輪僅失一局，對上讓一追二的臨場調整',
    'summary' => '十六夜蝶已有兩場實戰且僅失一局；羅馬則在上一輪首局落敗後，靠牌組調整連勝兩局完成逆轉。',
    'key' => '十六夜蝶的穩定推進，將面對羅馬在落後局面下展現的換牌能力與決勝局執行力。',
    'left_ban' => '',
    'right_ban' => '',
    'ban_status' => 'pending',
  ],
  [
    'code' => 'R3-3',
    'left_id' => 13,
    'left' => '我去賣身^q^',
    'right_id' => 8,
    'right' => '嗷嗷龜',
    'tag' => '臨場調整戰',
    'headline' => '兩位讓一追二選手，正面比拚系列賽判斷',
    'summary' => '兩人唯一實戰都以 2–1 晉級，也都曾在系列賽中調整牌組並扭轉局勢。雙方目前各有一副牌尚未正式出場。',
    'key' => '這場焦點不在單副牌的歷史高低，而在輸掉一局後，誰能更快修正選擇並完成第二勝。',
    'left_ban' => '',
    'right_ban' => '',
    'ban_status' => 'pending',
  ],
  [
    'code' => 'R3-4',
    'left_id' => 18,
    'left' => '海底窮人',
    'right_id' => 4,
    'right' => '打牌靠賽輕鬆',
    'tag' => '不敗選手交鋒',
    'headline' => '雙方皆以兩副不同牌組完成 2–0',
    'summary' => '兩人目前實戰都是兩勝零敗，且都使用兩副不同牌組各拿一勝。海底窮人 A 組、打牌靠賽輕鬆 B 組已確認遭到 BAN。',
    'key' => '雙方狀態亮眼，但目前都只有一場實戰；八強將首次檢驗兩人的不敗表現能否延續。',
    'left_ban' => 'A',
    'right_ban' => 'B',
    'ban_status' => 'confirmed',
  ],
];

$heroIntro = top8_section_text(
  $reportSections ?? [],
  'intro',
  '第一屆 UL.GG 杯正式進入八強。本篇將焦點放在本屆已發生的實戰表現、牌組使用方式與系列賽調整能力，並完整展示八位選手登錄的 A、B、C 三副牌組。'
);

$top8PlayerIds = [];

foreach ($matchups as $match) {
  $top8PlayerIds[] = (int)$match['left_id'];
  $top8PlayerIds[] = (int)$match['right_id'];
}

$top8PlayerIds = array_values(array_unique($top8PlayerIds));

$deckDataByPlayer = [];

$pdo = $db; // ⭐ 一定要這行
if ($top8PlayerIds) {
  $placeholders = implode(
    ',',
    array_fill(0, count($top8PlayerIds), '?')
  );

  $stmt = $pdo->prepare("
  SELECT
    d.player_id,
    d.deck_no,

    d.char1_id,
    u1.name  AS char1_name,
    u1.level AS char1_level,
    u1.ico   AS char1_ico,

    d.char2_id,
    u2.name  AS char2_name,
    u2.level AS char2_level,
    u2.ico   AS char2_ico,

    d.char3_id,
    u3.name  AS char3_name,
    u3.level AS char3_level,
    u3.ico   AS char3_ico

  FROM tournament_player_decks d

  LEFT JOIN unlight u1
    ON u1.id = d.char1_id

  LEFT JOIN unlight u2
    ON u2.id = d.char2_id

  LEFT JOIN unlight u3
    ON u3.id = d.char3_id

  WHERE d.tournament_id = ?
    AND d.player_id IN ($placeholders)

  ORDER BY
    d.player_id,
    d.deck_no
");

  $stmt->execute([
    $tournamentId,
    ...$top8PlayerIds
  ]);

  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $deckRow) {
    $playerId = (int)$deckRow['player_id'];
    $deckNo = (int)$deckRow['deck_no'];

    $deckCode = match ($deckNo) {
      1 => 'A',
      2 => 'B',
      3 => 'C',
      default => (string)$deckNo,
    };

    $deckDataByPlayer[$playerId][$deckCode] = [
      'code' => $deckCode,

      'cards' => [
        [
          'id' => (int)$deckRow['char1_id'],
          'name' => (string)($deckRow['char1_name'] ?? ''),
          'level' => (string)($deckRow['char1_level'] ?? ''),
          'ico' => (string)($deckRow['char1_ico'] ?? ''),
        ],
        [
          'id' => (int)$deckRow['char2_id'],
          'name' => (string)($deckRow['char2_name'] ?? ''),
          'level' => (string)($deckRow['char2_level'] ?? ''),
          'ico' => (string)($deckRow['char2_ico'] ?? ''),
        ],
        [
          'id' => (int)$deckRow['char3_id'],
          'name' => (string)($deckRow['char3_name'] ?? ''),
          'level' => (string)($deckRow['char3_level'] ?? ''),
          'ico' => (string)($deckRow['char3_ico'] ?? ''),
        ],
      ],
    ];
  }
}
?>

<style>
  .top8-report {
    --top8-bg: #0f1522;
    --top8-panel: rgba(24, 33, 50, 0.92);
    --top8-panel-soft: rgba(255, 255, 255, 0.045);
    --top8-line: rgba(255, 255, 255, 0.10);
    --top8-text: #f5f7fb;
    --top8-muted: #aeb8cb;
    --top8-gold: #f4c95d;
    --top8-orange: #ff9d57;
    --top8-blue: #71b7ff;
    --top8-purple: #b99cff;
    --top8-green: #74d6a0;
    color: var(--top8-text);
    max-width: 1180px;
    margin: 0 auto;
    padding: 18px 14px 56px;
  }

  .top8-report * {
    box-sizing: border-box;
  }

  .top8-hero {
    position: relative;
    overflow: hidden;
    padding: 34px;
    border: 1px solid var(--top8-line);
    border-radius: 24px;
    background:
      radial-gradient(circle at 86% 15%, rgba(244, 201, 93, 0.23), transparent 28%),
      radial-gradient(circle at 10% 90%, rgba(113, 183, 255, 0.16), transparent 34%),
      linear-gradient(145deg, #1a2335, #101621 68%);
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.28);
  }

  .top8-hero::after {
    content: 'TOP 8';
    position: absolute;
    right: 24px;
    bottom: 180px;
    font-size: clamp(62px, 11vw, 132px);
    font-weight: 900;
    letter-spacing: -0.08em;
    color: rgba(255, 255, 255, 0.035);
    pointer-events: none;
  }

  .top8-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding: 7px 12px;
    border: 1px solid rgba(244, 201, 93, 0.28);
    border-radius: 999px;
    background: rgba(244, 201, 93, 0.09);
    color: #ffe39c;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.08em;
  }

  .top8-title {
    margin: 0;
    font-size: clamp(30px, 5vw, 52px);
    line-height: 1.12;
    letter-spacing: -0.035em;
  }

  .top8-subtitle {
    position: relative;
    z-index: 1;
    margin: 16px 0 0;
    color: var(--top8-muted);
    line-height: 1.85;
    font-size: 16px;
  }

  .top8-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-top: 24px;
  }

  .top8-summary-card {
    padding: 17px;
    border: 1px solid var(--top8-line);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.055);
    backdrop-filter: blur(8px);
  }

  .top8-summary-card span {
    display: block;
    color: var(--top8-muted);
    font-size: 12px;
  }

  .top8-summary-card strong {
    display: block;
    margin-top: 6px;
    font-size: 21px;
  }

  .top8-summary-card small {
    display: block;
    margin-top: 5px;
    color: #dce4f2;
    font-size: 12px;
    line-height: 1.5;
  }

  .top8-section {
    margin-top: 30px;
    padding: 26px;
    border: 1px solid var(--top8-line);
    border-radius: 22px;
    background: linear-gradient(180deg, rgba(25, 34, 51, 0.97), rgba(16, 23, 35, 0.97));
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.18);
  }

  .top8-section-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 16px;
    margin-bottom: 20px;
  }

  .top8-section-title {
    margin: 0;
    font-size: 24px;
    letter-spacing: -0.02em;
  }

  .top8-section-desc {
    margin: 6px 0 0;
    color: var(--top8-muted);
    font-size: 14px;
    line-height: 1.7;
  }

  .top8-note-chip {
    flex: 0 0 auto;
    padding: 7px 10px;
    border-radius: 999px;
    background: rgba(113, 183, 255, 0.10);
    color: #b9dcff;
    font-size: 12px;
    font-weight: 700;
  }

  .top8-match-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }

  .top8-match-card {
    padding: 20px;
    border: 1px solid var(--top8-line);
    border-radius: 18px;
    background: var(--top8-panel-soft);
  }

  .top8-match-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
  }

  .top8-match-code {
    font-weight: 900;
    color: #fff;
  }

  .top8-match-tag {
    padding: 5px 9px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.07);
    color: var(--top8-muted);
    font-size: 12px;
  }

  .top8-versus {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 10px;
  }

  .top8-side {
    min-width: 0;
  }

  .top8-side:last-child {
    text-align: right;
  }

  .top8-side-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 16px;
    font-weight: 800;
  }

  .top8-side-rate {
    margin-top: 4px;
    color: #fff;
    font-size: 25px;
    font-weight: 900;
  }

  .top8-vs-label {
    color: rgba(255, 255, 255, 0.36);
    font-size: 12px;
    font-weight: 900;
  }

  .top8-progress {
    display: flex;
    overflow: hidden;
    height: 13px;
    margin-top: 14px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
  }

  .top8-progress-left {
    background: linear-gradient(90deg, #5fa8ff, #82c8ff);
  }

  .top8-progress-right {
    background: linear-gradient(90deg, #ffb05f, #ff7b61);
  }

  .top8-match-copy {
    margin: 15px 0 0;
    color: var(--top8-muted);
    font-size: 14px;
    line-height: 1.75;
  }

  .top8-match-key {
    margin: 12px 0px;
    padding: 11px 12px;
    border-left: 3px solid rgba(244, 201, 93, 0.75);
    background: rgba(244, 201, 93, 0.07);
    color: #f4e7bd;
    font-size: 13px;
    line-height: 1.65;
  }

  .top8-champion-layout {
    display: grid;
    grid-template-columns: minmax(250px, 0.8fr) minmax(0, 1.7fr);
    gap: 24px;
    align-items: center;
  }

  .top8-donut-wrap {
    text-align: center;
  }

  .top8-donut {
    --value: 21.7;
    width: 210px;
    height: 210px;
    margin: 0 auto;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background:
      radial-gradient(circle at center, #151e2e 0 54%, transparent 55%),
      conic-gradient(var(--top8-gold) calc(var(--value) * 1%), rgba(255, 255, 255, 0.08) 0);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08), 0 18px 40px rgba(0, 0, 0, 0.25);
  }

  .top8-donut-inner strong {
    display: block;
    font-size: 38px;
    line-height: 1;
  }

  .top8-donut-inner span {
    display: block;
    margin-top: 7px;
    color: var(--top8-muted);
    font-size: 13px;
  }

  .top8-donut-caption {
    margin-top: 14px;
    font-weight: 800;
  }

  .top8-rank-list {
    display: grid;
    gap: 12px;
  }

  .top8-rank-row {
    display: grid;
    grid-template-columns: 32px minmax(110px, 1fr) minmax(140px, 2.3fr) 64px;
    align-items: center;
    gap: 12px;
  }

  .top8-rank-no {
    color: var(--top8-muted);
    font-weight: 900;
  }

  .top8-rank-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 800;
  }

  .top8-rank-bar {
    overflow: hidden;
    height: 11px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.07);
  }

  .top8-rank-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #6dafff, #b99cff);
  }

  .top8-rank-row:first-child .top8-rank-fill {
    background: linear-gradient(90deg, #f0b94d, #ffe08a);
  }

  .top8-rank-rate {
    text-align: right;
    font-weight: 900;
  }

  .top8-highlight-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
  }

  .top8-highlight-card {
    min-height: 160px;
    padding: 18px;
    border: 1px solid var(--top8-line);
    border-radius: 17px;
    background: rgba(255, 255, 255, 0.04);
  }

  .top8-highlight-label {
    color: #b9dcff;
    font-size: 12px;
    font-weight: 800;
  }

  .top8-highlight-name {
    margin-top: 10px;
    font-size: 18px;
    font-weight: 900;
  }

  .top8-highlight-score-label {
    margin-top: 12px;
    color: var(--top8-muted);
    font-size: 11px;
  }

  .top8-highlight-score {
    margin-top: 3px;
    color: var(--top8-gold);
    font-size: 30px;
    font-weight: 900;
  }

  .top8-highlight-detail {
    margin-top: 9px;
    color: var(--top8-muted);
    font-size: 13px;
    line-height: 1.65;
  }

  .top8-player-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }

  .top8-player-card {
    padding: 20px;
    border: 1px solid var(--top8-line);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.038);
  }

  .top8-player-card[data-tone="gold"] {
    border-color: rgba(244, 201, 93, 0.42);
    background: linear-gradient(145deg, rgba(244, 201, 93, 0.10), rgba(255, 255, 255, 0.035));
  }

  .top8-player-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
  }

  .top8-player-name {
    margin: 0;
    font-size: 21px;
  }

  .top8-player-tier {
    margin-top: 4px;
    color: var(--top8-muted);
    font-size: 12px;
  }

  .top8-player-champion {
    text-align: right;
  }

  .top8-player-champion strong {
    display: block;
    color: var(--top8-gold);
    font-size: 26px;
  }

  .top8-player-champion span {
    color: var(--top8-muted);
    font-size: 11px;
  }

  .top8-player-summary {
    margin: 16px 0 0;
    color: var(--top8-muted);
    line-height: 1.75;
    font-size: 14px;
  }

  .top8-player-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    margin-top: 16px;
  }

  .top8-player-stats--compact {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .top8-player-stat {
    padding: 11px 9px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.055);
    text-align: center;
  }

  .top8-player-stat span {
    display: block;
    color: var(--top8-muted);
    font-size: 11px;
  }

  .top8-player-stat strong {
    display: block;
    margin-top: 4px;
    font-size: 16px;
  }

  .top8-player-points {
    display: grid;
    gap: 8px;
    margin-top: 15px;
  }

  .top8-player-point {
    padding: 10px 11px;
    border-radius: 11px;
    background: rgba(255, 255, 255, 0.04);
    color: #dfe5f0;
    font-size: 13px;
    line-height: 1.6;
  }

  .top8-player-point strong {
    color: #fff;
  }

  .top8-semi-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }

  .top8-semi-card {
    padding: 20px;
    border: 1px solid var(--top8-line);
    border-radius: 18px;
    background: linear-gradient(145deg, rgba(113, 183, 255, 0.08), rgba(185, 156, 255, 0.06));
  }

  .top8-semi-card h3 {
    margin: 0;
    font-size: 14px;
    color: #b9dcff;
  }

  .top8-semi-match {
    margin-top: 10px;
    font-size: 21px;
    font-weight: 900;
  }

  .top8-semi-rate {
    margin-top: 6px;
    color: var(--top8-gold);
    font-size: 30px;
    font-weight: 900;
  }

  .top8-semi-desc {
    margin: 10px 0 0;
    color: var(--top8-muted);
    font-size: 13px;
    line-height: 1.7;
  }

  .top8-method {
    color: var(--top8-muted);
    font-size: 13px;
    line-height: 1.85;
  }

  .top8-method strong {
    color: #fff;
  }

  .top8-warning {
    margin-top: 16px;
    padding: 14px 16px;
    border: 1px solid rgba(255, 176, 95, 0.22);
    border-radius: 14px;
    background: rgba(255, 176, 95, 0.07);
    color: #f7d6b4;
    font-size: 13px;
    line-height: 1.75;
  }

  @media (max-width: 900px) {

    .top8-summary-grid,
    .top8-highlight-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .top8-champion-layout {
      grid-template-columns: 1fr;
    }
  }



  .top8-deck-preview {
    margin-top: 14px;
    padding: 15px;
    border: 1px solid var(--top8-line);
    border-radius: 15px;
    background:
      linear-gradient(145deg,
        rgba(113, 183, 255, 0.055),
        rgba(185, 156, 255, 0.035));
  }

  .top8-deck-preview-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
    font-size: 13px;
  }

  .top8-ban-status {
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
  }

  .top8-ban-status.is-predicted {
    color: #ffd494;
    background: rgba(255, 176, 95, 0.12);
    border: 1px solid rgba(255, 176, 95, 0.22);
  }

  .top8-ban-status.is-confirmed {
    color: #ffb0a6;
    background: rgba(255, 105, 92, 0.12);
    border: 1px solid rgba(255, 105, 92, 0.25);
  }

  .top8-deck-columns {
    display: grid;
    grid-template-columns:
      minmax(0, 1fr) 28px minmax(0, 1fr);
    gap: 10px;
    align-items: stretch;
  }

  .top8-deck-side {
    min-width: 0;
  }

  .top8-deck-player {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 9px;
  }

  .top8-deck-player>span {
    padding: 3px 6px;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.07);
    color: var(--top8-muted);
    font-size: 10px;
    font-weight: 900;
  }

  .top8-deck-player strong {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 13px;
  }

  .top8-deck-row {
    position: relative;
    margin-top: 8px;
    padding: 9px;
    overflow: hidden;
    border: 1px solid rgba(116, 214, 160, 0.17);
    border-radius: 11px;
    background: rgba(116, 214, 160, 0.045);
  }

  .top8-deck-row-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
  }

  .top8-deck-title {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    font-size: 11px;
  }

  .top8-deck-model-score {
    color: #e9eef8;
    font-size: 11px;
    font-weight: 900;
  }

  .top8-deck-ban-label {
    padding: 3px 6px;
    border-radius: 999px;
    color: #ffb3a8;
    background: rgba(255, 105, 92, 0.14);
    font-size: 9px;
    font-weight: 900;
  }

  .top8-deck-cards {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 5px;
  }

  .top8-mini-card {
    position: relative;
    overflow: hidden;
    min-width: 0;
    aspect-ratio: 3 / 4;
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: 7px;
    background: rgba(255, 255, 255, 0.06);
  }

  .top8-mini-card img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .top8-mini-card {
    position: relative;
    overflow: hidden;
    min-width: 0;
    aspect-ratio: 3 / 4;
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: 7px;
    background: rgba(255, 255, 255, 0.06);
  }

  .top8-mini-card img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .top8-mini-card-caption {
    position: absolute;
    right: 0;
    bottom: 0;
    left: 0;
    padding: 14px 4px 4px;
    background:
      linear-gradient(transparent,
        rgba(0, 0, 0, 0.88));
    color: #fff;
    text-align: center;
    line-height: 1.2;
  }

  .top8-mini-card-caption span {
    display: block;
    font-size: 7px;
    color: rgba(255, 255, 255, 0.76);
  }

  .top8-mini-card-caption strong {
    display: block;
    overflow: hidden;
    margin-top: 2px;
    font-size: 8px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .top8-mini-card.is-image-error {
    display: grid;
    place-items: center;
  }

  .top8-mini-card.is-image-error img {
    display: none;
  }

  .top8-mini-card.is-image-error::before {
    content: '圖片未設定';
    padding: 5px;
    color: var(--top8-muted);
    text-align: center;
    font-size: 9px;
    line-height: 1.4;
  }

  .top8-deck-row.is-banned {
    opacity: 0.54;
    border-color: rgba(255, 105, 92, 0.26);
    background:
      repeating-linear-gradient(-45deg,
        rgba(255, 105, 92, 0.06),
        rgba(255, 105, 92, 0.06) 8px,
        rgba(255, 255, 255, 0.014) 8px,
        rgba(255, 255, 255, 0.014) 16px);
  }

  .top8-deck-row.is-banned::after {
    content: 'BAN';
    position: absolute;
    top: 50%;
    left: 50%;
    z-index: 3;
    transform:
      translate(-50%, -50%) rotate(-12deg);
    padding: 6px 12px;
    border: 2px solid rgba(255, 150, 140, 0.82);
    border-radius: 7px;
    color: #ffaca3;
    background: rgba(27, 20, 23, 0.78);
    font-size: 18px;
    font-weight: 1000;
    letter-spacing: 0.12em;
    pointer-events: none;
  }

  .top8-deck-row.is-banned .top8-mini-card img {
    filter: grayscale(0.9) brightness(0.62);
  }

  .top8-deck-divider {
    position: relative;
    display: grid;
    place-items: center;
    color: rgba(255, 255, 255, 0.3);
    font-size: 9px;
    font-weight: 900;
  }

  .top8-deck-divider::before {
    content: '';
    position: absolute;
    inset-block: 0;
    left: 50%;
    width: 1px;
    background: rgba(255, 255, 255, 0.08);
  }

  .top8-deck-divider span {
    position: relative;
    z-index: 1;
    padding: 5px 3px;
    background: #182133;
  }

  .top8-deck-empty {
    grid-column: 1 / -1;
    padding: 14px 8px;
    color: var(--top8-muted);
    text-align: center;
    font-size: 11px;
  }

  .top8-deck-preview-note {
    margin-top: 11px;
    color: var(--top8-muted);
    font-size: 10px;
    line-height: 1.55;
  }

  @media (max-width: 680px) {

    .top8-report {
      padding-inline: 8px;
    }

    .top8-hero,
    .top8-section {
      padding: 13px;
      border-radius: 18px;
    }

    .top8-summary-grid,
    .top8-match-grid,
    .top8-highlight-grid,
    .top8-player-grid,
    .top8-semi-grid {
      grid-template-columns: 1fr;
    }

    .top8-section-head {
      align-items: flex-start;
      flex-direction: column;
    }

    .top8-rank-row {
      grid-template-columns: 28px minmax(86px, 1fr) minmax(90px, 1.6fr) 58px;
      gap: 8px;
      font-size: 13px;
    }

    .top8-player-stats,
    .top8-player-stats--compact {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .top8-player-summary {
      margin-top: 12px;
      font-size: 13px;
      line-height: 1.6;
    }

    .top8-player-point {
      padding: 8px 10px;
    }

    .top8-deck-preview {
      padding: 10px;
    }

    .top8-deck-columns {
      grid-template-columns:
        minmax(0, 1fr) 12px minmax(0, 1fr);
      gap: 4px;
    }

    .top8-deck-player {
      align-items: flex-start;
      flex-direction: column;
      gap: 4px;
    }

    .top8-deck-player strong {
      width: 100%;
      font-size: 11px;
    }

    .top8-deck-row {
      padding: 5px;
    }

    .top8-deck-row-head {
      margin-bottom: 5px;
    }

    .top8-deck-title {
      align-items: flex-start;
      flex-direction: column;
      gap: 3px;
    }

    .top8-deck-cards {
      gap: 2px;
    }

    .top8-mini-card {
      border-radius: 4px;
    }

    .top8-mini-card-caption {
      padding: 11px 2px 3px;
    }

    .top8-mini-card-caption span {
      font-size: 6px;
    }

    .top8-mini-card-caption strong {
      font-size: 7px;
    }

    .top8-deck-model-score {
      font-size: 9px;
    }

    .top8-deck-row.is-banned::after {
      padding: 4px 7px;
      font-size: 11px;
    }
  }


  .top8-overview-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--top8-line);
    border-radius: 16px;
  }

  .top8-overview-table {
    width: 100%;
    min-width: 860px;
    border-collapse: collapse;
  }

  .top8-overview-table th,
  .top8-overview-table td {
    padding: 13px 14px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.07);
    text-align: left;
    white-space: nowrap;
    font-size: 13px;
  }

  .top8-overview-table th {
    color: #b9dcff;
    background: rgba(113, 183, 255, 0.07);
    font-size: 11px;
    letter-spacing: 0.04em;
  }

  .top8-overview-table tbody tr:last-child td {
    border-bottom: 0;
  }

  .top8-overview-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.035);
  }

  .top8-style-chip {
    display: inline-flex;
    padding: 5px 9px;
    border-radius: 999px;
    color: #e7effc;
    background: rgba(185, 156, 255, 0.11);
    font-size: 11px;
    font-weight: 800;
  }

  .top8-versus--names-only {
    padding: 12px 0 4px;
  }

  .top8-versus--names-only .top8-side-name {
    font-size: 19px;
  }

  .top8-match-headline {
    margin: 16px 0 0;
    color: #fff;
    font-size: 17px;
    line-height: 1.45;
  }

  .top8-deck-used-label,
  .top8-deck-unused-label {
    padding: 3px 6px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 900;
  }

  .top8-deck-used-label {
    color: #9be7bb;
    background: rgba(116, 214, 160, 0.12);
  }

  .top8-deck-unused-label {
    color: #c9d2e3;
    background: rgba(255, 255, 255, 0.07);
  }

  .top8-deck-usage-score {
    color: #e9eef8;
    font-size: 10px;
    font-weight: 900;
  }

  .top8-player-record strong {
    color: var(--top8-gold);
    font-size: 20px;
  }

  .top8-player-card[data-tone="orange"] {
    border-color: rgba(255, 157, 87, 0.25);
  }

  .top8-player-card[data-tone="purple"] {
    border-color: rgba(185, 156, 255, 0.25);
  }

  .top8-player-card[data-tone="blue"] {
    border-color: rgba(113, 183, 255, 0.25);
  }

  .top8-player-card[data-tone="green"] {
    border-color: rgba(116, 214, 160, 0.25);
  }
</style>

<article class="top8-report">
  <header class="top8-hero">
    <div class="top8-kicker">🏆 ULGG CUP · TOP 8 REPORT</div>
    <h1 class="top8-title"><?= top8_e($pageTitleText ?? '第一屆 UL.GG 杯八強戰前狀態觀察') ?></h1>
    <div class="top8-subtitle"><?= $heroIntro ?></div>

    <div class="top8-summary-grid">
      <div class="top8-summary-card">
        <span>目前零失局</span>
        <strong>ASAPbaby</strong>
        <small>兩場實戰，四個小局全勝</small>
      </div>
      <div class="top8-summary-card">
        <span>三副皆已出場過</span>
        <strong>2 位選手</strong>
        <small>ASAPbaby、花間與風</small>
      </div>
      <div class="top8-summary-card">
        <span>讓一追二晉級</span>
        <strong>3 位選手</strong>
        <small>羅馬、嗷嗷龜、我去賣身^q^</small>
      </div>
      <div class="top8-summary-card">
        <span>小樣本 2–0</span>
        <strong>2 位選手</strong>
        <small>海底窮人、打牌靠賽輕鬆</small>
      </div>
    </div>

    <!-- <div class="top8-warning">
      本篇只整理已發生的公開賽事資訊，不提供建議 BAN、牌組對位矩陣或精確奪冠率。
      棄賽與判定晉級不列入小局勝率、2–0 次數及牌組實戰統計。
    </div> -->
  </header>

  <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">📊 八強實戰全景</h2>
        <p class="top8-section-desc">以本屆已完成且具逐局紀錄的實際對戰為準。</p>
      </div>
      <div class="top8-note-chip">不含棄賽與判定局</div>
    </div>

    <div class="top8-overview-table-wrap">
      <table class="top8-overview-table">
        <thead>
          <tr>
            <th>Seed</th>
            <th>選手</th>
            <th>實戰場數</th>
            <th>小局戰績</th>
            <th>小局勝率</th>
            <th>2–0</th>
            <th>已出場牌組</th>
            <th>目前類型</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($playersBySeed as $player): ?>
            <tr>
              <td>#<?= (int)$player['seed'] ?></td>
              <td><strong><?= top8_e($player['name']) ?></strong></td>
              <td><?= (int)$player['matches'] ?></td>
              <td><?= top8_e($player['games']) ?></td>
              <td><?= top8_percent((float)$player['game_rate'], 1) ?></td>
              <td><?= (int)$player['sweeps'] ?> 次</td>
              <td><?= (int)$player['used_decks'] ?> / 3</td>
              <td><span class="top8-style-chip"><?= top8_e($player['icon'] . ' ' . $player['style']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">⚔️ 四場八強對戰焦點</h2>
        <!-- <p class="top8-section-desc">從實戰風格與系列賽故事切入，不提供勝率或戰術建議。</p> -->
      </div>
      <div class="top8-note-chip">四場單淘汰</div>
    </div>

    <div class="top8-match-grid">
      <?php foreach ($matchups as $match): ?>
        <article class="top8-match-card">
          <div class="top8-match-meta">
            <div class="top8-match-code"><?= top8_e($match['code']) ?></div>
            <div class="top8-match-tag"><?= top8_e($match['tag']) ?></div>
          </div>

          <div class="top8-versus top8-versus--names-only">
            <div class="top8-side">
              <div class="top8-side-name"><?= top8_e($match['left']) ?></div>
            </div>
            <div class="top8-vs-label">VS</div>
            <div class="top8-side">
              <div class="top8-side-name"><?= top8_e($match['right']) ?></div>
            </div>
          </div>

          <h3 class="top8-match-headline"><?= top8_e($match['headline']) ?></h3>
          <p class="top8-match-copy"><?= top8_e($match['summary']) ?></p>
          <div class="top8-match-key"><strong>觀察重點：</strong><?= top8_e($match['key']) ?></div>

          <div><!-- class="top8-deck-preview" -->
            <div class="top8-deck-preview-head">
              <strong>🃏 雙方登錄牌組</strong>
              <?php if (($match['ban_status'] ?? '') === 'confirmed'): ?>
                <span class="top8-ban-status is-confirmed">實際 BAN 已標示</span>
              <?php else: ?>
                <span class="top8-ban-status is-predicted">BAN 尚未確認</span>
              <?php endif; ?>
            </div>

            <div class="top8-deck-columns">
              <div class="top8-deck-side">
                <div class="top8-deck-player"><span>P1</span><strong><?= top8_e($match['left']) ?></strong></div>
                <?php top8_render_decks((int)$match['left_id'], $deckDataByPlayer, $deckUsageByPlayer, (string)$match['left_ban'], (string)$match['ban_status']); ?>
              </div>

              <div class="top8-deck-divider"><span>VS</span></div>

              <div class="top8-deck-side">
                <div class="top8-deck-player"><span>P2</span><strong><?= top8_e($match['right']) ?></strong></div>
                <?php top8_render_decks((int)$match['right_id'], $deckDataByPlayer, $deckUsageByPlayer, (string)$match['right_ban'], (string)$match['ban_status']); ?>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">🔍 八強選手狀態與風格</h2>
        <!-- <p class="top8-section-desc">依種子序排列；百分比僅表示本屆目前小局結果。</p> -->
      </div>
    </div>

    <div class="top8-player-grid">
      <?php foreach ($playersBySeed as $player): ?>
        <article class="top8-player-card" data-tone="<?= top8_e($player['tone']) ?>">
          <div class="top8-player-head">
            <div>
              <h3 class="top8-player-name">Seed <?= (int)$player['seed'] ?>｜<?= top8_e($player['name']) ?></h3>
              <div class="top8-player-tier"><?= top8_e($player['icon'] . ' ' . $player['style']) ?></div>
            </div>
            <div class="top8-player-champion top8-player-record">
              <strong><?= top8_e($player['games']) ?></strong>
              <span>本屆小局戰績</span>
            </div>
          </div>

          <p class="top8-player-summary"><?= top8_e($player['summary']) ?></p>

          <div class="top8-player-stats">
            <div class="top8-player-stat"><span>實戰場數</span><strong><?= (int)$player['matches'] ?></strong></div>
            <div class="top8-player-stat"><span>小局勝率</span><strong><?= top8_percent((float)$player['game_rate'], 1) ?></strong></div>
            <div class="top8-player-stat"><span>牌組出場</span><strong><?= (int)$player['used_decks'] ?> / 3</strong></div>
          </div>

          <div class="top8-player-points">
            <div class="top8-player-point"><strong>目前亮點：</strong><?= top8_e($player['strength']) ?></div>
            <div class="top8-player-point"><strong>觀察重點：</strong><?= top8_e($player['focus']) ?></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">📝 資料說明</h2>
      </div>
    </div>
    <div class="top8-method">
      <strong>統計範圍：</strong>僅計入已完成、具逐局紀錄的實際對戰。嗷嗷龜首輪因對手棄賽晉級，不計入實戰場數與 2–0 紀錄。<br>
      <strong>牌組戰績：</strong>僅呈現本屆目前出場與勝負情形；一至三局的小樣本不能代表牌組的絕對強弱。<br>
      <strong>資料異常：</strong>嗷嗷龜 A 組曾出現實戰角色 ID 與提交資料不一致，相關細部判讀需保守看待。<br>
      <strong>報導原則：</strong>保留公開的 A、B、C 牌組內容與已確認 BAN，但不公開建議 BAN、歷史對位矩陣及精確奪冠率。
    </div>
  </section> -->
</article>