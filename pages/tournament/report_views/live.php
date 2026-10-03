<?php

if (!isset($pdo, $report, $reportSections)) {
  die('請由 report.php 載入本頁');
}

$isAdmin = $isAdmin ?? false;
$tournamentId = (int)($report['tournament_id'] ?? ($_GET['tid'] ?? 1));

if (!function_exists('h')) {
  function h($value): string
  {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

function live_event_label(array $eventInfoMap, int $eventId): string
{
  $event = $eventInfoMap[$eventId] ?? null;
  if (!$event) return '事件卡 #' . $eventId;
  return (string)($event['name'] ?? ('事件卡 #' . $eventId));
}

function live_event_icon(array $eventInfoMap, int $eventId): string
{
  $event = $eventInfoMap[$eventId] ?? null;
  return $event ? (string)($event['ico'] ?? '') : '';
}

function live_event_cost(array $eventInfoMap, int $eventId): string
{
  $event = $eventInfoMap[$eventId] ?? null;
  if (!$event || $event['cost'] === null) return '-';
  return (string)(int)$event['cost'];
}
function live_pct($num, $den): string
{
  $num = (float)$num;
  $den = (float)$den;
  if ($den <= 0) return '0.0';
  return number_format($num / $den * 100, 1);
}

function live_round_name($roundKey): string
{
  $map = [
    'round1' => '第 1 回合',
    'round2' => '第 2 回合',
    'round3' => '第 3 回合',
    'semifinal' => '準決賽',
    'final' => '決賽',
  ];
  return $map[$roundKey] ?? (string)$roundKey;
}

$stmtSummary = $pdo->prepare("
  SELECT
    COUNT(*) AS completed_matches,
    SUM(CASE WHEN player1_score = 0 AND player2_score = 0 THEN 1 ELSE 0 END) AS forfeit_matches,
    SUM(CASE WHEN NOT (player1_score = 0 AND player2_score = 0) AND ABS(player1_score - player2_score) = 2 THEN 1 ELSE 0 END) AS sweep_matches,
    SUM(CASE WHEN NOT (player1_score = 0 AND player2_score = 0) AND ABS(player1_score - player2_score) = 1 THEN 1 ELSE 0 END) AS full_set_matches,
    MAX(updated_at) AS last_match_at
  FROM tournament_matches
  WHERE tournament_id = :tournament_id
    AND status IN ('confirmed', 'finished')
    AND winner_player_id IS NOT NULL
");
$stmtSummary->execute([':tournament_id' => $tournamentId]);
$matchSummary = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

$stmtGameSummary = $pdo->prepare("
  SELECT
    COUNT(*) AS total_games,
    SUM(CASE WHEN result_status = 'verified' THEN 1 ELSE 0 END) AS verified_games,
    SUM(CASE WHEN result_status = 'dispute' THEN 1 ELSE 0 END) AS dispute_games,
    SUM(CASE WHEN result_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_games,
    MAX(updated_at) AS last_game_at
  FROM tournament_match_games
  WHERE tournament_id = :tournament_id
");
$stmtGameSummary->execute([':tournament_id' => $tournamentId]);
$gameSummary = $stmtGameSummary->fetch(PDO::FETCH_ASSOC) ?: [];

$stmtPlayerSummary = $pdo->prepare("
  SELECT
    COUNT(*) AS total_players,
    SUM(
      CASE
        WHEN EXISTS (
          SELECT 1
          FROM tournament_matches m
          WHERE m.tournament_id = p.tournament_id
            AND m.winner_player_id IS NOT NULL
            AND m.winner_player_id <> p.id
            AND (m.player1_id = p.id OR m.player2_id = p.id)
            AND m.status IN ('confirmed', 'finished')
        )
        OR p.player_status IN ('eliminated','withdrawn','dq')
        THEN 1 ELSE 0
      END
    ) AS out_players,
    SUM(
      CASE
        WHEN NOT EXISTS (
          SELECT 1
          FROM tournament_matches m
          WHERE m.tournament_id = p.tournament_id
            AND m.winner_player_id IS NOT NULL
            AND m.winner_player_id <> p.id
            AND (m.player1_id = p.id OR m.player2_id = p.id)
            AND m.status IN ('confirmed', 'finished')
        )
        AND p.player_status NOT IN ('eliminated','withdrawn','dq')
        THEN 1 ELSE 0
      END
    ) AS active_players
  FROM tournament_players p
  WHERE p.tournament_id = :tournament_id
    AND p.is_public = 1
");
$stmtPlayerSummary->execute([':tournament_id' => $tournamentId]);
$playerSummary = $stmtPlayerSummary->fetch(PDO::FETCH_ASSOC) ?: [];

$stmtLatest = $pdo->prepare("
  SELECT
    m.match_code,
    m.round_key,
    m.round_name,
    m.player1_score,
    m.player2_score,
    m.updated_at,
    p1.display_name AS p1_name,
    p2.display_name AS p2_name,
    w.display_name AS winner_name
  FROM tournament_matches m
  LEFT JOIN tournament_players p1 ON p1.id = m.player1_id
  LEFT JOIN tournament_players p2 ON p2.id = m.player2_id
  LEFT JOIN tournament_players w ON w.id = m.winner_player_id
  WHERE m.tournament_id = :tournament_id
    AND m.status IN ('confirmed', 'finished')
    AND m.winner_player_id IS NOT NULL
  ORDER BY m.updated_at DESC, m.id DESC
  LIMIT 6
");
$stmtLatest->execute([':tournament_id' => $tournamentId]);
$latestMatches = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);

$stmtBan = $pdo->prepare("
  SELECT
  b.char_id,
  b.char_name,
  b.card_level,
  b.card_cost,
  u.ico AS char_ico,
    u.name AS db_char_name,
    u.level AS db_char_level,
    COUNT(*) AS ban_count,
    COUNT(DISTINCT b.match_id) AS banned_matches,
    SUM(
      CASE
        WHEN b.ban_side = 'p1' AND m.winner_player_id = m.player1_id THEN 1
        WHEN b.ban_side = 'p2' AND m.winner_player_id = m.player2_id THEN 1
        ELSE 0
      END
    ) AS banned_side_wins,
    SUM(
      CASE
        WHEN b.ban_side = 'p1' AND m.winner_player_id = m.player2_id THEN 1
        WHEN b.ban_side = 'p2' AND m.winner_player_id = m.player1_id THEN 1
        ELSE 0
      END
    ) AS banned_side_losses
  FROM tournament_match_ban_cards b
  JOIN tournament_matches m ON m.id = b.match_id
  LEFT JOIN unlight u ON u.id = b.char_id
  WHERE b.tournament_id = :tournament_id
    AND m.status IN ('confirmed', 'finished')
    AND m.winner_player_id IS NOT NULL
  GROUP BY b.char_id, b.char_name, b.card_level, b.card_cost, u.ico, u.name, u.level
  ORDER BY ban_count DESC, banned_matches DESC, banned_side_wins DESC, b.char_id ASC
  LIMIT 10
");
$stmtBan->execute([':tournament_id' => $tournamentId]);
$banRows = $stmtBan->fetchAll(PDO::FETCH_ASSOC);

$stmtBanCharacter = $pdo->prepare("
  SELECT
    base.character_name,
    base.char_ico,
    base.min_cost,
    base.max_cost,
    base.ban_count,
    base.banned_matches,
    base.banned_card_count,
    base.banned_levels,
    base.banned_side_wins,
    tm.common_teammates
  FROM (
    SELECT
      COALESCE(u.name, b.char_name) AS character_name,
      SUBSTRING_INDEX(
        GROUP_CONCAT(u.ico ORDER BY b.card_cost DESC, b.char_id DESC SEPARATOR ','),
        ',',
        1
      ) AS char_ico,
      MIN(NULLIF(b.card_cost, 0)) AS min_cost,
      MAX(b.card_cost) AS max_cost,
      COUNT(*) AS ban_count,
      COUNT(DISTINCT b.match_id) AS banned_matches,
      COUNT(DISTINCT b.char_id) AS banned_card_count,
      GROUP_CONCAT(
        DISTINCT COALESCE(u.level, b.card_level)
        ORDER BY b.char_id ASC
        SEPARATOR ' / '
      ) AS banned_levels,
      SUM(
        CASE
          WHEN b.ban_side = 'p1' AND m.winner_player_id = m.player1_id THEN 1
          WHEN b.ban_side = 'p2' AND m.winner_player_id = m.player2_id THEN 1
          ELSE 0
        END
      ) AS banned_side_wins
    FROM tournament_match_ban_cards b
    JOIN tournament_matches m ON m.id = b.match_id
    LEFT JOIN unlight u ON u.id = b.char_id
    WHERE b.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND m.winner_player_id IS NOT NULL
    GROUP BY COALESCE(u.name, b.char_name)
  ) base

  LEFT JOIN (
    SELECT
      ranked.main_name,
      GROUP_CONCAT(
  CONCAT(ranked.teammate_levels, ' ', ranked.teammate_name, ' ×', ranked.together_ban_count)
  ORDER BY ranked.together_ban_count DESC, ranked.teammate_name ASC
  SEPARATOR ' / '
) AS common_teammates
    FROM (
      SELECT
        pair_counts.*,
        ROW_NUMBER() OVER (
          PARTITION BY pair_counts.main_name
          ORDER BY pair_counts.together_ban_count DESC, pair_counts.teammate_name ASC
        ) AS rn
      FROM (
        SELECT
  COALESCE(u1.name, b1.char_name) AS main_name,
  COALESCE(u2.name, b2.char_name) AS teammate_name,
  GROUP_CONCAT(
    DISTINCT COALESCE(u2.level, b2.card_level)
    ORDER BY b2.char_id ASC
    SEPARATOR '/'
  ) AS teammate_levels,
  COUNT(*) AS together_ban_count
        FROM tournament_match_ban_cards b1
        JOIN tournament_match_ban_cards b2
          ON b2.tournament_id = b1.tournament_id
          AND b2.match_id = b1.match_id
          AND b2.ban_side = b1.ban_side
          AND b2.char_id <> b1.char_id
        JOIN tournament_matches m ON m.id = b1.match_id
        LEFT JOIN unlight u1 ON u1.id = b1.char_id
        LEFT JOIN unlight u2 ON u2.id = b2.char_id
        WHERE b1.tournament_id = :tournament_id
          AND m.status IN ('confirmed', 'finished')
          AND m.winner_player_id IS NOT NULL
        GROUP BY
          COALESCE(u1.name, b1.char_name),
          COALESCE(u2.name, b2.char_name)
      ) pair_counts
    ) ranked
    WHERE ranked.rn <= 2
    GROUP BY ranked.main_name
  ) tm ON tm.main_name = base.character_name

  ORDER BY base.ban_count DESC, base.banned_matches DESC, base.banned_side_wins DESC, base.character_name ASC
  LIMIT 10
");
$stmtBanCharacter->execute([':tournament_id' => $tournamentId]);
$banCharacterRows = $stmtBanCharacter->fetchAll(PDO::FETCH_ASSOC);



$stmtCharacterMeta = $pdo->prepare("
  SELECT
  x.char_id,
  u.name AS char_name,
  u.level AS char_level,
  u.ico AS char_ico,
  u.cost AS char_cost,
  COUNT(*) AS appearances,
  SUM(x.is_win) AS wins,
  SUM(x.is_loss) AS losses,
  COALESCE(bc.ban_count, 0) AS ban_count,
  COUNT(*) + COALESCE(bc.ban_count, 0) AS total_pressure
  FROM (
    SELECT d.char1_id AS char_id,
      CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END AS is_win,
      CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END AS is_loss
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    JOIN tournament_player_decks d
      ON d.tournament_id = g.tournament_id
      AND d.player_id = g.player1_id
      AND d.deck_no = ASCII(g.player1_deck_code) - 64
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player1_deck_code IS NOT NULL

    UNION ALL

    SELECT d.char2_id,
      CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END,
      CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    JOIN tournament_player_decks d
      ON d.tournament_id = g.tournament_id
      AND d.player_id = g.player1_id
      AND d.deck_no = ASCII(g.player1_deck_code) - 64
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player1_deck_code IS NOT NULL

    UNION ALL

    SELECT d.char3_id,
      CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END,
      CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    JOIN tournament_player_decks d
      ON d.tournament_id = g.tournament_id
      AND d.player_id = g.player1_id
      AND d.deck_no = ASCII(g.player1_deck_code) - 64
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player1_deck_code IS NOT NULL

    UNION ALL

    SELECT d.char1_id,
      CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END,
      CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    JOIN tournament_player_decks d
      ON d.tournament_id = g.tournament_id
      AND d.player_id = g.player2_id
      AND d.deck_no = ASCII(g.player2_deck_code) - 64
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player2_deck_code IS NOT NULL

    UNION ALL

    SELECT d.char2_id,
      CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END,
      CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    JOIN tournament_player_decks d
      ON d.tournament_id = g.tournament_id
      AND d.player_id = g.player2_id
      AND d.deck_no = ASCII(g.player2_deck_code) - 64
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player2_deck_code IS NOT NULL

    UNION ALL

    SELECT d.char3_id,
      CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END,
      CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    JOIN tournament_player_decks d
      ON d.tournament_id = g.tournament_id
      AND d.player_id = g.player2_id
      AND d.deck_no = ASCII(g.player2_deck_code) - 64
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player2_deck_code IS NOT NULL
  ) x
  LEFT JOIN unlight u ON u.id = x.char_id
LEFT JOIN (
  SELECT char_id, COUNT(*) AS ban_count
  FROM tournament_match_ban_cards
  WHERE tournament_id = :tournament_id
  GROUP BY char_id
) bc ON bc.char_id = x.char_id
GROUP BY x.char_id, u.name, u.level, u.ico, u.cost, bc.ban_count
ORDER BY
  total_pressure DESC,
  (SUM(x.is_win) / NULLIF(COUNT(*), 0)) DESC,
  wins DESC,
  appearances DESC,
  ban_count DESC,
  x.char_id ASC
");
$stmtCharacterMeta->execute([
  ':tournament_id' => $tournamentId,
]);
$characterMetaRows = $stmtCharacterMeta->fetchAll(PDO::FETCH_ASSOC);

$stmtCharacterTeammates = $pdo->prepare("
  WITH side_decks AS (
    SELECT
      d.char1_id,
      d.char2_id,
      d.char3_id
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    JOIN tournament_player_decks d
      ON d.tournament_id = g.tournament_id
      AND d.player_id = g.player1_id
      AND d.deck_no = ASCII(g.player1_deck_code) - 64
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player1_deck_code IS NOT NULL

    UNION ALL

    SELECT
      d.char1_id,
      d.char2_id,
      d.char3_id
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    JOIN tournament_player_decks d
      ON d.tournament_id = g.tournament_id
      AND d.player_id = g.player2_id
      AND d.deck_no = ASCII(g.player2_deck_code) - 64
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player2_deck_code IS NOT NULL
  ),
  pairs AS (
    SELECT char1_id AS main_char_id, char2_id AS teammate_char_id FROM side_decks
    UNION ALL SELECT char1_id, char3_id FROM side_decks
    UNION ALL SELECT char2_id, char1_id FROM side_decks
    UNION ALL SELECT char2_id, char3_id FROM side_decks
    UNION ALL SELECT char3_id, char1_id FROM side_decks
    UNION ALL SELECT char3_id, char2_id FROM side_decks
  ),
  pair_counts AS (
    SELECT
      main_char_id,
      teammate_char_id,
      COUNT(*) AS together_count
    FROM pairs
    WHERE main_char_id IS NOT NULL
      AND teammate_char_id IS NOT NULL
      AND main_char_id > 0
      AND teammate_char_id > 0
    GROUP BY main_char_id, teammate_char_id
  ),
  ranked AS (
    SELECT
      pc.*,
      ROW_NUMBER() OVER (
        PARTITION BY pc.main_char_id
        ORDER BY pc.together_count DESC, pc.teammate_char_id ASC
      ) AS rn
    FROM pair_counts pc
  )
  SELECT
    r.main_char_id,
    GROUP_CONCAT(
      CONCAT(
        COALESCE(u.level, ''),
        ' ',
        COALESCE(u.name, CONCAT('角色#', r.teammate_char_id)),
        ' ×',
        r.together_count
      )
      ORDER BY r.together_count DESC, r.teammate_char_id ASC
      SEPARATOR ' / '
    ) AS common_teammates
  FROM ranked r
  LEFT JOIN unlight u ON u.id = r.teammate_char_id
  WHERE r.rn <= 2
  GROUP BY r.main_char_id
");
$stmtCharacterTeammates->execute([':tournament_id' => $tournamentId]);

$characterTeammateMap = [];
while ($row = $stmtCharacterTeammates->fetch(PDO::FETCH_ASSOC)) {
  $characterTeammateMap[(int)$row['main_char_id']] = (string)($row['common_teammates'] ?? '');
}

/* $stmtDeckMeta = $pdo->prepare("
  SELECT
    y.player_id,
    y.player_name,
    y.deck_code,
    y.appearances,
    y.wins,
    y.losses,
    d.deck_label,
    d.char1_id,
    d.char2_id,
    d.char3_id,
    u1.name AS char1_name,
    u1.level AS char1_level,
    u1.ico AS char1_ico,
    u2.name AS char2_name,
    u2.level AS char2_level,
    u2.ico AS char2_ico,
    u3.name AS char3_name,
    u3.level AS char3_level,
    u3.ico AS char3_ico
  FROM (
    SELECT
      player_id,
      player_name,
      deck_code,
      COUNT(*) AS appearances,
      SUM(is_win) AS wins,
      SUM(is_loss) AS losses
    FROM (
      SELECT
        g.player1_id AS player_id,
        p1.display_name AS player_name,
        g.player1_deck_code AS deck_code,
        CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END AS is_win,
        CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END AS is_loss
      FROM tournament_match_games g
      JOIN tournament_matches m ON m.id = g.match_id
      LEFT JOIN tournament_players p1 ON p1.id = g.player1_id
      WHERE g.tournament_id = :tournament_id
        AND m.status IN ('confirmed', 'finished')
        AND g.result_status = 'verified'
        AND g.player1_deck_code IS NOT NULL

      UNION ALL

      SELECT
        g.player2_id,
        p2.display_name,
        g.player2_deck_code,
        CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END,
        CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END
      FROM tournament_match_games g
      JOIN tournament_matches m ON m.id = g.match_id
      LEFT JOIN tournament_players p2 ON p2.id = g.player2_id
      WHERE g.tournament_id = :tournament_id
        AND m.status IN ('confirmed', 'finished')
        AND g.result_status = 'verified'
        AND g.player2_deck_code IS NOT NULL
    ) x
    GROUP BY player_id, player_name, deck_code
  ) y
  LEFT JOIN tournament_player_decks d
    ON d.tournament_id = :tournament_id
    AND d.player_id = y.player_id
    AND d.deck_no = ASCII(y.deck_code) - 64
  LEFT JOIN unlight u1 ON u1.id = d.char1_id
  LEFT JOIN unlight u2 ON u2.id = d.char2_id
  LEFT JOIN unlight u3 ON u3.id = d.char3_id
  WHERE  (y.wins / NULLIF(y.appearances, 0)) > 0.5
ORDER BY
  (y.wins / NULLIF(y.appearances, 0)) DESC,
  y.wins DESC,
  y.appearances DESC,
  y.player_name ASC,
  y.deck_code ASC
");
$stmtDeckMeta->execute([':tournament_id' => $tournamentId]);
$deckMetaRows = $stmtDeckMeta->fetchAll(PDO::FETCH_ASSOC); */

function live_decode_event_cards($json): array
{
  $json = trim((string)$json);
  if ($json === '') return [];

  $arr = json_decode($json, true);
  if (!is_array($arr)) return [];

  $cards = [];
  foreach ($arr as $id) {
    $id = (int)$id;
    if ($id > 0) $cards[] = $id;
  }
  return $cards;
}

function live_sorted_chars(array $chars): array
{
  $chars = array_map('intval', $chars);
  sort($chars);
  return $chars;
}

function live_same_char_set(array $a, array $b): bool
{
  return live_sorted_chars($a) === live_sorted_chars($b);
}

$eventCardStats = [];
$playerEventStats = [];
$charEventStats = [];
$totalEventDeckSamples = 0;



$stmtEventGames = $pdo->prepare("
  SELECT
    g.id,
    g.game_no,
    g.player1_id,
    g.player2_id,
    g.winner_player_id,
    g.player1_char1_id,
    g.player1_char2_id,
    g.player1_char3_id,
    g.player2_char1_id,
    g.player2_char2_id,
    g.player2_char3_id,
    p1.display_name AS p1_name,
    p2.display_name AS p2_name,
    a.e1,
    a.e2,
    a.e3,
    a.u1,
    a.u2,
    a.u3,
    a.eventindex1,
    a.eventindex2
  FROM tournament_match_games g
  JOIN tournament_matches m ON m.id = g.match_id
  JOIN arena_unlight a ON a.id = g.arena_id
  LEFT JOIN tournament_players p1 ON p1.id = g.player1_id
  LEFT JOIN tournament_players p2 ON p2.id = g.player2_id
  WHERE g.tournament_id = :tournament_id
    AND m.status IN ('confirmed', 'finished')
    AND g.result_status = 'verified'
    AND g.arena_id IS NOT NULL
");
$stmtEventGames->execute([':tournament_id' => $tournamentId]);
$eventGameRows = $stmtEventGames->fetchAll(PDO::FETCH_ASSOC);

foreach ($eventGameRows as $game) {
  $arenaEChars = [(int)$game['e1'], (int)$game['e2'], (int)$game['e3']];
  $arenaUChars = [(int)$game['u1'], (int)$game['u2'], (int)$game['u3']];

  $p1Chars = [(int)$game['player1_char1_id'], (int)$game['player1_char2_id'], (int)$game['player1_char3_id']];
  $p2Chars = [(int)$game['player2_char1_id'], (int)$game['player2_char2_id'], (int)$game['player2_char3_id']];

  $p1Events = [];
  $p2Events = [];

  if (live_same_char_set($p1Chars, $arenaEChars)) {
    $p1Events = live_decode_event_cards($game['eventindex1']);
  } elseif (live_same_char_set($p1Chars, $arenaUChars)) {
    $p1Events = live_decode_event_cards($game['eventindex2']);
  }

  if (live_same_char_set($p2Chars, $arenaEChars)) {
    $p2Events = live_decode_event_cards($game['eventindex1']);
  } elseif (live_same_char_set($p2Chars, $arenaUChars)) {
    $p2Events = live_decode_event_cards($game['eventindex2']);
  }

  $sides = [
    [
      'player_id' => (int)$game['player1_id'],
      'player_name' => (string)$game['p1_name'],
      'is_win' => (int)$game['winner_player_id'] === (int)$game['player1_id'],
      'cards' => $p1Events,
      'chars' => $p1Chars,
    ],
    [
      'player_id' => (int)$game['player2_id'],
      'player_name' => (string)$game['p2_name'],
      'is_win' => (int)$game['winner_player_id'] === (int)$game['player2_id'],
      'cards' => $p2Events,
      'chars' => $p2Chars,
    ],
  ];

  foreach ($sides as $sideIndex => $side) {
    if (empty($side['cards'])) continue;

    $totalEventDeckSamples++;

    $sideKey = (int)$game['id'] . ':' . $sideIndex . ':' . (int)$side['player_id'];
    $uniqueCardsInSide = [];

    foreach ($side['cards'] as $eventId) {
      $eventId = (int)$eventId;
      if ($eventId <= 0) continue;

      if (!isset($eventCardStats[$eventId])) {
        $eventCardStats[$eventId] = [
          'event_id' => $eventId,
          'used_count' => 0,
          'deck_map' => [],
          'win_deck_map' => [],
        ];
      }

      $eventCardStats[$eventId]['used_count']++;
      $uniqueCardsInSide[$eventId] = true;
    }

    foreach (array_keys($uniqueCardsInSide) as $eventId) {
      $eventCardStats[$eventId]['deck_map'][$sideKey] = true;

      if ($side['is_win']) {
        $eventCardStats[$eventId]['win_deck_map'][$sideKey] = true;
      }
    }

    foreach (($side['chars'] ?? []) as $charId) {
      $charId = (int)$charId;
      if ($charId <= 0) continue;

      foreach ($side['cards'] as $eventId) {
        $eventId = (int)$eventId;
        if ($eventId <= 0) continue;

        $key = $charId . ':' . $eventId;

        if (!isset($charEventStats[$key])) {
          $charEventStats[$key] = [
            'char_id' => $charId,
            'event_id' => $eventId,
            'used_count' => 0,
            'wins' => 0,
            'losses' => 0,
            'deck_map' => [],
            'win_deck_map' => [],
          ];
        }

        $charEventStats[$key]['used_count']++;
        $side['is_win'] ? $charEventStats[$key]['wins']++ : $charEventStats[$key]['losses']++;
      }

      foreach (array_keys($uniqueCardsInSide) as $eventId) {
        $key = $charId . ':' . $eventId;
        $charEventStats[$key]['deck_map'][$sideKey] = true;

        if ($side['is_win']) {
          $charEventStats[$key]['win_deck_map'][$sideKey] = true;
        }
      }
    }

    $playerId = (int)$side['player_id'];
    if ($playerId > 0) {
      if (!isset($playerEventStats[$playerId])) {
        $playerEventStats[$playerId] = [
          'player_id' => $playerId,
          'player_name' => $side['player_name'],
          'used_count' => 0,
          'wins' => 0,
          'losses' => 0,
          'cards' => [],
        ];
      }

      $playerEventStats[$playerId]['used_count'] += count($side['cards']);
      $side['is_win'] ? $playerEventStats[$playerId]['wins']++ : $playerEventStats[$playerId]['losses']++;

      foreach ($side['cards'] as $eventId) {
        $eventId = (int)$eventId;
        if ($eventId <= 0) continue;

        $playerEventStats[$playerId]['cards'][$eventId] = ($playerEventStats[$playerId]['cards'][$eventId] ?? 0) + 1;
      }
    }
  }
}


$allEventIds = array_keys($eventCardStats);

$eventInfoMap = [];
if (!empty($allEventIds)) {
  $eventPlaceholders = implode(',', array_fill(0, count($allEventIds), '?'));
  $stmtEvents = $pdo->prepare("
    SELECT
      id,
      ico,
      name,
      cost,
      sword,
      gun,
      shield,
      shift,
      special
    FROM unlight_eventindex
    WHERE id IN ($eventPlaceholders)
  ");
  $stmtEvents->execute(array_values($allEventIds));

  while ($event = $stmtEvents->fetch(PDO::FETCH_ASSOC)) {
    $eventInfoMap[(int)$event['id']] = $event;
  }
}

foreach ($eventCardStats as $eventId => &$row) {
  $row['deck_count'] = count($row['deck_map']);
  $row['win_deck_count'] = count($row['win_deck_map']);

  unset($row['deck_map'], $row['win_deck_map']);
}
unset($row);



usort($eventCardStats, function ($a, $b) {
  $aDeckCount = (int)($a['deck_count'] ?? 0);
  $bDeckCount = (int)($b['deck_count'] ?? 0);

  $aWinRate = $aDeckCount > 0 ? ((int)($a['win_deck_count'] ?? 0) / $aDeckCount) : 0;
  $bWinRate = $bDeckCount > 0 ? ((int)($b['win_deck_count'] ?? 0) / $bDeckCount) : 0;

  if (abs($aWinRate - $bWinRate) > 0.0001) {
    return $aWinRate < $bWinRate ? 1 : -1;
  }

  if ($aDeckCount !== $bDeckCount) {
    return $bDeckCount <=> $aDeckCount;
  }

  if ((int)$a['used_count'] !== (int)$b['used_count']) {
    return (int)$b['used_count'] <=> (int)$a['used_count'];
  }

  return (int)$a['event_id'] <=> (int)$b['event_id'];
});



usort($playerEventStats, function ($a, $b) {
  if ($a['used_count'] !== $b['used_count']) return $b['used_count'] <=> $a['used_count'];
  return strcmp($a['player_name'], $b['player_name']);
});



$eventCardStats = array_values(array_filter($eventCardStats, function ($row) {
  return (int)($row['win_deck_count'] ?? 0) > 0
    && (int)($row['deck_count'] ?? 0) > 0
    && (int)($row['used_count'] ?? 0) > 1;
}));

$eventCardStats = array_slice($eventCardStats, 0, 10);
$playerEventStats = array_slice($playerEventStats, 0, 10);


foreach ($charEventStats as $key => &$row) {
  $row['deck_count'] = count($row['deck_map']);
  $row['win_deck_count'] = count($row['win_deck_map']);

  $deckCount = (int)$row['deck_count'];
  $winDeckCount = (int)$row['win_deck_count'];
  $deckWinRate = $deckCount > 0 ? ($winDeckCount / $deckCount) : 0;

  $row['weighted_score'] = $deckCount * (0.7 + ($deckWinRate * 0.3));

  unset($row['deck_map'], $row['win_deck_map']);
}
unset($row);

usort($charEventStats, function ($a, $b) {
  if (abs($a['weighted_score'] - $b['weighted_score']) > 0.0001) {
    return $a['weighted_score'] < $b['weighted_score'] ? 1 : -1;
  }

  if ($a['deck_count'] !== $b['deck_count']) {
    return $b['deck_count'] <=> $a['deck_count'];
  }

  if ($a['win_deck_count'] !== $b['win_deck_count']) {
    return $b['win_deck_count'] <=> $a['win_deck_count'];
  }

  if ($a['used_count'] !== $b['used_count']) {
    return $b['used_count'] <=> $a['used_count'];
  }

  return $a['event_id'] <=> $b['event_id'];
});

$charEventStatsAll = $charEventStats;

usort($charEventStats, function ($a, $b) {
  if (abs($a['weighted_score'] - $b['weighted_score']) > 0.0001) {
    return $a['weighted_score'] < $b['weighted_score'] ? 1 : -1;
  }

  if ($a['deck_count'] !== $b['deck_count']) {
    return $b['deck_count'] <=> $a['deck_count'];
  }

  if ($a['win_deck_count'] !== $b['win_deck_count']) {
    return $b['win_deck_count'] <=> $a['win_deck_count'];
  }

  if ($a['used_count'] !== $b['used_count']) {
    return $b['used_count'] <=> $a['used_count'];
  }

  return $a['event_id'] <=> $b['event_id'];
});

$eventCrossSourceRows = $eventCardStats;

$eventCrossSourceRows = array_values(array_filter($eventCardStats, function ($row) {
  $deckCount = (int)($row['deck_count'] ?? 0);
  $winDeckCount = (int)($row['win_deck_count'] ?? 0);

  return $deckCount >= 3
    && $winDeckCount > 0
    && ($winDeckCount / max(1, $deckCount)) >= 0.5;
}));

usort($eventCrossSourceRows, function ($a, $b) {
  $aDeckCount = (int)($a['deck_count'] ?? 0);
  $bDeckCount = (int)($b['deck_count'] ?? 0);

  $aWinRate = $aDeckCount > 0 ? ((int)($a['win_deck_count'] ?? 0) / $aDeckCount) : 0;
  $bWinRate = $bDeckCount > 0 ? ((int)($b['win_deck_count'] ?? 0) / $bDeckCount) : 0;

  if (abs($aWinRate - $bWinRate) > 0.0001) {
    return $aWinRate < $bWinRate ? 1 : -1;
  }

  if ($aDeckCount !== $bDeckCount) {
    return $bDeckCount <=> $aDeckCount;
  }

  if ((int)$a['used_count'] !== (int)$b['used_count']) {
    return (int)$b['used_count'] <=> (int)$a['used_count'];
  }

  return (int)$a['event_id'] <=> (int)$b['event_id'];
});

$eventCrossRows = [];

foreach (array_slice($eventCrossSourceRows, 0, 10) as $eventRow) {
  $eventId = (int)$eventRow['event_id'];

  $eventCrossRows[$eventId] = [
    'event_id' => $eventId,
    'event_stat' => $eventRow,
    'chars' => [],
  ];
}

foreach ($charEventStatsAll as $row) {
  $eventId = (int)$row['event_id'];
  if (!isset($eventCrossRows[$eventId])) continue;

  $deckCount = (int)($row['deck_count'] ?? 0);
  $winDeckCount = (int)($row['win_deck_count'] ?? 0);
  $winRate = $deckCount > 0 ? ($winDeckCount / $deckCount) : 0;

  if ($deckCount < 2) continue;
  if ($winDeckCount <= 0) continue;
  if ($winRate < 0.5) continue;

  $eventCrossRows[$eventId]['chars'][] = $row;
}

foreach ($eventCrossRows as $eventId => &$group) {
  usort($group['chars'], function ($a, $b) {
    if ((int)$a['deck_count'] !== (int)$b['deck_count']) {
      return (int)$b['deck_count'] <=> (int)$a['deck_count'];
    }

    $aDeckCount = (int)($a['deck_count'] ?? 0);
    $bDeckCount = (int)($b['deck_count'] ?? 0);

    $aWinRate = $aDeckCount > 0 ? ((int)($a['win_deck_count'] ?? 0) / $aDeckCount) : 0;
    $bWinRate = $bDeckCount > 0 ? ((int)($b['win_deck_count'] ?? 0) / $bDeckCount) : 0;

    if (abs($aWinRate - $bWinRate) > 0.0001) {
      return $aWinRate < $bWinRate ? 1 : -1;
    }

    if ((int)$a['used_count'] !== (int)$b['used_count']) {
      return (int)$b['used_count'] <=> (int)$a['used_count'];
    }

    return (int)$a['char_id'] <=> (int)$b['char_id'];
  });

  $group['chars'] = array_slice($group['chars'], 0, 5);
}
unset($group);

$eventCrossRows = array_values(array_filter($eventCrossRows, function ($group) {
  return !empty($group['chars']);
}));

$eventCrossCharIds = [];
foreach ($eventCrossRows as $group) {
  foreach ($group['chars'] as $row) {
    $eventCrossCharIds[(int)$row['char_id']] = (int)$row['char_id'];
  }
}

$charEventInfoMap = [];
if (!empty($eventCrossCharIds)) {
  $charPlaceholders = implode(',', array_fill(0, count($eventCrossCharIds), '?'));

  $stmtCharEvents = $pdo->prepare("
    SELECT
      id,
      ico,
      name,
      level
    FROM unlight
    WHERE id IN ($charPlaceholders)
  ");

  $stmtCharEvents->execute(array_values($eventCrossCharIds));

  while ($char = $stmtCharEvents->fetch(PDO::FETCH_ASSOC)) {
    $charEventInfoMap[(int)$char['id']] = $char;
  }
}


$liveWeaponIconMap = [
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
  84 => '84.jpg',
];

$liveCharWeaponPairs = [];

$stmtWeaponGames = $pdo->prepare("
  SELECT
    g.id,
    g.player1_id,
    g.player2_id,
    g.winner_player_id,
    g.player1_char1_id,
    g.player1_char2_id,
    g.player1_char3_id,
    g.player2_char1_id,
    g.player2_char2_id,
    g.player2_char3_id,
    a.e1,
    a.e2,
    a.e3,
    a.u1,
    a.u2,
    a.u3,
    a.w1,
    a.w2,
    a.w3,
    a.v1,
    a.v2,
    a.v3
  FROM tournament_match_games g
  JOIN tournament_matches m ON m.id = g.match_id
  JOIN arena_unlight a ON a.id = g.arena_id
  WHERE g.tournament_id = :tournament_id
    AND m.status IN ('confirmed', 'finished')
    AND g.result_status = 'verified'
    AND g.arena_id IS NOT NULL
");
$stmtWeaponGames->execute([':tournament_id' => $tournamentId]);
$weaponGameRows = $stmtWeaponGames->fetchAll(PDO::FETCH_ASSOC);

$charWeaponStats = [];

foreach ($weaponGameRows as $game) {
  $arenaEChars = [(int)$game['e1'], (int)$game['e2'], (int)$game['e3']];
  $arenaUChars = [(int)$game['u1'], (int)$game['u2'], (int)$game['u3']];

  $p1Chars = [(int)$game['player1_char1_id'], (int)$game['player1_char2_id'], (int)$game['player1_char3_id']];
  $p2Chars = [(int)$game['player2_char1_id'], (int)$game['player2_char2_id'], (int)$game['player2_char3_id']];

  $eSlots = [
    ['char_id' => (int)$game['e1'], 'weapon_id' => (int)($game['w1'] ?? 0)],
    ['char_id' => (int)$game['e2'], 'weapon_id' => (int)($game['w2'] ?? 0)],
    ['char_id' => (int)$game['e3'], 'weapon_id' => (int)($game['w3'] ?? 0)],
  ];

  $uSlots = [
    ['char_id' => (int)$game['u1'], 'weapon_id' => (int)($game['v1'] ?? 0)],
    ['char_id' => (int)$game['u2'], 'weapon_id' => (int)($game['v2'] ?? 0)],
    ['char_id' => (int)$game['u3'], 'weapon_id' => (int)($game['v3'] ?? 0)],
  ];

  $sides = [];

  if (live_same_char_set($p1Chars, $arenaEChars)) {
    $sides[] = [
      'is_win' => (int)$game['winner_player_id'] === (int)$game['player1_id'],
      'slots' => $eSlots,
    ];
  } elseif (live_same_char_set($p1Chars, $arenaUChars)) {
    $sides[] = [
      'is_win' => (int)$game['winner_player_id'] === (int)$game['player1_id'],
      'slots' => $uSlots,
    ];
  }

  if (live_same_char_set($p2Chars, $arenaEChars)) {
    $sides[] = [
      'is_win' => (int)$game['winner_player_id'] === (int)$game['player2_id'],
      'slots' => $eSlots,
    ];
  } elseif (live_same_char_set($p2Chars, $arenaUChars)) {
    $sides[] = [
      'is_win' => (int)$game['winner_player_id'] === (int)$game['player2_id'],
      'slots' => $uSlots,
    ];
  }

  foreach ($sides as $side) {
    foreach ($side['slots'] as $slot) {
      $charId = (int)($slot['char_id'] ?? 0);
      $weaponId = (int)($slot['weapon_id'] ?? 0);

      if ($charId <= 0 || $weaponId <= 0) continue;

      $key = $charId . ':' . $weaponId;

      if (!isset($charWeaponStats[$key])) {
        $charWeaponStats[$key] = [
          'char_id' => $charId,
          'weapon_id' => $weaponId,
          'used' => 0,
          'wins' => 0,
          'losses' => 0,
        ];
      }

      $charWeaponStats[$key]['used']++;

      if (!empty($side['is_win'])) {
        $charWeaponStats[$key]['wins']++;
      } else {
        $charWeaponStats[$key]['losses']++;
      }
    }
  }
}

$weaponCharIds = [];
$weaponIds = [];

foreach ($charWeaponStats as $row) {
  $weaponCharIds[(int)$row['char_id']] = (int)$row['char_id'];
  $weaponIds[(int)$row['weapon_id']] = (int)$row['weapon_id'];
}

$liveWeaponCharInfoMap = [];
if (!empty($weaponCharIds)) {
  $ph = implode(',', array_fill(0, count($weaponCharIds), '?'));

  $stmtChars = $pdo->prepare("
    SELECT id, ico, name, level
    FROM unlight
    WHERE id IN ($ph)
  ");
  $stmtChars->execute(array_values($weaponCharIds));

  while ($char = $stmtChars->fetch(PDO::FETCH_ASSOC)) {
    $liveWeaponCharInfoMap[(int)$char['id']] = $char;
  }
}

$liveWeaponInfoMap = [];
if (!empty($weaponIds)) {
  $ph = implode(',', array_fill(0, count($weaponIds), '?'));

  $stmtWeapons = $pdo->prepare("
    SELECT id, name, ico
    FROM unlight_weapon
    WHERE id IN ($ph)
  ");
  $stmtWeapons->execute(array_values($weaponIds));

  while ($weapon = $stmtWeapons->fetch(PDO::FETCH_ASSOC)) {
    $liveWeaponInfoMap[(int)$weapon['id']] = $weapon;
  }
}

usort($charWeaponStats, function ($a, $b) {
  $aUsed = (int)($a['used'] ?? 0);
  $bUsed = (int)($b['used'] ?? 0);

  if ($aUsed !== $bUsed) {
    return $bUsed <=> $aUsed;
  }

  $aRate = $aUsed > 0 ? ((int)$a['wins'] / $aUsed) : 0;
  $bRate = $bUsed > 0 ? ((int)$b['wins'] / $bUsed) : 0;

  if (abs($aRate - $bRate) > 0.0001) {
    return $aRate < $bRate ? 1 : -1;
  }

  return ((int)$a['char_id'] <=> (int)$b['char_id'])
    ?: ((int)$a['weapon_id'] <=> (int)$b['weapon_id']);
});



foreach (array_slice($charWeaponStats, 0, 10) as $i => $row) {
  $charId = (int)$row['char_id'];
  $weaponId = (int)$row['weapon_id'];

  $charInfo = $liveWeaponCharInfoMap[$charId] ?? [];
  $weaponInfo = $liveWeaponInfoMap[$weaponId] ?? [];

  $used = (int)$row['used'];
  $wins = (int)$row['wins'];

  $liveCharWeaponPairs[] = [
    'rank' => $i + 1,
    'char_id' => $charId,
    'weapon_id' => $weaponId,
    'char_label' => trim(($charInfo['level'] ?? '') . ' ' . ($charInfo['name'] ?? ('角色#' . $charId))),
    'char_ico' => (string)($charInfo['ico'] ?? ''),
    'weapon_name' => (string)($weaponInfo['name'] ?? ('武器#' . $weaponId)),
    'weapon_ico' => (string)($weaponInfo['ico'] ?? ($liveWeaponIconMap[$weaponId] ?? '')),
    'used' => $used,
    'wins' => $wins,
    'losses' => (int)$row['losses'],
    'win_rate' => live_pct($wins, $used),
  ];
}




$stmtWinTypes = $pdo->prepare("
  SELECT
    CASE
      WHEN player1_score = 0 AND player2_score = 0 THEN '棄賽 / 判定'
      WHEN ABS(player1_score - player2_score) = 2 THEN '壓制型 2-0'
      WHEN ABS(player1_score - player2_score) = 1 THEN '激戰型 2-1'
      ELSE '其他'
    END AS win_type,
    COUNT(*) AS match_count
  FROM tournament_matches
  WHERE tournament_id = :tournament_id
    AND status IN ('confirmed', 'finished')
    AND winner_player_id IS NOT NULL
  GROUP BY win_type
  ORDER BY FIELD(win_type, '壓制型 2-0', '激戰型 2-1', '棄賽 / 判定', '其他')
");
$stmtWinTypes->execute([':tournament_id' => $tournamentId]);
$winTypeRows = $stmtWinTypes->fetchAll(PDO::FETCH_ASSOC);

$auditRows = [];
if ($isAdmin) {
  $stmtAudit = $pdo->prepare("
    SELECT
      m.match_code,
      g.game_no,
      p1.display_name AS p1_name,
      g.player1_deck_code,
      p2.display_name AS p2_name,
      g.player2_deck_code,
      g.result_status,
      g.note
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    LEFT JOIN tournament_players p1 ON p1.id = g.player1_id
    LEFT JOIN tournament_players p2 ON p2.id = g.player2_id
    WHERE g.tournament_id = :tournament_id
      AND (
        g.player1_deck_code IS NULL
        OR g.player2_deck_code IS NULL
        OR g.result_status IN ('dispute','rejected','pending')
      )
    ORDER BY m.match_no ASC, g.game_no ASC
    LIMIT 20
  ");
  $stmtAudit->execute([':tournament_id' => $tournamentId]);
  $auditRows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);
}

$lastUpdated = $matchSummary['last_match_at'] ?: ($gameSummary['last_game_at'] ?? null);

$stmtPlayerMeta = $pdo->prepare("
  SELECT
    y.player_id,
    p.display_name AS player_name,
    p.seed_no,
    p.tonamel_icon_url AS player_icon,

    COUNT(*) AS appearances,
    SUM(y.is_win) AS wins,
    COUNT(*) - SUM(y.is_win) AS losses,

    ROUND(SUM(y.is_win) / NULLIF(COUNT(*), 0) * 100, 1) AS win_rate,

    COUNT(DISTINCT y.match_id) AS match_count,
    COUNT(DISTINCT y.deck_code) AS used_deck_count

  FROM (
    SELECT
      g.match_id,
      g.player1_id AS player_id,
      g.player1_deck_code AS deck_code,
      CASE WHEN g.winner_player_id = g.player1_id THEN 1 ELSE 0 END AS is_win
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player1_id IS NOT NULL

    UNION ALL

    SELECT
      g.match_id,
      g.player2_id AS player_id,
      g.player2_deck_code AS deck_code,
      CASE WHEN g.winner_player_id = g.player2_id THEN 1 ELSE 0 END AS is_win
    FROM tournament_match_games g
    JOIN tournament_matches m ON m.id = g.match_id
    WHERE g.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND g.result_status = 'verified'
      AND g.player2_id IS NOT NULL
  ) y
  JOIN tournament_players p ON p.id = y.player_id
  GROUP BY
    y.player_id,
    p.display_name,
    p.seed_no,
    p.tonamel_icon_url
  HAVING appearances > 0
  ORDER BY
    win_rate DESC,
    wins DESC,
    appearances DESC,
    match_count DESC,
    p.seed_no ASC
");
$stmtPlayerMeta->execute([
  ':tournament_id' => $tournamentId,
]);
$playerMetaRows = $stmtPlayerMeta->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
  .live-report {
    max-width: 1280px;
    margin: 0 auto;
    padding: 20px 14px 48px;
    color: #e5e7eb
  }

  .live-hero {
    position: relative;
    overflow: hidden;
    border-radius: 22px;
    padding: 28px;
    background: radial-gradient(circle at top left, rgba(255, 212, 121, .22), transparent 34%), linear-gradient(135deg, #121826, #20283a);
    border: 1px solid rgba(255, 212, 121, .22);
    box-shadow: 0 18px 38px rgba(0, 0, 0, .28)
  }

  .live-hero-sub {
    color: #ffd479;
    font-size: 13px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase
  }

  .live-hero-title {
    margin-top: 8px;
    color: #fff;
    font-size: 34px;
    font-weight: 950;
    line-height: 1.18
  }

  .live-hero-title span {
    display: block;
    color: #9ed5ff;
    font-size: 17px;
    font-weight: 800;
    margin-top: 8px
  }

  .live-hero-desc {
    margin-top: 12px;
    color: rgba(226, 232, 240, .78);
    font-size: 14px;
    line-height: 1.8;
    max-width: 780px
  }

  .live-hero-meta {
    margin-top: 14px;
    color: rgba(226, 232, 240, .58);
    font-size: 12px;
    line-height: 1.7
  }

  .live-summary-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
    margin-top: 16px
  }

  .live-summary-card {
    border-radius: 16px;
    padding: 14px;
    background: linear-gradient(180deg, rgba(38, 45, 58, .96), rgba(24, 29, 39, .96));
    border: 1px solid rgba(148, 163, 184, .18);
    box-shadow: 0 12px 24px rgba(0, 0, 0, .18)
  }

  .live-summary-value {
    color: #ffd479;
    font-size: 27px;
    font-weight: 950;
    line-height: 1
  }

  .live-summary-title {
    margin-top: 8px;
    color: rgba(226, 232, 240, .68);
    font-size: 12px;
    font-weight: 800
  }

  .live-section {
    margin-top: 18px
  }

  .live-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
    color: #fff;
    font-size: 19px;
    font-weight: 950
  }

  .live-section-sub {
    color: rgba(226, 232, 240, .58);
    font-size: 12px;
    font-weight: 600
  }

  .live-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px
  }

  .live-card {
    border-radius: 18px;
    padding: 16px;
    background: linear-gradient(180deg, rgba(38, 45, 58, .96), rgba(24, 29, 39, .96));
    border: 1px solid rgba(148, 163, 184, .18);
    box-shadow: 0 12px 26px rgba(0, 0, 0, .20)
  }

  .live-card h3 {
    margin: 0 0 10px;
    color: #ffd479;
    font-size: 15px;
    font-weight: 950
  }

  .live-card p {
    margin: 0;
    color: rgba(226, 232, 240, .72);
    font-size: 13px;
    line-height: 1.75
  }

  .live-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px
  }

  .live-table th {
    padding: 9px 8px;
    color: rgba(226, 232, 240, .62);
    font-size: 12px;
    text-align: left;
    border-bottom: 1px solid rgba(148, 163, 184, .18);
    white-space: nowrap
  }

  .live-table td {
    padding: 9px 8px;
    color: #e5e7eb;
    border-bottom: 1px solid rgba(148, 163, 184, .10);
    vertical-align: middle
  }

  .live-table tr:last-child td {
    border-bottom: 0
  }

  .live-num {
    font-weight: 950;
    color: #ffd479
  }

  .live-win {
    font-weight: 950;
    color: #bfffe3
  }

  .live-loss {
    font-weight: 950;
    color: #ffb4b4
  }

  .live-muted {
    color: rgba(226, 232, 240, .55)
  }

  .live-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 12px;
    font-weight: 900;
    background: rgba(111, 168, 255, .12);
    color: #9ed5ff;
    border: 1px solid rgba(111, 168, 255, .25)
  }

  .live-pill.gold {
    background: rgba(255, 212, 121, .12);
    color: #ffd479;
    border-color: rgba(255, 212, 121, .28)
  }

  .live-pill.green {
    background: rgba(77, 213, 153, .12);
    color: #bfffe3;
    border-color: rgba(77, 213, 153, .25)
  }

  .live-pill.red {
    background: rgba(255, 90, 90, .12);
    color: #ffb4b4;
    border-color: rgba(255, 90, 90, .25)
  }

  .live-match-list {
    display: flex;
    flex-direction: column;
    gap: 9px
  }

  .live-match-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 11px;
    border-radius: 13px;
    background: rgba(8, 12, 20, .48);
    border: 1px solid rgba(148, 163, 184, .12);
    text-decoration: none;
    color: #e5e7eb
  }

  .live-match-main {
    min-width: 0
  }

  .live-match-code {
    color: #9ed5ff;
    font-size: 12px;
    font-weight: 950
  }

  .live-match-title {
    margin-top: 3px;
    font-size: 13px;
    font-weight: 900;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis
  }

  .live-match-score {
    flex: 0 0 auto;
    color: #ffd479;
    font-size: 18px;
    font-weight: 950
  }

  .live-char-cell {
    display: flex;
    align-items: center;
    gap: 8px
  }

  .live-char-img {
    width: 34px;
    height: 48px;
    object-fit: cover;
    border-radius: 7px;
    border: 1px solid rgba(255, 255, 255, .14);
    background: #111827
  }

  .live-audit {
    border-color: rgba(255, 90, 90, .28);
    background: linear-gradient(180deg, rgba(70, 32, 38, .92), rgba(31, 24, 29, .96))
  }

  @media (max-width:991px) {
    .live-summary-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr))
    }

    .live-grid-2 {
      grid-template-columns: 1fr
    }
  }

  @media (max-width:640px) {
    .live-hero-title {
      font-size: 26px
    }

    .live-summary-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr))
    }

    .live-table {
      font-size: 12px
    }

    .live-table th,
    .live-table td {
      padding: 8px 6px
    }

    .live-char-img {
      width: 28px;
      height: 40px
    }
  }

  .live-deck-team {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 220px;
  }

  .live-deck-icons {
    display: flex;
    align-items: center;
    gap: 5px;
  }

  .live-deck-img {
    width: 34px;
    height: 48px;
    object-fit: cover;
    border-radius: 7px;
    border: 1px solid rgba(255, 255, 255, .14);
    background: #111827;
  }

  .live-deck-names {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    color: rgba(226, 232, 240, .72);
    font-size: 12px;
    line-height: 1.45;
  }

  .live-deck-names span:not(:last-child)::after {
    content: " /";
    color: rgba(226, 232, 240, .35);
  }

  @media (max-width: 640px) {
    .live-deck-team {
      min-width: 180px;
    }

    .live-deck-img {
      width: 28px;
      height: 40px;
    }

    .live-deck-names {
      font-size: 11px;
    }
  }

  .live-event-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 180px;
  }

  .live-event-img {
    width: 38px;
    height: 54px;
    object-fit: contain;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, .14);
    background: #111827;
  }

  .live-event-name {
    color: #f8fafc;
    font-size: 13px;
    font-weight: 900;
    line-height: 1.35;
  }

  .live-event-sub {
    margin-top: 2px;
    color: rgba(226, 232, 240, .50);
    font-size: 11px;
  }

  .live-event-mini-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  .live-event-mini {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    padding: 4px 8px;
    color: #e5e7eb;
    background: rgba(255, 255, 255, .06);
    border: 1px solid rgba(255, 255, 255, .10);
    font-size: 12px;
    font-weight: 800;
  }

  .live-event-mini img {
    width: 24px;
    height: 34px;
    object-fit: contain;
    border-radius: 4px;
  }

  .live-writing-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .live-writing-item {
    padding: 13px;
    border-radius: 14px;
    background: rgba(8, 12, 20, .38);
    border: 1px solid rgba(148, 163, 184, .12);
  }

  .live-writing-head {
    margin-bottom: 9px;
  }

  .live-writing-body p {
    margin: 0 0 8px;
    color: rgba(226, 232, 240, .74);
    font-size: 13px;
    line-height: 1.75;
  }

  .live-writing-body strong {
    color: #ffd479;
  }

  .live-cross-char-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
  }

  .live-cross-char {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    border-radius: 12px;
    background: rgba(255, 255, 255, .045);
    border: 1px solid rgba(255, 255, 255, .08);
  }

  .live-cross-char-name {
    color: #f8fafc;
    font-size: 13px;
    font-weight: 900;
  }

  .live-cross-char-sub {
    margin-top: 2px;
    color: rgba(226, 232, 240, .56);
    font-size: 11px;
    line-height: 1.45;
  }

  @media (max-width: 720px) {
    .live-cross-char-list {
      grid-template-columns: 1fr;
    }
  }

  /* ===== Mobile layout polish ===== */

  .live-card {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  .live-table {
    min-width: 680px;
  }

  .live-card::-webkit-scrollbar {
    height: 6px;
  }

  .live-card::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, .35);
    border-radius: 999px;
  }

  @media (max-width: 640px) {
    .live-report {
      padding: 12px 8px 36px;
    }

    .live-hero {
      padding: 18px 16px;
      border-radius: 16px;
    }

    .live-hero-title {
      font-size: 23px;
      line-height: 1.25;
    }

    .live-hero-title span {
      font-size: 14px;
    }

    .live-hero-desc {
      font-size: 13px;
      line-height: 1.65;
    }

    .live-hero-meta {
      font-size: 11px;
    }

    .live-summary-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
    }

    .live-summary-card {
      padding: 11px 10px;
      border-radius: 13px;
    }

    .live-summary-value {
      font-size: 22px;
    }

    .live-summary-title {
      font-size: 11px;
    }

    .live-section {
      margin-top: 14px;
    }

    .live-section-title {
      display: block;
      font-size: 17px;
      line-height: 1.4;
    }

    .live-section-sub {
      display: block;
      margin-top: 4px;
      font-size: 11px;
      line-height: 1.5;
    }

    .live-card {
      padding: 12px;
      border-radius: 15px;
    }

    .live-card h3 {
      font-size: 14px;
      line-height: 1.45;
    }

    .live-card p {
      font-size: 12px;
      line-height: 1.65;
    }

    .live-table {
      min-width: 620px;
      font-size: 12px;
    }

    .live-table th,
    .live-table td {
      padding: 7px 6px;
    }

    .live-match-item {
      display: block;
      padding: 10px;
    }

    .live-match-title {
      white-space: normal;
      line-height: 1.45;
    }

    .live-match-score {
      margin-top: 6px;
      font-size: 16px;
      text-align: right;
    }

    .live-char-cell,
    .live-event-cell {
      min-width: 150px;
      gap: 6px;
    }

    .live-char-img {
      width: 26px;
      height: 38px;
      border-radius: 5px;
    }

    .live-event-img {
      width: 30px;
      height: 42px;
    }

    .live-event-name {
      font-size: 12px;
    }

    .live-event-sub {
      font-size: 10px;
    }

    .live-deck-team {
      min-width: 160px;
    }

    .live-deck-icons {
      gap: 3px;
    }

    .live-deck-img {
      width: 25px;
      height: 36px;
      border-radius: 5px;
    }

    .live-deck-names {
      font-size: 10px;
      line-height: 1.35;
    }

    .live-writing-item {
      padding: 11px;
      border-radius: 13px;
    }

    .live-writing-body p {
      font-size: 12px;
      line-height: 1.65;
    }

    .live-cross-char-list {
      grid-template-columns: 1fr;
      gap: 7px;
    }

    .live-cross-char {
      padding: 7px;
    }

    .live-cross-char-name {
      font-size: 12px;
    }

    .live-cross-char-sub {
      font-size: 10px;
      line-height: 1.5;
    }
  }

  @media (max-width: 390px) {
    .live-summary-grid {
      grid-template-columns: 1fr 1fr;
    }

    .live-table {
      min-width: 600px;
    }

    .live-event-cell {
      min-width: 135px;
    }

    .live-hero-title {
      font-size: 21px;
    }
  }

  .live-pager {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 12px;
  }

  .live-page-btn {
    border: 1px solid rgba(148, 163, 184, .22);
    background: rgba(15, 23, 42, .72);
    color: rgba(226, 232, 240, .72);
    border-radius: 999px;
    padding: 6px 11px;
    font-size: 12px;
    font-weight: 900;
    cursor: pointer;
  }

  .live-page-btn:hover,
  .live-page-btn.active {
    color: #111827;
    background: #ffd479;
    border-color: rgba(255, 212, 121, .75);
  }

  @media (max-width:640px) {
    .live-pager {
      overflow-x: auto;
      flex-wrap: nowrap;
      padding-bottom: 4px;
    }

    .live-page-btn {
      flex: 0 0 auto;
      padding: 6px 10px;
      font-size: 11px;
    }
  }

  .live-sortable-table th[data-sort] {
    cursor: pointer;
    user-select: none;
    position: relative;
  }

  .live-sortable-table th[data-sort]::after {
    content: " ↕";
    color: rgba(226, 232, 240, .35);
    font-size: 11px;
  }

  .live-sortable-table th.sort-asc::after {
    content: " ▲";
    color: #ffd479;
  }

  .live-sortable-table th.sort-desc::after {
    content: " ▼";
    color: #ffd479;
  }

  .live-table-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 10px;
  }

  .live-search-input {
    width: min(260px, 100%);
    border: 1px solid rgba(148, 163, 184, .25);
    background: rgba(15, 23, 42, .72);
    color: rgba(226, 232, 240, .92);
    border-radius: 999px;
    padding: 8px 13px;
    font-size: 13px;
    font-weight: 700;
    outline: none;
  }

  .live-search-input::placeholder {
    color: rgba(148, 163, 184, .65);
  }

  .live-search-input:focus {
    border-color: rgba(255, 212, 121, .65);
    box-shadow: 0 0 0 3px rgba(255, 212, 121, .12);
  }

  @media (max-width: 640px) {
    .live-table-toolbar {
      justify-content: stretch;
    }

    .live-search-input {
      width: 100%;
      font-size: 12px;
    }
  }

  .live-char-link {
    color: inherit;
    text-decoration: none;
    font-weight: 900;
  }

  .live-char-link:hover {
    color: #ffd479;
    text-decoration: underline;
  }

  .live-player-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 140px;
    font-weight: 900;
  }

  .live-player-img {
    width: 28px;
    height: 28px;
    border-radius: 999px;
    object-fit: cover;
    border: 1px solid rgba(255, 255, 255, .18);
    background: rgba(15, 23, 42, .9);
  }

  .live-report-card {
    border-radius: 18px;
    padding: 16px;
    background: linear-gradient(180deg, rgba(38, 45, 58, .96), rgba(24, 29, 39, .96));
    border: 1px solid rgba(148, 163, 184, .18);
    box-shadow: 0 12px 26px rgba(0, 0, 0, .20);
  }

  .live-report-card h3 {
    margin: 0 0 8px;
    color: #ffd479;
    font-size: 15px;
    font-weight: 950;
  }

  .live-report-card p {
    margin: 0 0 14px;
    color: rgba(226, 232, 240, .72);
    font-size: 13px;
    line-height: 1.75;
  }

  .live-board {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }

  .live-board-col {
    min-width: 0;
  }

  .live-board-title {
    margin-bottom: 10px;
    color: #f8fafc;
    font-size: 14px;
    font-weight: 950;
  }

  .live-card-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .live-event-cross-card {
    padding: 11px;
    border-radius: 15px;
    background: rgba(8, 12, 20, .42);
    border: 1px solid rgba(148, 163, 184, .13);
  }

  .live-event-cross-head {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 10px;
    align-items: center;
  }

  .live-rank-event-img {
    width: 38px;
    height: 54px;
    object-fit: contain;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, .14);
    background: #111827;
  }

  .live-rank-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(226, 232, 240, .55);
    font-weight: 950;
  }

  .live-rank-main {
    min-width: 0;
  }

  .live-rank-name {
    color: #f8fafc;
    font-size: 13px;
    font-weight: 950;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .live-rank-sub {
    margin-top: 2px;
    color: rgba(226, 232, 240, .50);
    font-size: 11px;
  }

  .live-rank-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 6px;
  }

  .live-rank-tags span {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    border-radius: 999px;
    padding: 3px 7px;
    color: rgba(226, 232, 240, .72);
    background: rgba(255, 255, 255, .055);
    border: 1px solid rgba(255, 255, 255, .08);
    font-size: 11px;
    font-weight: 800;
  }

  .live-rank-tags b {
    color: #ffd479;
  }

  .live-mini-pair-list {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-top: 10px;
  }

  .live-mini-pair-card {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 8px;
    padding: 8px;
    border-radius: 12px;
    background: rgba(255, 255, 255, .045);
    border: 1px solid rgba(255, 255, 255, .08);
  }

  .live-mini-char-img {
    width: 28px;
    height: 40px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, .14);
    background: #111827;
  }

  .live-mini-pair-main {
    min-width: 0;
  }

  .live-mini-pair-name {
    color: #f8fafc;
    font-size: 12px;
    font-weight: 900;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .live-mini-pair-sub {
    margin-top: 2px;
    color: rgba(226, 232, 240, .56);
    font-size: 10px;
    line-height: 1.45;
  }

  .live-mini-pair-rate {
    min-width: 48px;
    text-align: right;
  }

  .live-mini-pair-rate span {
    display: block;
    color: rgba(226, 232, 240, .45);
    font-size: 10px;
    font-weight: 800;
  }

  .live-mini-pair-rate b {
    display: block;
    margin-top: 2px;
    color: #bfffe3;
    font-size: 12px;
    font-weight: 950;
  }

  .live-empty-card,
  .live-placeholder-card {
    padding: 14px;
    border-radius: 14px;
    color: rgba(226, 232, 240, .64);
    background: rgba(8, 12, 20, .32);
    border: 1px dashed rgba(148, 163, 184, .24);
    font-size: 13px;
    line-height: 1.7;
  }

  .live-placeholder-title {
    color: #ffd479;
    font-size: 14px;
    font-weight: 950;
    margin-bottom: 4px;
  }

  .live-placeholder-text {
    color: rgba(226, 232, 240, .66);
    font-size: 12px;
    line-height: 1.65;
  }

  @media (max-width: 900px) {
    .live-board {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 640px) {
    .live-event-cross-head {
      grid-template-columns: auto minmax(0, 1fr);
    }

    .live-rank-event-img {
      width: 32px;
      height: 45px;
    }

    .live-mini-pair-card {
      grid-template-columns: auto minmax(0, 1fr);
    }

    .live-mini-pair-rate {
      grid-column: 2;
      text-align: left;
    }
  }

  .live-weapon-rank-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .live-weapon-rank-card {
    display: grid;
    grid-template-columns: auto auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    min-height: 78px;
    padding: 12px 14px;
    border-radius: 15px;
    background: rgba(8, 12, 20, .42);
    border: 1px solid rgba(148, 163, 184, .16);
  }

  .live-weapon-rank-no {
    width: 44px;
    height: 44px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #111827;
    background: #e8bd58;
    font-size: 17px;
    font-weight: 950;
    box-shadow: 0 8px 18px rgba(0, 0, 0, .22);
  }

  .live-weapon-pair-icons {
    position: relative;
    display: flex;
    align-items: flex-end;
    width: 92px;
    min-width: 92px;
  }

  .live-weapon-char-img {
    width: 52px;
    height: 72px;
    object-fit: cover;
    border-radius: 7px;
    border: 1px solid rgba(255, 255, 255, .18);
    background: #111827;
    z-index: 2;
  }

  .live-weapon-img {
    width: 42px;
    height: 42px;
    object-fit: contain;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, .18);
    background: #111827;
    margin-left: -9px;
    z-index: 3;
  }

  .live-weapon-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(226, 232, 240, .55);
    font-weight: 950;
  }

  .live-weapon-main {
    min-width: 0;
  }

  .live-weapon-name {
    color: #f8fafc;
    font-size: 15px;
    font-weight: 950;
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .live-weapon-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
    color: rgba(226, 232, 240, .62);
    font-size: 13px;
    font-weight: 700;
  }

  .live-weapon-meta b {
    color: #ffd479;
    font-size: 14px;
    font-weight: 950;
  }

  .live-weapon-meta b.win {
    color: #6dff7d;
  }

  @media (max-width: 1180px) {
    .live-weapon-rank-card {
      grid-template-columns: auto auto minmax(0, 1fr);
    }

    .live-weapon-meta {
      grid-column: 3;
      margin-top: -4px;
    }
  }

  @media (max-width: 640px) {
    .live-weapon-rank-card {
      grid-template-columns: auto auto minmax(0, 1fr);
      gap: 9px;
      padding: 10px;
      min-height: 68px;
    }

    .live-weapon-rank-no {
      width: 38px;
      height: 38px;
      font-size: 15px;
    }

    .live-weapon-pair-icons {
      width: 76px;
      min-width: 76px;
    }

    .live-weapon-char-img {
      width: 44px;
      height: 62px;
    }

    .live-weapon-img {
      width: 36px;
      height: 36px;
      margin-left: -8px;
    }

    .live-weapon-name {
      font-size: 13px;
    }

    .live-weapon-meta {
      grid-column: 3;
      flex-wrap: wrap;
      gap: 6px;
      font-size: 11px;
    }

    .live-weapon-meta b {
      font-size: 12px;
    }
  }

  .live-weapon-rank-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .live-weapon-rank-card {
    display: grid;
    grid-template-columns: auto auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    min-height: 78px;
    padding: 12px 14px;
    border-radius: 15px;
    background: rgba(8, 12, 20, .42);
    border: 1px solid rgba(148, 163, 184, .16);
  }

  .live-weapon-rank-no {
    width: 44px;
    height: 44px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #111827;
    background: #e8bd58;
    font-size: 17px;
    font-weight: 950;
    box-shadow: 0 8px 18px rgba(0, 0, 0, .22);
  }

  .live-weapon-pair-icons {
    position: relative;
    display: flex;
    align-items: flex-end;
    width: 92px;
    min-width: 92px;
  }

  .live-weapon-char-img {
    width: 52px;
    height: 72px;
    object-fit: cover;
    border-radius: 7px;
    border: 1px solid rgba(255, 255, 255, .18);
    background: #111827;
    z-index: 2;
  }

  .live-weapon-img {
    width: 42px;
    height: 42px;
    object-fit: contain;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, .18);
    background: #111827;
    margin-left: -9px;
    z-index: 3;
  }

  .live-weapon-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(226, 232, 240, .55);
    font-weight: 950;
  }

  .live-weapon-main {
    min-width: 0;
  }

  .live-weapon-name {
    color: #f8fafc;
    font-size: 15px;
    font-weight: 950;
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .live-weapon-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
    color: rgba(226, 232, 240, .62);
    font-size: 13px;
    font-weight: 700;
  }

  .live-weapon-meta b {
    color: #ffd479;
    font-size: 14px;
    font-weight: 950;
  }

  .live-weapon-meta b.win {
    color: #6dff7d;
  }

  @media (max-width: 1180px) {
    .live-weapon-rank-card {
      grid-template-columns: auto auto minmax(0, 1fr);
    }

    .live-weapon-meta {
      grid-column: 3;
      margin-top: -4px;
    }
  }

  @media (max-width: 640px) {
    .live-weapon-rank-card {
      grid-template-columns: auto auto minmax(0, 1fr);
      gap: 9px;
      padding: 10px;
      min-height: 68px;
    }

    .live-weapon-rank-no {
      width: 38px;
      height: 38px;
      font-size: 15px;
    }

    .live-weapon-pair-icons {
      width: 76px;
      min-width: 76px;
    }

    .live-weapon-char-img {
      width: 44px;
      height: 62px;
    }

    .live-weapon-img {
      width: 36px;
      height: 36px;
      margin-left: -8px;
    }

    .live-weapon-name {
      font-size: 13px;
    }

    .live-weapon-meta {
      grid-column: 3;
      flex-wrap: wrap;
      gap: 6px;
      font-size: 11px;
    }

    .live-weapon-meta b {
      font-size: 12px;
    }
  }

  .live-event-rank-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .live-event-rank-card {
    display: grid;
    grid-template-columns: auto auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    min-height: 78px;
    padding: 12px 14px;
    border-radius: 15px;
    background: rgba(8, 12, 20, .42);
    border: 1px solid rgba(148, 163, 184, .16);
  }

  .live-event-rank-no {
    width: 44px;
    height: 44px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #111827;
    background: #e8bd58;
    font-size: 17px;
    font-weight: 950;
    box-shadow: 0 8px 18px rgba(0, 0, 0, .22);
  }

  .live-event-rank-icons {
    position: relative;
    display: flex;
    align-items: flex-end;
    width: 96px;
    min-width: 96px;
  }

  .live-event-rank-img {
    width: 42px;
    height: 58px;
    object-fit: contain;
    border-radius: 7px;
    border: 1px solid rgba(255, 255, 255, .18);
    background: #111827;
    z-index: 3;
  }

  .live-event-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(226, 232, 240, .55);
    font-weight: 950;
  }

  .live-event-char-stack {
    display: flex;
    align-items: flex-end;
    margin-left: -6px;
  }

  .live-event-char-mini {
    width: 28px;
    height: 40px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, .18);
    background: #111827;
    margin-left: -7px;
    box-shadow: 0 5px 12px rgba(0, 0, 0, .25);
  }

  .live-event-rank-main {
    min-width: 0;
  }

  .live-event-rank-name {
    color: #f8fafc;
    font-size: 15px;
    font-weight: 950;
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .live-event-rank-pairs {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 6px;
  }

  .live-event-rank-pairs span {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    max-width: 100%;
    border-radius: 999px;
    padding: 3px 7px;
    color: rgba(226, 232, 240, .72);
    background: rgba(255, 255, 255, .055);
    border: 1px solid rgba(255, 255, 255, .08);
    font-size: 11px;
    font-weight: 800;
  }

  .live-event-rank-pairs b {
    color: #bfffe3;
  }

  .live-event-rank-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
    color: rgba(226, 232, 240, .62);
    font-size: 13px;
    font-weight: 700;
  }

  .live-event-rank-meta b {
    color: #ffd479;
    font-size: 14px;
    font-weight: 950;
  }

  .live-event-rank-meta b.win {
    color: #6dff7d;
  }

  @media (max-width: 1180px) {
    .live-event-rank-card {
      grid-template-columns: auto auto minmax(0, 1fr);
    }

    .live-event-rank-meta {
      grid-column: 3;
      margin-top: -4px;
    }
  }

  @media (max-width: 640px) {
    .live-event-rank-card {
      grid-template-columns: auto auto minmax(0, 1fr);
      gap: 9px;
      padding: 10px;
      min-height: 68px;
    }

    .live-event-rank-no {
      width: 38px;
      height: 38px;
      font-size: 15px;
    }

    .live-event-rank-icons {
      width: 78px;
      min-width: 78px;
    }

    .live-event-rank-img {
      width: 34px;
      height: 48px;
    }

    .live-event-char-mini {
      width: 23px;
      height: 34px;
      margin-left: -6px;
    }

    .live-event-rank-name {
      font-size: 13px;
    }

    .live-event-rank-meta {
      grid-column: 3;
      flex-wrap: wrap;
      gap: 6px;
      font-size: 11px;
    }

    .live-event-rank-meta b {
      font-size: 12px;
    }

    .live-event-rank-pairs span {
      font-size: 10px;
      padding: 3px 6px;
    }
  }

  .live-table-compact {
    min-width: 0;
    width: 100%;
    table-layout: fixed;
  }

  .live-table-compact th:nth-child(1),
  .live-table-compact td:nth-child(1) {
    width: 48%;
  }

  .live-table-compact th:nth-child(2),
  .live-table-compact td:nth-child(2) {
    width: 22%;
    text-align: center;
  }

  .live-table-compact th:nth-child(3),
  .live-table-compact td:nth-child(3) {
    width: 30%;
    text-align: right;
  }

  .live-table-compact .live-pill {
    max-width: 100%;
    justify-content: center;
    white-space: nowrap;
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="live-report">
      <div class="live-hero">
        <div class="live-hero-sub">
          <?= h($report['issue_label'] ?? 'ULGG CUP #1') ?>
        </div>
        <div class="live-hero-title">
          <?= h($report['report_title'] ?? 'ULGG 杯 Meta 追蹤報告') ?>
          <span><?= h($report['report_subtitle'] ?? 'Live Tournament Meta Report') ?></span>
        </div>
        <div class="live-hero-desc">
          <?= h($report['hero_desc'] ?? '統計目前已完成對戰的 BAN、實戰牌組、角色出場與晉級趨勢。') ?>
        </div>
        <div class="live-hero-meta">
          統計範圍：已確認場次 <?= number_format((int)($matchSummary['completed_matches'] ?? 0)) ?> 場 /
          已確認小局 <?= number_format((int)($gameSummary['verified_games'] ?? 0)) ?> 局
          <?php if ($lastUpdated): ?>
            ｜最後更新：<?= h(date('Y/m/d H:i', strtotime($lastUpdated))) ?>
          <?php endif; ?>
          <br>
          爭議、駁回與待確認小局不納入角色勝率與牌組勝率統計。
        </div>
      </div>

      <div class="live-summary-grid">
        <div class="live-summary-card">
          <div class="live-summary-value"><?= number_format((int)($matchSummary['completed_matches'] ?? 0)) ?></div>
          <div class="live-summary-title">完成場次</div>
        </div>
        <div class="live-summary-card">
          <div class="live-summary-value"><?= number_format((int)($gameSummary['verified_games'] ?? 0)) ?></div>
          <div class="live-summary-title">確認小局</div>
        </div>
        <div class="live-summary-card">
          <div class="live-summary-value"><?= number_format((int)($matchSummary['sweep_matches'] ?? 0)) ?></div>
          <div class="live-summary-title">壓制型勝利</div>
        </div>
        <div class="live-summary-card">
          <div class="live-summary-value"><?= number_format((int)($matchSummary['full_set_matches'] ?? 0)) ?></div>
          <div class="live-summary-title">激戰型勝利</div>
        </div>
        <div class="live-summary-card">
          <div class="live-summary-value"><?= number_format((int)($matchSummary['forfeit_matches'] ?? 0)) ?></div>
          <div class="live-summary-title">棄賽 / 判定</div>
        </div>
        <div class="live-summary-card">
          <div class="live-summary-value"><?= number_format((int)($playerSummary['active_players'] ?? 0)) ?></div>
          <div class="live-summary-title">仍在賽選手</div>
        </div>
      </div>

      <div class="live-section">
        <div class="live-grid-2">
          <div class="live-card">
            <h3>📰 最新完成對戰</h3>
            <div class="live-match-list">
              <?php foreach ($latestMatches as $match): ?>
                <a class="live-match-item" href="/pages/tournament/ulgg_cup_match.php?match=<?= urlencode($match['match_code']) ?>">
                  <div class="live-match-main">
                    <div class="live-match-code"><?= h($match['round_name'] ?: live_round_name($match['round_key'])) ?>｜<?= h($match['match_code']) ?></div>
                    <div class="live-match-title">
                      <?= h($match['p1_name']) ?> vs <?= h($match['p2_name']) ?>
                      <span class="live-muted">｜<?= h($match['winner_name']) ?> 勝出</span>
                    </div>
                  </div>
                  <div class="live-match-score">
                    <?= (int)$match['player1_score'] ?> - <?= (int)$match['player2_score'] ?>
                  </div>
                </a>
              <?php endforeach; ?>
              <?php if (empty($latestMatches)): ?>
                <p>目前尚無已確認賽果。</p>
              <?php endif; ?>
            </div>
          </div>

          <div class="live-card">
            <h3>⚔ 對戰型態</h3>
            <p style="margin-top:10px;">
              這裡會隨賽程更新，用來觀察整體賽事偏向壓制局，還是經常打滿三局。
            </p>
            <table class="live-table live-table-compact">
              <thead>
                <tr>
                  <th>類型</th>
                  <th>場次</th>
                  <th>占比</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($winTypeRows as $row): ?>
                  <tr>
                    <td>
                      <?php
                      $pillClass = 'gold';
                      if ($row['win_type'] === '激戰型 2-1') $pillClass = 'green';
                      if ($row['win_type'] === '棄賽 / 判定') $pillClass = 'red';
                      ?>
                      <span class="live-pill <?= h($pillClass) ?>"><?= h($row['win_type']) ?></span>
                    </td>
                    <td class="live-num"><?= number_format((int)$row['match_count']) ?></td>
                    <td><?= live_pct((int)$row['match_count'], (int)($matchSummary['completed_matches'] ?? 0)) ?>%</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

          </div>
        </div>
      </div>

      <!-- <div class="live-section">
        <div class="live-card">
          <h3>🚫 BAN 熱門角色</h3>
          <table class="live-table">
            <thead>
              <tr>
                <th>#</th>
                <th>角色</th>
                <th>COST</th>
                <th>BAN</th>
                <th>被 BAN 方勝率</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($banRows as $i => $row): ?>
                <?php
                $banCount = (int)$row['ban_count'];
                $banWins = (int)$row['banned_side_wins'];
                $banCharLabel = trim(
                  (($row['db_char_level'] ?? '') ?: ($row['card_level'] ?? '')) . ' ' .
                    (($row['db_char_name'] ?? '') ?: ($row['char_name'] ?? ''))
                );
                ?>
                <tr>
                  <td class="live-muted">#<?= $i + 1 ?></td>
                  <td>
                    <div class="live-char-cell">
                      <?php if (!empty($row['char_ico'])): ?>
                        <img class="live-char-img" src="<?= IMG_BASE . h($row['char_ico']) ?>" loading="lazy" alt="<?= h($banCharLabel) ?>">
                      <?php endif; ?>
                      <span><?= h($banCharLabel) ?></span>
                    </div>
                  </td>
                  <td class="live-num"><?= number_format((int)($row['card_cost'] ?? 0)) ?></td>
                  <td class="live-num"><?= number_format($banCount) ?></td>
                  <td class="live-win"><?= live_pct($banWins, $banCount) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div> -->

      <div class="live-section">
        <div class="live-card">
          <h3>🚫 BAN 熱門角色統合</h3>
          <span class="live-section-sub">
            將同一角色不同等級卡片合併統計，例如 L1～L5 / R1～R5 會視為同一角色。
          </span>
          <table class="live-table">
            <thead>
              <tr>
                <th>#</th>
                <th>角色</th>
                <th>常見隊友</th>
                <th>COST範圍</th>
                <th>BAN</th>
                <th>涉及場次</th>
                <th>被 BAN 方仍勝率</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($banCharacterRows as $i => $row): ?>
                <?php
                $banCount = (int)($row['ban_count'] ?? 0);
                $banWins = (int)($row['banned_side_wins'] ?? 0);
                $minCost = $row['min_cost'] !== null ? (int)$row['min_cost'] : 0;
                $maxCost = $row['max_cost'] !== null ? (int)$row['max_cost'] : 0;
                $costText = $minCost === $maxCost ? (string)$maxCost : ($minCost . '～' . $maxCost);
                ?>
                <tr>
                  <td class="live-muted">#<?= $i + 1 ?></td>
                  <td>
                    <div class="live-char-cell">
                      <?php if (!empty($row['char_ico'])): ?>
                        <img class="live-char-img" src="<?= IMG_BASE . h($row['char_ico']) ?>" loading="lazy" alt="<?= h($row['character_name']) ?>">
                      <?php endif; ?>
                      <div>
                        <div><?= h($row['character_name']) ?></div>
                        <div class="live-event-sub"><?= h($row['banned_levels'] ?? '') ?></div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="live-muted">
                      <?= h($row['common_teammates'] ?: '-') ?>
                    </span>
                  </td>
                  <td class="live-num"><?= h($costText) ?></td>
                  <td class="live-num"><?= number_format($banCount) ?></td>
                  <td><?= number_format((int)($row['banned_matches'] ?? 0)) ?></td>
                  <td class="live-win"><?= live_pct($banWins, $banCount) ?>%</td>
                </tr>
              <?php endforeach; ?>

              <?php if (empty($banCharacterRows)): ?>
                <tr>
                  <td colspan="8" class="live-muted">尚無角色統合 BAN 資料。</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>

          <p style="margin-top:10px;">
            此區統計的是角色本體熱度，不區分卡片等級；上方 BAN 熱門角色則保留單卡版本。
          </p>
        </div>
      </div>

      <div class="live-section">
        <div class="live-section-title">
          <span>🔥 角色實戰 Meta</span>
          <span class="live-section-sub">依目前已確認小局統計</span>
        </div>
        <div class="live-card">
          <div class="live-table-toolbar">
            <input
              type="search"
              id="characterMetaSearch"
              class="live-search-input"
              placeholder="搜尋角色名稱..."
              autocomplete="off">
          </div>
          <table class="live-table live-sortable-table" id="characterMetaTable">
            <thead>
              <tr>
                <th data-sort="rank">#</th>
                <th data-sort="text">角色</th>
                <th data-sort="text">常見隊友</th>
                <th data-sort="number">COST</th>
                <th data-sort="number">出場</th>
                <th data-sort="percent">勝率</th>
                <th data-sort="number">BAN</th>
                <th data-sort="number">
                  <span title="總壓力 = 實戰出場次數 + 被 BAN 次數，用來觀察角色在賽事中的整體存在感。">
                    總壓力 ⓘ
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($characterMetaRows as $i => $row): ?>
                <?php $pageNo = intdiv($i, 10) + 1; ?>
                <?php
                $appearances = (int)$row['appearances'];
                $wins = (int)$row['wins'];
                $losses = (int)$row['losses'];
                $banCount = (int)$row['ban_count'];
                $charLabel = trim(($row['char_level'] ?? '') . ' ' . ($row['char_name'] ?? ''));
                ?>
                <tr class="live-character-row" data-page="<?= $pageNo ?>">
                  <td class="live-muted">#<?= $i + 1 ?></td>
                  <td>
                    <div class="live-char-cell">
                      <?php if (!empty($row['char_ico'])): ?>
                        <img class="live-char-img" src="<?= IMG_BASE . h($row['char_ico']) ?>" loading="lazy" alt="<?= h($charLabel) ?>">
                      <?php endif; ?>
                      <a
                        class="live-char-link"
                        href="/pages/analysis_character_card.php?char_id=<?= (int)$row['char_id'] ?>&char=<?= urlencode($charLabel) ?>&days=30&tab=skill">
                        <?= h($charLabel) ?>
                      </a>
                    </div>
                  </td>
                  <td>
                    <span class="live-muted">
                      <?= h($characterTeammateMap[(int)$row['char_id']] ?? '-') ?>
                    </span>
                  </td>
                  <td class="live-num"><?= number_format((int)($row['char_cost'] ?? 0)) ?></td>
                  <td class="live-num"><?= number_format($appearances) ?></td>
                  <td class="live-win"><?= live_pct($wins, $appearances) ?>%</td>
                  <td><?= number_format($banCount) ?></td>
                  <td class="live-num"><?= number_format($appearances + $banCount) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($characterMetaRows)): ?>
                <tr>
                  <td colspan="8" class="live-muted">尚無可統計的小局牌組資料。</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
          <?php $characterPageCount = (int)ceil(count($characterMetaRows) / 10); ?>

          <?php if ($characterPageCount > 1): ?>
            <div class="live-pager" data-target="character">
              <?php for ($p = 1; $p <= $characterPageCount; $p++): ?>
                <button type="button"
                  class="live-page-btn <?= $p === 1 ? 'active' : '' ?>"
                  data-page="<?= $p ?>">
                  <?= (($p - 1) * 10 + 1) ?>-<?= min($p * 10, count($characterMetaRows)) ?>
                </button>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="live-section">


        <div class="live-card">
          <h3>🎴 事件卡使用牌組勝率 TOP10</h3>
          <span class="live-section-sub">以有攜帶該事件卡的出戰牌組為分母，統計該事件卡牌組的實戰勝率；攜帶牌組數過少時僅供參考。</span>
          <table class="live-table">
            <thead>
              <tr>
                <th>#</th>
                <th>事件卡</th>
                <th title="有攜帶此事件卡的出戰牌組中，贏下該小局的比例。">使用牌組勝率 ⓘ</th>
                <th title="有攜帶此事件卡且贏下小局的出戰牌組數。">勝方牌組 ⓘ</th>
                <th title="有攜帶此事件卡的出戰牌組數。同一小局雙方各算一副出戰牌組。">攜帶牌組 ⓘ</th>
                <th title="此事件卡在所有已解析出戰牌組中的總攜帶張數。">張數 ⓘ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($eventCardStats as $i => $row): ?>
                <?php
                $eventId = (int)$row['event_id'];
                $eventName = live_event_label($eventInfoMap, $eventId);
                $eventIcon = live_event_icon($eventInfoMap, $eventId);
                $usedCount = (int)$row['used_count'];
                ?>
                <tr>
                  <td class="live-muted">#<?= $i + 1 ?></td>
                  <td>
                    <div class="live-event-cell">
                      <?php if ($eventIcon !== ''): ?>
                        <img class="live-event-img" src="<?= IMG_BASE . h($eventIcon) ?>" loading="lazy" alt="<?= h($eventName) ?>">
                      <?php endif; ?>
                      <div>
                        <div class="live-event-name"><?= h($eventName) ?></div>
                        <div class="live-event-sub">COST <?= h(live_event_cost($eventInfoMap, $eventId)) ?></div>
                      </div>
                    </div>
                  </td>
                  <?php
                  $deckCount = (int)($row['deck_count'] ?? 0);
                  $winDeckCount = (int)($row['win_deck_count'] ?? 0);
                  $usedCount = (int)($row['used_count'] ?? 0);
                  ?>
                  <td class="live-win"><?= live_pct($winDeckCount, $deckCount) ?>%</td>
                  <td class="live-num"><?= number_format($winDeckCount) ?></td>
                  <td><?= number_format($deckCount) ?></td>
                  <td><?= number_format($usedCount) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($eventCardStats)): ?>
                <tr>
                  <td colspan="6" class="live-muted">尚無事件卡資料。</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="live-section">
        <div class="report-card live-report-card">
          <h3>🧬 事件卡 × 角色共現觀察</h3>
          <p>
            本區為共現分析：事件卡屬於整副牌共用，因此此處表示「含有該角色的出戰牌組中，該事件卡的共現與勝率表現」，不代表事件卡由單一角色使用。共現牌組數偏少時，勝率容易受單一對戰影響。
          </p>

          <div class="live-board">
            <div class="live-board-col">
              <div class="live-board-title">🎴 事件卡共現</div>

              <div class="live-event-rank-list">
                <?php foreach ($eventCrossRows as $i => $group): ?>
                  <?php
                  $eventId = (int)$group['event_id'];
                  $eventStat = $group['event_stat'] ?? [];

                  $eventName = live_event_label($eventInfoMap, $eventId);
                  $eventIcon = live_event_icon($eventInfoMap, $eventId);

                  $eventUsedCount = (int)($eventStat['used_count'] ?? 0);
                  $eventDeckCount = (int)($eventStat['deck_count'] ?? 0);
                  $eventWinDeckCount = (int)($eventStat['win_deck_count'] ?? 0);
                  $eventWinRate = live_pct($eventWinDeckCount, $eventDeckCount);

                  $topChars = array_slice($group['chars'] ?? [], 0, 3);
                  ?>
                  <div class="live-event-rank-card">
                    <div class="live-event-rank-no">#<?= $i + 1 ?></div>

                    <div class="live-event-rank-icons">
                      <?php if ($eventIcon !== ''): ?>
                        <img
                          src="<?= IMG_BASE . h($eventIcon) ?>"
                          class="live-event-rank-img"
                          loading="lazy"
                          alt="<?= h($eventName) ?>">
                      <?php else: ?>
                        <div class="live-event-rank-img live-event-empty">?</div>
                      <?php endif; ?>

                      <div class="live-event-char-stack">
                        <?php foreach ($topChars as $charRow): ?>
                          <?php
                          $charId = (int)($charRow['char_id'] ?? 0);
                          $charInfo = $charEventInfoMap[$charId] ?? [];
                          $charIcon = (string)($charInfo['ico'] ?? '');
                          $charName = trim(($charInfo['level'] ?? '') . ' ' . ($charInfo['name'] ?? ('角色#' . $charId)));
                          ?>
                          <?php if ($charIcon !== ''): ?>
                            <img
                              src="<?= IMG_BASE . h($charIcon) ?>"
                              class="live-event-char-mini"
                              loading="lazy"
                              title="<?= h($charName) ?>"
                              alt="<?= h($charName) ?>">
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </div>
                    </div>

                    <div class="live-event-rank-main">
                      <div class="live-event-rank-name"><?= h($eventName) ?></div>

                      <?php if (!empty($topChars)): ?>
                        <div class="live-event-rank-pairs">
                          <?php foreach ($topChars as $charRow): ?>
                            <?php
                            $charId = (int)($charRow['char_id'] ?? 0);
                            $charInfo = $charEventInfoMap[$charId] ?? [];
                            $charName = trim(($charInfo['level'] ?? '') . ' ' . ($charInfo['name'] ?? ('角色#' . $charId)));

                            $deckCount = (int)($charRow['deck_count'] ?? 0);
                            $winDeckCount = (int)($charRow['win_deck_count'] ?? 0);
                            $charWinRate = live_pct($winDeckCount, $deckCount);
                            ?>
                            <span><?= h($charName) ?> <b><?= h($charWinRate) ?>%</b></span>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <div class="live-event-rank-pairs">
                          <span>尚無達門檻角色共現</span>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="live-event-rank-meta">
                      <span>攜帶 <b><?= number_format($eventDeckCount) ?>副</b></span>
                      <span>勝率 <b class="win"><?= h($eventWinRate) ?>%</b></span>
                    </div>
                  </div>
                <?php endforeach; ?>

                <?php if (empty($eventCrossRows)): ?>
                  <div class="live-empty-card">目前尚無足夠的事件卡與角色共現資料。</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="live-board-col">
              <div class="live-board-title">⚔ 角色 × 武器配置</div>

              <div class="live-weapon-rank-list">
                <?php foreach ($liveCharWeaponPairs as $pair): ?>
                  <div class="live-weapon-rank-card">
                    <div class="live-weapon-rank-no">#<?= (int)$pair['rank'] ?></div>

                    <div class="live-weapon-pair-icons">
                      <?php if (!empty($pair['char_ico'])): ?>
                        <img
                          src="<?= IMG_BASE . h($pair['char_ico']) ?>"
                          class="live-weapon-char-img"
                          loading="lazy"
                          alt="<?= h($pair['char_label']) ?>">
                      <?php endif; ?>

                      <?php if (!empty($pair['weapon_ico'])): ?>
                        <img
                          src="<?= IMG_BASE . h($pair['weapon_ico']) ?>"
                          class="live-weapon-img"
                          loading="lazy"
                          alt="<?= h($pair['weapon_name']) ?>">
                      <?php else: ?>
                        <div class="live-weapon-img live-weapon-empty">?</div>
                      <?php endif; ?>
                    </div>

                    <div class="live-weapon-main">
                      <div class="live-weapon-name">
                        <?= h($pair['char_label']) ?> × <?= h($pair['weapon_name']) ?>
                      </div>
                    </div>

                    <div class="live-weapon-meta">
                      <span>使用 <b><?= number_format((int)$pair['used']) ?>場</b></span>
                      <span>勝率 <b class="win"><?= h($pair['win_rate']) ?>%</b></span>
                    </div>
                  </div>
                <?php endforeach; ?>

                <?php if (empty($liveCharWeaponPairs)): ?>
                  <div class="live-empty-card">目前尚無角色 × 武器配置資料。</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>


      <div class="live-section">
        <div class="live-section-title">
          <span>🏅 玩家實戰排名</span>
          <span class="live-section-sub">依目前已確認小局統計，著重玩家整體實戰表現</span>
        </div>

        <div class="live-card">
          <table class="live-table live-sortable-table" id="playerMetaTable">
            <thead>
              <tr>
                <th>#</th>
                <th>玩家</th>
                <th>Seed</th>
                <th>出場</th>
                <th>勝</th>
                <th>敗</th>
                <th>勝率</th>
                <th>對戰場次</th>
                <th>使用牌組數</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $playerRankNo = 0;
              $playerPrevRankKey = '';
              ?>

              <?php foreach ($playerMetaRows as $i => $row): ?>
                <?php
                $playerPageNo = intdiv($i, 10) + 1;

                $appearances = (int)($row['appearances'] ?? 0);
                $wins = (int)($row['wins'] ?? 0);
                $losses = (int)($row['losses'] ?? 0);
                $winRate = live_pct($wins, $appearances);

                $rankKey = implode('|', [
                  number_format((float)$winRate, 1, '.', ''),
                  $wins,
                  $appearances,
                  (int)($row['match_count'] ?? 0),
                  (int)($row['used_deck_count'] ?? 0),
                ]);

                if ($rankKey !== $playerPrevRankKey) {
                  $playerRankNo = $i + 1;
                  $playerPrevRankKey = $rankKey;
                }
                ?>

                <tr class="live-player-row" data-page="<?= $playerPageNo ?>">
                  <td class="live-muted">#<?= $playerRankNo ?></td>

                  <td>
                    <div class="live-player-cell">
                      <?php if (!empty($row['player_icon'])): ?>
                        <img
                          class="live-player-img"
                          src="<?= h($row['player_icon']) ?>"
                          loading="lazy"
                          alt="<?= h($row['player_name']) ?>">
                      <?php endif; ?>
                      <span><?= h($row['player_name']) ?></span>
                    </div>
                  </td>

                  <td>
                    <span class="live-pill">
                      #<?= number_format((int)($row['seed_no'] ?? 0)) ?>
                    </span>
                  </td>

                  <td class="live-num"><?= number_format($appearances) ?></td>
                  <td class="live-win"><?= number_format($wins) ?></td>
                  <td class="live-loss"><?= number_format($losses) ?></td>
                  <td class="live-win"><?= h($winRate) ?>%</td>
                  <td class="live-num"><?= number_format((int)($row['match_count'] ?? 0)) ?></td>
                  <td class="live-num"><?= number_format((int)($row['used_deck_count'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>

              <?php if (empty($playerMetaRows)): ?>
                <tr>
                  <td colspan="9" class="live-muted">尚無可統計的玩家實戰資料。</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
          <?php $playerPageCount = (int)ceil(count($playerMetaRows) / 10); ?>

          <?php if ($playerPageCount > 1): ?>
            <div class="live-pager" data-target="player">
              <?php for ($p = 1; $p <= $playerPageCount; $p++): ?>
                <button type="button"
                  class="live-page-btn <?= $p === 1 ? 'active' : '' ?>"
                  data-page="<?= $p ?>">
                  <?= (($p - 1) * 10 + 1) ?>-<?= min($p * 10, count($playerMetaRows)) ?>
                </button>
              <?php endfor; ?>
            </div>
          <?php endif; ?>

          <p style="margin-top:10px;">
            此區以已確認小局為基準統計玩家整體表現；若出場數較少，勝率容易受單一小局影響。
          </p>
        </div>
      </div>

      <?php if ($isAdmin && !empty($auditRows)): ?>
        <div class="live-section">
          <div class="live-card live-audit">
            <h3>⚠ 管理員資料檢查</h3>
            <table class="live-table">
              <thead>
                <tr>
                  <th>場次</th>
                  <th>小局</th>
                  <th>P1</th>
                  <th>P1牌組</th>
                  <th>P2</th>
                  <th>P2牌組</th>
                  <th>狀態</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($auditRows as $row): ?>
                  <tr>
                    <td><?= h($row['match_code']) ?></td>
                    <td><?= (int)$row['game_no'] ?></td>
                    <td><?= h($row['p1_name']) ?></td>
                    <td><?= h($row['player1_deck_code'] ?: '未對應') ?></td>
                    <td><?= h($row['p2_name']) ?></td>
                    <td><?= h($row['player2_deck_code'] ?: '未對應') ?></td>
                    <td><span class="live-pill red"><?= h($row['result_status']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p style="margin-top:10px;">
              此區僅管理員可見，用來檢查未對應牌組、爭議小局或待確認資料。
            </p>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </section>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('characterMetaTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const pager = document.querySelector('.live-pager[data-target="character"]');
    const pageSize = 10;

    let currentPage = 1;
    let currentSortIndex = null;
    let currentSortDir = 'desc';

    function getRows() {
      return Array.from(tbody.querySelectorAll('.live-character-row'));
    }

    function getFilteredRows() {
      const searchInput = document.getElementById('characterMetaSearch');
      const keyword = searchInput ? searchInput.value.trim().toLowerCase() : '';

      return getRows().filter(row => {
        if (!keyword) return true;

        const charCell = row.children[1];
        const charText = charCell ? charCell.innerText.trim().toLowerCase() : '';

        return charText.includes(keyword);
      });
    }

    function getCellValue(row, index, type) {
      const cell = row.children[index];
      if (!cell) return '';

      let text = cell.innerText.trim();

      if (type === 'number' || type === 'rank') {
        return parseFloat(text.replace('#', '').replace(/,/g, '')) || 0;
      }

      if (type === 'percent') {
        return parseFloat(text.replace('%', '').replace(/,/g, '')) || 0;
      }

      return text;
    }

    function rebuildPager(totalRows) {
      if (!pager) return;

      const pageCount = Math.ceil(totalRows / pageSize);
      pager.innerHTML = '';

      if (pageCount <= 1) return;

      for (let p = 1; p <= pageCount; p++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'live-page-btn' + (p === currentPage ? ' active' : '');
        btn.dataset.page = p;
        btn.textContent = ((p - 1) * pageSize + 1) + '-' + Math.min(p * pageSize, totalRows);

        btn.addEventListener('click', function() {
          currentPage = Number(this.dataset.page);
          showPage(currentPage);
        });

        pager.appendChild(btn);
      }
    }

    function showPage(page) {
      const allRows = getRows();
      const rows = getFilteredRows();
      const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));

      currentPage = Math.min(Math.max(1, Number(page)), pageCount);

      allRows.forEach(row => {
        row.style.display = 'none';
      });

      rows.forEach((row, index) => {
        const rowPage = Math.floor(index / pageSize) + 1;
        row.dataset.page = rowPage;
        row.style.display = rowPage === currentPage ? '' : 'none';
      });

      if (pager) {
        pager.querySelectorAll('.live-page-btn').forEach(btn => {
          btn.classList.toggle('active', Number(btn.dataset.page) === currentPage);
        });
      }
    }

    function sortTable(index, type, th) {
      const rows = getRows();

      if (currentSortIndex === index) {
        currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
      } else {
        currentSortIndex = index;
        currentSortDir = type === 'text' ? 'asc' : 'desc';
      }

      rows.sort((a, b) => {
        const av = getCellValue(a, index, type);
        const bv = getCellValue(b, index, type);

        let result = 0;

        if (type === 'text') {
          result = String(av).localeCompare(String(bv), 'zh-Hant');
        } else {
          result = Number(av) - Number(bv);
        }

        return currentSortDir === 'asc' ? result : -result;
      });

      rows.forEach(row => tbody.appendChild(row));

      table.querySelectorAll('th[data-sort]').forEach(head => {
        head.classList.remove('sort-asc', 'sort-desc');
      });

      th.classList.add(currentSortDir === 'asc' ? 'sort-asc' : 'sort-desc');

      currentPage = 1;
      rebuildPager(getFilteredRows().length);
      showPage(1);
    }

    table.querySelectorAll('th[data-sort]').forEach((th, index) => {
      th.addEventListener('click', function() {
        sortTable(index, this.dataset.sort, this);
      });
    });
    const searchInput = document.getElementById('characterMetaSearch');

    if (searchInput) {
      searchInput.addEventListener('input', function() {
        currentPage = 1;
        rebuildPager(getFilteredRows().length);
        showPage(1);
      });
    }
    rebuildPager(getFilteredRows().length);
    showPage(1);
  });
</script>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('playerMetaTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const pager = document.querySelector('.live-pager[data-target="player"]');
    const pageSize = 10;

    let currentPage = 1;

    function getRows() {
      return Array.from(tbody.querySelectorAll('.live-player-row'));
    }

    function showPage(page) {
      const rows = getRows();
      const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));

      currentPage = Math.min(Math.max(1, Number(page)), pageCount);

      rows.forEach((row, index) => {
        const rowPage = Math.floor(index / pageSize) + 1;
        row.dataset.page = rowPage;
        row.style.display = rowPage === currentPage ? '' : 'none';
      });

      if (pager) {
        pager.querySelectorAll('.live-page-btn').forEach(btn => {
          btn.classList.toggle('active', Number(btn.dataset.page) === currentPage);
        });
      }
    }

    if (pager) {
      pager.querySelectorAll('.live-page-btn').forEach(btn => {
        btn.addEventListener('click', function() {
          showPage(this.dataset.page);
        });
      });
    }

    showPage(1);
  });
</script>