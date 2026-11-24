<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

use InvalidArgumentException;

/**
 * Immutable-by-convention DTO response. Explicit public/private field
 * allowlists, deterministic SHA256 over payload, no identity/Steam secrets.
 * This is a synthetic staging snapshot, not a production historical revision.
 */
final class ResponseSnapshot
{
    private static function cup(array $source): array
    {
        $reports = [];
        foreach (($source['reports'] ?? []) as $r) {
            $sections = [];
            foreach (($r['sections'] ?? []) as $s) {
                $sections[] = [
                    'key' => (string)($s['key'] ?? ''),
                    'title' => (string)($s['title'] ?? ''),
                ];
            }
            $reports[] = [
                'tournament_id' => (int)($r['tournament_id'] ?? 0),
                'issue_key' => (string)($r['issue_key'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'published_at' => (string)($r['published_at'] ?? ''),
                'sections' => $sections,
            ];
        }
        return [
            'status' => ($source['status'] ?? null)==='VERIFIED' ? 'VERIFIED' : 'UNKNOWN',
            'published_report_count' => count($reports),
            'verified_games' => ($source['status'] ?? null)==='VERIFIED'
                ? (int)($source['verified_games'] ?? 0) : null,
            'reports' => $reports,
            'personal_cup_status' => 'UNKNOWN',
        ];
    }

    private static function pvp(array $src, array $coverage): array
    {
        if (($coverage['source_verified'] ?? false) !== true) {
            return [
                'status'=>'UNKNOWN','verified_matches'=>null,
                'wins'=>null,'losses'=>null,'draws'=>null,
                'win_rate_pct'=>null,'active_months'=>null,
                'top_characters'=>[],'top_teams'=>[],
                'monthly_trend'=>[], 'reason'=>'SOURCE_COVERAGE_UNVERIFIED',
            ];
        }
        return [
            'status' => in_array(($src['status'] ?? null),['VERIFIED','PARTIAL'],true)
                ? $src['status'] : 'UNKNOWN',
            'verified_matches' => $src['verified_matches'] ?? null,
            'wins' => $src['wins'] ?? null,
            'losses' => $src['losses'] ?? null,
            'draws' => $src['draws'] ?? null,
            'win_rate_pct' => $src['win_rate_pct'] ?? null,
            'active_months' => $src['active_months'] ?? null,
            'top_characters' => array_map(
                static fn($r)=>['char_id'=>(int)$r['char_id'],'usage'=>(int)$r['usage']],
                $src['top_characters'] ?? []
            ),
            'top_teams' => array_map(
                static fn($r)=>['team_key'=>(string)$r['team_key'],'matches'=>(int)$r['matches']],
                $src['top_teams'] ?? []
            ),
            'monthly_trend' => $src['monthly_trend'] ?? [],
            'excluded_count' => (int)($src['excluded_count'] ?? 0),
        ];
    }

    private static function envelope(string $access, array $payload, array $coverage, string $asOf): array
    {
        if (($coverage['mode'] ?? null) !== 'SYNTHETIC_STAGING') {
            throw new InvalidArgumentException('SYNTHETIC_STAGING_EVIDENCE_REQUIRED');
        }
        $json=json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
        );
        return [
            'definition_version' => 'checkpoint2026/1.0.0-draft',
            'snapshot_revision' => 'M2-fixture-v1',
            'year' => 2026,
            'as_of' => $asOf,
            'readiness' => 'STAGING_ONLY',
            'access' => $access,
            'schema_fingerprint' => $coverage['schema_fingerprint'],
            'source_watermark' => $coverage['source_watermark'],
            'source_coverage_status' => $coverage['status'],
            'payload_sha256' => hash('sha256',$json),
            'payload' => $payload,
        ];
    }

    public static function personal(array $identity, array $pvp, array $cup, array $coverage): array
    {
        if (($identity['status'] ?? null) !== 'VERIFIED' || !isset($identity['subject'])) {
            throw new InvalidArgumentException('IDENTITY_UNVERIFIED');
        }
        $asOf=(string)($pvp['as_of']??'');
        if ($asOf === '' || $asOf !== ($cup['as_of'] ?? null)) {
            throw new InvalidArgumentException('SNAPSHOT_WATERMARK_MISMATCH');
        }
        $payload=[
            'pvp' => self::pvp($pvp,$coverage),
            'cup' => self::cup($cup),
            'raid' => ['status'=>'UNKNOWN','reason'=>'NO_VERIFIED_PLAYER_HISTORY'],
        ];
        return self::envelope('PRIVATE',$payload,$coverage,$asOf);
    }

    public static function publicCup(array $cup, array $coverage): array
    {
        $asOf=(string)($cup['as_of']??'');
        if ($asOf==='') {
            throw new InvalidArgumentException('PUBLIC_SNAPSHOT_TIME_REQUIRED');
        }
        return self::envelope('PUBLIC',['cup'=>self::cup($cup)],$coverage,$asOf);
    }
}
