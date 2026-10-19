<?php

declare(strict_types=1);

/*
 * UL.GG TW auth health — canonical predicate + dashboard row projection.
 *
 * P1_TW_AUTH_AWARE_DASHBOARD_20261019_V1
 *
 * Canonical source:
 *   /run/unlight/tw_auth_provider.json   (state + heartbeat + session flag + pid)
 * Auxiliary diagnostic (never an authority):
 *   /run/unlight/tw_watcher_health.json  (transition-only; may explain a failure,
 *                                         must never veto a healthy provider)
 *
 * Properties:
 *   - pure functions (no I/O except explicit file readers / /proc probe)
 *   - never returns or logs session_sha256, instance_id, UUID, token or ticket
 *   - safe for root cron and for unit tests
 */

const ULGG_TW_AUTH_TTL_SECONDS = 30.0;

/*
 * Rows that stop producing data when the TW provider is unhealthy.
 * Verified against channel_watcher_unlight.py main():
 *   ChannelWatcher(1,TW), ChannelWatcher(2,TW), RankingWatcher(TW).
 * JP / CR rows are retired or separately authed and must NOT be gated here.
 */
const ULGG_TW_AUTH_DEPENDENT_KEYS = [
    'channel1_tw',
    'channel2_tw',
    'ranking_bp_tw',
    'ranking_qp_tw',
];

function ulggTwAuthDependent(string $key, string $region): bool
{
    if (strtoupper(trim($region)) !== 'TW') {
        return false;
    }

    return in_array($key, ULGG_TW_AUTH_DEPENDENT_KEYS, true);
}

/**
 * Read a JSON object from disk. Missing / unreadable / invalid => null.
 */
function ulggTwAuthReadJson(string $path): ?array
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = @file_get_contents($path);

    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Non-destructive liveness probe.
 *
 * Production is Linux: /proc/<pid> exists while the provider runs.
 * On non-Linux the caller must inject the value (tests do).
 */
function ulggTwAuthPidAlive($pid): bool
{
    if (is_string($pid) && ctype_digit($pid)) {
        $pid = (int)$pid;
    }

    if (!is_int($pid) || $pid <= 0) {
        return false;
    }

    if (is_dir('/proc')) {
        return is_dir('/proc/' . $pid);
    }

    /*
     * Non-Linux: there is no side-effect-free probe. Mirror the Python
     * watcher (`_pid_alive` returns True on Windows) and rely on the
     * heartbeat TTL instead of inventing a false negative.
     */
    return true;
}

function ulggTwAuthParseTime(?string $iso): ?int
{
    if ($iso === null || trim($iso) === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($iso))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

function ulggTwAuthFormatEpoch(int $timestamp, string $timezone = 'Asia/Taipei'): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('Y-m-d H:i:s');
}

function ulggTwAuthFormatTime(?string $iso, string $timezone = 'Asia/Taipei'): ?string
{
    $timestamp = ulggTwAuthParseTime($iso);

    return $timestamp === null ? null : ulggTwAuthFormatEpoch($timestamp, $timezone);
}

/**
 * Keep log/Discord/UI fields to a small, non-secret alphabet.
 */
function ulggTwAuthSafeState($value): string
{
    $raw = is_scalar($value) ? (string)$value : '';
    $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($raw)) ?? '';

    return $safe === '' ? 'unknown' : substr($safe, 0, 32);
}

/**
 * Diagnostic fallback for an unavailable / malformed provider file.
 * It can only label an already-failing result; it never creates `ok=true`.
 */
function ulggTwAuthWatcherFallback(array $result, ?array $watcherHealth): array
{
    if (!is_array($watcherHealth)) {
        return $result;
    }

    $watcherState = ulggTwAuthSafeState($watcherHealth['state'] ?? '');

    if (in_array($watcherState, ['needs_token', 'error'], true)) {
        $result['state'] = 'watcher_' . $watcherState;
        $result['reason'] .= '; watcher health=' . $watcherState;
    }

    return $result;
}

/**
 * Canonical TW auth predicate.
 *
 * AUTH_OK =
 *     provider.state == "active"
 *     AND heartbeat_age <= ttl
 *     AND pid_alive
 *     AND session_sha256 非空
 *
 * The watcher health file is diagnostic only:
 *   - it may explain a missing/malformed/unhealthy provider
 *   - it must NEVER turn AUTH_OK=true into false
 *
 * @param array|null $provider      Decoded tw_auth_provider.json.
 * @param array|null $watcherHealth Decoded tw_watcher_health.json (auxiliary).
 * @param int        $now           Injected epoch (tests / CLI --now).
 * @param float      $ttl           Heartbeat TTL seconds.
 * @param bool|null  $pidAlive      Injected liveness (null = /proc probe).
 *
 * @return array{ok:bool,state:string,reason:string,heartbeat_at:?string,
 *               heartbeat_age_seconds:?int,session_present:bool,pid_alive:bool}
 */
function ulggTwAuthHealth(
    ?array $provider,
    ?array $watcherHealth,
    int $now,
    float $ttl = ULGG_TW_AUTH_TTL_SECONDS,
    ?bool $pidAlive = null
): array {
    $heartbeatAt = null;

    if (is_array($provider) && is_string($provider['heartbeat_at'] ?? null)) {
        $heartbeatAt = $provider['heartbeat_at'];
    }

    $heartbeatTs = ulggTwAuthParseTime($heartbeatAt);
    $heartbeatAge = $heartbeatTs === null ? null : ($now - $heartbeatTs);

    $sessionPresent = is_array($provider)
        && is_string($provider['session_sha256'] ?? null)
        && $provider['session_sha256'] !== '';

    $resolvedPidAlive = $pidAlive
        ?? ulggTwAuthPidAlive(is_array($provider) ? ($provider['pid'] ?? null) : null);

    $result = [
        'ok' => false,
        'state' => 'unknown',
        'reason' => '',
        'heartbeat_at' => $heartbeatAt,
        'heartbeat_age_seconds' => $heartbeatAge,
        'session_present' => $sessionPresent,
        'pid_alive' => $resolvedPidAlive,
    ];

    if (!is_array($provider)) {
        $result['state'] = 'stopped';
        $result['reason'] = 'provider state file missing';

        return ulggTwAuthWatcherFallback($result, $watcherHealth);
    }

    $rawProviderState = $provider['state'] ?? null;
    $providerState = is_string($rawProviderState)
        ? ulggTwAuthSafeState($rawProviderState)
        : 'malformed';

    if ($providerState === 'malformed' || $providerState === 'unknown') {
        $result['state'] = 'unknown';
        $result['reason'] = 'provider state malformed';

        return ulggTwAuthWatcherFallback($result, $watcherHealth);
    }

    if ($providerState !== 'active') {
        $result['state'] = $providerState;
        $result['reason'] = 'provider state=' . $providerState;

        if (is_array($watcherHealth)) {
            $watcherState = ulggTwAuthSafeState($watcherHealth['state'] ?? '');

            if (in_array($watcherState, ['needs_token', 'error'], true)) {
                $result['reason'] .= '; watcher health=' . $watcherState;
            }
        }

        return $result;
    }

    if ($heartbeatTs === null || $heartbeatAge === null) {
        $result['state'] = 'stale';
        $result['reason'] = 'provider heartbeat missing';

        return $result;
    }

    if ($heartbeatAge < 0 || $heartbeatAge > $ttl) {
        $result['state'] = 'stale';
        $result['reason'] = sprintf(
            'provider heartbeat stale (%ds > %ds)',
            $heartbeatAge,
            (int)$ttl
        );

        return $result;
    }

    if (!$resolvedPidAlive) {
        $result['state'] = 'pid_dead';
        $result['reason'] = 'provider pid not alive';

        return $result;
    }

    if (!$sessionPresent) {
        $result['state'] = 'session_missing';
        $result['reason'] = 'provider session missing';

        return $result;
    }

    /*
     * Watcher health is diagnostic only from here on: a healthy canonical
     * provider must never be vetoed by a watcher transition file.
     */
    $result['ok'] = true;
    $result['state'] = 'active';
    $result['reason'] = '';

    return $result;
}

/**
 * Dashboard projection for one admin_watcher_heartbeat row.
 *
 * Precedence (minimal compatible with the existing collector):
 *   service inactive          => stopped
 *   room/ranking file missing => stopped   (existing behaviour)
 *   TW auth unhealthy         => warning   (new)
 *   ranking age > 300s        => stopped   (existing)
 *   ranking age > 120s        => warning   (existing)
 *   empty file                => warning   (existing)
 *   otherwise                 => running
 *
 * TW auth-dependent rows report the provider heartbeat instead of the
 * collector's NOW(). Non-TW rows keep the old NOW() behaviour.
 *
 * @return array{status:string,last_error:?string,last_data_at:?string,
 *               last_heartbeat_at:?string,heartbeat_mode:string,
 *               auth_ok:?bool,auth_state:?string,auth_reason:?string}
 */
function ulggWatcherRowState(
    array $row,
    bool $serviceActive,
    bool $fileExists,
    int|false|null $fileMtime,
    int|false|null $fileSize,
    ?array $auth,
    int $now
): array {
    $key = (string)($row['key'] ?? '');
    $region = (string)($row['region'] ?? '');
    $isTwAuth = ulggTwAuthDependent($key, $region);

    $mtime = is_int($fileMtime) ? $fileMtime : null;
    $lastDataAt = $mtime === null ? null : ulggTwAuthFormatEpoch($mtime);

    $authOk = is_array($auth) && ($auth['ok'] ?? false) === true;
    $authReason = is_array($auth) ? (string)($auth['reason'] ?? '') : 'auth health unavailable';

    $status = $serviceActive ? 'running' : 'stopped';
    $lastError = null;

    if (!$serviceActive) {
        $status = 'stopped';
    } elseif (!$fileExists) {
        $status = 'stopped';
        $lastError = '找不到 JSON 檔案';
    } else {
        $authBlocked = $isTwAuth && !$authOk;

        if ($authBlocked) {
            $status = 'warning';
            $lastError = 'TW auth: '
                . ($authReason !== '' ? $authReason : 'unavailable');
        } elseif (str_starts_with($key, 'ranking_') && $mtime !== null) {
            $age = $now - $mtime;

            if ($age > 300) {
                $status = 'stopped';
                $lastError = '排行資料超過 5 分鐘未更新';
            } elseif ($age > 120) {
                $status = 'warning';
                $lastError = '排行資料超過 2 分鐘未更新';
            }
        }

        if ($fileSize === false || $fileSize === 0) {
            $status = 'warning';
            $lastError = 'JSON 檔案為空';
        }
    }

    $heartbeatMode = 'now';
    $lastHeartbeatAt = null;

    if ($isTwAuth) {
        $rawHeartbeat = is_array($auth) && is_string($auth['heartbeat_at'] ?? null)
            ? $auth['heartbeat_at']
            : null;
        $formatted = ulggTwAuthFormatTime($rawHeartbeat);

        if ($formatted !== null) {
            $heartbeatMode = 'provider';
            $lastHeartbeatAt = $formatted;
        } else {
            // Provider file unreadable: keep the previous DB value.
            $heartbeatMode = 'keep';
        }
    }

    return [
        'status' => $status,
        'last_error' => $lastError,
        'last_data_at' => $lastDataAt,
        'last_heartbeat_at' => $lastHeartbeatAt,
        'heartbeat_mode' => $heartbeatMode,
        'auth_ok' => $isTwAuth ? $authOk : null,
        'auth_state' => $isTwAuth && is_array($auth) ? ($auth['state'] ?? null) : null,
        'auth_reason' => $isTwAuth ? $authReason : null,
    ];
}
