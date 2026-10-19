<?php
/**
 * UL.GG Quest Public Analytics V1.4 - Phase 2A staging site bootstrap.
 *
 * The public pages are intentionally FREE of session and database usage.
 * `layout/base.php` + `config.php` both force a PDO connection and
 * `session_start()`, so a public page must NOT include them (see README.md,
 * "Why this page does not include layout/base.php").
 *
 * Environment knobs (all optional; defaults are production-shaped):
 *   ULGG_PUBLIC_DATA_DIR    : directory holding the Phase 1 JSON tree
 *   ULGG_PUBLIC_ASSET_BASE  : base URL for shared CSS/JS (default /assets)
 *   ULGG_PUBLIC_BASE_PATH   : base URL path of these pages. Default: derived
 *                             from the directory of the running entry script,
 *                             so the package keeps working after it is moved to
 *                             a versioned directory (/pages/quest_public/<ver>/).
 */

declare(strict_types=1);

/**
 * Refuse a *direct* web request to this include. It is only ever pulled in by an
 * entry page (index.php / quest.php / stars.php / teams.php); asking for
 * /inc/bootstrap.php directly must not execute the include body. This file is the
 * one that defines QPUB_VERSION, so it cannot use the `!defined('QPUB_VERSION')`
 * guard the other includes use.
 *
 * Two SAPI-independent signals are checked, and both can only be true when
 * bootstrap.php itself was the requested script, so a legitimate include from a
 * page is never rejected:
 *   - SCRIPT_FILENAME resolves to this file (Apache mod_php / php-fpm), or
 *   - SCRIPT_NAME's basename is this file (front controllers may rewrite the
 *     filename but keep the request URI).
 */
if ((isset($_SERVER['SCRIPT_FILENAME'])
        && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__))
    || (isset($_SERVER['SCRIPT_NAME'])
        && basename((string)$_SERVER['SCRIPT_NAME']) === basename(__FILE__))) {
    http_response_code(404);
    exit;
}

if (!defined('QPUB_VERSION')) {
    /* Published contract these pages consume. */
    define('QPUB_VERSION', 'ulgg.quest_public.v1.4');
    define('QPUB_PHASE', 'Phase 2D (rc)');

    $env = static function (string $key, string $default = ''): string {
        $value = getenv($key);
        if ($value === false) {
            $value = $_SERVER[$key] ?? '';
        }
        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    };

    if (!defined('QPUB_DATA_DIR_OVERRIDE')) {
        define('QPUB_DATA_DIR_OVERRIDE', $env('ULGG_PUBLIC_DATA_DIR'));
    }
    if (!defined('QPUB_ASSET_BASE')) {
        define('QPUB_ASSET_BASE', rtrim($env('ULGG_PUBLIC_ASSET_BASE', '/assets'), '/'));
    }
    /* Entry scripts. Only these may define the public base path, so an
       include pulled in from an unrelated front controller cannot do it. */
    define('QPUB_ENTRY_SCRIPTS', ['index.php', 'quest.php', 'stars.php', 'teams.php']);

    if (!defined('QPUB_BASE_PATH')) {
        /* Phase 2D: the package is deployed under a VERSIONED directory
           (/pages/quest_public/<version>/), so the base path is derived from
           the running entry script instead of being hard-coded. The derivation
           is skipped for CLI / unknown front controllers, which fall back to
           same-directory relative links. ULGG_PUBLIC_BASE_PATH still wins. */
        $basePath = rtrim($env('ULGG_PUBLIC_BASE_PATH', ''), '/');
        if ($basePath === '') {
            $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            if (in_array(basename($scriptName), QPUB_ENTRY_SCRIPTS, true)) {
                /* dirname() is platform aware: normalise before use so a Windows
                   dev server cannot produce a backslash base path. */
                $derived = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
                $basePath = ($derived === '.' || $derived === '/') ? '' : $derived;
            }
        }
        define('QPUB_BASE_PATH', $basePath);
    }

    /* Time windows, matching the frozen public contract. */
    define('QPUB_WINDOWS', ['today', '7d', '30d', 'all']);
    define('QPUB_DEFAULT_WINDOW', 'all');

    /* Durations are the panel's verified duration indexes 0..10 ("0m".."3d"). */
    define('QPUB_DURATION_LABELS', [
        0 => '0m', 1 => '3m', 2 => '10m', 3 => '30m', 4 => '1h', 5 => '2h',
        6 => '4h', 7 => '8h', 8 => '16h', 9 => '1d', 10 => '3d',
    ]);
    define('QPUB_DURATIONS', array_keys(QPUB_DURATION_LABELS));

    /* Team publication thresholds (mirrors the exporter contract). */
    define('QPUB_TEAM_MIN_ELIGIBLE', 5);
    define('QPUB_TEAM_MIN_QPH', 5);
    define('QPUB_TEAM_MIN_VALH', 5);

    /* Phase 2B: team association + per-duration Quest thresholds. */
    define('QPUB_TEAM_MIN_ASSOC', 5);
    define('QPUB_DURATION_MIN_QP', 5);
    define('QPUB_DURATION_MIN_VALH', 5);

    /* Low-sample presentation flag threshold (regions/quests only). */
    define('QPUB_LOW_SAMPLE', 5);

    define('QPUB_QUEST_PAGE_SIZE', 50);

    define('QPUB_TABS', [
        'regions' => ['file' => 'index.php', 'label' => '地域排行', 'en' => 'Regions'],
        'quests' => ['file' => 'quest.php', 'label' => 'Quest 效率', 'en' => 'Quests'],
        'stars' => ['file' => 'stars.php', 'label' => '星數機率', 'en' => 'Stars'],
        'teams' => ['file' => 'teams.php', 'label' => '隊伍比較', 'en' => 'Teams'],
    ]);
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/params.php';
require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/layout.php';
