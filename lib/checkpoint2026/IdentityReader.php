<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

use PDO;

/**
 * Authoritative, read-only Steam-ID + game_user.id verification.
 * Caller supplies trusted PHP $_SESSION only; never use URL player/steam_id.
 * No writes, environment reads, implicit username lookup, or fallback.
 */
final class IdentityReader
{
    public static function resolve(PDO $pdo, array $session): array
    {
        $steam = $session['steam_id'] ?? null;
        $uid = $session['user_id'] ?? null;
        $validId = is_int($uid) ? $uid > 0 :
            (is_string($uid) && preg_match('/^[1-9]\d*$/D', $uid) === 1);
        if (!is_string($steam) || preg_match('/^\d{17}$/D', $steam)!==1 || !$validId) {
            return ['status'=>'IDENTITY_UNVERIFIED','reason'=>'NO_VALID_STEAM_SESSION','subject'=>null];
        }

        // DB unique index on steamID, but don't assume a corrupt database
        // cannot produce two inconsistent records.
        $sql = <<<'SQL'
SELECT id, steamID, username, region, ack, apply, black_list
FROM game_user
WHERE id = :id AND steamID = :steam_id
LIMIT 2
SQL;
        $q = $pdo->prepare($sql);
        $q->bindValue(':id', (int)$uid, PDO::PARAM_INT);
        $q->bindValue(':steam_id', $steam, PDO::PARAM_STR);
        $q->execute();
        $records = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($records)!==1) {
            return ['status'=>'IDENTITY_UNVERIFIED','reason'=>'NO_UNIQUE_STEAM_BINDING','subject'=>null];
        }
        return IdentityGate::fromSessionAndAccount($session,$records[0]);
    }
}
