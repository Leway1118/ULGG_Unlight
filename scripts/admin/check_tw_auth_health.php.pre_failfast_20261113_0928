<?php

declare(strict_types=1);

/*
 * UL.GG TW auth alert monitor.
 *
 * P1_TW_AUTH_PERSISTENT_ALERT_20261019_V1
 *
 * Cron (root, every minute):
 *   * * * * * /usr/bin/php /var/www/html/unlight/scripts/admin/check_tw_auth_health.php
 *
 * Design:
 *   - never touches provider/watcher hot paths (separate CLI process)
 *   - constant-time: runs even when auth is fine
 *   - one DEGRADED alert after >=180s continuous failure
 *   - one RECOVERY alert after a degraded state that was alerted
 *   - no periodic reminder (v1)
 *   - transport failure is logged and retried next run; exit code stays 0
 *   - persistent state has no credential / session hash / token / UUID
 *
 * Test seams:
 *   --dry-run --now=<epoch> --provider-file=... --watcher-health-file=...
 *   --state-file=... --transport=file|fake --transport-out=...
 *   --transport-result=false --transport-throw
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__, 2);

require_once $root . '/lib/tw_auth_health.php';
require_once $root . '/lib/discord_webhook.php';

/* Load .env without config.php (config.php opens a PDO connection). */
$autoload = $root . '/vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

if (class_exists('Dotenv\\Dotenv')) {
    try {
        Dotenv\Dotenv::createImmutable($root)->safeLoad();
    } catch (Throwable) {
        // .env is optional for dry-run / file transport.
    }
}

$opts = getopt('', [
    'dry-run',
    'now:',
    'provider-file:',
    'watcher-health-file:',
    'state-file:',
    'transport:',
    'transport-out:',
    'transport-result:',
    'transport-throw',
    'pid-alive:',
    'help',
]);

if ($opts === false) {
    $opts = [];
}

if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "check_tw_auth_health.php [--dry-run] [--now=epoch] ...\n");
    exit(0);
}

$dryRun = array_key_exists('dry-run', $opts);
$now = isset($opts['now']) ? (int)$opts['now'] : time();

$providerFile = (string)(
    $opts['provider-file']
    ?? (getenv('ULGG_TW_AUTH_PROVIDER_FILE') ?: '/run/unlight/tw_auth_provider.json')
);
$healthFile = (string)(
    $opts['watcher-health-file']
    ?? (getenv('ULGG_TW_WATCHER_HEALTH_FILE') ?: '/run/unlight/tw_watcher_health.json')
);
$stateFile = (string)(
    $opts['state-file']
    ?? (getenv('ULGG_TW_AUTH_ALERT_STATE_FILE') ?: '/var/lib/unlight/tw_auth_alert_state.json')
);

$transport = strtolower((string)(
    $opts['transport'] ?? ($dryRun ? 'fake' : 'discord')
));

if ($dryRun && $transport === 'discord') {
    // Safety: a dry run must never reach Discord.
    $transport = 'fake';
}

$transportOut = isset($opts['transport-out']) ? (string)$opts['transport-out'] : '';
$transportResult = strtolower((string)($opts['transport-result'] ?? 'true')) !== 'false';
$transportThrow = array_key_exists('transport-throw', $opts);

$pidAlive = null;

if (isset($opts['pid-alive'])) {
    $pidAliveRaw = strtolower((string)$opts['pid-alive']);

    if (in_array($pidAliveRaw, ['1', 'true', 'yes'], true)) {
        $pidAlive = true;
    } elseif (in_array($pidAliveRaw, ['0', 'false', 'no'], true)) {
        $pidAlive = false;
    }
}

const ULGG_TW_ALERT_THRESHOLD_SECONDS = 180;

/**
 * Format an epoch as Asia/Taipei, independent of the PHP default timezone.
 */
function ulggTwAlertTime(int $epoch): string
{
    return (new DateTimeImmutable('@' . $epoch))
        ->setTimezone(new DateTimeZone('Asia/Taipei'))
        ->format('Y-m-d H:i:s');
}

/**
 * @return array{version:int,logical_state:string,degraded_since:?int,
 *               alert_sent:bool,last_transition_at:?int,last_reason:string,
 *               last_alert_at:?int}
 */
function ulggTwAlertDefaults(): array
{
    return [
        'version' => 1,
        'logical_state' => 'ok',
        'degraded_since' => null,
        'alert_sent' => false,
        'last_transition_at' => null,
        'last_reason' => '',
        'last_alert_at' => null,
    ];
}

function ulggTwAlertReadState(string $path): array
{
    $state = ulggTwAlertDefaults();

    if (!is_file($path) || !is_readable($path)) {
        return $state;
    }

    $decoded = json_decode((string)@file_get_contents($path), true);

    if (!is_array($decoded)) {
        return $state;
    }

    foreach (array_keys($state) as $key) {
        if (array_key_exists($key, $decoded)) {
            $state[$key] = $decoded[$key];
        }
    }

    if (!in_array($state['logical_state'], ['ok', 'degraded'], true)) {
        $state['logical_state'] = 'ok';
    }

    $state['version'] = 1;
    $state['degraded_since'] = is_int($state['degraded_since'])
        ? $state['degraded_since']
        : null;
    $state['alert_sent'] = (bool)$state['alert_sent'];
    $state['last_transition_at'] = is_int($state['last_transition_at'])
        ? $state['last_transition_at']
        : null;
    $state['last_reason'] = is_string($state['last_reason'])
        ? $state['last_reason']
        : '';
    $state['last_alert_at'] = is_int($state['last_alert_at'])
        ? $state['last_alert_at']
        : null;

    return $state;
}

function ulggTwAlertWriteState(string $path, array $state): void
{
    $dir = dirname($path);

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        return;
    }

    $tmp = $path . '.tmp.' . getmypid();

    if (@file_put_contents($tmp, $json . "\n") === false) {
        return;
    }

    @chmod($tmp, 0600);
    @rename($tmp, $path);
}

/**
 * State machine. Pure: no I/O, no transport.
 *
 * @return array{0:array,1:array<int,array{type:string,duration_seconds?:int,downtime_seconds?:int}>}
 */
function ulggTwAlertTransition(
    array $state,
    bool $authOk,
    int $now,
    int $threshold = ULGG_TW_ALERT_THRESHOLD_SECONDS,
    string $reason = ''
): array {
    $actions = [];

    if (!$authOk) {
        if ($state['logical_state'] !== 'degraded') {
            $state['logical_state'] = 'degraded';
            $state['degraded_since'] = $now;
            $state['alert_sent'] = false;
            $state['last_transition_at'] = $now;
        }

        $state['last_reason'] = $reason;
        $since = is_int($state['degraded_since']) ? $state['degraded_since'] : $now;

        if (!$state['alert_sent'] && ($now - $since) >= $threshold) {
            $actions[] = [
                'type' => 'degraded',
                'duration_seconds' => $now - $since,
            ];
        }

        return [$state, $actions];
    }

    if ($state['logical_state'] === 'degraded') {
        $since = is_int($state['degraded_since']) ? $state['degraded_since'] : $now;

        if ($state['alert_sent']) {
            $actions[] = [
                'type' => 'recovery',
                'downtime_seconds' => $now - $since,
            ];
        }

        $state['logical_state'] = 'ok';
        $state['degraded_since'] = null;
        $state['alert_sent'] = false;
        $state['last_transition_at'] = $now;
        $state['last_reason'] = '';
    }

    return [$state, $actions];
}

/**
 * Safe Discord payload. Only: state, duration, provider_alive, timestamp.
 */
function ulggTwAlertPayload(array $action, array $auth, int $now): array
{
    $time = ulggTwAlertTime($now);

    if (($action['type'] ?? '') === 'recovery') {
        $minutes = intdiv(max(0, (int)($action['downtime_seconds'] ?? 0)), 60);

        return [
            'content' => "UL.GG TW auth recovered\n"
                . "downtime={$minutes}m\n"
                . "time={$time}",
        ];
    }

    $state = ulggTwAuthSafeState($auth['state'] ?? 'unknown');
    $minutes = intdiv(max(0, (int)($action['duration_seconds'] ?? 0)), 60);
    $alive = !empty($auth['pid_alive']) ? 'true' : 'false';

    return [
        'content' => "UL.GG TW auth degraded\n"
            . "state={$state}\n"
            . "duration={$minutes}m\n"
            . "provider_alive={$alive}\n"
            . "time={$time}",
    ];
}

/**
 * Transport abstraction. Never throws.
 */
function ulggTwAlertSend(
    array $payload,
    string $transport,
    string $outFile,
    bool $transportResult,
    bool $transportThrow
): bool {
    try {
        if ($transportThrow) {
            throw new RuntimeException('simulated transport failure');
        }

        if (!$transportResult) {
            return false;
        }

        if ($transport === 'fake') {
            return true;
        }

        if ($transport === 'file') {
            if ($outFile === '') {
                return false;
            }

            $line = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($line === false) {
                return false;
            }

            return @file_put_contents($outFile, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
        }

        $webhook = trim((string)(
            getenv('DISCORD_WEBHOOK')
            ?: ($_ENV['DISCORD_WEBHOOK'] ?? '')
        ));

        if ($webhook === '') {
            return false;
        }

        return sendDiscordWebhook($webhook, $payload, 5);
    } catch (Throwable) {
        return false;
    }
}

$lockPath = $stateFile . '.lock';
$stateDir = dirname($stateFile);

if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0700, true);
}

$lock = @fopen($lockPath, 'c');

if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
    // Another run is in progress.
    exit(0);
}

try {
    $auth = ulggTwAuthHealth(
        ulggTwAuthReadJson($providerFile),
        ulggTwAuthReadJson($healthFile),
        $now,
        ULGG_TW_AUTH_TTL_SECONDS,
        $pidAlive
    );

    $state = ulggTwAlertReadState($stateFile);
    [$state, $actions] = ulggTwAlertTransition(
        $state,
        (bool)$auth['ok'],
        $now,
        ULGG_TW_ALERT_THRESHOLD_SECONDS,
        (string)$auth['reason']
    );

    foreach ($actions as $action) {
        $payload = ulggTwAlertPayload($action, $auth, $now);
        $delivered = ulggTwAlertSend(
            $payload,
            $transport,
            $transportOut,
            $transportResult,
            $transportThrow
        );

        if ($delivered && ($action['type'] ?? '') === 'degraded') {
            $state['alert_sent'] = true;
            $state['last_alert_at'] = $now;
        }

        if (!$delivered) {
            fwrite(
                STDERR,
                '[' . ulggTwAlertTime($now) . '] alert transport failed: '
                    . (string)($action['type'] ?? 'unknown')
                    . PHP_EOL
            );
        }
    }

    ulggTwAlertWriteState($stateFile, $state);
} catch (Throwable $e) {
    fwrite(
        STDERR,
        '[' . ulggTwAlertTime($now) . '] monitor error: '
            . get_class($e)
            . PHP_EOL
    );
}

// Cron contract: never fail the caller.
exit(0);
