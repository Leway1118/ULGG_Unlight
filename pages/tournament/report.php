<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



require_once __DIR__ . '/../../config.php';
$pdo = $db;

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

    $stmt = $pdo->prepare("
        SELECT ack
        FROM game_user
        WHERE username = :name
           OR nickname = :name
        ORDER BY ack DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':name' => $loginName
    ]);

    $dbAck = $stmt->fetchColumn();

    return $dbAck !== false && (int)$dbAck >= 2;
}

$tournamentId = (int)($_GET['tid'] ?? 1);
$issue = $_GET['issue'] ?? 'pre';
$allowIssues = ['pre', 'deck_open', 'live', 'round1', 'top8', 'top8_20260720', 'top4', 'final'];

if (!in_array($issue, $allowIssues, true)) {
    die('戰報不存在');
}
$isAdmin = isTournamentAdmin($pdo);
$currentLoginName = currentLoginName();
$currentPlayerId = 0;

/* echo '<pre>';
echo 'currentLoginName = ';
var_dump($currentLoginName);

echo 'isAdmin = ';
var_dump($isAdmin);

echo 'currentPlayerId = ';
var_dump($currentPlayerId);

echo 'SESSION = ';
print_r($_SESSION);
echo '</pre>';
exit; */

$stmt = $pdo->prepare("
    SELECT id, seed_no
    FROM tournament_players
    WHERE tournament_id = ?
");
$stmt->execute([$tournamentId]);

$playerIdBySeed = [];
/* ===== 測試用：假裝自己是奇怪的叔叔 ===== */
/* $debugPretendSeed = 17;

$isAdmin = false;
$currentLoginName = '奇怪的叔叔';
$currentPlayerId = $playerIdBySeed[$debugPretendSeed] ?? 0; */
/* ===== 測試結束後請刪除 ===== */
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $playerIdBySeed[(int)$row['seed_no']] = (int)$row['id'];
}

if ($currentLoginName !== '') {
    $stmt = $pdo->prepare("
        SELECT id
        FROM tournament_players
        WHERE tournament_id = ?
          AND (
            game_name = ?
            OR display_name = ?
            OR discord_name = ?
            OR tonamel_user_name = ?
            OR tonamel_entry_name = ?
          )
        LIMIT 1
    ");
    $stmt->execute([
        $tournamentId,
        $currentLoginName,
        $currentLoginName,
        $currentLoginName,
        $currentLoginName,
        $currentLoginName
    ]);
    $currentPlayerId = (int)($stmt->fetchColumn() ?: 0);
}

$stmt = $pdo->prepare("
    SELECT *
    FROM tournament_reports
    WHERE tournament_id = ?
      AND issue_key = ?
      AND status = 'published'
    LIMIT 1
");
$stmt->execute([$tournamentId, $issue]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die('戰報尚未發布');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM tournament_report_sections
    WHERE report_id = ?
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute([$report['id']]);
$sectionsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reportSections = [];
foreach ($sectionsRaw as $sec) {
    $reportSections[$sec['section_key']] = $sec;
}

$pageTitleText = $report['report_title'] ?? 'ULGG CUP 戰報';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "ulgg_cup";

ob_start();
?>

<style>
    <?php include __DIR__ . '/assets/report.css'; ?>
</style>

<?php
$viewFile = __DIR__ . '/report_views/' . $issue . '.php';
if ($issue === 'top8_20260720') {
    if (!$isAdmin) {
        http_response_code(404);
        exit('Not Found');
    }

    require __DIR__ . '/report_views/top8_20260720.php';
    exit;
}
if (!file_exists($viewFile)) {
    die('戰報版型不存在');
}

include $viewFile;

$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
