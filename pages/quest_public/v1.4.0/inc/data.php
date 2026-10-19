<?php
/**
 * Read-only access to the public JSON tree (Phase 2B / schema 1.1.0).
 *
 * Published files: manifest.json, overview.json, quality.json,
 * quest_catalog.json, teams_all.json and
 * windows/{today,7d,30d,all}/{regions,quests,quests_duration,star_matrix}.json.
 *
 * Nothing here recomputes a statistic: every number is copied out of the JSON.
 * The site additionally re-checks the published thresholds defensively so a
 * corrupted/hand-edited tree can never expose a below-threshold team, a thin
 * per-duration cell, or a below-threshold team association.
 */

declare(strict_types=1);

if (!defined('QPUB_VERSION')) {
    // Reached by direct web access to an include file: refuse instead of
    // leaking internals or fataling.
    http_response_code(404);
    exit;
}

function qpub_data_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    // An explicit override is authoritative: never silently fall back to another
    // tree when the operator pointed at a specific one.
    if (QPUB_DATA_DIR_OVERRIDE !== '') {
        return $dir = rtrim(QPUB_DATA_DIR_OVERRIDE, '/\\');
    }
    $candidates = [
        __DIR__ . '/../data',
        __DIR__ . '/../../out_live',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/manifest.json')) {
            return $dir = $candidate;
        }
    }
    return $dir = $candidates[0];
}

function qpub_read_json(string $relative): ?array
{
    static $cache = [];
    if (array_key_exists($relative, $cache)) {
        return $cache[$relative];
    }
    $path = qpub_data_dir() . '/' . $relative;
    if (!is_file($path)) {
        return $cache[$relative] = null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return $cache[$relative] = null;
    }
    $decoded = json_decode($raw, true);
    return $cache[$relative] = is_array($decoded) ? $decoded : null;
}

/** True when a published tree is present at all. */
function qpub_data_available(): bool
{
    return is_array(qpub_read_json('manifest.json'));
}

/** @return array<int,string> contract violations detected while loading */
function qpub_contract_warnings(): array
{
    static $warnings = null;
    if ($warnings !== null) {
        return $warnings;
    }
    $warnings = [];
    $manifest = qpub_read_json('manifest.json');
    if ($manifest === null) {
        return $warnings = ['manifest.json is missing'];
    }
    if (($manifest['contract_version'] ?? null) !== QPUB_VERSION) {
        $warnings[] = 'manifest.contract_version does not match ' . QPUB_VERSION;
    }
    $asOf = $manifest['as_of_utc'] ?? null;
    $generated = $manifest['generated_at_utc'] ?? null;
    if (is_string($asOf) && is_string($generated)) {
        if (strtotime($generated) < strtotime($asOf)) {
            $warnings[] = 'manifest.generated_at_utc is earlier than as_of_utc';
        }
    }
    $catalog = qpub_read_json('quest_catalog.json');
    if ($catalog === null) {
        $warnings[] = 'quest_catalog.json is missing';
    } else {
        if (($catalog['as_of_utc'] ?? null) !== $asOf) {
            $warnings[] = 'quest_catalog.json has a different as_of_utc';
        }
        if (!is_array($catalog['rows'] ?? null)) {
            $warnings[] = 'quest_catalog.json has no rows array';
        }
    }
    foreach (QPUB_WINDOWS as $window) {
        foreach (['regions', 'quests', 'star_matrix'] as $kind) {
            $doc = qpub_read_json("windows/{$window}/{$kind}.json");
            if ($doc === null) {
                $warnings[] = "windows/{$window}/{$kind}.json is missing";
                continue;
            }
            if (($doc['as_of_utc'] ?? null) !== $asOf) {
                $warnings[] = "windows/{$window}/{$kind}.json has a different as_of_utc";
            }
            if (!is_array($doc['rows'] ?? null)) {
                $warnings[] = "windows/{$window}/{$kind}.json has no rows array";
            }
        }
        $duration = qpub_read_json("windows/{$window}/quests_duration.json");
        if ($duration === null) {
            $warnings[] = "windows/{$window}/quests_duration.json is missing";
            continue;
        }
        if (($duration['as_of_utc'] ?? null) !== $asOf) {
            $warnings[] = "windows/{$window}/quests_duration.json has a different as_of_utc";
        }
        if (!is_array($duration['durations'] ?? null)) {
            $warnings[] = "windows/{$window}/quests_duration.json has no durations array";
        }
    }
    $teams = qpub_read_json('teams_all.json');
    if ($teams === null) {
        $warnings[] = 'teams_all.json is missing';
    } elseif (($teams['period'] ?? null) !== 'all') {
        $warnings[] = 'teams_all.json is not the all-period document';
    }
    return $warnings;
}

function qpub_meta(): array
{
    static $meta = null;
    if ($meta !== null) {
        return $meta;
    }
    $manifest = qpub_read_json('manifest.json') ?? [];
    $quality = qpub_read_json('quality.json') ?? [];
    return $meta = [
        'contract_version' => $manifest['contract_version'] ?? null,
        'schema_version' => $manifest['schema_version'] ?? null,
        'as_of_utc' => $manifest['as_of_utc'] ?? null,
        'generated_at_utc' => $manifest['generated_at_utc'] ?? null,
        'publish_after_snapshot' => array_key_exists('publish_after_snapshot', $manifest)
            ? (bool)$manifest['publish_after_snapshot'] : null,
        'anchor_in_future' => array_key_exists('anchor_in_future', $manifest)
            ? (bool)$manifest['anchor_in_future'] : null,
        'cutoffs_utc' => is_array($manifest['cutoffs_utc'] ?? null)
            ? $manifest['cutoffs_utc'] : [],
        'window_definition' => is_array($manifest['window_definition'] ?? null)
            ? $manifest['window_definition'] : [],
        'panel_version' => $manifest['source']['panel_version'] ?? null,
        'records' => $quality['records'] ?? ($manifest['source']['records'] ?? null),
        'runs_total' => $quality['runs_total'] ?? null,
        'catalog_rows' => $quality['catalog_rows'] ?? null,
        'history_exists' => (bool)($quality['history_exists'] ?? false),
        'value_engine' => is_array($quality['value_engine'] ?? null)
            ? $quality['value_engine'] : [],
    ];
}

/** @return array<int,array<string,mixed>> */
function qpub_window_rows(string $window, string $kind): array
{
    if (!in_array($window, QPUB_WINDOWS, true)) {
        return [];
    }
    $doc = qpub_read_json("windows/{$window}/{$kind}.json");
    $rows = $doc['rows'] ?? null;
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function qpub_window_doc(string $window, string $kind): array
{
    $doc = qpub_read_json("windows/{$window}/{$kind}.json");
    return is_array($doc) ? $doc : [];
}

function qpub_overview(): array
{
    $doc = qpub_read_json('overview.json');
    return is_array($doc) ? $doc : [];
}

function qpub_quality(): array
{
    $doc = qpub_read_json('quality.json');
    return is_array($doc) ? $doc : [];
}

/** Regions for one window, keyed by zone_key. */
function qpub_regions(string $window): array
{
    static $cache = [];
    if (isset($cache[$window])) {
        return $cache[$window];
    }
    $out = [];
    foreach (qpub_window_rows($window, 'regions') as $row) {
        $key = (string)($row['zone_key'] ?? '');
        if ($key !== '') {
            $out[$key] = $row;
        }
    }
    return $cache[$window] = $out;
}

/**
 * Team rows, defensively re-checked against the published thresholds.
 * Returns only rows that satisfy eligible>=5, qph_samples>=5, valh_samples>=5.
 */
function qpub_teams_rows(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $doc = qpub_read_json('teams_all.json') ?? [];
    $rows = [];
    foreach (($doc['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $eligible = $row['eligible_samples'] ?? null;
        $qphN = $row['qph_samples'] ?? null;
        $valhN = $row['valh_samples'] ?? null;
        if (!qpub_is_number($eligible) || $eligible < QPUB_TEAM_MIN_ELIGIBLE) {
            continue;
        }
        if (!qpub_is_number($qphN) || $qphN < QPUB_TEAM_MIN_QPH) {
            continue;
        }
        if (!qpub_is_number($valhN) || $valhN < QPUB_TEAM_MIN_VALH) {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

/** Star matrix for one window (the contract serves one global aggregate). */
function qpub_star_matrix(string $window): array
{
    $doc = qpub_window_doc($window, 'star_matrix');
    $rows = $doc['rows'] ?? [];
    return [
        'meta' => $doc,
        'rows' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
    ];
}

/** Duration labels come from the contract when present, else the panel table. */
function qpub_duration_label(int $index): string
{
    $doc = qpub_window_doc(QPUB_DEFAULT_WINDOW, 'star_matrix');
    foreach (($doc['rows'] ?? []) as $row) {
        if (is_array($row) && (int)($row['duration_index'] ?? -1) === $index) {
            $label = trim((string)($row['duration'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }
    }
    return QPUB_DURATION_LABELS[$index] ?? (string)$index;
}

/* ---------------------------------------------------------------------- */
/* Phase 2B: quest catalog, per-duration cells, team associations          */
/* ---------------------------------------------------------------------- */

/** Every reliably identified known Quest (identity authority). */
function qpub_catalog_rows(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    $doc = qpub_read_json('quest_catalog.json') ?? [];
    $rows = is_array($doc['rows'] ?? null)
        ? array_values(array_filter($doc['rows'], 'is_array')) : [];
    return $rows;
}

/** Catalog keyed by quest_key (zone_key#quest_id). */
function qpub_catalog_by_key(): array
{
    static $byKey = null;
    if ($byKey !== null) {
        return $byKey;
    }
    $byKey = [];
    foreach (qpub_catalog_rows() as $row) {
        $key = (string)($row['quest_key'] ?? '');
        if ($key !== '') {
            $byKey[$key] = $row;
        }
    }
    return $byKey;
}

/**
 * zone_key => true for every zone in the Quest catalog.
 *
 * Used to tell a *real* public region identity (which may simply have no
 * published team association) apart from a value that does not exist at all.
 */
function qpub_catalog_zone_keys(): array
{
    static $zones = null;
    if ($zones !== null) {
        return $zones;
    }
    $zones = [];
    foreach (qpub_catalog_rows() as $row) {
        $zoneKey = (string)($row['zone_key'] ?? '');
        if ($zoneKey !== '') {
            $zones[$zoneKey] = true;
        }
    }
    return $zones;
}

/**
 * quest_name => list of quest_key for the whole catalog.
 *
 * A name is a LABEL, never an identity: callers must only resolve it when it maps
 * to exactly one key (a name shared by several IDs stays rejected as ambiguous).
 */
function qpub_catalog_name_index(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = [];
    foreach (qpub_catalog_rows() as $row) {
        $name = (string)($row['quest_name'] ?? '');
        $key = (string)($row['quest_key'] ?? '');
        if ($name !== '' && $key !== '') {
            $index[$name][] = $key;
        }
    }
    return $index;
}

function qpub_quests_duration_doc(string $window): array
{
    if (!in_array($window, QPUB_WINDOWS, true)) {
        return [];
    }
    $doc = qpub_read_json("windows/{$window}/quests_duration.json");
    return is_array($doc) ? $doc : [];
}

/** True when the tree actually carries a per-duration Quest document. */
function qpub_duration_contract_available(string $window): bool
{
    return qpub_quests_duration_doc($window) !== [];
}

function qpub_duration_rows(string $window, int $duration): array
{
    $doc = qpub_quests_duration_doc($window);
    foreach (($doc['durations'] ?? []) as $block) {
        if (is_array($block) && (int)($block['duration_index'] ?? -1) === $duration) {
            $rows = $block['rows'] ?? [];
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }
    }
    return [];
}

/** Defensive re-check of the published per-duration cell thresholds. */
function qpub_duration_min_samples(string $window): array
{
    $doc = qpub_quests_duration_doc($window);
    return [
        'qp' => qpub_is_number($doc['min_qp_samples'] ?? null)
            ? (int)$doc['min_qp_samples'] : QPUB_DURATION_MIN_QP,
        'valh' => qpub_is_number($doc['min_valh_samples'] ?? null)
            ? (int)$doc['min_valh_samples'] : QPUB_DURATION_MIN_VALH,
    ];
}

/** Published per-duration cells that also pass the thresholds here. */
function qpub_duration_rows_safe(string $window, int $duration): array
{
    $min = qpub_duration_min_samples($window);
    $out = [];
    foreach (qpub_duration_rows($window, $duration) as $cell) {
        $qpN = $cell['qp_samples'] ?? null;
        $valhN = $cell['value_samples'] ?? null;
        if (!qpub_is_number($qpN) || (int)$qpN < $min['qp']) {
            continue;
        }
        if (!qpub_is_number($valhN) || (int)$valhN < $min['valh']) {
            continue;
        }
        $out[] = $cell;
    }
    return $out;
}

function qpub_team_assoc_min(): int
{
    static $min = null;
    if ($min !== null) {
        return $min;
    }
    $doc = qpub_read_json('teams_all.json') ?? [];
    return $min = (qpub_is_number($doc['min_association_samples'] ?? null)
        ? (int)$doc['min_association_samples'] : QPUB_TEAM_MIN_ASSOC);
}

/** Below-threshold associations are dropped defensively before display. */
function qpub_team_safe_associations(array $team): array
{
    $min = qpub_team_assoc_min();
    $regions = [];
    foreach (($team['regions'] ?? []) as $assoc) {
        if (!is_array($assoc)) {
            continue;
        }
        $samples = $assoc['samples'] ?? null;
        $zoneKey = (string)($assoc['zone_key'] ?? '');
        if (!qpub_is_number($samples) || $samples < $min || $zoneKey === '') {
            continue;
        }
        $regions[] = [
            'zone_key' => $zoneKey,
            'zone_name' => (string)($assoc['zone_name'] ?? ''),
            'samples' => (int)$samples,
        ];
    }
    $quests = [];
    foreach (($team['quests'] ?? []) as $assoc) {
        if (!is_array($assoc)) {
            continue;
        }
        $samples = $assoc['samples'] ?? null;
        $questKey = (string)($assoc['quest_key'] ?? '');
        if (!qpub_is_number($samples) || $samples < $min || $questKey === '') {
            continue;
        }
        $quests[] = [
            'quest_key' => $questKey,
            'zone_key' => (string)($assoc['zone_key'] ?? ''),
            'quest_name' => (string)($assoc['quest_name'] ?? ''),
            'samples' => (int)$samples,
        ];
    }
    return ['regions' => $regions, 'quests' => $quests];
}

/**
 * The distinct region / Quest identities carried by any published team.
 * Used to validate the Teams-tab drill filters.
 */
function qpub_team_scope(): array
{
    static $scope = null;
    if ($scope !== null) {
        return $scope;
    }
    $regionKeys = [];
    $questKeys = [];
    $questNames = [];
    foreach (qpub_teams_rows() as $team) {
        $safe = qpub_team_safe_associations($team);
        foreach ($safe['regions'] as $assoc) {
            $regionKeys[$assoc['zone_key']] = true;
        }
        foreach ($safe['quests'] as $assoc) {
            if (!isset($questKeys[$assoc['quest_key']])) {
                $questKeys[$assoc['quest_key']] = $assoc;
                $questNames[$assoc['quest_name']][] = $assoc['quest_key'];
            }
        }
    }
    return $scope = [
        'regions' => $regionKeys,
        'quests' => $questKeys,
        'quest_names' => $questNames,
    ];
}

/**
 * Quest tab view: the complete catalog joined with one window (or one
 * duration of that window). Unobserved efficiency is null, never zero.
 *
 * @return array<int,array<string,mixed>>
 */
function qpub_quest_view(string $window, string $duration): array
{
    if ($duration !== 'all') {
        $out = [];
        foreach (qpub_duration_rows_safe($window, (int)$duration) as $cell) {
            $out[] = qpub_quest_view_row(null, $cell, 'duration');
        }
        return $out;
    }

    $windowRows = qpub_window_rows($window, 'quests');
    $byKey = [];
    foreach ($windowRows as $row) {
        $key = (string)($row['quest_key'] ?? '');
        if ($key !== '') {
            $byKey[$key] = $row;
        }
    }

    $out = [];
    $seen = [];
    foreach (qpub_catalog_rows() as $cat) {
        $key = (string)($cat['quest_key'] ?? '');
        $match = $key !== '' && isset($byKey[$key]) ? $byKey[$key] : null;
        $out[] = qpub_quest_view_row($cat, $match, 'window');
        if ($key !== '') {
            $seen[$key] = true;
        }
    }
    /* Observed rows with no catalog identity (unresolved / off-catalog). */
    foreach ($windowRows as $row) {
        $key = (string)($row['quest_key'] ?? '');
        if ($key !== '' && isset($seen[$key])) {
            continue;
        }
        if (empty($row['observed'])) {
            continue;
        }
        $out[] = qpub_quest_view_row(null, $row, 'window');
    }
    return $out;
}

/** Uniform display row: catalog identity + (optional) window metrics. */
function qpub_quest_view_row(?array $cat, ?array $src, string $source): array
{
    $cat = $cat ?? [];
    $src = $src ?? [];
    $pick = static function (string $key) use ($cat, $src) {
        if (array_key_exists($key, $src) && $src[$key] !== null && $src[$key] !== '') {
            return $src[$key];
        }
        if (array_key_exists($key, $cat) && $cat[$key] !== null) {
            return $cat[$key];
        }
        return null;
    };

    $observed = $source === 'duration' || !empty($src['observed']);
    $observedAll = $observed
        || !empty($cat['observed_all_period'])
        || !empty($src['observed_all_period']);
    $status = $observed ? 'observed' : ($observedAll ? 'not_in_window' : 'never');

    $row = [
        'zone_key' => (string)$pick('zone_key'),
        'region_name' => (string)$pick('region_name'),
        'quest_id' => $pick('quest_id'),
        'quest_name' => (string)$pick('quest_name'),
        'quest_key' => (string)$pick('quest_key'),
        'quest_type' => $pick('quest_type'),
        'star' => $pick('star'),
        'occurrences' => $pick('occurrences'),
        'completed_count' => $pick('completed_count'),
        'qp' => $pick('qp'),
        'qph' => $pick('qph'),
        'qp_samples' => $pick('qp_samples'),
        'valh' => $pick('valh'),
        'value_samples' => $pick('value_samples'),
        'search_ap' => $pick('search_ap'),
        'execute_ap' => $pick('execute_ap'),
        'min_battles' => $pick('min_battles'),
        'median_text' => $pick('median_text'),
        'mean_text' => $pick('mean_text'),
        'low_sample' => (bool)($src['low_sample'] ?? false),
        'observed' => $observed,
        'observed_all_period' => $observedAll,
        'name_ambiguous' => !empty($cat['name_ambiguous']) || !empty($src['name_ambiguous']),
        'status' => $status,
        'source' => $source,
    ];
    if (!$observed) {
        /* "未觀測效率為 null": never present an unobserved cell as a number. */
        foreach ([
            'occurrences', 'completed_count', 'qp', 'qph', 'qp_samples',
            'valh', 'value_samples', 'median_text', 'mean_text',
        ] as $key) {
            $row[$key] = null;
        }
    }
    return $row;
}
