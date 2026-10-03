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
    array $deckScores,
    string $bannedDeck,
    string $banStatus
  ): void {
    $playerDecks = $deckDataByPlayer[$playerId] ?? [];

    foreach (['A', 'B', 'C'] as $deckCode) {
      $deck = $playerDecks[$deckCode] ?? null;
      $score = (float)($deckScores[$playerId][$deckCode] ?? 0);
      $isBanned = $deckCode === $bannedDeck;

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
              <span class="top8-deck-ban-label">
                <?= $banStatus === 'confirmed' ? '已 BAN' : '預計 BAN' ?>
              </span>
            <?php endif; ?>
          </div>

          <span class="top8-deck-model-score">
            <?= number_format($score, 1) ?>
          </span>
        </div>

        <div class="top8-deck-cards">
          <?php if ($deck && !empty($deck['cards'])): ?>
            <?php foreach ($deck['cards'] as $card): ?>
              <?php
              $cardId = (int)($card['id'] ?? 0);
              $cardName = trim(
                (string)($card['level'] ?? '')
                  . ' '
                  . (string)($card['name'] ?? '')
              );
              $cardIco = trim((string)($card['ico'] ?? ''));

              $imageUrl = $cardIco !== ''
                ? IMG_BASE . $cardIco
                : '';
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
            <div class="top8-deck-empty">
              無牌組資料
            </div>
          <?php endif; ?>
        </div>
      </div>
<?php
    }
  }
}

// 通關制模型重算結果：
// 八強晉級率 → 四強條件勝率 → 決賽率 → 決賽條件勝率 → 奪冠率
// 八人奪冠率加總約 100.00%
$champions = [
  [
    'rank' => 1,
    'id' => 23,
    'name' => '十六夜蝶',
    'qf' => 58.55,
    'sf_conditional' => 62.55,
    'final' => 36.62,
    'final_conditional' => 59.31,
    'champion' => 21.72,
    'tier' => '👑 奪冠熱門',
    'tone' => 'gold',
    'summary' => '預計 BAN A 後，B、C 兩副仍同時維持約 60 分，最符合雙副皆須通關的賽制需求。',
    'strength' => 'BAN 後兩副高度最高，較不容易被單一弱副卡住。',
    'risk' => '上半區仍可能遇到本屆實戰狀態最強的選手。',
  ],
  [
    'rank' => 2,
    'id' => 4,
    'name' => '打牌靠賽輕鬆',
    'qf' => 58.77,
    'sf_conditional' => 52.80,
    'final' => 31.03,
    'final_conditional' => 48.85,
    'champion' => 15.15,
    'tier' => '🔥 強力競爭者',
    'tone' => 'orange',
    'summary' => '八強實際 BAN 讓最強 C 組保留，可望先取得一勝；但 A 組能否完成第二副通關才是系列賽核心。',
    'strength' => 'C 組單局上限最高，容易先建立一副牌的勝場。',
    'risk' => 'A 組對海底兩副的第二勝能力，明顯低於 C 組。',
  ],
  [
    'rank' => 3,
    'id' => 13,
    'name' => '我去賣身^q^',
    'qf' => 59.77,
    'sf_conditional' => 49.85,
    'final' => 29.79,
    'final_conditional' => 49.14,
    'champion' => 14.64,
    'tier' => '🔥 強力競爭者',
    'tone' => 'orange',
    'summary' => '三副牌差距小，BAN 後不容易留下單一明顯弱副，通關穩定度較高。',
    'strength' => '剩餘兩副較容易各自找到可完成一勝的對位。',
    'risk' => '整體牌組高度接近，但缺少能穩定拉開首勝的絕對王牌。',
  ],
  [
    'rank' => 4,
    'id' => 18,
    'name' => '海底窮人',
    'qf' => 41.23,
    'sf_conditional' => 55.60,
    'final' => 22.93,
    'final_conditional' => 50.82,
    'champion' => 11.65,
    'tier' => '⚔️ 有力挑戰者',
    'tone' => 'blue',
    'summary' => '八強雖需面對打牌靠賽的王牌 C，但 B、C 兩副皆具有完成通關的機會。',
    'strength' => '牌組均衡，沒有只能依賴單一牌組取勝的問題。',
    'risk' => 'B 組對打牌靠賽 C 的單局勝率偏低，可能成為卡關點。',
  ],
  [
    'rank' => 5,
    'id' => 7,
    'name' => '羅馬',
    'qf' => 41.45,
    'sf_conditional' => 53.94,
    'final' => 22.36,
    'final_conditional' => 50.40,
    'champion' => 11.27,
    'tier' => '⚔️ 有力挑戰者',
    'tone' => 'blue',
    'summary' => '八強面對十六夜蝶仍處劣勢，但若突破首關，後續雙副通關對位並不差。',
    'strength' => '三副牌分布平均，較少出現一副完全無法通關的情況。',
    'risk' => '首戰對手 BAN 後兩副的整體高度仍高於自己。',
  ],
  [
    'rank' => 6,
    'id' => 1,
    'name' => 'ASAPbaby',
    'qf' => 52.97,
    'sf_conditional' => 42.80,
    'final' => 22.67,
    'final_conditional' => 48.01,
    'champion' => 10.88,
    'tier' => '⚔️ 有力挑戰者',
    'tone' => 'blue',
    'summary' => '本屆兩輪皆以 2–0 晉級，三副牌皆曾完成勝場，是目前最有說服力的實戰通關者。',
    'strength' => '正式賽已證明不同牌組皆能取得必要勝場。',
    'risk' => '上半區若遇十六夜蝶，B、C 兩副的第二勝壓力較高。',
  ],
  [
    'rank' => 7,
    'id' => 5,
    'name' => '花間與風',
    'qf' => 47.03,
    'sf_conditional' => 39.02,
    'final' => 18.35,
    'final_conditional' => 44.63,
    'champion' => 8.19,
    'tier' => '🎲 黑馬',
    'tone' => 'purple',
    'summary' => 'PICK 使用最分散，但 A 組遭 BAN 後，B、C 兩副需要同時完成勝場，容錯空間下降。',
    'strength' => '三副牌皆有正式賽使用經驗，出牌變化較多。',
    'risk' => 'BAN 後較弱牌組的通關能力，是本場最大考驗。',
  ],
  [
    'rank' => 8,
    'id' => 8,
    'name' => '嗷嗷龜',
    'qf' => 40.23,
    'sf_conditional' => 40.40,
    'final' => 16.25,
    'final_conditional' => 39.93,
    'champion' => 6.49,
    'tier' => '🎲 黑馬',
    'tone' => 'purple',
    'summary' => '三副牌最接近，但整體歷史分偏低，且牌組對位樣本仍少。',
    'strength' => '沒有單一牌組特別容易成為唯一破口。',
    'risk' => '兩副牌都必須各贏一次，整體高度不足會被放大。',
  ],
];


$deckHighlights = [
  [
    'name' => '十六夜蝶',
    'score' => 60.5,
    'label' => 'BAN 後雙副高度最高',
    'score_label' => '第二通關牌組',
    'detail' => '預計 BAN A 後：B 60.6／C 60.5',
  ],
  [
    'name' => 'ASAPbaby',
    'score' => 52.9,
    'label' => '實戰通關最完整',
    'score_label' => '第二通關牌組',
    'detail' => '三副皆曾正式上場並取勝，本屆小局 4–0',
  ],
  [
    'name' => '海底窮人',
    'score' => 55.1,
    'label' => '雙副通關較均衡',
    'score_label' => '第二通關牌組',
    'detail' => '實際 BAN A 後：B 55.1／C 61.5',
  ],
  [
    'name' => '打牌靠賽輕鬆',
    'score' => 77.8,
    'label' => '最高單副上限',
    'score_label' => 'C 組歷史分',
    'detail' => 'C 有望先取一勝；A 能否完成第二勝才是關鍵',
  ],
];

$mostLikelySemifinals = [
  [
    'title' => '上半區最可能組合',
    'match' => 'ASAPbaby vs 十六夜蝶',
    'rate' => 31.02,
    'desc' => '本屆實戰狀態最強者，對上 BAN 後雙副高度最高者。',
  ],
  [
    'title' => '下半區最可能組合',
    'match' => '我去賣身^q^ vs 打牌靠賽輕鬆',
    'rate' => 35.13,
    'desc' => '平均型雙副通關結構，對上王牌先取一勝、另一副再尋求突破的配置。',
  ],
];

$heroIntro = top8_section_text(
  $reportSections ?? [],
  'intro',
  '第一屆 UL.GG 杯正式進入八強。本次預測改採通關制模型：BAN 一副後，剩餘兩副牌皆須各自取得一勝，才能贏下系列賽。'
);

$matchups = [
  [
    'code' => 'R3-1',
    'left_id' => 1,
    'left' => 'ASAPbaby',
    'left_rate' => 52.97,

    'right_id' => 5,
    'right' => '花間與風',
    'right_rate' => 47.03,

    'tag' => '最接近五五開',
    'summary' => '雙方互 BAN A 後，B、C 都必須各自取得一勝；ASAPbaby僅以極小幅度領先。',
    'key' => '不能只靠其中一副強牌連勝，B、C 任一副卡關都會輸掉系列賽。',

    'left_ban' => 'A',
    'right_ban' => 'A',
    'ban_status' => 'predicted',
  ],

  [
    'code' => 'R3-2',
    'left_id' => 23,
    'left' => '十六夜蝶',
    'left_rate' => 58.55,

    'right_id' => 7,
    'right' => '羅馬',
    'right_rate' => 41.45,

    'tag' => '牌組池高度戰',
    'summary' => '十六夜蝶 BAN 後保留的 B、C 兩副皆維持高分，雙副通關結構仍優於羅馬。',
    'key' => '十六夜蝶不是只靠 A 組；B、C 兩副都具備完成必要勝場的能力。',

    'left_ban' => 'A',
    'right_ban' => 'C',
    'ban_status' => 'predicted',
  ],

  [
    'code' => 'R3-3',
    'left_id' => 13,
    'left' => '我去賣身^q^',
    'left_rate' => 59.77,

    'right_id' => 8,
    'right' => '嗷嗷龜',
    'right_rate' => 40.23,

    'tag' => '雙副穩定度戰',
    'summary' => '我去賣身保留的 A、C 兩副，對嗷嗷龜 A、B 四種對位皆維持五成以上。',
    'key' => '四格勝率介於 54.7% 至 58.4%，沒有單一明顯卡關牌組，因此雙副通關結構較穩定。',

    'left_ban' => 'B',
    'right_ban' => 'C',
    'ban_status' => 'predicted',
  ],

  [
    'code' => 'R3-4',
    'left_id' => 18,
    'left' => '海底窮人',
    'left_rate' => 41.23,

    'right_id' => 4,
    'right' => '打牌靠賽輕鬆',
    'right_rate' => 58.77,

    'tag' => 'BAN 分歧焦點',
    'summary' => '打牌靠賽 C 可望先取一勝，但 A 仍必須再完成第二副通關。',
    'key' => '打牌靠賽雖有強勢 C 組，但仍需另一副牌完成第二勝；海底兩副較均衡，因此系列賽仍保有反擊空間。',
    'left_ban' => 'A',
    'right_ban' => 'B',
    'ban_status' => 'confirmed',
  ],
];
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
$deckScores = [
  1 => [
    'A' => 61.80,
    'B' => 54.04,
    'C' => 52.93,
  ],
  4 => [
    'A' => 54.79,
    'B' => 55.40,
    'C' => 77.81,
  ],
  5 => [
    'A' => 60.71,
    'B' => 53.06,
    'C' => 50.24,
  ],
  7 => [
    'A' => 54.09,
    'B' => 55.88,
    'C' => 58.29,
  ],
  8 => [
    'A' => 51.05,
    'B' => 48.94,
    'C' => 51.34,
  ],
  13 => [
    'A' => 54.87,
    'B' => 59.21,
    'C' => 55.97,
  ],
  18 => [
    'A' => 57.96,
    'B' => 55.13,
    'C' => 61.45,
  ],
  23 => [
    'A' => 71.94,
    'B' => 60.55,
    'C' => 60.52,
  ],
];
$quarterfinalPairs = [
  [
    'possible_round' => 'R3-1',
    'player1_id' => 1,
    'player2_id' => 5,
  ],
  [
    'possible_round' => 'R3-2',
    'player1_id' => 23,
    'player2_id' => 7,
  ],
  [
    'possible_round' => 'R3-3',
    'player1_id' => 13,
    'player2_id' => 8,
  ],
  [
    'possible_round' => 'R3-4',
    'player1_id' => 18,
    'player2_id' => 4,
  ],
];
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
    margin-top: 12px;
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
      padding: 20px;
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
</style>

<article class="top8-report">
  <header class="top8-hero">
    <div class="top8-kicker">🏆 ULGG CUP · TOP 8 REPORT</div>
    <h1 class="top8-title"><?= top8_e($pageTitleText ?? '第一屆 UL.GG 杯八強戰力分析與奪冠預測') ?></h1>
    <div class="top8-subtitle"><?= $heroIntro ?></div>
    <div class="top8-warning">
      本文為依公開牌組、歷史對戰資料與賽制建立的模型分析，僅供觀賽參考。
      預測結果不代表選手實際實力或最終賽果，臨場選牌、BAN 策略與操作仍可能大幅影響比賽結果。
    </div>
    <br>
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">📌 模型說明</h2>
      </div>
    </div>

    <div class="top8-method">
      <strong>本次系列賽模型：</strong>
      每場先排除雙方遭 BAN 的牌組，再以剩餘兩副牌建立 2×2 對位矩陣。
      本屆採通關制，兩副牌都必須各自取得一勝，依每副牌是否已完成勝場逐局計算系列賽晉級率。
    </div>

    <!-- <div class="top8-warning">
      單副高分只能幫助取得其中一勝，不能取代另一副牌完成通關。
      本模型因此更重視第二通關牌組是否能找到可贏對位。
    </div> -->

    <!-- <div class="top8-summary-grid">
      <div class="top8-summary-card">
        <span>模型預測奪冠熱門</span>
        <strong>十六夜蝶</strong>
        <small>奪冠率 21.72%</small>
      </div>
      <div class="top8-summary-card">
        <span>最高八強晉級率</span>
        <strong>打牌靠賽輕鬆</strong>
        <small>58.77%</small>
      </div>
      <div class="top8-summary-card">
        <span>最接近五五開</span>
        <strong>R3-1</strong>
        <span>ASAPbaby VS 花間與風</span>
        <small>53.0%：47.0%</small>
      </div>
      <div class="top8-summary-card">
        <span>最大 BAN 分歧焦點</span>
        <strong>R3-4</strong>
        <small>打牌靠賽模型最強 C 組成功保留</small>
      </div>
    </div> -->
  </header>

  <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">🃏 牌組評分重點</h2>
      </div>
    </div>

    <div class="top8-highlight-grid">
      <?php foreach ($deckHighlights as $highlight): ?>
        <article class="top8-highlight-card">
          <div class="top8-highlight-label"><?= top8_e($highlight['label']) ?></div>
          <div class="top8-highlight-name"><?= top8_e($highlight['name']) ?></div>
          <div class="top8-highlight-score-label"><?= top8_e($highlight['score_label']) ?></div>
          <div class="top8-highlight-score"><?= number_format($highlight['score'], 1) ?></div>
          <div class="top8-highlight-detail"><?= top8_e($highlight['detail']) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">⚔️ 八強對戰預測</h2>
        <p class="top8-section-desc">百分比為 BAN 後兩副牌皆須各自取得一勝的通關制晉級率。</p>
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

          <div class="top8-versus">
            <div class="top8-side">
              <div class="top8-side-name"><?= top8_e($match['left']) ?></div>
              <div class="top8-side-rate"><?= top8_percent($match['left_rate'], 1) ?></div>
            </div>

            <div class="top8-vs-label">VS</div>

            <div class="top8-side">
              <div class="top8-side-name"><?= top8_e($match['right']) ?></div>
              <div class="top8-side-rate"><?= top8_percent($match['right_rate'], 1) ?></div>
            </div>
          </div>

          <div class="top8-progress" aria-label="<?= top8_e($match['left']) ?> <?= top8_percent($match['left_rate']) ?>，<?= top8_e($match['right']) ?> <?= top8_percent($match['right_rate']) ?>">
            <div class="top8-progress-left" style="width: <?= $match['left_rate'] ?>%"></div>
            <div class="top8-progress-right" style="width: <?= $match['right_rate'] ?>%"></div>
          </div>

          <p class="top8-match-copy"><?= top8_e($match['summary']) ?></p>
          <div class="top8-match-key"><strong>對戰關鍵：</strong><?= top8_e($match['key']) ?></div>
          <br>
          <div><!-- class="top8-deck-preview" -->
            <div class="top8-deck-preview-head">
              <strong>🃏 三副牌組與 BAN 預覽</strong>

              <?php if (($match['ban_status'] ?? '') === 'confirmed'): ?>
                <span class="top8-ban-status is-confirmed">
                  實際 BAN
                </span>
              <?php else: ?>
                <span class="top8-ban-status is-predicted">
                  模型預計 BAN
                </span>
              <?php endif; ?>
            </div>

            <div class="top8-deck-columns">
              <div class="top8-deck-side">
                <div class="top8-deck-player">
                  <span>P1</span>
                  <strong><?= top8_e($match['left']) ?></strong>
                </div>

                <?php
                top8_render_decks(
                  (int)$match['left_id'],
                  $deckDataByPlayer,
                  $deckScores,
                  (string)$match['left_ban'],
                  (string)$match['ban_status']
                );
                ?>
              </div>

              <div class="top8-deck-divider">
                <span>VS</span>
              </div>

              <div class="top8-deck-side">
                <div class="top8-deck-player">
                  <span>P2</span>
                  <strong><?= top8_e($match['right']) ?></strong>
                </div>

                <?php
                top8_render_decks(
                  (int)$match['right_id'],
                  $deckDataByPlayer,
                  $deckScores,
                  (string)$match['right_ban'],
                  (string)$match['ban_status']
                );
                ?>
              </div>
            </div>

            <!-- <div class="top8-deck-preview-note">
              每副牌顯示三張角色卡與歷史模型分。灰色斜紋牌組為已 BAN 或模型預計遭 BAN，因此不會進入本場可選牌池。
            </div> -->
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">🏆 奪冠機率</h2>
      </div>
      <div class="top8-note-chip">籤表模型</div>
    </div>

    <div class="top8-champion-layout">
      <div class="top8-donut-wrap">
        <div class="top8-donut" style="--value: <?= $champions[0]['champion'] ?>">
          <div class="top8-donut-inner">
            <strong><?= top8_percent($champions[0]['champion'], 1) ?></strong>
            <span>模型預測最高</span>
          </div>
        </div>
        <div class="top8-donut-caption">模型領先者｜<?= top8_e($champions[0]['name']) ?></div>
      </div>

      <div class="top8-rank-list">
        <?php foreach ($champions as $player): ?>
          <div class="top8-rank-row">
            <div class="top8-rank-no">#<?= (int)$player['rank'] ?></div>
            <div class="top8-rank-name"><?= top8_e($player['name']) ?></div>
            <div class="top8-rank-bar">
              <div class="top8-rank-fill" style="width: <?= min(100, $player['champion'] / 25 * 100) ?>%"></div>
            </div>
            <div class="top8-rank-rate"><?= top8_percent($player['champion'], 1) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>



  <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">🔍 八強選手分析</h2>
      </div>
    </div>

    <div class="top8-player-grid">
      <?php foreach ($champions as $player): ?>
        <article class="top8-player-card" data-tone="<?= top8_e($player['tone']) ?>">
          <div class="top8-player-head">
            <div>
              <h3 class="top8-player-name">#<?= (int)$player['rank'] ?> <?= top8_e($player['name']) ?></h3>
              <div class="top8-player-tier"><?= top8_e($player['tier']) ?></div>
            </div>

            <div class="top8-player-champion">
              <strong><?= top8_percent($player['champion'], 1) ?></strong>
              <span>奪冠率</span>
            </div>
          </div>

          <p class="top8-player-summary"><?= top8_e($player['summary']) ?></p>

          <div class="top8-player-stats top8-player-stats--compact">
            <div class="top8-player-stat">
              <span>八強晉級</span>
              <strong><?= top8_percent($player['qf'], 1) ?></strong>
            </div>
            <div class="top8-player-stat">
              <span>進決賽</span>
              <strong><?= top8_percent($player['final'], 1) ?></strong>
            </div>
          </div>

          <div class="top8-player-points">
            <div class="top8-player-point"><strong>優勢：</strong><?= top8_e($player['strength']) ?></div>
            <div class="top8-player-point"><strong>觀察重點：</strong><?= top8_e($player['risk']) ?></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="top8-section">
    <div class="top8-section-head">
      <div>
        <h2 class="top8-section-title">🔮 最可能的四強組合</h2>
        <p class="top8-section-desc">由目前四場八強晉級率相乘得到。</p>
      </div>
    </div>

    <div class="top8-semi-grid">
      <?php foreach ($mostLikelySemifinals as $semi): ?>
        <article class="top8-semi-card">
          <h3><?= top8_e($semi['title']) ?></h3>
          <div class="top8-semi-match"><?= top8_e($semi['match']) ?></div>
          <div class="top8-semi-rate"><?= top8_percent($semi['rate'], 2) ?></div>
          <p class="top8-semi-desc"><?= top8_e($semi['desc']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </section>


</article>