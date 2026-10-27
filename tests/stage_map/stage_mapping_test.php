<?php
declare(strict_types=1);

/**
 * Offline regression guard for the stage IDs in fight list/detail.
 * This validates the mappings only; it does not open a DB or prove live UI output.
 */
$root = dirname(__DIR__, 2);
$expected = [
    13 => '白魔的圓環石陣',
    14 => '聖域的凱旋門',
];

$canonical = require $root . '/lib/stage_map.php';
if (!is_array($canonical)) {
    fwrite(STDERR, "[FAIL] canonical stage map is not an array\n");
    exit(1);
}

foreach ($expected as $id => $name) {
    if (($canonical[$id] ?? null) !== $name) {
        fwrite(STDERR, "[FAIL] canonical stage {$id} mismatch\n");
        exit(1);
    }
}
if (($canonical[12] ?? null) !== '烏波斯黑湖') {
    fwrite(STDERR, "[FAIL] existing stage 12 changed\n");
    exit(1);
}

foreach (['pages/fight.php', 'pages/fight_detail.php'] as $relativePath) {
    $source = file_get_contents($root . '/' . $relativePath);
    if ($source === false) {
        fwrite(STDERR, "[FAIL] could not read {$relativePath}\n");
        exit(1);
    }

    foreach ($expected as $id => $name) {
        $literal = preg_quote("'" . $name . "'", '/');
        $pattern = '/^[ \t]*' . $id . '[ \t]*=>[ \t]*' . $literal . '[ \t]*,/mu';
        $found = preg_match_all($pattern, $source);
        if ($found !== 1) {
            fwrite(STDERR, "[FAIL] {$relativePath}: stage {$id} mapping missing or duplicated\n");
            exit(1);
        }
    }
}

echo "[PASS] stage ID 13/14 agree in canonical, fight list, and fight detail\n";
echo "[LIMIT] this is a source-level regression check, not a live UI or authoritative game-data check\n";
