<?php
/**
 * Small output/format helpers. Every value that reaches HTML goes through
 * qpub_h() (ENT_QUOTES, UTF-8) - there is no unescaped echo of data in this
 * staging site.
 */

declare(strict_types=1);

if (!defined('QPUB_VERSION')) {
    // Reached by direct web access to an include file: refuse instead of
    // leaking internals or fataling.
    http_response_code(404);
    exit;
}

/** Escape for HTML text and attribute context. */
function qpub_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape for a URL that is already built from whitelisted parts. */
function qpub_hu(string $url): string
{
    return qpub_h($url);
}

function qpub_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function qpub_substr(string $value, int $start, int $length): string
{
    return function_exists('mb_substr')
        ? mb_substr($value, $start, $length, 'UTF-8')
        : substr($value, $start, $length);
}

/** Remove control characters and collapse whitespace (URL/parameter hygiene). */
function qpub_clean_text(string $value, int $max = 64): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $stripped = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    if ($stripped === null) {
        // Invalid UTF-8: drop non-ASCII bytes rather than echoing them back.
        $stripped = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    }
    $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;
    return qpub_substr(trim($stripped), 0, $max);
}

function qpub_is_number(mixed $value): bool
{
    return is_int($value) || is_float($value);
}

/** Numeric display with a fixed precision; null => "—". */
function qpub_num(mixed $value, int $decimals = 2): string
{
    if (!qpub_is_number($value)) {
        return '—';
    }
    return number_format((float)$value, $decimals, '.', ',');
}

/** Counts without decimals; null => "—". */
function qpub_int(mixed $value): string
{
    if (!qpub_is_number($value)) {
        return '—';
    }
    return number_format((float)$value, 0, '.', ',');
}

function qpub_pct(mixed $value, int $decimals = 1): string
{
    if (!qpub_is_number($value)) {
        return '—';
    }
    return number_format((float)$value, $decimals, '.', ',') . '%';
}

/**
 * Display text for HTML *text* context: trims, substitutes an em dash for empty
 * values, and ESCAPES the result.
 *
 * Escaping here is deliberate: published strings (quest names, region names,
 * team names, model names) come from player-influenced data, so they must never
 * reach the response raw. Do not wrap this in qpub_h() again, and do not use it
 * for attribute values that are built from several parts.
 */
function qpub_text(mixed $value): string
{
    if ($value === null) {
        return '—';
    }
    $text = trim((string)$value);
    return $text === '' ? '—' : qpub_h($text);
}

/**
 * Low-sample presentation chip for regions/quests. The public contract does not
 * limit n for these tables, so the row stays visible and is flagged instead.
 */
function qpub_low_sample(array $row): bool
{
    $threshold = QPUB_LOW_SAMPLE;
    foreach (['eligible_samples', 'qp_samples', 'valh_samples', 'value_samples'] as $key) {
        if (array_key_exists($key, $row) && qpub_is_number($row[$key])) {
            if ((float)$row[$key] < $threshold) {
                return true;
            }
        }
    }
    return false;
}

function qpub_badge(string $text, string $kind = 'muted'): string
{
    return '<span class="qpub-badge qpub-badge--' . qpub_h($kind) . '">'
        . qpub_h($text) . '</span>';
}

/** Neutral metadata chip: observation window / snapshot / publish date. */
function qpub_meta_chip(string $label, string $value): string
{
    return '<span class="qpub-meta-chip"><span class="qpub-meta-chip__label">'
        . qpub_h($label) . '</span><span class="qpub-meta-chip__value">'
        . qpub_h($value) . '</span></span>';
}

function qpub_empty_state(string $title, string $hint = ''): string
{
    $html = '<div class="qpub-empty" role="status"><p class="qpub-empty__title">'
        . qpub_h($title) . '</p>';
    if ($hint !== '') {
        $html .= '<p class="qpub-empty__hint">' . qpub_h($hint) . '</p>';
    }
    return $html . '</div>';
}

/** "./index.php" on staging, "/pages/quest_public/index.php" in production. */
function qpub_page_url(string $file, array $params = []): string
{
    return qpub_url($file, $params);
}

/** UTC ISO string -> "2026-10-18 12:00 UTC (台北 20:00)". */
function qpub_utc_display(?string $iso, bool $withTaipei = true): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($iso);
    } catch (Exception) {
        return $iso;
    }
    $utc = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
    if (!$withTaipei) {
        return $utc . ' UTC';
    }
    $tpe = $dt->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i');
    return $utc . ' UTC（台北 ' . $tpe . '）';
}

function qpub_utc_short(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($iso);
    } catch (Exception) {
        return $iso;
    }
    return $dt->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i');
}
