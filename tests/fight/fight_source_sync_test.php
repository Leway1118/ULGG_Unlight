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

// Verify the exact VPS support component is included only after fight search results.
$ctaPath = dirname(__DIR__, 2) . '/includes/support_prompt_fight.php';
requireSource(is_file($ctaPath), 'fight support component must be tracked in Git');
$cta = file_get_contents($ctaPath);
requireSource(is_string($cta), 'cannot read fight support component');
$call = 'include __DIR__ . ' . "'/../includes/support_prompt_fight.php';";
requireSource(substr_count($source, $call) === 1, 'fight support component caller must exist exactly once');
requireSource(
    strpos($source, "if (!empty(" . '$index' . ")) {\n            " . $call) !== false,
    'support CTA must only render when the fight search returned rows'
);
requireSource(
    strpos($source, $call) > strpos($source, '$outPutString .= implode('),
    'support CTA must be rendered after the fight rows are assembled'
);
$layout = file_get_contents(dirname(__DIR__, 2) . '/layout/base.php');
requireSource(is_string($layout), 'layout base source unavailable');
requireSource(strpos($layout, 'id="supportModal"') !== false, 'shared payment modal missing');
requireSource(
    strpos($layout, "document.querySelectorAll('.open-support-modal')") !== false,
    'shared modal click handler missing'
);
ob_start();
include $ctaPath;
$ctaMarkup = (string) ob_get_clean();
requireSource(strpos($ctaMarkup, 'id="ulgg-fight-support-cta"') !== false, 'support CTA markup missing');
requireSource(strpos($ctaMarkup, 'class="open-support-modal ulgg-support-cta__button"') !== false, 'payment button not bound to modal');
requireSource(strpos($ctaMarkup, 'https://www.buymeacoffee.com/ulgg') !== false, 'external payment link mismatch');
requireSource(strpos($ctaMarkup, 'rel="noopener noreferrer"') !== false, 'external link missing isolation');
requireSource(strpos($ctaMarkup, "const endpoint = '/api/support_event.php';") !== false, 'support event endpoint reference changed');
requireSource(strpos($ctaMarkup, "credentials: 'omit'") !== false, 'support telemetry request must omit cookies');
requireSource(strpos($ctaMarkup, "referrerPolicy: 'no-referrer'") !== false, 'support telemetry referrer policy changed');
requireSource(strpos($ctaMarkup, "new Set(['impression', 'click_tw', 'click_bmc'])") !== false, 'support event allowlist changed');

echo "[PASS] verified fight support CTA, caller, and shared modal integration\n";
$eventPath = dirname(__DIR__, 2) . '/api/support_event.php';
requireSource(is_file($eventPath), 'support_event.php missing (telemetry would fail)');
$eventSource = file_get_contents($eventPath);
requireSource(is_string($eventSource), 'cannot read support_event.php');
requireSource(
    strpos($eventSource, "['impression', 'click_tw', 'click_bmc']") !== false,
    'support event server allowlist no longer agrees with client'
);
requireSource(strpos($eventSource, "http_response_code(204);") !== false, 'valid event 204 response missing');
echo "[PASS] support CTA and event endpoint are both tracked in Git\n";

echo "[PASS] BP computation remains forward; presentation is reversed only once\n";
echo "[PASS] two event images and empty slots reserve 44x68 dimensions\n";
echo "[LIMIT] static source regression only; DB-backed live UI not tested\n";
