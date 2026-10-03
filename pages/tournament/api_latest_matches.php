<?php
require_once __DIR__ . '/../../config.php';

$pdo = $db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$tournamentId = isset($_GET['tid']) ? (int)$_GET['tid'] : 1;

if ($tournamentId <= 0) {
  $tournamentId = 1;
}

$cacheDir = __DIR__ . '/../../cache';
$cacheFile = $cacheDir . '/tournament_latest_matches_' . $tournamentId . '.json';
$cacheTtl = 30;

if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0755, true);
}

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
  readfile($cacheFile);
  exit;
}

function latestPlayerName(array $row, string $side): string
{
  if ($side === 'p1') {
    return $row['p1_display_name']
      ?: ($row['p1_game_name'] ?: ($row['p1_tonamel_entry_name'] ?: 'P1'));
  }

  if ($side === 'p2') {
    return $row['p2_display_name']
      ?: ($row['p2_game_name'] ?: ($row['p2_tonamel_entry_name'] ?: 'P2'));
  }

  return $row['winner_display_name']
    ?: ($row['winner_game_name'] ?: ($row['winner_tonamel_entry_name'] ?: '勝者'));
}

function latestIsForfeit(array $row): bool
{
  $note = (string)($row['note'] ?? '');

  if (mb_strpos($note, '棄賽') !== false) {
    return true;
  }

  return
    (int)($row['player1_score'] ?? 0) === 0 &&
    (int)($row['player2_score'] ?? 0) === 0 &&
    (int)($row['winner_player_id'] ?? 0) > 0;
}

try {
  $stmt = $pdo->prepare("
    SELECT
      m.id,
      m.round_name,
      m.match_code,
      m.player1_id,
      m.player2_id,
      m.player1_score,
      m.player2_score,
      m.winner_player_id,
      m.status,
      m.note,
      m.updated_at,

      p1.display_name AS p1_display_name,
      p1.game_name AS p1_game_name,
      p1.tonamel_entry_name AS p1_tonamel_entry_name,

      p2.display_name AS p2_display_name,
      p2.game_name AS p2_game_name,
      p2.tonamel_entry_name AS p2_tonamel_entry_name,

      w.display_name AS winner_display_name,
      w.game_name AS winner_game_name,
      w.tonamel_entry_name AS winner_tonamel_entry_name

    FROM tournament_matches m
    LEFT JOIN tournament_players p1
      ON p1.id = m.player1_id
    LEFT JOIN tournament_players p2
      ON p2.id = m.player2_id
    LEFT JOIN tournament_players w
      ON w.id = m.winner_player_id
    WHERE m.tournament_id = :tournament_id
      AND m.status IN ('confirmed', 'finished')
      AND m.winner_player_id IS NOT NULL
    ORDER BY m.updated_at DESC, m.id DESC
    LIMIT 3
  ");

  $stmt->execute([
    ':tournament_id' => $tournamentId,
  ]);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $items = [];
  $latestKeyParts = [];

  foreach ($rows as $row) {
    $p1Name = latestPlayerName($row, 'p1');
    $p2Name = latestPlayerName($row, 'p2');
    $winnerName = latestPlayerName($row, 'winner');

    $p1Score = (int)($row['player1_score'] ?? 0);
    $p2Score = (int)($row['player2_score'] ?? 0);

    $winnerId = (int)($row['winner_player_id'] ?? 0);
    $p1IsWinner = $winnerId > 0 && $winnerId === (int)($row['player1_id'] ?? 0);
    $p2IsWinner = $winnerId > 0 && $winnerId === (int)($row['player2_id'] ?? 0);

    $isForfeit = latestIsForfeit($row);
    $scoreText = $p1Score . ' - ' . $p2Score;

    $loserName = $p1IsWinner ? $p2Name : $p1Name;

    if ($isForfeit) {
      $message = $loserName . ' 棄賽，' . $winnerName . ' 晉級。';
      $badge = '棄賽晉級';
      $type = 'forfeit';
    } else {
      $message = $winnerName . ' 以 ' . $scoreText . ' 晉級。';
      $badge = '賽果確認';
      $type = 'result';
    }

    $updatedAt = (string)($row['updated_at'] ?? '');

    $items[] = [
      'id' => (int)$row['id'],
      'round_name' => (string)$row['round_name'],
      'match_code' => (string)$row['match_code'],
      'p1_name' => $p1Name,
      'p2_name' => $p2Name,
      'winner_name' => $winnerName,
      'p1_score' => $p1Score,
      'p2_score' => $p2Score,
      'score_text' => $scoreText,
      'p1_is_winner' => $p1IsWinner,
      'p2_is_winner' => $p2IsWinner,
      'is_forfeit' => $isForfeit,
      'type' => $type,
      'badge' => $badge,
      'message' => $message,
      'updated_at' => $updatedAt,
      'time_text' => $updatedAt !== '' ? date('m/d H:i', strtotime($updatedAt)) : '-',
      'url' => '/pages/tournament/ulgg_cup_match.php?match=' . urlencode((string)$row['match_code']),
    ];

    $latestKeyParts[] = (string)$row['id'] . ':' . $updatedAt;
  }

  $payload = [
    'ok' => true,
    'tournament_id' => $tournamentId,
    'generated_at' => date('Y-m-d H:i:s'),
    'latest_key' => sha1(implode('|', $latestKeyParts)),
    'items' => $items,
  ];

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if ($json === false) {
    throw new RuntimeException('JSON encode failed');
  }

  @file_put_contents($cacheFile, $json);

  echo $json;
} catch (Throwable $e) {
  http_response_code(500);

  echo json_encode([
    'ok' => false,
    'message' => $e->getMessage(),
    'items' => [],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}