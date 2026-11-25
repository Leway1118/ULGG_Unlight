<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

/**
 * Pure authorization gate. Do not pass request GET player/steam_id here.
 * The caller MUST hydrate $account via WHERE id=:session_user_id AND
 * steamID=:session_steam_id, with no name-based fallback.
 * This class never queries DB and never returns private identifiers to the UI.
 */
final class IdentityGate
{
    public static function fromSessionAndAccount(array $session, ?array $account): array
    {
        $deny = static fn(string $reason): array => [
            'status' => 'IDENTITY_UNVERIFIED',
            'reason' => $reason,
            'subject' => null,
        ];

        $steam = $session['steam_id'] ?? null;
        $id = $session['user_id'] ?? null;
        if (!is_string($steam) || preg_match('/^\d{17}$/D', $steam) !== 1
            || (is_int($id) ? $id <= 0 : !(is_string($id) && preg_match('/^[1-9]\d*$/D', $id) === 1))) {
            return $deny('NO_VALID_STEAM_SESSION');
        }
        if ($account === null) {
            return $deny('MISSING_ACCOUNT_BINDING');
        }
        if (!isset($account['id'], $account['steamID'], $account['username'], $account['region'])) {
            return $deny('INCOMPLETE_ACCOUNT_BINDING');
        }
        $accountId = (int)$account['id'];
        $name = (string)$account['username'];
        $region = (string)$account['region'];
        if ($accountId <= 0 || $accountId !== (int)$id || !hash_equals($steam, (string)$account['steamID'])) {
            return $deny('SESSION_ACCOUNT_MISMATCH');
        }
        if ($name === '' || !in_array($region, ['TW','JP'], true)
            || (int)($account['ack'] ?? 0) < 1
            || (int)($account['black_list'] ?? 0) !== 0) {
            return $deny('ACCOUNT_NOT_VERIFIED');
        }
        // A stale or forged username/region is never permitted to select another account.
        if (($session['username'] ?? null) !== $name
            || ($session['user_region'] ?? null) !== $region) {
            return $deny('STALE_SESSION_BINDING');
        }
        return [
            'status' => 'VERIFIED',
            'reason' => null,
            'subject' => [
                // Internal authorization context, never serialize into public response.
                'user_id' => $accountId,
                'username' => $name,
                'region' => $region,
            ],
        ];
    }
}
