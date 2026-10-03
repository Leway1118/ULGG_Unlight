<?php
if (!isset($pdo, $report, $reportSections)) {
  die('請由 report.php 載入本頁');
}

function report_section_title($sections, $key, $fallback)
{
  return $sections[$key]['section_title'] ?? $fallback;
}

function report_section_text($sections, $key, $fallback = '')
{
  return $sections[$key]['section_content'] ?? $fallback;
}

$summaryJson = json_decode($reportSections['summary']['block_json'] ?? '{}', true);
$deckMetaJson = json_decode($reportSections['deck_meta']['block_json'] ?? '{}', true);
$popularChars = json_decode($reportSections['popular_chars']['block_json'] ?? '[]', true);
$rareChars = json_decode($reportSections['rare_chars']['block_json'] ?? '[]', true);
$costPatterns = json_decode($reportSections['cost_patterns']['block_json'] ?? '[]', true);
$banPressure = json_decode($reportSections['ban_pressure']['block_json'] ?? '[]', true);
$playerStyles = json_decode($reportSections['player_styles']['block_json'] ?? '[]', true);
$focusMatches = json_decode($reportSections['focus_matches']['block_json'] ?? '[]', true);

if (!is_array($popularChars)) $popularChars = [];
if (!is_array($rareChars)) $rareChars = [];
if (!is_array($costPatterns)) $costPatterns = [];
if (!is_array($banPressure)) $banPressure = [];
if (!is_array($playerStyles)) $playerStyles = [];
if (!is_array($focusMatches)) $focusMatches = [];

$summary = [
  'players' => (int)($summaryJson['players'] ?? 0),
  'decks' => (int)($summaryJson['decks'] ?? 0),
  'characters' => (int)($summaryJson['characters'] ?? 0),
  'cost' => (int)($summaryJson['cost'] ?? 74)
];

$charIconMap = [];
$charNameMap = [];
$charLevelMap = [];

$charIconFallbackMap = [
  19 => 'official/19_R4.png',
  185 => 'official/185_L5.png',
  186 => 'official/186_R1.png',
  428 => 'official/428_R3.png',
  437 => 'official/437_R2.png',
  486 => 'official/486_R1.png',
  509 => '509_R4.png',
  775 => '775_L5.png',
  776 => '776_R1.png',
];

$allCharIds = [];
$addCharId = function ($charId) use (&$allCharIds) {
  $charId = (int)$charId;
  if ($charId > 0) $allCharIds[$charId] = $charId;
};

foreach ($popularChars as $row) {
  if (!empty($row['char_id'])) $addCharId($row['char_id']);
}
foreach ($rareChars as $row) {
  if (!empty($row['char_id'])) $addCharId($row['char_id']);
}
foreach ($banPressure as $row) {
  if (!empty($row['char_id'])) $addCharId($row['char_id']);
}
foreach ($focusMatches as $match) {
  foreach (['left_chars', 'right_chars'] as $sideKey) {
    if (!empty($match[$sideKey]) && is_array($match[$sideKey])) {
      foreach ($match[$sideKey] as $charId) $addCharId($charId);
    }
  }
}

if (!empty($allCharIds)) {
  $ids = array_values($allCharIds);
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("
    SELECT id, name, level, ico
    FROM unlight
    WHERE id IN ($placeholders)
  ");
  $stmt->execute($ids);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$row['id'];
    $charNameMap[$id] = $row['name'] ?? '';
    $charLevelMap[$id] = $row['level'] ?? '';
    $ico = trim((string)($row['ico'] ?? ''));
    if ($ico === '' && !empty($charIconFallbackMap[$id])) $ico = $charIconFallbackMap[$id];
    if ($ico !== '') $charIconMap[$id] = $ico;
  }
}
?>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="pre-report round1-report">
      <div class="pre-hero">
        <div class="hero-sub">
          <?= htmlspecialchars($report['issue_label'] ?? 'ULGG CUP #1 ｜ 第一階段戰報') ?>
        </div>
        <div class="hero-title">
          <?= htmlspecialchars($report['report_title'] ?? '牌組公開特輯：COST 74 環境觀察') ?>
          <span><?= htmlspecialchars($report['report_subtitle'] ?? 'COST 74 Deck Meta Report') ?></span>
        </div>
        <div class="hero-desc">
          <?= htmlspecialchars($report['hero_desc'] ?? '從 25 位玩家、75 副提交牌組中，觀察熱門角色、COST 配置、牌組 BAN 壓力與首輪焦點戰。') ?>
        </div>
        <div class="hero-version">
          Data Source：<?= htmlspecialchars($report['data_source'] ?? 'Submitted Decks') ?> ｜ <?= htmlspecialchars($report['version_label'] ?? 'Issue 01') ?>
        </div>
      </div>

      <div class="summary-grid">
        <div class="summary-card">
          <div class="summary-value"><?= number_format($summary['players']) ?></div>
          <div class="summary-title">Players</div>
        </div>
        <div class="summary-card">
          <div class="summary-value"><?= number_format($summary['decks']) ?></div>
          <div class="summary-title">Submitted Decks</div>
        </div>
        <div class="summary-card">
          <div class="summary-value"><?= number_format($summary['characters']) ?></div>
          <div class="summary-title">Characters</div>
        </div>
        <div class="summary-card">
          <div class="summary-value"><?= number_format($summary['cost']) ?></div>
          <div class="summary-title">COST Limit</div>
        </div>
      </div>

      <div class="report-section">
        <div class="section-title">📰 Round 1 Tournament Report</div>

        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'overview', '第一階段環境總覽') ?></h3>
          <p><?= report_section_text($reportSections, 'overview', '本期戰報以所有參賽者提交的三副牌組為統計基礎，觀察本屆 ULGG CUP 在 COST 74 限制下的角色選擇、隊伍配置與 BAN/PICK 壓力。由於正式勝負尚未展開，本篇重點會放在賽前環境、潛在主流、冷門奇兵與首輪看點。') ?></p>
        </div>

        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'popular_chars_intro', '使用率最高的角色') ?></h3>
          <p><?= report_section_text($reportSections, 'popular_chars_intro', '從提交牌組來看，高登場率角色通常代表該角色在 COST 74 環境中具備穩定性、泛用性，或能在 BAN/PICK 中保留較高戰術彈性。以下整理本次提交牌組中最熱門的角色。') ?></p>

          <div class="meta-grid">
            <div class="meta-box">
              <div class="meta-box-title">🔥 熱門角色 TOP10</div>
              <div class="character-link-hint">點擊查看角色分析 →</div>
              <div class="character-grid">
                <?php if (!empty($popularChars)) { ?>
                  <?php foreach ($popularChars as $row) { ?>
                    <?php
                    $charId = (int)($row['char_id'] ?? 0);
                    if ($charId <= 0 || empty($charIconMap[$charId])) continue;
                    $charLabel = trim(($charLevelMap[$charId] ?? '') . ' ' . ($charNameMap[$charId] ?? ''));
                    ?>
                    <a class="character-card character-card-link" href="/pages/analysis_character_card.php?char_id=<?= $charId ?>&char=<?= urlencode($charLabel) ?>" target="_blank">
                      <div class="rank-badge">#<?= (int)($row['rank'] ?? 0) ?></div>
                      <img src="<?= IMG_BASE . htmlspecialchars($charIconMap[$charId], ENT_QUOTES, 'UTF-8') ?>" class="character-img" loading="lazy" alt="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>">
                      <div class="character-name"><?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="character-win"><?= (int)($row['players'] ?? 0) ?> 人採用</div>
                    </a>
                  <?php } ?>
                <?php } else { ?>
                  <?php for ($i = 1; $i <= 10; $i++) { ?>
                    <div class="character-card">
                      <div class="rank-badge">#<?= $i ?></div>
                      <div class="round1-placeholder-img">?</div>
                      <div class="character-name">角色待補</div>
                      <div class="character-use">- 次出現</div>
                      <div class="character-win">- 人採用</div>
                    </div>
                  <?php } ?>
                <?php } ?>
              </div>
            </div>

            <div class="meta-box">
              <div class="meta-box-title">🃏 冷門奇兵觀察</div>
              <div class="round1-feature-list">
                <?php if (!empty($rareChars)) { ?>
                  <?php foreach ($rareChars as $row) { ?>
                    <?php
                    $charId = (int)($row['char_id'] ?? 0);
                    $charLabel = $row['name'] ?? '';
                    if ($charId > 0) $charLabel = trim(($charLevelMap[$charId] ?? '') . ' ' . ($charNameMap[$charId] ?? ''));
                    ?>
                    <div class="round1-feature-card">
                      <div class="round1-feature-tag"><?= htmlspecialchars($row['tag'] ?? 'Rare Pick', ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="round1-feature-main">
                        <?php if ($charId > 0 && !empty($charIconMap[$charId])) { ?>
                          <img src="<?= IMG_BASE . htmlspecialchars($charIconMap[$charId], ENT_QUOTES, 'UTF-8') ?>" class="round1-mini-char" loading="lazy" alt="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>">
                        <?php } ?>
                        <div>
                          <div class="round1-feature-title"><?= htmlspecialchars($charLabel !== '' ? $charLabel : '角色待補', ENT_QUOTES, 'UTF-8') ?></div>
                          <div class="round1-feature-desc"><?= htmlspecialchars($row['comment'] ?? '少數玩家採用的角色，可能是針對主流環境準備的奇襲選擇。', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                      </div>
                    </div>
                  <?php } ?>
                <?php } else { ?>
                  <div class="round1-feature-card">
                    <div class="round1-feature-tag">Rare Pick</div>
                    <div class="round1-feature-title">冷門角色 A</div>
                    <div class="round1-feature-desc">雖然只有少數玩家採用，但可能針對主流配置，具備奇襲價值。</div>
                  </div>
                  <div class="round1-feature-card">
                    <div class="round1-feature-tag">One Player Pick</div>
                    <div class="round1-feature-title">信仰角色 B</div>
                    <div class="round1-feature-desc">明顯帶有玩家個人風格，若熟練度夠高，可能在首輪製造驚喜。</div>
                  </div>
                  <div class="round1-feature-card">
                    <div class="round1-feature-tag">Combo Piece</div>
                    <div class="round1-feature-title">特殊組合角色 C</div>
                    <div class="round1-feature-desc">單看角色不一定亮眼，但與特定隊友搭配後可能形成明確戰術。</div>
                  </div>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>

        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'cost', 'COST 74 組牌趨勢') ?></h3>
          <p><?= report_section_text($reportSections, 'cost', '本屆指定 COST 74，因此玩家如何分配三名角色的 COST，會直接影響隊伍強度、容錯率與 BAN 後的備案彈性。') ?></p>

          <div class="team-board">
            <div class="team-board-col">
              <div class="team-board-title">⚖ 常見 COST 配置</div>
              <div class="round1-cost-grid">
                <?php if (!empty($costPatterns)) { ?>
                  <?php foreach ($costPatterns as $row) { ?>
                    <div class="round1-cost-card">
                      <div class="round1-cost-pattern"><?= htmlspecialchars($row['pattern'] ?? '?? + ?? + ??', ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="round1-cost-desc"><?= htmlspecialchars($row['comment'] ?? '待補配置分析。', ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="round1-cost-count"><?= htmlspecialchars((string)($row['count'] ?? 0), ENT_QUOTES, 'UTF-8') ?> 組</div>
                    </div>
                  <?php } ?>
                <?php } else { ?>
                  <div class="round1-cost-card">
                    <div class="round1-cost-pattern">高核心 + 中段 + 補位</div>
                    <div class="round1-cost-desc">適合觀察高 COST 核心是否集中，以及低 COST 補位是否形成固定選擇。</div>
                    <div class="round1-cost-count">- 組</div>
                  </div>
                  <div class="round1-cost-card">
                    <div class="round1-cost-pattern">平均分配型</div>
                    <div class="round1-cost-desc">三名角色強度較平均，BAN 掉其中一名後仍有一定戰術彈性。</div>
                    <div class="round1-cost-count">- 組</div>
                  </div>
                  <div class="round1-cost-card">
                    <div class="round1-cost-pattern">極端爆發型</div>
                    <div class="round1-cost-desc">高風險高回報配置，可能在短局數中快速決勝。</div>
                    <div class="round1-cost-count">- 組</div>
                  </div>
                <?php } ?>
              </div>
            </div>

            <div class="team-board-col">
              <div class="team-board-title">🚫 牌組 BAN 壓力預測</div>
              <div class="round1-ban-list">
                <?php if (!empty($banPressure)) { ?>
                  <?php foreach ($banPressure as $row) { ?>
                    <?php
                    $charId = (int)($row['char_id'] ?? 0);
                    $charLabel = $row['name'] ?? '';
                    if ($charId > 0) $charLabel = trim(($charLevelMap[$charId] ?? '') . ' ' . ($charNameMap[$charId] ?? ''));
                    ?>
                    <div class="round1-ban-card">
                      <div class="round1-ban-rank">#<?= (int)($row['rank'] ?? 0) ?></div>
                      <?php if ($charId > 0 && !empty($charIconMap[$charId])) { ?>
                        <img src="<?= IMG_BASE . htmlspecialchars($charIconMap[$charId], ENT_QUOTES, 'UTF-8') ?>" class="round1-mini-char" loading="lazy" alt="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>">
                      <?php } ?>
                      <div class="round1-ban-main">
                        <div class="round1-ban-name"><?= htmlspecialchars($row['deck_title'] ?? '牌組待補', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="round1-ban-reason"><?= htmlspecialchars($row['reason'] ?? '高使用率、對策困難，或被 BAN 後會明顯影響牌組核心。', ENT_QUOTES, 'UTF-8') ?></div>
                      </div>
                      <div class="round1-ban-level"><?= htmlspecialchars($row['level'] ?? 'HIGH', ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                  <?php } ?>
                <?php } else { ?>
                  <?php for ($i = 1; $i <= 5; $i++) { ?>
                    <div class="round1-ban-card">
                      <div class="round1-ban-rank">#<?= $i ?></div>
                      <div class="round1-ban-main">
                        <div class="round1-ban-name">角色待補</div>
                        <div class="round1-ban-reason">高使用率、對策困難，或被 BAN 後會明顯影響牌組核心。</div>
                      </div>
                      <div class="round1-ban-level">HIGH</div>
                    </div>
                  <?php } ?>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>

        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'style', '玩家牌組風格分布') ?></h3>
          <p><?= report_section_text($reportSections, 'style', '依照提交牌組的方向，先將選手風格粗略分成進攻、控制、防守、平衡、奇襲與信仰等類型。這不是強弱排名，而是幫助觀眾快速理解每位選手可能的比賽節奏。') ?></p>

          <div class="round1-style-grid">
            <?php if (!empty($playerStyles)) { ?>
              <?php foreach ($playerStyles as $row) { ?>
                <div class="round1-style-card">
                  <div class="round1-style-name"><?= htmlspecialchars($row['style'] ?? '風格待補', ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="round1-style-count"><?= htmlspecialchars((string)($row['count'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="round1-style-desc"><?= htmlspecialchars($row['desc'] ?? '待補說明。', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
              <?php } ?>
            <?php } else { ?>
              <div class="round1-style-card">
                <div class="round1-style-name">進攻派</div>
                <div class="round1-style-count">-</div>
                <div class="round1-style-desc">偏向快速壓血與短回合決勝。</div>
              </div>
              <div class="round1-style-card">
                <div class="round1-style-name">控制派</div>
                <div class="round1-style-count">-</div>
                <div class="round1-style-desc">重視干擾、距離控制與資源交換。</div>
              </div>
              <div class="round1-style-card">
                <div class="round1-style-name">穩健派</div>
                <div class="round1-style-count">-</div>
                <div class="round1-style-desc">牌組容錯率高，較不怕單點被 BAN。</div>
              </div>
              <div class="round1-style-card">
                <div class="round1-style-name">奇襲派</div>
                <div class="round1-style-count">-</div>
                <div class="round1-style-desc">配置少見，容易造成對手準備壓力。</div>
              </div>
            <?php } ?>
          </div>
        </div>

        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'focus', '首輪焦點戰') ?></h3>
          <p><?= report_section_text($reportSections, 'focus', '首輪看點可以挑 3 到 5 場，不一定要說誰比較強，而是從牌組風格、熱門角色對撞、冷門奇兵、歷史數據差異等角度切入。') ?></p>

          <div class="round1-match-list">
            <?php if (!empty($focusMatches)) { ?>
              <?php foreach ($focusMatches as $row) { ?>
                <div class="round1-match-card">
                  <div class="round1-match-code"><?= htmlspecialchars($row['code'] ?? 'M01', ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="round1-match-main">
                    <div class="round1-match-title"><?= htmlspecialchars($row['title'] ?? '玩家 A vs 玩家 B', ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="round1-match-desc"><?= htmlspecialchars($row['desc'] ?? '待補看點。', ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="round1-match-teams">
                      <?php foreach (['left_chars', 'right_chars'] as $sideKey) { ?>
                        <?php if (!empty($row[$sideKey]) && is_array($row[$sideKey])) { ?>
                          <div class="team-chars player-team-chars">
                            <?php foreach ($row[$sideKey] as $charId) { ?>
                              <?php
                              $charId = (int)$charId;
                              if (empty($charIconMap[$charId])) continue;
                              $charLabel = trim(($charLevelMap[$charId] ?? '') . ' ' . ($charNameMap[$charId] ?? ''));
                              ?>
                              <img src="<?= IMG_BASE . htmlspecialchars($charIconMap[$charId], ENT_QUOTES, 'UTF-8') ?>" class="team-char-img" loading="lazy" title="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>">
                            <?php } ?>
                          </div>
                        <?php } ?>
                      <?php } ?>
                    </div>
                  </div>
                </div>
              <?php } ?>
            <?php } else { ?>
              <div class="round1-match-card">
                <div class="round1-match-code">M01</div>
                <div class="round1-match-main">
                  <div class="round1-match-title">玩家 A vs 玩家 B</div>
                  <div class="round1-match-desc">主流配置與奇襲牌組的對撞，BAN 選可能直接決定節奏。</div>
                </div>
              </div>
              <div class="round1-match-card">
                <div class="round1-match-code">M02</div>
                <div class="round1-match-main">
                  <div class="round1-match-title">玩家 C vs 玩家 D</div>
                  <div class="round1-match-desc">雙方都帶有熱門核心角色，第一 BAN 可能成為本場關鍵。</div>
                </div>
              </div>
              <div class="round1-match-card">
                <div class="round1-match-code">M03</div>
                <div class="round1-match-main">
                  <div class="round1-match-title">玩家 E vs 玩家 F</div>
                  <div class="round1-match-desc">歷史資料較少的選手，實戰表現仍保有高度未知數。</div>
                </div>
              </div>
            <?php } ?>
          </div>
        </div>

        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'next', '下一期預告') ?></h3>
          <p><?= report_section_text($reportSections, 'next', '第一階段戰報先從提交牌組觀察賽前環境。等比賽正式開打後，下一期可以接著整理實際 BAN 率、角色勝率、晉級牌組、爆冷場次與首輪 Meta 變化。') ?></p>
        </div>
      </div>
    </div>
  </section>
</div>