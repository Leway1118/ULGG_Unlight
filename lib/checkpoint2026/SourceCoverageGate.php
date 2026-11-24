<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

/**
 * Gate for provenance verified *synthetic staging* only.
 * Production evidence must get its own trusted, signed/watermarked adapter.
 * NEVER accept a browser-supplied source_verified=true value.
 */
final class SourceCoverageGate
{
    private const D1_SCHEMA_FINGERPRINT =
        '8de93e52c187059f924be7ffa9c6755767f3fe4bc2d104cc097098de6bd7cd34';

    public static function assess(array $evidence, string $asOf): array
    {
        $result = [
            'status' => 'UNKNOWN',
            'source_verified' => false,
            'mode' => 'UNVERIFIED',
            'source_watermark' => null,
            'schema_fingerprint' => null,
            'reason' => 'NO_TRUSTED_STAGING_EVIDENCE',
        ];
        if (($evidence['mode'] ?? null) !== 'SYNTHETIC_STAGING'
            || ($evidence['issuer'] ?? null) !== 'CHECKPOINT2026_SYNTHETIC_FIXTURE') {
            return $result;
        }
        $result['mode'] = 'SYNTHETIC_STAGING';
        $fingerprint = $evidence['schema_fingerprint'] ?? null;
        if (!is_string($fingerprint) ||
            !hash_equals(self::D1_SCHEMA_FINGERPRINT, $fingerprint)) {
            $result['reason']='SCHEMA_FINGERPRINT_MISMATCH';
            return $result;
        }
        $result['schema_fingerprint']=$fingerprint;
        if (!preg_match('/^2026-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $asOf)
            || ($evidence['start_at'] ?? null) !== '2026-01-01 00:00:00'
            || ($evidence['integrity'] ?? null) !== 'PASS') {
            $result['reason']='UNVERIFIED_TIME_OR_INTEGRITY';
            return $result;
        }
        $watermark=$evidence['source_watermark'] ?? null;
        $observed=$evidence['observed_rows'] ?? null;
        $expected=$evidence['expected_rows'] ?? null;
        $missing=$evidence['missing_slices'] ?? null;
        if (!is_string($watermark)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $watermark)!==1
            || !is_int($observed) || $observed<0
            || !is_int($expected) || $expected<0
            || !is_int($missing) || $missing<0) {
            $result['reason']='INVALID_COVERAGE_FIELDS';
            return $result;
        }
        $result['source_watermark']=$watermark;
        if ($watermark < $asOf || $observed !== $expected || $missing>0) {
            $result['status']='PARTIAL';
            $result['reason']='STALE_OR_INCOMPLETE';
            return $result;
        }
        $result['status']='VERIFIED';
        $result['source_verified']=true;
        $result['reason']=null;
        return $result;
    }
}
