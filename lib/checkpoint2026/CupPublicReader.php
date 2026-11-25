<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Read-only, public CUP annual source Reader.
 * Depends on an injected, externally authorized PDO. All SQL is SELECT only.
 * No username matching, hard-coded champions or unverified personal CUP data.
 */
final class CupPublicReader
{
    public static function read(PDO $pdo, int $year, string $asOf): array
    {
        if ($year !== 2026) {
            throw new InvalidArgumentException('M1 supports the 2026 annual window only');
        }
        $tz = new DateTimeZone('Asia/Taipei');
        $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$asOf,$tz);
        $start = new DateTimeImmutable('2026-01-01 00:00:00',$tz);
        $annualEnd = new DateTimeImmutable('2027-01-01 00:00:00',$tz);
        if (!$end || $end->format('Y-m-d H:i:s')!==$asOf || $end < $start || $end >= $annualEnd) {
            throw new InvalidArgumentException('Invalid 2026 as_of timestamp');
        }
        $reportsSql = <<<'SQL'
SELECT
    r.id AS report_id,
    r.tournament_id,
    r.issue_key,
    r.report_title,
    r.published_at,
    s.section_key,
    s.section_title,
    s.sort_order
FROM tournament_reports r
LEFT JOIN tournament_report_sections s
  ON s.report_id = r.id
 AND s.is_visible = 1
WHERE r.status = 'published'
  AND r.published_at IS NOT NULL
  AND r.published_at >= :from_time
  AND r.published_at <= :as_of
  AND r.published_at < :end_time
ORDER BY r.published_at, r.id, s.sort_order, s.id
SQL;
        $stmt=$pdo->prepare($reportsSql);
        $stmt->execute([
            ':from_time'=>'2026-01-01 00:00:00',
            ':as_of'=>$asOf,
            ':end_time'=>'2027-01-01 00:00:00',
        ]);
        $reports=[];
        $publishedTournaments=[];
        while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
            $reportId=(int)$r['report_id'];
            $tid=(int)$r['tournament_id'];
            if($tid<=0 || $reportId<=0){continue;}
            if(!isset($reports[$reportId])){
                $reports[$reportId]=[
                    'report_id'=>$reportId, 'tournament_id'=>$tid,
                    'issue_key'=>(string)($r['issue_key']??''),
                    'title'=>(string)($r['report_title']??''),
                    'published_at'=>(string)$r['published_at'],
                    'sections'=>[],
                ];
                $publishedTournaments[$tid]=true;
            }
            if($r['section_key']!==null){
                $reports[$reportId]['sections'][]=[
                    'key'=>(string)$r['section_key'],
                    'title'=>(string)($r['section_title']??''),
                ];
            }
        }

        $gameCounts=[];
        if($publishedTournaments!==[]){
            // All IN values come from numeric IDs in an already published DB record.
            $ids=array_map('intval',array_keys($publishedTournaments));
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $gameSql=<<<SQL
SELECT g.id AS game_id, g.tournament_id
FROM tournament_match_games g
JOIN tournament_matches m
  ON m.id=g.match_id AND m.tournament_id=g.tournament_id
WHERE g.tournament_id IN ($placeholders)
  AND g.result_status='verified'
  AND g.created_at >= ?
  AND g.created_at <= ?
  AND g.updated_at <= ?
  AND m.updated_at <= ?
  AND m.status IN ('confirmed','finished')
  AND m.winner_player_id IN (m.player1_id,m.player2_id)
  AND g.player1_id IS NOT NULL AND g.player2_id IS NOT NULL
  AND g.player1_id<>g.player2_id
  AND g.winner_player_id IN (g.player1_id,g.player2_id)
  AND g.player1_id IN (m.player1_id,m.player2_id)
  AND g.player2_id IN (m.player1_id,m.player2_id)
ORDER BY g.id
SQL;
            $gameStmt=$pdo->prepare($gameSql);
            $gameStmt->execute([...$ids, '2026-01-01 00:00:00', $asOf, $asOf, $asOf]);
            $seen=[];
            while($r=$gameStmt->fetch(PDO::FETCH_ASSOC)){
                $gameId=(int)$r['game_id'];$tid=(int)$r['tournament_id'];
                if($gameId<=0 || isset($seen[$gameId]) || !isset($publishedTournaments[$tid])){continue;}
                $seen[$gameId]=true;
                $gameCounts[$tid]=($gameCounts[$tid]??0)+1;
            }
        }
        ksort($gameCounts,SORT_NUMERIC);
        return [
            'status'=>'VERIFIED',
            'definition_version'=>'checkpoint2026/1.0.0-draft',
            'year'=>2026,'as_of'=>$asOf,
            'reports'=>array_values($reports),
            'published_report_count'=>count($reports),
            'verified_games'=>array_sum($gameCounts),
            'verified_games_by_tournament'=>$gameCounts,
            'personal_cup_status'=>'UNKNOWN',
            'personal_cup_reason'=>'NO_AUTHORITY_IDENTITY_MAPPING',
        ];
    }
}
