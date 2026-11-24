<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Read-only fact adapter. It revalidates the session against game_user
 * BEFORE executing any PvP SELECT. No user-controlled player or Steam ID.
 * No region column exists in arena_player_match_result; JOIN required.
 */
final class PvPFactReader
{
    public static function readForSession(PDO $pdo, array $session, string $asOf): array
    {
        $asOfTime = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s', $asOf, new DateTimeZone('Asia/Taipei')
        );
        if (!$asOfTime || $asOfTime->format('Y-m-d H:i:s') !== $asOf
            || $asOf < '2026-01-01 00:00:00'
            || $asOf >= '2027-01-01 00:00:00') {
            throw new InvalidArgumentException('INVALID_2026_AS_OF');
        }

        $identity = IdentityReader::resolve($pdo, $session);
        if ($identity['status'] !== 'VERIFIED') {
            return [
                'identity' => $identity,
                'rows' => [],
                'source_status' => 'UNAUTHORIZED',
            ];
        }
        $trusted = $identity['subject'];
        // Bind every value: never query with client-provided username, side
        // or region. No SELECT * or opponent PII beyond integrity checks.
        $sql = <<<'SQL'
SELECT
    m.match_id,
    m.side,
    m.player_name,
    m.update_time,
    a.update_time AS truth_update_time,
    a.region,
    a.ack1,
    a.ack2,
    a.name_p1,
    a.name_p2,
    a.win,
    a.lose,
    a.tie,
    m.leader_id,
    m.back1_id,
    m.back2_id,
    m.team_key,
    m.cost,
    m.is_win
FROM arena_unlight a
INNER JOIN arena_player_match_result m ON m.match_id = a.id
WHERE a.region = :region
  AND m.player_name = :player
  AND (
       (m.side = 'P1' AND a.name_p1 = :p1_name)
    OR (m.side = 'P2' AND a.name_p2 = :p2_name)
  )
  AND a.update_time >= :start_a
  AND a.update_time <= :as_of_a
  AND m.update_time >= :start_m
  AND m.update_time <= :as_of_m
ORDER BY m.match_id ASC, m.side ASC
SQL;
        $stmt = $pdo->prepare($sql);
        $params = [
            ':region' => $trusted['region'],
            ':player' => $trusted['username'],
            ':p1_name' => $trusted['username'],
            ':p2_name' => $trusted['username'],
            ':start_a' => '2026-01-01 00:00:00',
            ':start_m' => '2026-01-01 00:00:00',
            ':as_of_a' => $asOf,
            ':as_of_m' => $asOf,
        ];
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return [
            'identity' => $identity,
            'rows' => $rows,
            'source_status' => 'OBSERVED_NOT_YET_RECONCILED',
        ];
    }
}
