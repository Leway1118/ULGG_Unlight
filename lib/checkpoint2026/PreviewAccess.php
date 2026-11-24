<?php
declare(strict_types=1);
namespace ULGG\Checkpoint2026;

/** Shared, read-only access policy for the synthetic V0.3 preview.
 * No database, remember-token restoration, request-driven identity or production test hooks.
 */
final class PreviewAccess
{
    public const DIRECTORY = '/var/lib/ulgg/checkpoint2026-preview';
    public const HTML_SHA256 = 'f436bc0beee2cbb4ca4c14de0cbc31306f74698120d813e8e0de9b772a9f37c5';
    public const COMMIT = '22ad13beb66304d06a82239b1baea8238cb7c20d';

    private static function integer($value): ?int
    {
        if (is_int($value)) return $value;
        // PDO/Remember Me can restore decimal strings. No loose coercion.
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) return null;
        $n = filter_var($value, FILTER_VALIDATE_INT);
        return $n === false ? null : $n;
    }

    public static function authorized(array $session): bool
    {
        $permission = self::integer($session['permission'] ?? null);
        $ack = self::integer($session['ack'] ?? null);
        $userId = self::integer($session['user_id'] ?? null);
        $steam = $session['steam_id'] ?? null;
        if (is_int($steam)) $steam = (string)$steam;
        return $permission !== null && $permission >= 2
            && $ack === $permission && $userId !== null && $userId > 0
            && is_string($steam) && preg_match('/^[0-9]{17}$/D', $steam) === 1;
    }

    public static function requestAllowed(string $method, string $rawQuery): bool
    {
        // Attribution only; no duplicate, additional, encoded or identity selector parameters.
        return $method === 'GET' && ($rawQuery === '' || $rawQuery === 'utm_source=chatgpt.com');
    }

    private static function read(string $name, string $directory): ?string
    {
        $limits = ['enabled.flag'=>16, 'manifest.json'=>4096, 'preview.html'=>2097152];
        if (!isset($limits[$name]) || realpath($directory) !== $directory || is_link($directory)) return null;
        $path = $directory . '/' . $name;
        if (is_link($path) || !is_file($path) || !is_readable($path)) return null;
        $body = @file_get_contents($path, false, null, 0, $limits[$name]+1);
        return is_string($body) && strlen($body) <= $limits[$name] ? $body : null;
    }

    public static function enabled(string $directory = self::DIRECTORY): bool
    {
        return self::read('enabled.flag', $directory) === "ON\n";
    }

    public static function profileEntryVisible(array $session, bool $owner, bool $adminView, bool $banned, string $directory = self::DIRECTORY): bool
    {
        return $owner && !$adminView && !$banned && self::authorized($session) && self::enabled($directory);
    }

    public static function verifiedHtml(string $directory = self::DIRECTORY): ?string
    {
        $manifest = json_decode(self::read('manifest.json', $directory) ?? '', true);
        if ($manifest !== ['version'=>'V0.4','commit'=>self::COMMIT,'sha256'=>self::HTML_SHA256,'mode'=>'SYNTHETIC_ONLY']) return null;
        $html = self::read('preview.html', $directory);
        return $html !== null && hash_equals(self::HTML_SHA256, hash('sha256', $html)) ? $html : null;
    }
}
