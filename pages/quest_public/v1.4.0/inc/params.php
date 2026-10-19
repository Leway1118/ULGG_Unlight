<?php
/**
 * URL parameter handling.
 *
 * Every parameter is validated against an explicit whitelist and against the
 * published data. Anything unknown, malformed or not present in the data is
 * dropped (and reported in the UI as an ignored parameter) rather than being
 * echoed back into a link or the page.
 */

declare(strict_types=1);

if (!defined('QPUB_VERSION')) {
    // Reached by direct web access to an include file: refuse instead of
    // leaking internals or fataling.
    http_response_code(404);
    exit;
}

$GLOBALS['QPUB_IGNORED'] = [];

function qpub_reject(string $key, mixed $value = null): void
{
    $shown = is_scalar($value) ? qpub_clean_text((string)$value, 24) : '(array)';
    $entry = $key . ($shown === '' ? '' : '=' . $shown);
    if (!in_array($entry, $GLOBALS['QPUB_IGNORED'], true)) {
        $GLOBALS['QPUB_IGNORED'][] = $entry;
    }
}

function qpub_ignored_params(): array
{
    return $GLOBALS['QPUB_IGNORED'];
}

/**
 * Raw scalar read with control-character hygiene. Arrays / non-scalars are
 * rejected outright.
 */
function qpub_raw(string $key, int $max = 64): string
{
    if (!array_key_exists($key, $_GET)) {
        return '';
    }
    $value = $_GET[$key];
    if (!is_string($value)) {
        qpub_reject($key, is_array($value) ? '' : $value);
        return '';
    }
    return qpub_clean_text($value, $max);
}

function qpub_param_window(): string
{
    $raw = qpub_raw('window', 8);
    if ($raw === '') {
        return QPUB_DEFAULT_WINDOW;
    }
    if (!in_array($raw, QPUB_WINDOWS, true)) {
        qpub_reject('window', $raw);
        return QPUB_DEFAULT_WINDOW;
    }
    return $raw;
}

/** 'all' or '0'..'10'. */
function qpub_param_duration(): string
{
    $raw = qpub_raw('duration', 4);
    if ($raw === '' || $raw === 'all') {
        return 'all';
    }
    if (!preg_match('/^\d{1,2}$/', $raw) || !in_array((int)$raw, QPUB_DURATIONS, true)) {
        qpub_reject('duration', $raw);
        return 'all';
    }
    return (string)(int)$raw;
}

function qpub_param_sort(array $allowed, string $default): string
{
    $raw = qpub_raw('sort', 24);
    if ($raw === '') {
        return $default;
    }
    if (!in_array($raw, $allowed, true)) {
        qpub_reject('sort', $raw);
        return $default;
    }
    return $raw;
}

function qpub_param_dir(string $default = 'desc'): string
{
    $raw = strtolower(qpub_raw('dir', 8));
    if ($raw === '') {
        return $default;
    }
    if (!in_array($raw, ['asc', 'desc'], true)) {
        qpub_reject('dir', $raw);
        return $default;
    }
    return $raw;
}

function qpub_param_page(): int
{
    $raw = qpub_raw('page', 6);
    if ($raw === '') {
        return 1;
    }
    if (!preg_match('/^\d{1,5}$/', $raw) || (int)$raw < 1) {
        qpub_reject('page', $raw);
        return 1;
    }
    return (int)$raw;
}

/** Quest index for one window: names => row, plus id => [names]. */
function qpub_quest_index(string $window): array
{
    static $cache = [];
    if (isset($cache[$window])) {
        return $cache[$window];
    }
    $byName = [];
    $byId = [];
    foreach (qpub_window_rows($window, 'quests') as $row) {
        $name = (string)($row['quest_name'] ?? '');
        if ($name !== '') {
            $byName[$name] = $row;
        }
        $id = $row['quest_id'] ?? null;
        if (qpub_is_number($id)) {
            $byId[(string)(int)$id][] = $row;
        }
    }
    return $cache[$window] = ['by_name' => $byName, 'by_id' => $byId];
}

/** Region must exist in the given window and look like "z:s". */
function qpub_param_region(string $window): string
{
    $raw = qpub_raw('region', 12);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('/^\d{1,3}:\d{1,3}$/', $raw)) {
        qpub_reject('region', $raw);
        return '';
    }
    if (!array_key_exists($raw, qpub_regions($window))) {
        qpub_reject('region', $raw);
        return '';
    }
    return $raw;
}

/**
 * Region filter for the Quest tab: any zone_key present in the Quest catalog
 * (the tab shows the full catalog, including zones with no runs in the window).
 */
function qpub_param_catalog_region(): string
{
    $raw = qpub_raw('region', 12);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('/^\d{1,3}:\d{1,3}$/', $raw)) {
        qpub_reject('region', $raw);
        return '';
    }
    static $zones = null;
    if ($zones === null) {
        $zones = [];
        foreach (qpub_catalog_rows() as $row) {
            $zoneKey = (string)($row['zone_key'] ?? '');
            if ($zoneKey !== '') {
                $zones[$zoneKey] = true;
            }
        }
    }
    if (!isset($zones[$raw])) {
        qpub_reject('region', $raw);
        return '';
    }
    return $raw;
}

/** Quest filter: published quest_key, exact name, or a numeric quest id. */
function qpub_param_quest(string $window, string $region = ''): string
{
    $raw = qpub_raw('quest', 64);
    if ($raw === '') {
        return '';
    }
    if (isset(qpub_catalog_by_key()[$raw])) {
        return $raw;
    }
    $index = qpub_quest_index($window);
    if (isset($index['by_name'][$raw])) {
        return $raw;
    }
    if (preg_match('/^\d{1,9}$/', $raw) && isset($index['by_id'][(string)(int)$raw])) {
        return $raw;
    }
    /* A catalog Quest may legitimately be unobserved in this window. */
    foreach (qpub_catalog_rows() as $cat) {
        if ((string)($cat['quest_name'] ?? '') === $raw) {
            return $raw;
        }
    }
    qpub_reject('quest', $raw);
    return '';
}

/** Free-text search term (never used as a link target, only as a filter). */
function qpub_param_q(): string
{
    return qpub_raw('q', 64);
}

function qpub_param_team(): string
{
    $raw = qpub_raw('team', 64);
    if ($raw === '') {
        return '';
    }
    foreach (qpub_teams_rows() as $row) {
        if ((string)($row['team'] ?? '') === $raw) {
            return $raw;
        }
    }
    qpub_reject('team', $raw);
    return '';
}

/**
 * Teams drill filter: a region from the published team associations.
 *
 * A *real* catalog region that has no published team association is still
 * accepted as a filter; it then matches no team and the tab shows its empty
 * state. Reporting it as an invalid parameter instead would silently drop the
 * filter and list every team, which a reader would misread as "the teams that
 * ran this region".
 */
function qpub_param_team_region(): string
{
    $raw = qpub_raw('region', 12);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('/^\d{1,3}:\d{1,3}$/', $raw)) {
        qpub_reject('region', $raw);
        return '';
    }
    if (isset(qpub_team_scope()['regions'][$raw])) {
        return $raw;
    }
    if (isset(qpub_catalog_zone_keys()[$raw])) {
        return $raw;
    }
    qpub_reject('region', $raw);
    return '';
}

/**
 * Teams drill filter: a Quest (published association, real catalog identity, or
 * an unambiguous name).
 *
 * As with the region filter, a real catalog Quest that no published team ran is
 * kept as a filter (matching no team -> empty state) rather than dropped and
 * reported as invalid. An ambiguous name is never resolved to one ID.
 */
function qpub_param_team_quest(): string
{
    $raw = qpub_raw('quest', 80);
    if ($raw === '') {
        return '';
    }
    $scope = qpub_team_scope();
    if (isset($scope['quests'][$raw])) {
        return $raw;
    }
    if (isset(qpub_catalog_by_key()[$raw])) {
        return $raw;
    }
    $catalogNames = qpub_catalog_name_index();
    if (isset($catalogNames[$raw]) && count($catalogNames[$raw]) === 1) {
        return (string)$catalogNames[$raw][0];
    }
    if (isset($scope['quest_names'][$raw]) && count($scope['quest_names'][$raw]) === 1) {
        return (string)$scope['quest_names'][$raw][0];
    }
    qpub_reject('quest', $raw);
    return '';
}

/** Drill-down context coming from another tab (never a filter on this tab). */
function qpub_param_context(string $window): array
{
    $context = [];
    $region = qpub_raw('from_region', 12);
    if ($region !== '') {
        if (preg_match('/^\d{1,3}:\d{1,3}$/', $region)
            && array_key_exists($region, qpub_regions($window))
        ) {
            $context['from_region'] = $region;
        } else {
            qpub_reject('from_region', $region);
        }
    }
    $quest = qpub_raw('from_quest', 64);
    if ($quest !== '') {
        $index = qpub_quest_index($window);
        if (isset($index['by_name'][$quest])) {
            $context['from_quest'] = $quest;
        } else {
            qpub_reject('from_quest', $quest);
        }
    }
    return $context;
}

const QPUB_URL_KEYS = [
    'window', 'region', 'quest', 'q', 'duration', 'sort', 'dir', 'page',
    'team', 'from_region', 'from_quest',
];

/** Build a shareable URL from whitelisted parts only. */
function qpub_url(string $file, array $params = []): string
{
    $query = [];
    foreach (QPUB_URL_KEYS as $key) {
        if (!array_key_exists($key, $params)) {
            continue;
        }
        $value = $params[$key];
        if ($value === null || $value === '' || $value === []) {
            continue;
        }
        if ($key === 'page' && (string)$value === '1') {
            continue;
        }
        $query[$key] = (string)$value;
    }
    $base = QPUB_BASE_PATH === '' ? '' : QPUB_BASE_PATH . '/';
    $queryString = http_build_query($query);
    // Keep the human-readable "zone:stage" region form literal, as published.
    $queryString = str_replace('%3A', ':', $queryString);
    return $base . $file . ($query === [] ? '' : '?' . $queryString);
}

/** Canonical URL of the current page (shareable / copyable). */
function qpub_current_url(string $file, array $params = []): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'ulgg.online');
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host) ?? 'ulgg.online';
    return $scheme . '://' . $host . qpub_url($file, $params);
}

/** Sidebar / tab link that keeps only the parameters relevant for that tab. */
function qpub_tab_params(string $tab, array $carry = []): array
{
    if ($tab === 'teams') {
        // The teams tab never inherits a time window or duration.
        return [];
    }
    $out = [];
    foreach (['window', 'region', 'quest', 'q', 'duration', 'sort', 'dir', 'page'] as $key) {
        if (array_key_exists($key, $carry) && $carry[$key] !== '' && $carry[$key] !== null) {
            $out[$key] = $carry[$key];
        }
    }
    return $out;
}
