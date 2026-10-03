<?php

if (!isset($pdo, $report, $reportSections)) {
  die('請由 report.php 載入本頁');
}
$isAdmin = $isAdmin ?? false;
$currentLoginName = $currentLoginName ?? '';
$currentPlayerId = $currentPlayerId ?? 0;
$playerIdBySeed = $playerIdBySeed ?? [];

function report_section_title($sections, $key, $fallback)
{
  return $sections[$key]['section_title'] ?? $fallback;
}

function report_section_text($sections, $key, $fallback = '')
{
  return $sections[$key]['section_content'] ?? $fallback;
}

/* $pdo = $db; */
/* $stmt = $pdo->query("
    SELECT COUNT(*) AS players
    FROM tournament_players
    WHERE tournament_id = 1
      AND entry_status IN ('registered','confirmed')
      AND player_status <> 'withdrawn'
");

$playerCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['players']; */
$summaryJson = json_decode($reportSections['summary']['block_json'] ?? '{}', true);

$summary = [
  'players' => (int)($summaryJson['players'] ?? 0),
  'history' => (int)($summaryJson['history'] ?? 0),
  'wins' => (int)($summaryJson['wins'] ?? 0),
  'losses' => (int)($summaryJson['losses'] ?? 0)
];



$weaponIconMap = [
  13 => 'phpp90mdZ.png',
  6 => 'phpN3ZEsK.png',
  188 => '188.jpg',
  9 => 'phpWzikOi.png',
  11 => 'phpFtIzyb.png',
  14 => 'phpHq1Mug.png',
  204 => 'weapon_204.png',
  10 => 'phpNPnE8C.png',
  136 => '136.jpg',
  206 => 'weapon_206.png',
  65 => '65.jpg',
  84 => '84.jpg'
];

$popularChars = json_decode($reportSections['popular_chars']['block_json'] ?? '[]', true);
$winRateChars = json_decode($reportSections['win_rate_chars']['block_json'] ?? '[]', true);

$popularTeams = json_decode($reportSections['popular_teams']['block_json'] ?? '[]', true);
$bestTeams = json_decode($reportSections['best_teams']['block_json'] ?? '[]', true);

$popularWeapons = json_decode($reportSections['popular_weapons']['block_json'] ?? '[]', true);
$charWeaponPairs = json_decode($reportSections['char_weapon_pairs']['block_json'] ?? '[]', true);
$playerReports = json_decode($reportSections['player_reports']['block_json'] ?? '[]', true);

if (!is_array($playerReports)) {
  $playerReports = [];
}

$charIconMap = [];
$charNameMap = [];
$charLevelMap = [];

/**
 * 少數 unlight.ico 可能為空或實際路徑特殊，保留 fallback。
 * 這裡只放必要補丁，不再維護全部角色。
 */
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
  if ($charId > 0) {
    $allCharIds[$charId] = $charId;
  }
};

/* 1. 熱門角色 */
foreach ($popularChars as $row) {
  if (!empty($row['char_id'])) {
    $addCharId($row['char_id']);
  }
}

/* 2. 高勝率角色 */
foreach ($winRateChars as $row) {
  if (!empty($row['char_id'])) {
    $addCharId($row['char_id']);
  }
}

/* 3. 熱門三人組 */
foreach ($popularTeams as $team) {
  if (!empty($team['chars']) && is_array($team['chars'])) {
    foreach ($team['chars'] as $charId) {
      $addCharId($charId);
    }
  }
}

/* 4. 高勝率三人組 */
foreach ($bestTeams as $team) {
  if (!empty($team['chars']) && is_array($team['chars'])) {
    foreach ($team['chars'] as $charId) {
      $addCharId($charId);
    }
  }
}

/* 5. 角色 × 武器 */
foreach ($charWeaponPairs as $pair) {
  if (!empty($pair['char_id'])) {
    $addCharId($pair['char_id']);
  }
}

/* 6. 玩家專欄：正式公開牌組 + 歷史 top_chars */
foreach ($playerReports as $player) {
  if (!empty($player['decks']) && is_array($player['decks'])) {
    foreach ($player['decks'] as $deck) {
      if (!empty($deck['chars']) && is_array($deck['chars'])) {
        foreach ($deck['chars'] as $char) {
          if (is_array($char) && isset($char['char_id'])) {
            $addCharId($char['char_id']);
          } else {
            $addCharId($char);
          }
        }
      }
    }
  }

  if (!empty($player['top_chars']) && is_array($player['top_chars'])) {
    foreach ($player['top_chars'] as $charId) {
      $addCharId($charId);
    }
  }
}

/* 7. 從 DB 抓角色資料 */
if (!empty($allCharIds)) {
  $placeholders = implode(',', array_fill(0, count($allCharIds), '?'));

  $stmtChars = $pdo->prepare("
    SELECT
      id,
      ico,
      name,
      level
    FROM unlight
    WHERE id IN ($placeholders)
  ");

  $stmtChars->execute(array_values($allCharIds));

  while ($char = $stmtChars->fetch(PDO::FETCH_ASSOC)) {
    $charId = (int)$char['id'];

    $dbIco = trim((string)($char['ico'] ?? ''));

    if ($dbIco !== '') {
      $charIconMap[$charId] = $dbIco;
    } elseif (!empty($charIconFallbackMap[$charId])) {
      $charIconMap[$charId] = $charIconFallbackMap[$charId];
    } else {
      $charIconMap[$charId] = '';
    }

    $charNameMap[$charId] = $char['name'] ?? '';
    $charLevelMap[$charId] = $char['level'] ?? '';
  }
}

/* 8. DB 沒抓到但 fallback 有的，也補進去 */
foreach ($charIconFallbackMap as $charId => $ico) {
  if (isset($allCharIds[$charId]) && empty($charIconMap[$charId])) {
    $charIconMap[$charId] = $ico;
  }
}
?>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="pre-report">
      <div class="pre-hero">
        <div class="hero-sub">
          <?= htmlspecialchars($report['issue_label'] ?? 'ULGG CUP #1') ?>
        </div>
        <div class="hero-title">
          <?= htmlspecialchars($report['report_title'] ?? '第一屆 ULGG CUP 戰前分析') ?>
          <span><?= htmlspecialchars($report['report_subtitle'] ?? 'Tournament Preview') ?></span>
        </div>
        <div class="hero-desc">
          <?= htmlspecialchars($report['hero_desc'] ?? 'COST 74 Meta Analysis') ?>
        </div>
        <div class="hero-version">
          Data Source：<?= htmlspecialchars($report['data_source'] ?? 'ULGG Arena') ?> ｜ <?= htmlspecialchars($report['version_label'] ?? 'Version 1.0') ?>
        </div>
      </div>
      <div class="summary-grid">
        <div class="summary-card">
          <div class="summary-value">
            <?= number_format($summary['players']) ?>
          </div>
          <div class="summary-title">
            Players
          </div>
        </div>
        <div class="summary-card">
          <div class="summary-value">
            <?= number_format($summary['history']) ?>
          </div>
          <div class="summary-title">
            Historical Teams
          </div>
        </div>
        <div class="summary-card">
          <div class="summary-value">
            <?= number_format($summary['wins']) ?>
          </div>
          <div class="summary-title">
            Wins
          </div>
        </div>
        <div class="summary-card">
          <div class="summary-value">
            <?= number_format($summary['losses']) ?>
          </div>
          <div class="summary-title">
            Losses
          </div>
        </div>
      </div>

      <div class="report-section">
        <div class="section-title">📰 Tournament Report</div>
        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'overview', 'ULGG CUP 第一屆') ?></h3>
          <p><?= report_section_text($reportSections, 'overview') ?></p>
          <!-- <h3>ULGG CUP 第一屆</h3>
          <p>
            本次分析以符合本屆賽制（角色 + 武器 COST = 74、事件卡不計入 COST）的歷史資料為基礎，
            共統計 1,982 組隊伍、1,930 場有效勝負資料，希望讓選手與觀眾在開賽前，快速掌握目前的 Meta 環境。
          </p> -->

        </div>
        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'character', '使用率最高的角色') ?></h3>
          <p><?= report_section_text($reportSections, 'character') ?></p>
          <!-- <h3>使用率最高的角色</h3>
          <p>
            從目前 Meta 來看，環境已逐漸形成固定核心。
            <strong>R5 威廉</strong>仍是目前使用率最高的角色，而<strong>R4 瑪爾瑟斯</strong>、<strong>R5 米利安</strong>等角色則在高使用率下仍維持接近七成勝率，顯示目前環境不只是「熱門」，更開始出現穩定且成熟的核心體系。
          </p> -->
          <div class="meta-grid">
            <div class="meta-box">
              <div class="meta-box-title">
                🔥 熱門角色 TOP10
              </div>
              <div class="character-link-hint">
                點擊查看角色分析 →
              </div>

              <div class="character-grid">
                <?php foreach ($popularChars as $row) { ?>
                  <?php
                  $charId = (int)$row['char_id'];

                  if (empty($charIconMap[$charId])) {
                    continue;
                  }

                  $charLabel = trim(($charLevelMap[$charId] ?? '') . ' ' . ($charNameMap[$charId] ?? ''));
                  ?>
                  <a class="character-card character-card-link" href="/pages/analysis_character_card.php?char_id=<?= $charId ?>&char=<?= urlencode($charLabel) ?>" target="_blank">
                    <div class="rank-badge">#<?= $row['rank'] ?></div>
                    <img src="<?= IMG_BASE . $charIconMap[$charId] ?>" class="character-img" loading="lazy" alt="<?= htmlspecialchars($charLabel) ?>">
                    <div class="character-name"><?= htmlspecialchars($charLabel) ?></div>
                    <div class="character-use"><?= $row['used_count'] ?> 場</div>
                    <div class="character-win"><?= $row['win_rate'] ?>%</div>
                  </a>
                <?php } ?>
              </div>

            </div>

            <div class="meta-box">
              <div class="meta-box-title">
                ⭐ 高勝率角色 TOP10
              </div>
              <div class="character-link-hint">
                點擊查看角色分析 →
              </div>
              <div class="character-grid">
                <?php foreach ($winRateChars as $row) { ?>
                  <?php
                  $charId = (int)$row['char_id'];

                  if (empty($charIconMap[$charId])) {
                    continue;
                  }

                  $charLabel = trim(($charLevelMap[$charId] ?? '') . ' ' . ($charNameMap[$charId] ?? ''));
                  ?>
                  <a class="character-card character-card-link" href="/pages/analysis_character_card.php?char_id=<?= $charId ?>&char=<?= urlencode($charLabel) ?>" target="_blank">
                    <div class="rank-badge">#<?= $row['rank'] ?></div>
                    <img src="<?= IMG_BASE . $charIconMap[$charId] ?>" class="character-img" loading="lazy" alt="<?= htmlspecialchars($charLabel) ?>">
                    <div class="character-name"><?= htmlspecialchars($charLabel) ?></div>
                    <div class="character-use"><?= $row['used_count'] ?> 場</div>
                    <div class="character-win"><?= $row['win_rate'] ?>%</div>
                  </a>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>
        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'team', '隊伍配置') ?></h3>
          <p><?= report_section_text($reportSections, 'team') ?></p>
          <!-- <h3>隊伍配置</h3>
          <p>
            在隊伍配置方面，三人組合也開始趨於固定，
            <strong>R1 梅莉／R5 貝琳達／R2 凱倫貝克</strong>是目前出場次數最高的隊伍；而<strong>L4 伊芙琳 / R5 米利安 / R5 威廉</strong>、<strong>R3 布勞／R1 史特靈／R5 威廉</strong>則繳出更亮眼的歷史勝率。
          </p> -->
          <div class="team-board">
            <div class="team-board-col">
              <div class="team-board-title">🔥 熱門三人組 TOP10</div>
              <div class="team-link-hint">點擊查看三人組分析 →</div>
              <div class="team-grid team-grid-single">
                <?php foreach ($popularTeams as $team) { ?>
                  <a href="/pages/team_analysis.php?id1=<?= $team['chars'][0] ?>&id2=<?= $team['chars'][1] ?>&id3=<?= $team['chars'][2] ?>"
                    class="team-card team-card-link">
                    <div class="team-rank">#<?= $team['rank'] ?></div>
                    <div class="team-chars">
                      <?php foreach ($team['chars'] as $charId) { ?>
                        <?php
                        $charId = (int)$charId;

                        if (empty($charIconMap[$charId])) {
                          echo '<span class="team-char-missing">?</span>';
                          continue;
                        }

                        $charLabel = trim(($charLevelMap[$charId] ?? '') . ' ' . ($charNameMap[$charId] ?? ''));
                        ?>
                        <img src="<?= IMG_BASE . htmlspecialchars($charIconMap[$charId], ENT_QUOTES, 'UTF-8') ?>"
                          class="team-char-img"
                          loading="lazy"
                          alt="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>"
                          title="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>">
                      <?php } ?>
                    </div>
                    <div class="team-info">
                      <div class="team-title">
                        <?php
                        $teamNames = [];
                        foreach ($team['chars'] as $charId) {
                          $teamNames[] = $charLevelMap[$charId] . ' ' . $charNameMap[$charId];
                        }
                        echo implode(' / ', $teamNames);
                        ?>
                      </div>

                      <div class="team-meta">
                        <div class="team-meta-item">
                          <span class="team-meta-label">使用</span>
                          <span class="team-meta-value"><?= $team['used'] ?>場</span>
                        </div>
                        <div class="team-meta-item">
                          <span class="team-meta-label">勝率</span>
                          <span class="team-meta-value win"><?= $team['win_rate'] ?>%</span>
                        </div>
                      </div>

                    </div>
                  </a>
                <?php } ?>
              </div>
            </div>

            <div class="team-board-col">
              <div class="team-board-title">⭐ 高勝率三人組 TOP10</div>
              <div class="team-link-hint">點擊查看三人組分析 →</div>
              <div class="team-grid team-grid-single">
                <?php foreach ($bestTeams as $team) { ?>
                  <a href="/pages/team_analysis.php?id1=<?= $team['chars'][0] ?>&id2=<?= $team['chars'][1] ?>&id3=<?= $team['chars'][2] ?>"
                    class="team-card team-card-link">
                    <div class="team-rank">#<?= $team['rank'] ?></div>
                    <div class="team-chars">
                      <?php foreach ($team['chars'] as $charId) { ?>
                        <img src="<?= IMG_BASE . $charIconMap[$charId] ?>" class="team-char-img" loading="lazy">
                      <?php } ?>
                    </div>
                    <div class="team-info">
                      <div class="team-title">
                        <?php
                        $title = [];
                        foreach ($team['chars'] as $charId) {
                          $title[] = $charLevelMap[$charId] . ' ' . $charNameMap[$charId];
                        }
                        echo implode(' / ', $title);
                        ?>
                      </div>
                      <div class="team-meta">
                        <div class="team-meta-item">
                          <span class="team-meta-label">使用</span>
                          <span class="team-meta-value"><?= $team['used'] ?>場</span>
                        </div>
                        <div class="team-meta-item">
                          <span class="team-meta-label">勝率</span>
                          <span class="team-meta-value win"><?= $team['win_rate'] ?>%</span>
                        </div>
                      </div>

                    </div>
                  </a>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>

        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'weapon', '武器配置') ?></h3>
          <p><?= report_section_text($reportSections, 'weapon') ?></p>
          <!-- <h3>武器配置</h3>
          <p>
            武器配置，<strong>白的彈藥</strong>仍是目前最主流的選擇，使用次數超過七百場，幾乎成為現今 Meta 的標準配置。除此之外，<strong>妖魔戒指</strong>、<strong>白色桿麵棍</strong>與<strong>白的槍劍</strong>同樣維持極高出場率。從角色搭配來看，不少角色已逐漸形成固定武器組合，例如 <strong>R5 威廉 × 妖魔戒指</strong>、<strong>R4 庫恩 × 白的彈藥</strong>、<strong>R3 布勞 × 白的彈藥</strong> 等，都展現出相當穩定的使用率與勝率，也代表武器選擇開始成為隊伍構築的重要一環。
          </p> -->

          <div class="weapon-board">
            <div class="weapon-board-col">
              <div class="weapon-board-title">🗡 熱門武器 TOP10</div>
              <div class="weapon-list">
                <?php foreach ($popularWeapons as $weapon) { ?>
                  <div class="weapon-card">
                    <div class="weapon-rank">#<?= $weapon['rank'] ?></div>

                    <?php if (!empty($weaponIconMap[$weapon['weapon_id']])) { ?>
                      <img src="<?= IMG_BASE . $weaponIconMap[$weapon['weapon_id']] ?>" class="weapon-icon" loading="lazy">
                    <?php } else { ?>
                      <div class="weapon-icon weapon-icon-empty">?</div>
                    <?php } ?>

                    <div class="weapon-main">
                      <div class="weapon-name"><?= $weapon['name'] ?></div>

                      <div class="weapon-stats">
                        <?php if ($weapon['melee'] == $weapon['ranged'] && $weapon['def_melee'] == $weapon['def_ranged']) { ?>
                          <?php if ($weapon['melee'] != 0) { ?><span class="stat-all">ATK <?= $weapon['melee'] > 0 ? '+' : '' ?><?= $weapon['melee'] ?></span><?php } ?>
                          <?php if ($weapon['def_melee'] != 0) { ?><span class="stat-all">DEF <?= $weapon['def_melee'] > 0 ? '+' : '' ?><?= $weapon['def_melee'] ?></span><?php } ?>
                        <?php } else { ?>
                          <?php if ($weapon['melee'] != 0) { ?><span class="stat-melee">ATK <?= $weapon['melee'] > 0 ? '+' : '' ?><?= $weapon['melee'] ?></span><?php } ?>
                          <?php if ($weapon['def_melee'] != 0) { ?><span class="stat-melee">DEF <?= $weapon['def_melee'] > 0 ? '+' : '' ?><?= $weapon['def_melee'] ?></span><?php } ?>
                          <?php if ($weapon['ranged'] != 0) { ?><span class="stat-ranged">ATK <?= $weapon['ranged'] > 0 ? '+' : '' ?><?= $weapon['ranged'] ?></span><?php } ?>
                          <?php if ($weapon['def_ranged'] != 0) { ?><span class="stat-ranged">DEF <?= $weapon['def_ranged'] > 0 ? '+' : '' ?><?= $weapon['def_ranged'] ?></span><?php } ?>
                        <?php } ?>
                      </div>
                    </div>

                    <div class="weapon-meta">
                      <span>使用 <b><?= $weapon['used'] ?>場</b></span>
                      <span>勝率 <b class="win"><?= $weapon['win_rate'] ?>%</b></span>
                    </div>
                  </div>
                <?php } ?>
              </div>
            </div>

            <div class="weapon-board-col">
              <div class="weapon-board-title">⚔ 角色 × 武器配置 TOP10</div>
              <div class="weapon-list">
                <?php foreach ($charWeaponPairs   as $pair) { ?>
                  <div class="weapon-card">
                    <div class="weapon-rank">#<?= $pair['rank'] ?></div>
                    <div class="pair-icons">
                      <img src="<?= IMG_BASE . $charIconMap[$pair['char_id']] ?>" class="pair-char-img" loading="lazy">

                      <?php if (!empty($weaponIconMap[$pair['weapon_id']])) { ?>
                        <img src="<?= IMG_BASE . $weaponIconMap[$pair['weapon_id']] ?>" class="pair-weapon-img" loading="lazy">
                      <?php } else { ?>
                        <div class="pair-weapon-img weapon-icon-empty">?</div>
                      <?php } ?>
                    </div>
                    <div class="weapon-name">
                      <?= $charLevelMap[$pair['char_id']] . ' ' . $charNameMap[$pair['char_id']] ?> × <?= $pair['weapon_name'] ?> </div>
                    <div class="weapon-meta">
                      <span>使用 <b><?= $pair['used'] ?>場</b></span>
                      <span>勝率 <b class="win"><?= $pair['win_rate'] ?>%</b></span>
                    </div>
                  </div>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>
        <div class="report-card">
          <h3><?= report_section_title($reportSections, 'player', '玩家專欄') ?></h3>
          <p><?= report_section_text($reportSections, 'player') ?></p>
          <!-- <h3>玩家專欄</h3>
          <p>
            從歷史 COST 74 對戰紀錄來看，各位選手的比賽經驗差距相當明顯。
            有人早已累積數十場以上實戰，不斷在天梯磨練自己的牌組；也有不少選手屬於首次踏入這個環境，真正的實力仍充滿未知數。本專欄將帶大家快速認識每位參賽者的歷史數據，包括出場場數、勝率與最高 BP，一起看看誰是本屆最值得關注的熱門選手，又有哪些黑馬可能在正式比賽一鳴驚人。
          </p> -->

          <div class="player-board">
            <?php foreach ($playerReports as $player) { ?>
              <div class="player-card" id="player-seed-<?= (int)$player['seed'] ?>">
                <div class="player-head">
                  <div class="player-seed">#<?= $player['seed'] ?></div>
                  <div>
                    <div class="player-name"><?= $player['name'] ?></div>
                    <?php if (!empty($player['tag'])) { ?>
                      <div class="player-tag">
                        #<?= htmlspecialchars(ltrim($player['tag'], '#'), ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    <?php } ?>

                  </div>

                </div>
                <?php if (!empty($player['intro'])) { ?>
                  <div class="player-intro">
                    <?= htmlspecialchars($player['intro'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                <?php } ?>
                <div class="player-caster-line">
                  <?php if ($player['team_count'] >= 20) { ?>
                    74COST歷史資料相當充足，是本屆賽前最容易被研究的選手之一。
                  <?php } elseif ($player['win_rate'] >= 80) { ?>
                    74COST樣本數雖然不多，但目前勝率表現相當亮眼，具備黑馬潛力。
                  <?php } elseif ($player['max_bp'] >= 1700) { ?>
                    最高 BP 表現突出，若能穩定發揮，有機會成為賽事焦點。
                  <?php } else { ?>
                    74COST歷史資料不算多，正式比賽中的牌組選擇仍保有相當神秘感。
                  <?php } ?>
                </div>

                <div class="player-stats">
                  <div><span>出場</span><b><?= $player['team_count'] ?></b></div>
                  <div><span>勝率</span><b class="win"><?= $player['win_rate'] ?>%</b></div>
                  <div><span>MAX BP</span><b><?= $player['max_bp'] ?></b></div>
                </div>



                <?php
                $playerSeed = (int)$player['seed'];
                $playerId = $playerIdBySeed[$playerSeed] ?? 0;
                $canViewRecordDeck = $isAdmin || ($currentPlayerId > 0 && $currentPlayerId === $playerId);

                $publicDecks = [];
                $hasSubmittedDecks = false;

                if (!empty($player['decks']) && is_array($player['decks'])) {
                  $hasSubmittedDecks = true;

                  foreach ($player['decks'] as $deckIndex => $deck) {
                    $deckChars = [];

                    if (!empty($deck['chars']) && is_array($deck['chars'])) {
                      foreach ($deck['chars'] as $char) {
                        if (is_array($char) && isset($char['char_id'])) {
                          $deckChars[] = (int)$char['char_id'];
                        } else {
                          $deckChars[] = (int)$char;
                        }
                      }
                    }

                    $publicDecks[] = [
                      'deck_no' => $deck['deck_no'] ?? ('牌組' . chr(65 + $deckIndex)),
                      'deck_name' => $deck['deck_name'] ?? '',
                      'chars' => array_slice($deckChars, 0, 3),
                      'type' => 'submitted'
                    ];
                  }
                } elseif ($canViewRecordDeck && !empty($player['top_chars']) && is_array($player['top_chars'])) {
                  $publicDecks[] = [
                    'deck_no' => '代表',
                    'deck_name' => '最高 BP 紀錄牌組',
                    'chars' => array_slice(array_map('intval', $player['top_chars']), 0, 3),
                    'type' => 'record'
                  ];
                }
                ?>

                <div class="player-decks-public">
                  <div class="player-decks-title">
                    <?= $hasSubmittedDecks ? '公開牌組' : '歷史紀錄' ?>
                  </div>

                  <?php if (!empty($publicDecks)) { ?>
                    <div class="player-deck-grid">
                      <?php foreach ($publicDecks as $deck) { ?>
                        <?php
                        $deckChars = $deck['chars'] ?? [];
                        $deckUrl = '';

                        if (count($deckChars) >= 3) {
                          $deckUrl = '/pages/team_analysis.php?id1=' . (int)$deckChars[0]
                            . '&id2=' . (int)$deckChars[1]
                            . '&id3=' . (int)$deckChars[2];
                        }
                        ?>

                        <div class="player-deck-box">
                          <div class="player-deck-box-head">
                            <span class="player-deck-no">
                              <?= htmlspecialchars((string)$deck['deck_no'], ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <?php if (!empty($deck['deck_name'])) { ?>
                              <span class="player-deck-name">
                                <?= htmlspecialchars((string)$deck['deck_name'], ENT_QUOTES, 'UTF-8') ?>
                              </span>
                            <?php } ?>
                          </div>

                          <?php if ($deckUrl !== '') { ?>
                            <a href="<?= htmlspecialchars($deckUrl, ENT_QUOTES, 'UTF-8') ?>" class="player-deck-link" target="_blank">
                            <?php } ?>

                            <div class="team-chars player-team-chars">
                              <?php foreach ($deckChars as $charId) { ?>
                                <?php
                                $charId = (int)$charId;
                                if (empty($charIconMap[$charId])) {
                                  continue;
                                }
                                $charLabel = trim(($charLevelMap[$charId] ?? '') . ' ' . ($charNameMap[$charId] ?? ''));
                                ?>
                                <img src="<?= IMG_BASE . $charIconMap[$charId] ?>"
                                  class="team-char-img"
                                  loading="lazy"
                                  title="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>"
                                  alt="<?= htmlspecialchars($charLabel, ENT_QUOTES, 'UTF-8') ?>">
                              <?php } ?>
                            </div>

                            <?php if ($deckUrl !== '') { ?>
                              <div class="team-link-hint">查看三人組分析 →</div>
                            </a>
                          <?php } ?>
                        </div>
                      <?php } ?>
                    </div>
                  <?php } elseif (!$hasSubmittedDecks && !$canViewRecordDeck && !empty($player['top_chars'])) { ?>
                    <div class="player-deck-private">
                      最高 BP 紀錄牌組僅本人登入與管理員可見
                    </div>
                  <?php } else { ?>
                    <div class="player-deck-empty">
                      尚無紀錄牌組
                    </div>
                  <?php } ?>
                </div>
              </div>
            <?php } ?>
          </div>
        </div>
        <div class="report-card">
          <h3>ULGG Arena 即時統計</h3>


          <p>
            本頁所有排行榜皆由 ULGG Arena 即時統計，
            包括熱門角色、高勝率角色、熱門隊伍、熱門武器與 Ban 建議等內容，
            後續將隨資料庫持續更新，希望能提供選手、主播與觀眾最即時的賽前情報。
          </p>

        </div>

      </div>



    </div>

  </section>

</div>

<script>

</script>