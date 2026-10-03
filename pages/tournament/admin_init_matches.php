<?php
// /pages/tournament/admin_init_matches.php
// ULGG 杯：初始化 32 強單淘汰賽程
// ⚠️ 不要 session_start()，交給 config.php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';

$pdoConn = null;

if (isset($db) && $db instanceof PDO) {
  $pdoConn = $db;
} elseif (isset($pdo) && $pdo instanceof PDO) {
  $pdoConn = $pdo;
}

if (!$pdoConn) {
  http_response_code(500);
  echo '<pre>';
  echo '資料庫連線不存在或不是 PDO。' . PHP_EOL;
  echo '請檢查 config.php 內實際 PDO 變數名稱。' . PHP_EOL;
  echo '目前 $db：' . (isset($db) ? gettype($db) : '未定義') . PHP_EOL;
  echo '目前 $pdo：' . (isset($pdo) ? gettype($pdo) : '未定義') . PHP_EOL;
  echo '</pre>';
  exit;
}

$pdo = $pdoConn;

$pdo = $db;
$tournamentId = 1;
$pageTitleText = 'ULGG 杯賽程初始化';
$seoTitle = 'ULGG 杯賽程初始化 | UL.GG 管理員工具';
$pageTitleFull = 'ULGG 杯賽程初始化 | UL.GG';
$activeMenu = 'ulgg_cup_2026';

if (!function_exists('h')) {
  function h($value): string
  {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

function currentLoginName(): string
{
  return trim((string)(
    $_SESSION['username']
    ?? $_SESSION['nickname']
    ?? $_SESSION['user_name']
    ?? ''
  ));
}

function isTournamentAdmin(PDO $pdo): bool
{
  $ack = (int)(
    $_SESSION['ack']
    ?? $_SESSION['permission']
    ?? $_SESSION['check_ack']
    ?? 0
  );

  if ($ack >= 2) {
    return true;
  }

  $loginName = currentLoginName();

  if ($loginName === '') {
    return false;
  }

  $stmt = $pdo->prepare("        SELECT ack        FROM game_user        WHERE username = :name           OR nickname = :name        ORDER BY ack DESC        LIMIT 1    ");
  $stmt->execute([':name' => $loginName]);

  $dbAck = $stmt->fetchColumn();

  return $dbAck !== false && (int)$dbAck >= 2;
}

function tableColumns(PDO $pdo, string $tableName): array
{
  $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '`');
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $columns = [];
  foreach ($rows as $row) {
    $columns[$row['Field']] = true;
  }

  return $columns;
}

function ensureTournamentMatchesSchema(PDO $pdo): array
{
  $messages = [];

  $pdo->exec("        CREATE TABLE IF NOT EXISTS tournament_matches (            id INT(11) NOT NULL AUTO_INCREMENT,            tournament_id INT(11) NOT NULL DEFAULT 1,            round_key VARCHAR(30) NOT NULL,            round_name VARCHAR(50) NOT NULL,            match_no INT(11) NOT NULL,            match_code VARCHAR(20) NOT NULL,            player1_seed_no INT(11) DEFAULT NULL,            player2_seed_no INT(11) DEFAULT NULL,            player1_id INT(11) DEFAULT NULL,            player2_id INT(11) DEFAULT NULL,            player1_score INT(11) DEFAULT NULL,            player2_score INT(11) DEFAULT NULL,            winner_player_id INT(11) DEFAULT NULL,            status VARCHAR(30) NOT NULL DEFAULT 'waiting',            deadline_text VARCHAR(50) DEFAULT NULL,            room_id INT(11) DEFAULT NULL,            screenshot_url VARCHAR(500) DEFAULT NULL,            ban1 VARCHAR(100) DEFAULT NULL,            ban2 VARCHAR(100) DEFAULT NULL,            ban3 VARCHAR(100) DEFAULT NULL,            pick1 VARCHAR(100) DEFAULT NULL,            pick2 VARCHAR(100) DEFAULT NULL,            pick3 VARCHAR(100) DEFAULT NULL,            note  TEXT DEFAULT NULL,            next_match_code VARCHAR(20) DEFAULT NULL COMMENT '勝者晉級到哪一場',            next_slot TINYINT DEFAULT NULL COMMENT '1=進下一場P1, 2=進下一場P2',            source_match1_code VARCHAR(20) DEFAULT NULL COMMENT 'P1來源場次',            source_match2_code VARCHAR(20) DEFAULT NULL COMMENT 'P2來源場次',            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,            PRIMARY KEY (id),            UNIQUE KEY uniq_tournament_match_code (tournament_id, match_code),            KEY idx_tournament_round (tournament_id, round_key, match_no),            KEY idx_tournament_status (tournament_id, status)        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci    ");

  $columns = tableColumns($pdo, 'tournament_matches');

  $requiredAdds = [
    'next_match_code' => "ALTER TABLE tournament_matches ADD COLUMN next_match_code VARCHAR(20) DEFAULT NULL COMMENT '勝者晉級到哪一場' AFTER note",
    'next_slot' => "ALTER TABLE tournament_matches ADD COLUMN next_slot TINYINT DEFAULT NULL COMMENT '1=進下一場P1, 2=進下一場P2' AFTER next_match_code",
    'source_match1_code' => "ALTER TABLE tournament_matches ADD COLUMN source_match1_code VARCHAR(20) DEFAULT NULL COMMENT 'P1來源場次' AFTER next_slot",
    'source_match2_code' => "ALTER TABLE tournament_matches ADD COLUMN source_match2_code VARCHAR(20) DEFAULT NULL COMMENT 'P2來源場次' AFTER source_match1_code",
  ];

  foreach ($requiredAdds as $column => $sql) {
    if (!isset($columns[$column])) {
      $pdo->exec($sql);
      $messages[] = '已補上欄位：' . $column;
    }
  }

  return $messages;
}

function getPlayersBySeed(PDO $pdo, int $tournamentId): array
{
  $columns = tableColumns($pdo, 'tournament_players');
  $publicColumn = isset($columns['is_public']) ? 'is_public' : (isset($columns['public_flag']) ? 'public_flag' : null);

  $wherePublic = $publicColumn ? " AND {$publicColumn} = 1" : '';

  $stmt = $pdo->prepare("        SELECT id, seed_no, display_name, game_name, tonamel_entry_name, tonamel_user_name        FROM tournament_players        WHERE tournament_id = :tournament_id          {$wherePublic}        ORDER BY seed_no ASC    ");
  $stmt->execute([':tournament_id' => $tournamentId]);

  $playersBySeed = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $playersBySeed[(int)$row['seed_no']] = $row;
  }

  return $playersBySeed;
}

function playerDisplayName(?array $player, int $seedNo): string
{
  if (!$player) {
    return 'Seed ' . $seedNo . '（未匯入）';
  }

  foreach (['display_name', 'game_name', 'tonamel_entry_name', 'tonamel_user_name'] as $key) {
    if (!empty($player[$key])) {
      return (string)$player[$key];
    }
  }

  return 'Seed ' . $seedNo;
}

function buildUlggCupBracketRows(array $playersBySeed): array
{
  $rows = [];

  // 標準 32 人單淘汰 bracket seed 排列
  $round32Pairs = [
    [1, 32],
    [16, 17],
    [8, 25],
    [9, 24],
    [4, 29],
    [13, 20],
    [5, 28],
    [12, 21],
    [2, 31],
    [15, 18],
    [7, 26],
    [10, 23],
    [3, 30],
    [14, 19],
    [6, 27],
    [11, 22],
  ];

  foreach ($round32Pairs as $i => $pair) {
    $matchNo = $i + 1;
    $matchCode = 'M' . str_pad((string)$matchNo, 2, '0', STR_PAD_LEFT);
    $nextNo = 17 + intdiv($i, 2);

    $rows[] = [
      'round_key' => 'round32',
      'round_name' => '32強',
      'match_no' => $matchNo,
      'match_code' => $matchCode,
      'player1_seed_no' => $pair[0],
      'player2_seed_no' => $pair[1],
      'player1_id' => $playersBySeed[$pair[0]]['id'] ?? null,
      'player2_id' => $playersBySeed[$pair[1]]['id'] ?? null,
      'status' => 'waiting',
      'deadline_text' => '2026-07-12 23:59:00',
      'next_match_code' => 'M' . str_pad((string)$nextNo, 2, '0', STR_PAD_LEFT),
      'next_slot' => ($i % 2) + 1,
      'source_match1_code' => null,
      'source_match2_code' => null,
    ];
  }

  // 16強：M17 ~ M24
  for ($i = 0; $i < 8; $i++) {
    $matchNo = 17 + $i;
    $source1 = 'M' . str_pad((string)(1 + $i * 2), 2, '0', STR_PAD_LEFT);
    $source2 = 'M' . str_pad((string)(2 + $i * 2), 2, '0', STR_PAD_LEFT);
    $nextQfNo = 1 + intdiv($i, 2);

    $rows[] = [
      'round_key' => 'round16',
      'round_name' => '16強',
      'match_no' => $matchNo,
      'match_code' => 'M' . str_pad((string)$matchNo, 2, '0', STR_PAD_LEFT),
      'player1_seed_no' => null,
      'player2_seed_no' => null,
      'player1_id' => null,
      'player2_id' => null,
      'status' => 'pending',
      'deadline_text' => '2026-07-19 23:59:00',
      'next_match_code' => 'QF' . $nextQfNo,
      'next_slot' => ($i % 2) + 1,
      'source_match1_code' => $source1,
      'source_match2_code' => $source2,
    ];
  }

  // 八強：QF1 ~ QF4
  for ($i = 0; $i < 4; $i++) {
    $matchNo = 25 + $i;
    $source1 = 'M' . str_pad((string)(17 + $i * 2), 2, '0', STR_PAD_LEFT);
    $source2 = 'M' . str_pad((string)(18 + $i * 2), 2, '0', STR_PAD_LEFT);
    $nextSfNo = 1 + intdiv($i, 2);

    $rows[] = [
      'round_key' => 'quarterfinal',
      'round_name' => '八強',
      'match_no' => $matchNo,
      'match_code' => 'QF' . ($i + 1),
      'player1_seed_no' => null,
      'player2_seed_no' => null,
      'player1_id' => null,
      'player2_id' => null,
      'status' => 'pending',
      'deadline_text' => '2026-07-26 23:59:00',
      'next_match_code' => 'SF' . $nextSfNo,
      'next_slot' => ($i % 2) + 1,
      'source_match1_code' => $source1,
      'source_match2_code' => $source2,
    ];
  }

  // 準決賽：SF1 ~ SF2
  for ($i = 0; $i < 2; $i++) {
    $matchNo = 29 + $i;
    $source1 = 'QF' . (1 + $i * 2);
    $source2 = 'QF' . (2 + $i * 2);

    $rows[] = [
      'round_key' => 'semifinal',
      'round_name' => '準決賽',
      'match_no' => $matchNo,
      'match_code' => 'SF' . ($i + 1),
      'player1_seed_no' => null,
      'player2_seed_no' => null,
      'player1_id' => null,
      'player2_id' => null,
      'status' => 'pending',
      'deadline_text' => '2026-08-01 23:59:00',
      'next_match_code' => 'FINAL',
      'next_slot' => ($i % 2) + 1,
      'source_match1_code' => $source1,
      'source_match2_code' => $source2,
    ];
  }

  // 決賽：FINAL
  $rows[] = [
    'round_key' => 'final',
    'round_name' => '決賽',
    'match_no' => 31,
    'match_code' => 'FINAL',
    'player1_seed_no' => null,
    'player2_seed_no' => null,
    'player1_id' => null,
    'player2_id' => null,
    'status' => 'pending',
    'deadline_text' => '2026-08-02 23:59:00',
    'next_match_code' => null,
    'next_slot' => null,
    'source_match1_code' => 'SF1',
    'source_match2_code' => 'SF2',
  ];

  return $rows;
}

function initTournamentMatches(PDO $pdo, int $tournamentId, array $playersBySeed): array
{
  $existingStmt = $pdo->prepare("        SELECT COUNT(*)        FROM tournament_matches        WHERE tournament_id = :tournament_id    ");
  $existingStmt->execute([':tournament_id' => $tournamentId]);
  $existingCount = (int)$existingStmt->fetchColumn();

  if ($existingCount > 0) {
    return [
      'ok' => false,
      'message' => '此賽事已存在 ' . $existingCount . ' 場賽程，為避免重複建立，本次未新增。',
      'inserted' => 0,
    ];
  }

  $rows = buildUlggCupBracketRows($playersBySeed);

  $insert = $pdo->prepare("
  INSERT INTO tournament_matches (
      tournament_id,
      round_key,
      round_name,
      match_no,
      match_code,
      player1_seed_no,
      player2_seed_no,
      player1_id,
      player2_id,
      status,
      deadline_text,
      next_match_code,
      next_slot,
      source_match1_code,
      source_match2_code,
      created_at,
      updated_at
  ) VALUES (
      :tournament_id,
      :round_key,
      :round_name,
      :match_no,
      :match_code,
      :player1_seed_no,
      :player2_seed_no,
      :player1_id,
      :player2_id,
      :status,
      :deadline_text,
      :next_match_code,
      :next_slot,
      :source_match1_code,
      :source_match2_code,
      CURRENT_TIMESTAMP,
      CURRENT_TIMESTAMP
  )
");
  $pdo->beginTransaction();

  try {
    foreach ($rows as $row) {
      $insert->execute([
        ':tournament_id' => $tournamentId,
        ':round_key' => $row['round_key'],
        ':round_name' => $row['round_name'],
        ':match_no' => $row['match_no'],
        ':match_code' => $row['match_code'],
        ':player1_seed_no' => $row['player1_seed_no'],
        ':player2_seed_no' => $row['player2_seed_no'],
        ':player1_id' => $row['player1_id'],
        ':player2_id' => $row['player2_id'],
        ':status' => $row['status'],
        ':deadline_text' => $row['deadline_text'],
        ':next_match_code' => $row['next_match_code'],
        ':next_slot' => $row['next_slot'],
        ':source_match1_code' => $row['source_match1_code'],
        ':source_match2_code' => $row['source_match2_code'],
      ]);
    }

    $pdo->commit();

    return [
      'ok' => true,
      'message' => '賽程初始化完成，共建立 ' . count($rows) . ' 場。',
      'inserted' => count($rows),
    ];
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function fetchMatches(PDO $pdo, int $tournamentId): array
{
  $stmt = $pdo->prepare("        SELECT            m.*,            p1.display_name AS p1_display_name,            p1.game_name AS p1_game_name,            p1.tonamel_entry_name AS p1_tonamel_entry_name,            p2.display_name AS p2_display_name,            p2.game_name AS p2_game_name,            p2.tonamel_entry_name AS p2_tonamel_entry_name        FROM tournament_matches m        LEFT JOIN tournament_players p1 ON p1.id = m.player1_id        LEFT JOIN tournament_players p2 ON p2.id = m.player2_id        WHERE m.tournament_id = :tournament_id        ORDER BY m.match_no ASC    ");
  $stmt->execute([':tournament_id' => $tournamentId]);

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$isAdmin = isTournamentAdmin($pdo);
$schemaMessages = [];
$actionMessage = null;
$actionOk = null;
$playersBySeed = [];
$matches = [];
$missingSeeds = [];

try {
  /* if ($isAdmin) {
    $schemaMessages = ensureTournamentMatchesSchema($pdo);
  } */

  $playersBySeed = getPlayersBySeed($pdo, $tournamentId);

  for ($seed = 1; $seed <= 32; $seed++) {
    if (!isset($playersBySeed[$seed])) {
      $missingSeeds[] = $seed;
    }
  }

  /* if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'init_matches') {
    if (!empty($missingSeeds)) {
      $actionOk = false;
      $actionMessage = '尚缺少 seed：' . implode(', ', $missingSeeds) . '。請先完成 32 位參賽者匯入，再初始化賽程。';
    } else {
      $result = initTournamentMatches($pdo, $tournamentId, $playersBySeed);
      $actionOk = $result['ok'];
      $actionMessage = $result['message'];
    }
  } */
  if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'init_matches') {
    $result = initTournamentMatches($pdo, $tournamentId, $playersBySeed);
    $actionOk = $result['ok'];
    $actionMessage = $result['message'];
  }

  if ($isAdmin) {
    $matches = fetchMatches($pdo, $tournamentId);
  }
} catch (Throwable $e) {
  $actionOk = false;
  $actionMessage = '發生錯誤：' . $e->getMessage();
}

ob_start();
?>

<style>
  .admin-init-wrap {
    max-width: 1120px;
    margin: 24px auto;
    padding: 0 14px 32px;
    color: #e8edf2;
  }

  .admin-panel {
    background: rgba(23, 27, 35, .96);
    border: 1px solid rgba(255, 255, 255, .09);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    box-shadow: 0 10px 26px rgba(0, 0, 0, .22);
  }

  .admin-title {
    margin: 0 0 8px;
    font-size: 24px;
    color: #fff;
    font-weight: 800;
  }

  .admin-desc {
    color: #aeb8c7;
    line-height: 1.7;
    margin: 0;
  }

  .admin-alert {
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 16px;
    border: 1px solid rgba(255, 255, 255, .12);
  }

  .admin-alert.ok {
    background: rgba(70, 190, 110, .13);
    color: #b9ffd0;
    border-color: rgba(70, 190, 110, .32);
  }

  .admin-alert.fail {
    background: rgba(230, 80, 80, .13);
    color: #ffc0c0;
    border-color: rgba(230, 80, 80, .32);
  }

  .admin-alert.info {
    background: rgba(90, 150, 220, .13);
    color: #c4ddff;
    border-color: rgba(90, 150, 220, .32);
  }

  .admin-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-top: 16px;
  }

  .admin-btn {
    border: 0;
    border-radius: 10px;
    padding: 10px 16px;
    background: rgba(199, 64, 80, .9);
    color: #fff;
    font-weight: 800;
    cursor: pointer;
  }

  .admin-btn:disabled {
    opacity: .45;
    cursor: not-allowed;
  }

  .admin-link {
    color: #ffd98a;
    text-decoration: none;
  }

  .admin-table-wrap {
    overflow-x: auto;
  }

  .admin-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 860px;
  }

  .admin-table th,
  .admin-table td {
    padding: 9px 10px;
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    text-align: left;
    font-size: 13px;
  }

  .admin-table th {
    color: #ffd98a;
    background: rgba(255, 255, 255, .04);
  }

  .admin-muted {
    color: #8f9aaa;
  }

  .admin-code {
    font-family: Consolas, Monaco, monospace;
    color: #fff;
  }
</style>

<div class="admin-init-wrap">
  <div class="admin-panel">
    <h1 class="admin-title">ULGG 杯賽程初始化</h1>
    <p class="admin-desc">
      此頁用來建立 tournament_id = <?= h($tournamentId) ?> 的 32 人單淘汰賽程。<br>
      建立後會產生 31 場：32強 16 場、16強 8 場、八強 4 場、準決賽 2 場、決賽 1 場。
    </p>
  </div>

  <?php if (!$isAdmin): ?>
    <div class="admin-alert fail">你沒有權限使用此頁。</div>
  <?php else: ?>

    <?php if ($actionMessage !== null): ?>
      <div class="admin-alert <?= $actionOk ? 'ok' : 'fail' ?>"><?= h($actionMessage) ?></div>
    <?php endif; ?>

    <?php if (!empty($schemaMessages)): ?>
      <div class="admin-alert info">
        <?= h(implode('、', $schemaMessages)) ?>
      </div>
    <?php endif; ?>

    <div class="admin-panel">
      <h2 class="admin-title" style="font-size:20px;">初始化狀態</h2>
      <p class="admin-desc">
        目前公開參賽者數量：<?= h(count($playersBySeed)) ?> / 32<br>
        目前已建立賽程數量：<?= h(count($matches)) ?> / 31
      </p>

      <?php if (!empty($missingSeeds)): ?>
        <div class="admin-alert fail" style="margin-top:14px;">
          尚缺少 seed：<?= h(implode(', ', $missingSeeds)) ?>。請先回活動頁匯入完整 CSV。
        </div>
      <?php endif; ?>

      <form method="post" class="admin-actions" onsubmit="return confirm('確定要初始化 ULGG 杯賽程嗎？建立後不會重複新增。');">
        <input type="hidden" name="action" value="init_matches">
        <button class="admin-btn" type="submit" <?= (count($matches) > 0) ? 'disabled' : '' ?>>
           建立 32 強賽程
        </button>
        <a class="admin-link" href="/pages/tournament/ulgg_cup.php">返回 ULGG 杯活動頁</a>
      </form>
    </div>

    <div class="admin-panel">
      <h2 class="admin-title" style="font-size:20px;">32 強預覽</h2>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>場次</th>
              <th>階段</th>
              <th>Player 1</th>
              <th>Player 2</th>
              <th>勝者進入</th>
              <th>截止時間</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice(buildUlggCupBracketRows($playersBySeed), 0, 16) as $row): ?>
              <tr>
                <td class="admin-code"><?= h($row['match_code']) ?></td>
                <td><?= h($row['round_name']) ?></td>
                <td>#<?= h($row['player1_seed_no']) ?> <?= h(playerDisplayName($playersBySeed[$row['player1_seed_no']] ?? null, (int)$row['player1_seed_no'])) ?></td>
                <td>#<?= h($row['player2_seed_no']) ?> <?= h(playerDisplayName($playersBySeed[$row['player2_seed_no']] ?? null, (int)$row['player2_seed_no'])) ?></td>
                <td class="admin-code"><?= h($row['next_match_code']) ?> / Slot <?= h($row['next_slot']) ?></td>
                <td><?= h($row['deadline_text']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if (!empty($matches)): ?>
      <div class="admin-panel">
        <h2 class="admin-title" style="font-size:20px;">已建立賽程</h2>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>場次</th>
                <th>階段</th>
                <th>P1</th>
                <th>P2</th>
                <th>狀態</th>
                <th>來源</th>
                <th>勝者進入</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($matches as $m): ?>
                <?php
                $p1Name = $m['p1_display_name'] ?: ($m['p1_game_name'] ?: ($m['p1_tonamel_entry_name'] ?: '待定'));
                $p2Name = $m['p2_display_name'] ?: ($m['p2_game_name'] ?: ($m['p2_tonamel_entry_name'] ?: '待定'));
                ?>
                <tr>
                  <td class="admin-code"><?= h($m['match_code']) ?></td>
                  <td><?= h($m['round_name']) ?></td>
                  <td><?= h($p1Name) ?></td>
                  <td><?= h($p2Name) ?></td>
                  <td><?= h($m['status']) ?></td>
                  <td class="admin-muted">
                    <?= h(trim(($m['source_match1_code'] ?? '') . ' ' . ($m['source_match2_code'] ?? '')) ?: '-') ?>
                  </td>
                  <td class="admin-code">
                    <?= h($m['next_match_code'] ?: '-') ?>
                    <?= $m['next_slot'] ? ' / Slot ' . h($m['next_slot']) : '' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>