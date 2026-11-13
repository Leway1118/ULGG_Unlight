<?php
declare(strict_types=1);

/**
 * Offline regression guard for PvP stage names across UL.GG pages.
 * No database connection or live UI is required.
 */
$root = dirname(__DIR__, 2);
$canonical = require $root . '/lib/stage_map.php';
$expected = [
    -1 => '全部',
     0 => '雷城',
     1 => '誘森',
     2 => '垃圾',
     3 => '冰封',
     4 => '人魂',
     5 => '盡村',
     6 => '風暴',
     7 => '峰亥盧',
     8 => '魔都',
     9 => '狂山',
    10 => '魔女山谷',
    11 => '隨機',
    12 => '烏波斯黑湖',
    13 => '白魔的圓環石陣',
    14 => '聖域的凱旋門',
];

if ($canonical !== $expected) {
    fwrite(STDERR, "[FAIL] canonical PvP stage map differs from approved ID/name list\n");
    exit(1);
}

$consumers = [
    'lib/_analysis_base.php'               => '/stage_map.php',
    'pages/fight.php'                      => '/../lib/stage_map.php',
    'pages/fight_detail.php'               => '/../lib/stage_map.php',
    'pages/ranking_char.php'               => '/../lib/stage_map.php',
    'pages/tournament/ulgg_cup_game.php'   => '/../../lib/stage_map.php',
    'pages/tournament/ulgg_cup_match.php'  => '/../../lib/stage_map.php',
    'pages/checkpoint/index.php'           => '/../../lib/stage_map.php',
];

foreach ($consumers as $path => $relativeMap) {
    $source = file_get_contents($root . '/' . $path);
    if ($source === false) {
        fwrite(STDERR, "[FAIL] cannot read {$path}\n");
        exit(1);
    }
    $expectedRequire = '$stages = require __DIR__ . ' . "'" . $relativeMap . "';";
    if (!str_contains($source, $expectedRequire)) {
        fwrite(STDERR, "[FAIL] {$path} does not load the canonical map\n");
        exit(1);
    }
    if (preg_match('/^[ \t]*\$stages\s*=\s*\[/m', $source) === 1) {
        fwrite(STDERR, "[FAIL] {$path} contains a duplicate stage map\n");
        exit(1);
    }
}

$teamAnalysis = file_get_contents($root . '/pages/team_analysis.php');
if ($teamAnalysis === false
    || !str_contains($teamAnalysis, "require_once __DIR__ . '/../lib/_analysis_base.php'")
    || !str_contains($teamAnalysis, '$stages[$stageId]')) {
    fwrite(STDERR, "[FAIL] team analysis no longer uses the shared base stage map\n");
    exit(1);
}

echo "[PASS] stage IDs -1 through 14 retain approved names\n";
echo "[PASS] 7 consumers load one canonical map; team analysis inherits it\n";
echo "[LIMIT] source-level regression only; live UI and production sync require independent checks\n";
