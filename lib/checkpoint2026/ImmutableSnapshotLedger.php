<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Staging-only append-sealed ledger: UNIQUE scope/revision, UPDATE/DELETE
 * triggers, HMAC chain, signed source provenance. SQLite synthetic only.
 * Not hardware WORM and NOT a production database migration.
 */
final class ImmutableSnapshotLedger
{
    private PDO $db;
    private string $ledgerKey;

    public function __construct(PDO $db,string $ledgerKey)
    {
        if(strlen($ledgerKey)<32)throw new InvalidArgumentException('LEDGER_SEALING_KEY_REQUIRED');
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'){
            throw new InvalidArgumentException('STAGING_SQLITE_ONLY');
        }
        $this->db=$db;
        $this->ledgerKey=$ledgerKey;
    }

    public static function createSyntheticSchema(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'){
            throw new InvalidArgumentException('STAGING_SQLITE_ONLY');
        }
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS checkpoint2026_snapshots(
 scope_hash TEXT NOT NULL,
 revision INTEGER NOT NULL CHECK(revision>=1),
 access TEXT NOT NULL CHECK(access IN ('PUBLIC','PRIVATE')),
 payload_json TEXT NOT NULL,
 payload_sha256 TEXT NOT NULL,
 source_claims_json TEXT NOT NULL,
 source_signature TEXT NOT NULL,
 previous_hash TEXT NOT NULL,
 record_hash TEXT NOT NULL,
 PRIMARY KEY(scope_hash,revision)
);
CREATE TRIGGER IF NOT EXISTS checkpoint2026_no_snapshot_update
 BEFORE UPDATE ON checkpoint2026_snapshots
 BEGIN SELECT RAISE(ABORT,'SNAPSHOT_IMMUTABLE_UPDATE'); END;
CREATE TRIGGER IF NOT EXISTS checkpoint2026_no_snapshot_delete
 BEFORE DELETE ON checkpoint2026_snapshots
 BEGIN SELECT RAISE(ABORT,'SNAPSHOT_IMMUTABLE_DELETE'); END;
SQL);
    }

    private function seal(string $scopeHash,int $revision,string $access,string $json,
                          string $claims,string $signature,string $previous):string
    {
        return hash_hmac('sha256',SourceProvenance::canonical([
            'scope_hash'=>$scopeHash,
            'revision'=>$revision,
            'access'=>$access,
            'payload_json'=>$json,
            'claims_json'=>$claims,
            'provenance_signature'=>$signature,
            'previous_hash'=>$previous,
        ]),$this->ledgerKey);
    }

    public function append(string $trustedScope,int $revision,array $snapshot,array $attestation,
                           string $trustedSourceKey,string $asOf,array $sourceRows,
                           string $expectedDataset='PVP_MATCH_FACT'):array
    {
        $result=SourceProvenance::verify(
            $attestation,$trustedSourceKey,$trustedScope,$asOf,$sourceRows,$expectedDataset);
        if($result['source_verified']!==true){
            throw new RuntimeException('SOURCE_PROVENANCE_UNVERIFIED:'.$result['reason']);
        }
        $payload=$snapshot['payload']??null;
        $access=$snapshot['access']??null;
        if($revision<1 || !is_array($payload)
            || !in_array($access,['PUBLIC','PRIVATE'],true)
            || ($snapshot['readiness']??null)!=='STAGING_ONLY'
            || ($snapshot['as_of']??null)!==$asOf
            || ($snapshot['schema_fingerprint']??null)!==$result['schema_fingerprint']
            || ($snapshot['source_watermark']??null)!==$result['source_watermark']
            || ($snapshot['source_coverage_status']??null)!=='VERIFIED'){
            throw new InvalidArgumentException('SNAPSHOT_PROVENANCE_INCONSISTENT');
        }
        $calc=hash('sha256',json_encode($payload,
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if(!is_string($snapshot['payload_sha256']??null)
            || !hash_equals($calc,$snapshot['payload_sha256'])){
            throw new InvalidArgumentException('SNAPSHOT_PAYLOAD_TAMPERED');
        }
        $scopeHash=SourceProvenance::scopeHash($trustedScope,$this->ledgerKey);
        // Preserve the original payload field order because the M2
        // payload_sha256 covers the exact canonical DTO serialization.
        // The record HMAC protects these exact serialized bytes.
        $json=json_encode($snapshot,
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $claims=SourceProvenance::canonical($attestation['claims']);
        $signature=$attestation['signature'];
        // PDO must own the transaction to make commit()/rollBack() reliable
        // across PHP 8.0/8.3 and SQLite PDO builds.
        if (!$this->db->beginTransaction()) {
            throw new RuntimeException('SNAPSHOT_BEGIN_TRANSACTION_FAILED');
        }
        try{
            $q=$this->db->prepare('SELECT revision,record_hash FROM checkpoint2026_snapshots WHERE scope_hash=? ORDER BY revision DESC LIMIT 1');
            $q->execute([$scopeHash]);
            $previous=$q->fetch(PDO::FETCH_ASSOC);
            $expected=$previous?(int)$previous['revision']+1:1;
            if($revision!==$expected){
                throw new InvalidArgumentException('SNAPSHOT_REVISION_NOT_NEXT');
            }
            $prevHash=$previous?(string)$previous['record_hash']:str_repeat('0',64);
            $seal=$this->seal($scopeHash,$revision,$access,$json,$claims,$signature,$prevHash);
            $sql=<<<'SQL'
INSERT INTO checkpoint2026_snapshots
 (scope_hash,revision,access,payload_json,payload_sha256,
 source_claims_json,source_signature,previous_hash,record_hash)
 VALUES (?,?,?,?,?,?,?,?,?)
SQL;
            $stmt=$this->db->prepare($sql);
            $stmt->execute([$scopeHash,$revision,$access,$json,$snapshot['payload_sha256'],
                $claims,$signature,$prevHash,$seal]);
            $this->db->commit();
            return ['status'=>'SEALED_SYNTHETIC','revision'=>$revision,
                    'record_hash'=>$seal,'previous_hash'=>$prevHash];
        } catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Full synthetic annual proof: every included PvP and public CUP section
     * gets a separate signed source assertion before one atomic append.
     * It is still staging evidence, not a production source watermark.
     */
    public function appendAnnual(
        string $trustedScope,int $revision,array $snapshot,
        array $pvpProof,array $cupProof,string $trustedSourceKey,
        string $asOf,array $pvpFacts,array $cupFacts
    ):array {
        $bundle=AnnualSourceBundleGate::assess(
            $pvpProof,$cupProof,$trustedSourceKey,
            $trustedScope,$asOf,$pvpFacts,$cupFacts
        );
        if($bundle['source_verified']!==true){
            throw new RuntimeException('ANNUAL_SOURCE_BUNDLE_UNVERIFIED');
        }
        if(($snapshot['payload']['cup']['status']??null)!=='VERIFIED'
           || !in_array(($snapshot['payload']['pvp']['status']??null),
                ['VERIFIED','PARTIAL'],true)
           || ($snapshot['source_watermark']??null)!==$bundle['source_watermark']
           || ($snapshot['schema_fingerprint']??null)!==$bundle['schema_fingerprint']){
            throw new RuntimeException('ANNUAL_SNAPSHOT_SOURCE_MISMATCH');
        }
        // Synthetic composite digest binds both independent signed proofs
        // into a single chain record without persisting private source rows.
        $joinedRows=[[
            'dataset'=>'ANNUAL_PVP_CUP',
            'pvp_source_sha256'=>$bundle['bundle_sha256'],
            'cup_source_sha256'=>$cupProof['claims']['fact_sha256'],
        ]];
        $claims=$pvpProof['claims'];
        $claims['dataset']='ANNUAL_PVP_CUP';
        $claims['source_revision']='annual-bundle-'.$bundle['bundle_sha256'];
        $claims['source_rows']=1;
        $claims['expected_rows']=1;
        $claims['fact_sha256']=SourceProvenance::hashFacts($joinedRows);
        $claims['source_watermark']=$bundle['source_watermark'];
        $claims['scope_hash']=SourceProvenance::scopeHash($trustedScope,$trustedSourceKey);
        $attested=[
            'claims'=>$claims,
            'signature'=>SourceProvenance::signForSyntheticFixture($claims,$trustedSourceKey),
        ];
        $snapshot['source_provenance_bundle_sha256']=$bundle['bundle_sha256'];
        return $this->append(
            $trustedScope,$revision,$snapshot,$attested,
            $trustedSourceKey,$asOf,$joinedRows,'ANNUAL_PVP_CUP'
        );
    }

    public function verifyAndRead(string $trustedScope,int $revision):array
    {
        if($revision<=0)throw new InvalidArgumentException('BAD_REVISION');
        $scopeHash=SourceProvenance::scopeHash($trustedScope,$this->ledgerKey);
        $query=$this->db->prepare(<<<'SQL'
SELECT * FROM checkpoint2026_snapshots
 WHERE scope_hash=? AND revision<=?
 ORDER BY revision ASC
SQL);
        $query->execute([$scopeHash,$revision]);
        $records=$query->fetchAll(PDO::FETCH_ASSOC);
        if(count($records)!==$revision)throw new RuntimeException('SNAPSHOT_NOT_FOUND_OR_GAP');
        $previous=str_repeat('0',64);
        $last=null;
        foreach($records as $i=>$row){
            $n=$i+1;
            if((int)$row['revision']!==$n
                || !hash_equals($previous,(string)$row['previous_hash'])){
                throw new RuntimeException('SNAPSHOT_CHAIN_BROKEN');
            }
            $data=json_decode((string)$row['payload_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data)||!is_array($data['payload']??null)){
                throw new RuntimeException('SNAPSHOT_PAYLOAD_CORRUPT');
            }
            $calc=hash('sha256',json_encode($data['payload'],
                JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            if(!hash_equals((string)$row['payload_sha256'],$calc)
                || !hash_equals((string)($data['payload_sha256']??''),$calc)){
                throw new RuntimeException('SNAPSHOT_PAYLOAD_HASH_MISMATCH');
            }
            if($data['access']!==$row['access']){
                throw new RuntimeException('SNAPSHOT_ACCESS_TAMPERED');
            }
            $seal=$this->seal($scopeHash,$n,(string)$row['access'],
                (string)$row['payload_json'],(string)$row['source_claims_json'],
                (string)$row['source_signature'],(string)$row['previous_hash']);
            if(!hash_equals($seal,(string)$row['record_hash'])){
                throw new RuntimeException('SNAPSHOT_CHAIN_SEAL_FAILED');
            }
            $last=['revision'=>$n,'record_hash'=>$seal,'previous_hash'=>$previous,'snapshot'=>$data];
            $previous=$seal;
        }
        return $last;
    }
}
