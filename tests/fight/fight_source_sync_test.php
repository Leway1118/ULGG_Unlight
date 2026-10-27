<?php
declare(strict_types=1);

/**
 * P1 offline source contract for production-proven fight-list modifications.
 * No DB connection and no live rendering; actual website UI still needs review.
 */
$sourcePath = dirname(__DIR__, 2) . '/pages/fight.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    fwrite(STDERR, "[FAIL] cannot read pages/fight.php\n");
    exit(1);
}
function requireSource(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}
function onlyOnce(string $source, string $needle): int
{
    $pos = strpos($source, $needle);
    requireSource($pos !== false, "missing {$needle}");
    requireSource(strpos($source, $needle, $pos + strlen($needle)) === false, "duplicate {$needle}");
    return $pos;
}

// Do not reverse the CSS layout a second time: PHP owns presentation order.
requireSource(
    preg_match('/\.fight-all\s*\{[^}]*\bflex-direction:\s*column\s*;/s', $source) === 1,
    'fight-all must render fragments in DOM order'
);
requireSource(
    strpos($source, 'flex-direction: column-reverse;') === false,
    'column-reverse would double-reverse the PHP output'
);

$open = onlyOnce($source, '$fightRowFragments = [];');
$start = onlyOnce($source, '$fightRowStart = strlen($outPutString);');
$read = onlyOnce($source, 'while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {');
$bp = onlyOnce($source, '$bp1_pre = $bp1;');
$capture = onlyOnce($source, '$rowHtml = substr($outPutString, $fightRowStart);');
$truncate = onlyOnce($source, '$outPutString = substr($outPutString, 0, $fightRowStart);');
$collect = onlyOnce($source, '$fightRowFragments[] = $rowHtml;');
$reverse = onlyOnce($source, '$outPutString .= implode(' . "''" . ', array_reverse($fightRowFragments));');
$close = onlyOnce($source, '$outPutString .= ' . "'</div>'; //fight-all");
requireSource(
    $open < $read && $read < $start && $start < $bp &&
    $bp < $capture && $capture < $truncate && $truncate < $collect &&
    $collect < $reverse && $reverse < $close,
    'forward BP processing or fragment capture/presentation order changed'
);

// Browser layout shift: both event images reserve identical intrinsic dimensions.
requireSource(
    preg_match_all('/class="event" width="44" height="68" title="/', $source) === 2,
    'both event cards must declare intrinsic image dimensions'
);
requireSource(
    preg_match('/\.event\s*\{[^}]*width:\s*44px;[^}]*height:\s*68px;[^}]*object-fit:\s*contain;/s', $source) === 1,
    'event-card CSS size / object-fit changed'
);
requireSource(
    preg_match('/\.event-slot-empty\s*\{[^}]*width:\s*44px;[^}]*height:\s*68px;/s', $source) === 1,
    'empty event slot must reserve the same dimensions'
);

echo "[PASS] BP computation remains forward; presentation is reversed only once\n";
echo "[PASS] two event images and empty slots reserve 44x68 dimensions\n";
echo "[LIMIT] static source regression only; DB-backed live UI not tested\n";
