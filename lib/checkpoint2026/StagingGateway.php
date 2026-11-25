<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

/**
 * M2 HTTP endpoints are intentionally synthetic-loopback ONLY.
 * No production config.php, .env, remote DB, Steam, Watcher or outbound IO.
 * Removing or relaxing this guard requires a separate deployment approval.
 */
final class StagingGateway
{
    public static function permitted(): bool
    {
        return PHP_SAPI === 'cli-server'
            && getenv('ULGG_CHECKPOINT2026_M2_STAGE') === 'synthetic-only'
            // PHP's Windows cli-server omits SERVER_ADDR. Require BOTH
            // peer loopback and an exact loopback Host:port instead.
            && in_array(($_SERVER['SERVER_ADDR'] ?? null), [null,'127.0.0.1'], true)
            && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
            && preg_match('/^127\.0\.0\.1:[1-9][0-9]{0,4}$/D',
                 (string)($_SERVER['HTTP_HOST'] ?? '')) === 1;
    }

    public static function requireStage(): void
    {
        if (!self::permitted()) {
            http_response_code(503);
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Robots-Tag: noindex, nofollow');
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":false,"error":"staging_only"}';
            exit;
        }
    }

    public static function beginSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        ini_set('session.cookie_httponly','1');
        ini_set('session.cookie_samesite','Strict');
        session_name('ULGG_M2_SYNTH_STAGING');
        session_start();
    }

    public static function json(array $response, int $status=200, bool $isPrivate=true): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: '.($isPrivate?'private, ':'').'no-store, max-age=0');
        echo json_encode(
            $response, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
        );
        exit;
    }
}
