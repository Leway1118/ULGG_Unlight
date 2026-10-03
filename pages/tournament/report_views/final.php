<?php
    if (! isset($pdo, $report, $reportSections)) {
    die('請由 report.php 載入本頁');
    }

    $tournamentId = (int) ($report['tournament_id'] ?? ($_GET['tid'] ?? 1));

    if (! function_exists('fr_e')) {
    function fr_e($v): string
    {return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');}
    }
    if (! function_exists('fr_team_name')) {
    function fr_team_name(array $cards): string
    {
        $parts = [];
        foreach ($cards as $card) {
            $parts[] = trim(($card['level'] ?? '') . ' ' . ($card['name'] ?? ''));
        }
        return implode('／', array_filter($parts));
    }
    }
    if (! function_exists('fr_render_cards')) {
    function fr_render_cards(array $cards): void
    {
        foreach ($cards as $card) {
            $label = trim(($card['level'] ?? '') . ' ' . ($card['name'] ?? ''));
            $ico   = trim((string) ($card['ico'] ?? ''));
            $src   = $ico !== '' ? IMG_BASE . $ico : '';
            ?>
      <div class="fr-card">
        <?php if ($src !== ''): ?>
          <img src="<?php echo fr_e($src) ?>" alt="<?php echo fr_e($label) ?>" loading="lazy"
               onerror="this.remove();this.parentElement.classList.add('is-missing');">
        <?php endif; ?>
        <div class="fr-card-caption">
          <span><?php echo fr_e($card['level'] ?? '') ?></span>
          <strong><?php echo fr_e($card['name'] ?? '') ?></strong>
        </div>
      </div>
      <?php
          }
              }
          }

          /* 最終四場 */
          $stmt = $pdo->prepare("\n  SELECT m.*, p1.display_name AS p1_name, p2.display_name AS p2_name, w.display_name AS winner_name\n  FROM tournament_matches m\n  LEFT JOIN tournament_players p1 ON p1.id = m.player1_id\n  LEFT JOIN tournament_players p2 ON p2.id = m.player2_id\n  LEFT JOIN tournament_players w ON w.id = m.winner_player_id\n  WHERE m.tournament_id = :tid\n    AND m.match_code IN ('SF-1','SF-2','THIRD','FINAL')\n  ORDER BY m.match_no ASC\n");
          $stmt->execute([':tid' => $tournamentId]);
          $finalRows = [];
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $finalRows[$row['match_code']] = $row;
          }

          $championId   = (int) ($finalRows['FINAL']['winner_player_id'] ?? 23);
          $championName = (string) ($finalRows['FINAL']['winner_name'] ?? '十六夜蝶');
          $runnerUpName = ((int) ($finalRows['FINAL']['winner_player_id'] ?? 0) === (int) ($finalRows['FINAL']['player1_id'] ?? 0))
              ? (string) ($finalRows['FINAL']['p2_name'] ?? '')
              : (string) ($finalRows['FINAL']['p1_name'] ?? '');
          $thirdName  = (string) ($finalRows['THIRD']['winner_name'] ?? 'ASAPbaby');
          $fourthName = ((int) ($finalRows['THIRD']['winner_player_id'] ?? 0) === (int) ($finalRows['THIRD']['player1_id'] ?? 0))
              ? (string) ($finalRows['THIRD']['p2_name'] ?? '')
              : (string) ($finalRows['THIRD']['p1_name'] ?? '');

          /* 冠軍之路 */
          $stmt = $pdo->prepare("\n  SELECT m.*, p1.display_name AS p1_name, p2.display_name AS p2_name\n  FROM tournament_matches m\n  LEFT JOIN tournament_players p1 ON p1.id = m.player1_id\n  LEFT JOIN tournament_players p2 ON p2.id = m.player2_id\n  WHERE m.tournament_id = :tid\n    AND m.winner_player_id = :pid\n    AND m.status IN ('confirmed','finished')\n  ORDER BY m.match_no ASC\n");
          $stmt->execute([':tid' => $tournamentId, ':pid' => $championId]);
          $championRoad = $stmt->fetchAll(PDO::FETCH_ASSOC);

          $matchWins  = count($championRoad);
          $gameWins   = 0;
          $gameLosses = 0;
          $sweeps     = 0;
          $fullSets   = 0;
          foreach ($championRoad as $row) {
              $isP1        = (int) $row['player1_id'] === $championId;
              $w           = $isP1 ? (int) $row['player1_score'] : (int) $row['player2_score'];
              $l           = $isP1 ? (int) $row['player2_score'] : (int) $row['player1_score'];
              $gameWins   += $w;
              $gameLosses += $l;
              if ($w === 2 && $l === 0) {
                  $sweeps++;
              }

              if ($w === 2 && $l === 1) {
                  $fullSets++;
              }

          }

          /* 決賽小局 */
          $stmt = $pdo->prepare("\n  SELECT\n    g.*,\n    p1.display_name AS p1_name, p2.display_name AS p2_name, w.display_name AS winner_name,\n    u11.name AS p1c1_name, u11.level AS p1c1_level, u11.ico AS p1c1_ico,\n    u12.name AS p1c2_name, u12.level AS p1c2_level, u12.ico AS p1c2_ico,\n    u13.name AS p1c3_name, u13.level AS p1c3_level, u13.ico AS p1c3_ico,\n    u21.name AS p2c1_name, u21.level AS p2c1_level, u21.ico AS p2c1_ico,\n    u22.name AS p2c2_name, u22.level AS p2c2_level, u22.ico AS p2c2_ico,\n    u23.name AS p2c3_name, u23.level AS p2c3_level, u23.ico AS p2c3_ico\n  FROM tournament_match_games g\n  JOIN tournament_matches m ON m.id = g.match_id\n  LEFT JOIN tournament_players p1 ON p1.id = g.player1_id\n  LEFT JOIN tournament_players p2 ON p2.id = g.player2_id\n  LEFT JOIN tournament_players w ON w.id = g.winner_player_id\n  LEFT JOIN unlight u11 ON u11.id = g.player1_char1_id\n  LEFT JOIN unlight u12 ON u12.id = g.player1_char2_id\n  LEFT JOIN unlight u13 ON u13.id = g.player1_char3_id\n  LEFT JOIN unlight u21 ON u21.id = g.player2_char1_id\n  LEFT JOIN unlight u22 ON u22.id = g.player2_char2_id\n  LEFT JOIN unlight u23 ON u23.id = g.player2_char3_id\n  WHERE m.tournament_id = :tid\n    AND m.match_code = 'FINAL'\n    AND g.result_status = 'verified'\n  ORDER BY g.game_no ASC, g.id ASC\n");
          $stmt->execute([':tid' => $tournamentId]);
          $finalGames = [];
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $row['p1_cards'] = [
                  ['name' => $row['p1c1_name'], 'level' => $row['p1c1_level'], 'ico' => $row['p1c1_ico']],
                  ['name' => $row['p1c2_name'], 'level' => $row['p1c2_level'], 'ico' => $row['p1c2_ico']],
                  ['name' => $row['p1c3_name'], 'level' => $row['p1c3_level'], 'ico' => $row['p1c3_ico']],
              ];
              $row['p2_cards'] = [
                  ['name' => $row['p2c1_name'], 'level' => $row['p2c1_level'], 'ico' => $row['p2c1_ico']],
                  ['name' => $row['p2c2_name'], 'level' => $row['p2c2_level'], 'ico' => $row['p2c2_ico']],
                  ['name' => $row['p2c3_name'], 'level' => $row['p2c3_level'], 'ico' => $row['p2c3_ico']],
              ];
              $finalGames[] = $row;
          }

          /* 決賽 BAN */
          $stmt = $pdo->prepare("\n  SELECT b.ban_side, b.char_id, COALESCE(u.name,b.char_name) AS char_name,\n         COALESCE(u.level,b.card_level) AS char_level, u.ico AS char_ico\n  FROM tournament_match_ban_cards b\n  JOIN tournament_matches m ON m.id = b.match_id\n  LEFT JOIN unlight u ON u.id = b.char_id\n  WHERE m.tournament_id = :tid AND m.match_code = 'FINAL'\n  ORDER BY b.ban_side, b.id\n");
          $stmt->execute([':tid' => $tournamentId]);
          $banCards = ['p1' => [], 'p2' => []];
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $banCards[$row['ban_side']][] = [
                  'name' => $row['char_name'], 'level' => $row['char_level'], 'ico' => $row['char_ico'],
              ];
          }

          /* 冠軍三副 */
          $stmt = $pdo->prepare("\n  SELECT d.deck_no,\n         u1.id AS c1_id,u1.name AS c1_name,u1.level AS c1_level,u1.ico AS c1_ico,\n         u2.id AS c2_id,u2.name AS c2_name,u2.level AS c2_level,u2.ico AS c2_ico,\n         u3.id AS c3_id,u3.name AS c3_name,u3.level AS c3_level,u3.ico AS c3_ico\n  FROM tournament_player_decks d\n  LEFT JOIN unlight u1 ON u1.id = d.char1_id\n  LEFT JOIN unlight u2 ON u2.id = d.char2_id\n  LEFT JOIN unlight u3 ON u3.id = d.char3_id\n  WHERE d.tournament_id = :tid AND d.player_id = :pid\n  ORDER BY d.deck_no\n");
          $stmt->execute([':tid' => $tournamentId, ':pid' => $championId]);
          $championDecks = [];
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $code                 = chr(64 + (int) $row['deck_no']);
              $championDecks[$code] = [
                  'code'  => $code,
                  'cards' => [
                      ['id' => (int) $row['c1_id'], 'name' => $row['c1_name'], 'level' => $row['c1_level'], 'ico' => $row['c1_ico']],
                      ['id' => (int) $row['c2_id'], 'name' => $row['c2_name'], 'level' => $row['c2_level'], 'ico' => $row['c2_ico']],
                      ['id' => (int) $row['c3_id'], 'name' => $row['c3_name'], 'level' => $row['c3_level'], 'ico' => $row['c3_ico']],
                  ],
                  'picks' => 0, 'wins' => 0, 'losses' => 0, 'bans' => 0,
                  'note'  => '',
              ];
          }

          $stmt = $pdo->prepare("\n  SELECT deck_code, COUNT(*) picks, SUM(is_win) wins\n  FROM (\n    SELECT g.player1_deck_code deck_code, (g.winner_player_id=g.player1_id) is_win\n    FROM tournament_match_games g JOIN tournament_matches m ON m.id=g.match_id\n    WHERE g.tournament_id=:tid AND g.player1_id=:pid AND g.result_status='verified'\n      AND m.status IN ('confirmed','finished')\n    UNION ALL\n    SELECT g.player2_deck_code, (g.winner_player_id=g.player2_id)\n    FROM tournament_match_games g JOIN tournament_matches m ON m.id=g.match_id\n    WHERE g.tournament_id=:tid AND g.player2_id=:pid AND g.result_status='verified'\n      AND m.status IN ('confirmed','finished')\n  ) x\n  WHERE deck_code IS NOT NULL\n  GROUP BY deck_code\n");
          $stmt->execute([':tid' => $tournamentId, ':pid' => $championId]);
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $code = $row['deck_code'];
              if (! isset($championDecks[$code])) {
                  continue;
              }

              $championDecks[$code]['picks']  = (int) $row['picks'];
              $championDecks[$code]['wins']   = (int) $row['wins'];
              $championDecks[$code]['losses'] = (int) $row['picks'] - (int) $row['wins'];
          }

          $stmt = $pdo->prepare("\n  SELECT m.match_code,b.char_id\n  FROM tournament_match_ban_cards b JOIN tournament_matches m ON m.id=b.match_id\n  WHERE b.tournament_id=:tid\n    AND ((b.ban_side='p1' AND m.player1_id=:pid) OR (b.ban_side='p2' AND m.player2_id=:pid))\n  ORDER BY m.match_no,b.id\n");
          $stmt->execute([':tid' => $tournamentId, ':pid' => $championId]);
          $banGroups = [];
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
              $banGroups[$row['match_code']][] = (int) $row['char_id'];
          }

          foreach ($banGroups as $ids) {
              sort($ids);
              foreach ($championDecks as &$deck) {
                  $deckIds = array_map(fn($c) => (int) $c['id'], $deck['cards']);
                  sort($deckIds);
                  if ($deckIds === $ids) {
                      $deck['bans']++;
                  }

              }
              unset($deck);
          }
          if (isset($championDecks['A'])) {
              $championDecks['A']['note'] = '準決賽取得勝場；決賽遭到BAN。';
          }

          if (isset($championDecks['B'])) {
              $championDecks['B']['note'] = '準決賽與決賽皆完成勝場，是冠軍戰扳平比分的關鍵隊伍。';
          }

          if (isset($championDecks['C'])) {
              $championDecks['C']['note'] = '決賽首局失利後於決勝局再度出戰，最終完成冠軍勝場。';
          }

          /* 摘要 */
          $stmt = $pdo->prepare("\n  SELECT COUNT(*) completed_matches,\n    SUM(player1_score=0 AND player2_score=0) forfeit_matches,\n    SUM(NOT(player1_score=0 AND player2_score=0) AND ABS(player1_score-player2_score)=2) sweep_matches,\n    SUM(NOT(player1_score=0 AND player2_score=0) AND ABS(player1_score-player2_score)=1) full_set_matches\n  FROM tournament_matches\n  WHERE tournament_id=:tid AND status IN ('confirmed','finished') AND winner_player_id IS NOT NULL\n");
          $stmt->execute([':tid' => $tournamentId]);
          $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

          $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_match_games WHERE tournament_id=:tid AND result_status='verified'");
          $stmt->execute([':tid' => $tournamentId]);
          $verifiedGames = (int) $stmt->fetchColumn();

          $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_players WHERE tournament_id=:tid AND is_public=1");
          $stmt->execute([':tid' => $tournamentId]);
          $playerCount = (int) $stmt->fetchColumn();

          $placements = [
              ['icon' => '🏆', 'label' => '冠軍', 'name' => $championName],
              ['icon' => '🥈', 'label' => '亞軍', 'name' => $runnerUpName],
              ['icon' => '🥉', 'label' => '季軍', 'name' => $thirdName],
              ['icon' => '4', 'label' => '第四名', 'name' => $fourthName],
          ];
          $top8Names = ['花間與風', '羅馬', '嗷嗷龜', '海底窮人'];
      ?>

<style>
.fr-report{--bg:#0f1522;--panel:rgba(25,34,51,.97);--soft:rgba(255,255,255,.045);--line:rgba(255,255,255,.1);--text:#f7f8fb;--muted:#aeb8ca;--gold:#f3ca6b;--blue:#79b8ff;max-width:1180px;margin:0 auto;padding:18px 14px 56px;color:var(--text)}
.fr-report *{box-sizing:border-box}.fr-hero{position:relative;overflow:hidden;padding:36px;border-radius:26px;border:1px solid rgba(243,202,107,.25);background:radial-gradient(circle at 86% 10%,rgba(243,202,107,.24),transparent 30%),radial-gradient(circle at 15% 100%,rgba(121,184,255,.16),transparent 34%),linear-gradient(145deg,#1d2739,#101621 72%);box-shadow:0 22px 55px rgba(0,0,0,.3)}
.fr-hero:after{content:'CHAMPION';position:absolute;right:18px;bottom:-8px;font-size:clamp(56px,10vw,126px);font-weight:950;letter-spacing:-.08em;color:rgba(255,255,255,.035)}
.fr-kicker{display:inline-flex;padding:7px 12px;border-radius:999px;color:#ffe5a5;background:rgba(243,202,107,.1);border:1px solid rgba(243,202,107,.28);font-size:13px;font-weight:900;letter-spacing:.08em}.fr-title{position:relative;z-index:1;margin:14px 0 0;max-width:900px;font-size:clamp(31px,5vw,54px);line-height:1.14;letter-spacing:-.035em}.fr-subtitle{position:relative;z-index:1;margin-top:15px;max-width:820px;color:var(--muted);font-size:16px;line-height:1.85}
.fr-summary,.fr-placement,.fr-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:24px;position:relative;z-index:1}.fr-summary-card,.fr-stat,.fr-place-card{padding:17px;border-radius:17px;background:rgba(255,255,255,.055);border:1px solid var(--line)}.fr-summary-card span,.fr-stat span,.fr-place-card span{display:block;color:var(--muted);font-size:12px}.fr-summary-card strong,.fr-stat strong,.fr-place-card strong{display:block;margin-top:6px;font-size:21px}.fr-place-card:first-child{background:linear-gradient(145deg,rgba(243,202,107,.16),rgba(255,255,255,.04));border-color:rgba(243,202,107,.34)}
.fr-section{margin-top:26px;padding:26px;border-radius:22px;background:linear-gradient(180deg,rgba(25,34,51,.98),rgba(15,22,34,.98));border:1px solid var(--line);box-shadow:0 16px 40px rgba(0,0,0,.18)}.fr-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;margin-bottom:20px}.fr-head h2{margin:0;font-size:25px}.fr-head p{margin:6px 0 0;color:var(--muted);font-size:14px;line-height:1.7}
.fr-top8{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}.fr-pill{padding:7px 11px;border-radius:999px;color:#cbd5e1;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:13px;font-weight:800}.fr-ban-grid,.fr-final-grid,.fr-four-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.fr-ban,.fr-game,.fr-deck,.fr-four{padding:19px;border-radius:18px;background:var(--soft);border:1px solid var(--line)}.fr-label{color:var(--gold);font-size:12px;font-weight:900;letter-spacing:.05em}.fr-team-name{margin-top:7px;font-size:18px;font-weight:950}.fr-cards{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px}.fr-card{width:86px;overflow:hidden;border-radius:12px;background:#111827;border:1px solid rgba(255,255,255,.12)}.fr-card img{display:block;width:100%;height:112px;object-fit:cover}.fr-card-caption{padding:7px;min-height:53px}.fr-card-caption span{display:block;color:var(--gold);font-size:11px;font-weight:900}.fr-card-caption strong{display:block;margin-top:2px;color:#fff;font-size:12px;line-height:1.3}.fr-card.is-missing{min-height:165px;display:flex;align-items:flex-end}
.fr-game{grid-column:span 2}.fr-game-vs{display:grid;grid-template-columns:1fr auto 1fr;gap:14px;align-items:start;margin-top:15px}.fr-game-side:last-child{text-align:right}.fr-game-side:last-child .fr-cards{justify-content:flex-end}.fr-player{font-size:17px;font-weight:950}.fr-vs{padding-top:8px;color:rgba(255,255,255,.34);font-weight:950}.fr-result{margin-top:14px;padding:10px 12px;border-left:3px solid var(--gold);color:#f6e8be;background:rgba(243,202,107,.07);font-size:14px}.fr-story{margin-top:18px;padding:18px;border-radius:16px;color:#dce5f3;background:rgba(121,184,255,.07);border:1px solid rgba(121,184,255,.17);line-height:1.9}
.fr-road{display:grid;gap:11px}.fr-road-item{display:grid;grid-template-columns:105px minmax(0,1fr) auto;align-items:center;gap:14px;padding:13px 14px;border-radius:14px;background:var(--soft);border:1px solid var(--line)}.fr-road-code{color:var(--blue);font-weight:900}.fr-road-score{color:var(--gold);font-size:18px;font-weight:950}.fr-road-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px}.fr-stat strong{color:var(--gold);font-size:24px}
.fr-deck-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.fr-deck-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:13px}.fr-deck-meta div{padding:9px;border-radius:11px;background:rgba(0,0,0,.18);text-align:center}.fr-deck-meta strong{display:block;font-size:17px}.fr-deck-meta span{display:block;margin-top:3px;color:var(--muted);font-size:11px}.fr-note{margin-top:13px;color:var(--muted);font-size:13px;line-height:1.7}.fr-four-score{margin-top:7px;color:var(--gold);font-size:22px;font-weight:950}.fr-thanks{color:#dce5f3;line-height:1.9;font-size:15px}
@media(max-width:900px){.fr-summary,.fr-placement,.fr-meta,.fr-road-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.fr-deck-grid{grid-template-columns:1fr}}@media(max-width:700px){.fr-report{padding:12px 10px 40px}.fr-hero,.fr-section{padding:10px;border-radius:18px}.fr-summary,.fr-placement,.fr-meta,.fr-ban-grid,.fr-final-grid,.fr-four-grid,.fr-road-summary{grid-template-columns:1fr}.fr-game{grid-column:auto}.fr-game-vs{grid-template-columns:1fr}.fr-vs{text-align:center;padding:0}.fr-game-side:last-child{text-align:left}.fr-game-side:last-child .fr-cards{justify-content:flex-start}.fr-road-item{grid-template-columns:88px minmax(0,1fr) auto;gap:9px}.fr-card{width:78px}.fr-card img{height:101px}}
</style>

<div class="fr-report">
  <section class="fr-hero">
    <div class="fr-kicker">🏆 ULGG CUP #1｜FINAL REPORT</div>
    <h1 class="fr-title"><?php echo fr_e($report['report_title'] ?? '第一屆 UL.GG 杯落幕｜十六夜蝶登頂奪冠') ?></h1>
    <div class="fr-subtitle">決賽先失一局後完成逆轉，十六夜蝶最終以 2 比 1 擊敗對手，成為第一屆 UL.GG 杯冠軍。</div>
    <div class="fr-summary">
      <div class="fr-summary-card"><span>冠軍</span><strong><?php echo fr_e($championName) ?></strong></div>
      <div class="fr-summary-card"><span>亞軍</span><strong><?php echo fr_e($runnerUpName) ?></strong></div>
      <div class="fr-summary-card"><span>季軍</span><strong><?php echo fr_e($thirdName) ?></strong></div>
      <div class="fr-summary-card"><span>冠軍成績</span><strong><?php echo $matchWins ?>勝0敗／小局<?php echo $gameWins ?>勝<?php echo $gameLosses ?>敗</strong></div>
    </div>
  </section>

  <section class="fr-section">
    <div class="fr-head"><div><h2>🏅 最終名次</h2><p>第一屆 UL.GG 杯最終排名與八強名單。</p></div></div>
    <div class="fr-placement">
      <?php foreach ($placements as $p): ?>
        <div class="fr-place-card"><span><?php echo fr_e($p['icon'].' '.$p['label']) ?></span><strong><?php echo fr_e($p['name']) ?></strong></div>
      <?php endforeach; ?>
    </div>
    <div class="fr-top8"><?php foreach ($top8Names as $n): ?><span class="fr-pill">八強｜<?php echo fr_e($n) ?></span><?php endforeach; ?></div>
  </section>

  <section class="fr-section">
    <div class="fr-head"><div><h2>⚔️ 決賽戰報</h2><p>雙方BAN、三局隊伍與冠軍戰轉折。</p></div></div>
    <div class="fr-ban-grid">
      <div class="fr-ban"><div class="fr-label">十六夜蝶遭BAN隊伍</div><div class="fr-team-name"><?php echo fr_e(fr_team_name($banCards['p1'])) ?></div><div class="fr-cards"><?php fr_render_cards($banCards['p1']); ?></div></div>
      <div class="fr-ban"><div class="fr-label">打牌靠賽輕鬆遭BAN隊伍</div><div class="fr-team-name"><?php echo fr_e(fr_team_name($banCards['p2'])) ?></div><div class="fr-cards"><?php fr_render_cards($banCards['p2']); ?></div></div>
    </div>
    <div class="fr-final-grid" style="margin-top:16px">
      <?php foreach ($finalGames as $g): ?>
        <article class="fr-game">
          <div class="fr-label">FINAL｜GAME <?php echo (int)$g['game_no'] ?></div>
          <div class="fr-game-vs">
            <div class="fr-game-side"><div class="fr-player"><?php echo fr_e($g['p1_name']) ?></div><div class="fr-team-name"><?php echo fr_e(fr_team_name($g['p1_cards'])) ?></div><div class="fr-cards"><?php fr_render_cards($g['p1_cards']); ?></div></div>
            <div class="fr-vs">VS</div>
            <div class="fr-game-side"><div class="fr-player"><?php echo fr_e($g['p2_name']) ?></div><div class="fr-team-name"><?php echo fr_e(fr_team_name($g['p2_cards'])) ?></div><div class="fr-cards"><?php fr_render_cards($g['p2_cards']); ?></div></div>
          </div>
          <div class="fr-result">勝者：<strong><?php echo fr_e($g['winner_name']) ?></strong></div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="fr-story">決賽首局，打牌靠賽輕鬆率先以 L4 伊芙琳、R5 威廉、R5 米利安取得勝利。面對落後，十六夜蝶第二局改由 R4 碧姬媞、R1 史特靈、R5 威廉扳平比分。進入最終決勝局後，十六夜蝶再次派出首局落敗的 R4 尤莉卡、R4 沃肯、R5 米利安，最終完成修正並拿下勝利，以 2 比 1 登頂首屆冠軍。</div>
  </section>

  <section class="fr-section">
    <div class="fr-head"><div><h2>🛣️ 冠軍之路</h2><p>十六夜蝶完整通過五輪淘汰賽。</p></div></div>
    <div class="fr-road">
      <?php foreach ($championRoad as $r): $isP1 = (int) $r['player1_id'] === $championId;
              $opp                                            = $isP1 ? $r['p2_name'] : $r['p1_name'];
          $score                                          = $isP1 ? ((int) $r['player1_score'] . '–' . (int) $r['player2_score']) : ((int) $r['player2_score'] . '–' . (int) $r['player1_score']); ?>
        <div class="fr-road-item"><div class="fr-road-code"><?php echo fr_e($r['match_code']) ?></div><div><?php echo fr_e($r['round_name'].'｜'.$opp) ?></div><div class="fr-road-score"><?php echo fr_e($score) ?></div></div>
      <?php endforeach; ?>
    </div>
    <div class="fr-road-summary">
      <div class="fr-stat"><strong><?php echo $matchWins ?>–0</strong><span>系列賽成績</span></div>
      <div class="fr-stat"><strong><?php echo $gameWins ?>–<?php echo $gameLosses ?></strong><span>小局成績</span></div>
      <div class="fr-stat"><strong><?php echo $sweeps ?></strong><span>2比0場次</span></div>
      <div class="fr-stat"><strong><?php echo $fullSets ?></strong><span>2比1場次</span></div>
    </div>
  </section>

  <section class="fr-section">
    <div class="fr-head"><div><h2>🃏 冠軍登錄隊伍與實戰表現</h2></div></div>
    <div class="fr-deck-grid">
      <?php foreach ($championDecks as $d): ?>
        <article class="fr-deck"><div class="fr-label">冠軍登錄隊伍</div><div class="fr-team-name"><?php echo fr_e(fr_team_name($d['cards'])) ?></div><div class="fr-cards"><?php fr_render_cards($d['cards']); ?></div>
          <div class="fr-deck-meta"><div><strong><?php echo (int)$d['picks'] ?></strong><span>出場</span></div><div><strong><?php echo (int)$d['wins'] ?>勝<?php echo (int)$d['losses'] ?>敗</strong><span>實戰</span></div><div><strong><?php echo (int)$d['bans'] ?></strong><span>遭BAN</span></div></div>
          <div class="fr-note"><?php echo fr_e($d['note']) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="fr-section">
    <div class="fr-head"><div><h2>🏟️ 四強收官</h2><p>準決賽、季軍賽與決賽最終結果。</p></div></div>
    <div class="fr-four-grid">
      <?php foreach (['SF-1', 'SF-2', 'THIRD', 'FINAL'] as $code): if (empty($finalRows[$code])) {
                  continue;
              }

          $r = $finalRows[$code]; ?>
        <div class="fr-four"><div class="fr-label"><?php echo fr_e($r['round_name'].'｜'.$r['match_code']) ?></div><div class="fr-four-score"><?php echo fr_e($r['p1_name']) ?> <?php echo (int)$r['player1_score'] ?>–<?php echo (int)$r['player2_score'] ?> <?php echo fr_e($r['p2_name']) ?></div><div class="fr-note">勝者：<?php echo fr_e($r['winner_name']) ?></div></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="fr-section">
    <div class="fr-head"><div><h2>📊 賽事總覽</h2><p>完整Meta細節保留在即時Meta報告，本頁只呈現收官摘要。</p></div></div>
    <div class="fr-meta">
      <div class="fr-stat"><strong><?php echo $playerCount ?></strong><span>公開參賽者</span></div>
      <div class="fr-stat"><strong><?php echo (int)($summary['completed_matches']??0) ?></strong><span>已完成系列賽</span></div>
      <div class="fr-stat"><strong><?php echo $verifiedGames ?></strong><span>有效小局</span></div>
      <div class="fr-stat"><strong><?php echo (int)($summary['sweep_matches']??0) ?>／<?php echo (int)($summary['full_set_matches']??0) ?></strong><span>2比0／2比1場次</span></div>
    </div>
    <div class="fr-story">完整角色、BAN、選手、武器與事件卡統計，請查看 <a href="/pages/tournament/report.php?tid=<?php echo $tournamentId ?>&issue=live" style="color:#ffd479;font-weight:900">即時Meta統計</a>。</div>
  </section>

  <section class="fr-section"><div class="fr-head"><div><h2>🙏 第一屆回顧與感謝</h2></div></div><div class="fr-thanks">感謝 <?php echo $playerCount ?> 位參賽選手、協助賽程管理與資料回報的人員，以及所有觀看、直播與討論賽事的玩家。第一屆 UL.GG 杯從牌組公開、正式對戰、逐局紀錄、BAN統計一路走到首屆冠軍誕生，也為後續賽事與環境分析留下了完整的資料基礎。</div></section>
</div>
