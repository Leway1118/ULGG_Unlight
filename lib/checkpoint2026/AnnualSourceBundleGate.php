<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

/**
 * M3-B synthetic annual source proof: PvP and PUBLIC CUP require separate
 * signed source attestations, independent dataset labels and scope keys.
 * A PvP-complete snapshot MUST NOT imply CUP source completeness.
 */
final class AnnualSourceBundleGate
{
    public const CUP_SCOPE='SYNTHETIC_PUBLIC_CUP_2026';

    public static function assess(
        array $pvpProof,array $cupProof,string $trustedKey,
        string $trustedPlayerScope,string $asOf,array $pvpFacts,array $cupFacts
    ):array {
        $pvp=SourceProvenance::verify(
            $pvpProof,$trustedKey,$trustedPlayerScope,$asOf,$pvpFacts,'PVP_MATCH_FACT'
        );
        $cup=SourceProvenance::verify(
            $cupProof,$trustedKey,self::CUP_SCOPE,$asOf,$cupFacts,'CUP_PUBLIC'
        );
        $validPvp=$pvp['source_verified']===true;
        $validCup=$cup['source_verified']===true;
        $status=($validPvp && $validCup)
            ?'VERIFIED':(($validPvp || $validCup)?'PARTIAL':'UNKNOWN');
        $root=($validPvp && $validCup)
            ?hash('sha256',SourceProvenance::canonical([
                'version'=>'checkpoint2026/annual-bundle-v1',
                'as_of'=>$asOf,
                'pvp_source_sha256'=>$pvp['provenance_sha256'],
                'cup_source_sha256'=>$cup['provenance_sha256'],
                'pvp_signature'=>$pvpProof['signature'],
                'cup_signature'=>$cupProof['signature'],
            ]))
            :null;
        return [
            'status'=>$status,'source_verified'=>$status==='VERIFIED',
            'pvp_status'=>$pvp['status'],'cup_status'=>$cup['status'],
            'bundle_sha256'=>$root,
            'source_watermark'=>($status==='VERIFIED')
                ?min($pvp['source_watermark'],$cup['source_watermark']):null,
            'schema_fingerprint'=>($status==='VERIFIED')
                ?$pvp['schema_fingerprint']:null,
        ];
    }
}
