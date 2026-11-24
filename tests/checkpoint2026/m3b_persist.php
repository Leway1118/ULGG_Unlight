<?php
declare(strict_types=1);
/**
 * Cross-process synthetic SQLite persistence E2E: create -> reopen ->
 * assert UPDATE/DELETE fail. User supplies unique temporary .sqlite path.
 * NEVER opens or writes production DB.
 */
require_once __DIR__.'/../../lib/checkpoint2026/IdentityGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/IdentityReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPFactReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPAnnualAggregator.php';
require_once __DIR__.'/../../lib/checkpoint2026/CupPublicReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/SourceCoverageGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/ResponseSnapshot.php';
require_once __DIR__.'/../../lib/checkpoint2026/SourceProvenance.php';
require_once __DIR__.'/../../lib/checkpoint2026/IdentityScopeResolver.php';
require_once __DIR__.'/../../lib/checkpoint2026/AnnualSourceBundleGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/ReadOnlyStagingPdo.php';
require_once __DIR__.'/../../lib/checkpoint2026/ImmutableSnapshotLedger.php';
require_once __DIR__.'/FixtureData.php';

use Ulgg\Checkpoint2026\IdentityReader;
use Ulgg\Checkpoint2026\PvPFactReader;
use Ulgg\Checkpoint2026\PvPAnnualAggregator;
use Ulgg\Checkpoint2026\CupPublicReader;
use Ulgg\Checkpoint2026\SourceCoverageGate;
use Ulgg\Checkpoint2026\ResponseSnapshot;
use Ulgg\Checkpoint2026\SourceProvenance;
use Ulgg\Checkpoint2026\IdentityScopeResolver;
use Ulgg\Checkpoint2026\AnnualSourceBundleGate;
use Ulgg\Checkpoint2026\ReadOnlyStagingPdo;
use Ulgg\Checkpoint2026\ImmutableSnapshotLedger;
use Ulgg\Checkpoint2026\Tests\FixtureData;

if(PHP_SAPI!=='cli'||$argc!==3||!in_array($argv[1],['write','verify','deny-write'],true)){
    fwrite(STDERR,"usage: php m3b_persist.php write|verify|deny-write ABS_SYNTHETIC_PATH.sqlite\n");
    exit(2);
}
$mode=$argv[1];$file=$argv[2];
$parent=realpath(dirname($file));
if($parent===false || !is_dir($parent)
   || strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='sqlite'
   || strpos(str_replace('\\','/',$parent),'ULGG-M3B-SYNTH-')===false) {
    throw new RuntimeException('STOP_NOT_EXPLICIT_SYNTHETIC_DIRECTORY');
}
$scopeDb=FixtureData::pdo();
$trustedScope=IdentityScopeResolver::fromSession(
    $scopeDb,FixtureData::sessionA(),
    hash('sha256','M3B_2026_FIXTURE_PROVENANCE_ONLY',true)
)['opaque_scope'];
if(!is_string($trustedScope)||strlen($trustedScope)!==64){
    throw new RuntimeException('STOP_IDENTITY_SCOPE_GATE');
}
$provenanceKey=hash('sha256','M3B_2026_FIXTURE_PROVENANCE_ONLY',true);
$ledgerKey=hash('sha256','M3B_2026_FIXTURE_LEDGER_ONLY',true);
$asOf='2026-11-24 10:00:00';

if($mode==='write'){
    if(is_file($file))throw new RuntimeException('STOP_REFUSE_TO_OVERWRITE_SQLITE');
    $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    ImmutableSnapshotLedger::createSyntheticSchema($pdo);
    $fixture=FixtureData::pdo();
    $id=IdentityReader::resolve($fixture,FixtureData::sessionA());
    $facts=PvPFactReader::readForSession($fixture,FixtureData::sessionA(),$asOf)['rows'];
    $pvp=PvPAnnualAggregator::aggregate($facts,[
       'year'=>2026,'region'=>'TW','player_name'=>'SYNTH_A','as_of'=>$asOf,'source_verified'=>true,
    ]);
    $cup=CupPublicReader::read($fixture,2026,$asOf);
    $cov=SourceCoverageGate::assess(FixtureData::manifest(),$asOf);
    $snapshot=ResponseSnapshot::personal($id,$pvp,$cup,$cov);
    $claims=[
       'contract'=>'checkpoint2026/provenance-v1',
        'dataset'=>'PVP_MATCH_FACT',
       'mode'=>'SYNTHETIC_STAGING',
       'issuer'=>'M3B_ISOLATED_TEST_ISSUER',
       'period_start'=>'2026-01-01 00:00:00','as_of'=>$asOf,
       'timezone'=>'Asia/Taipei',
       'schema_fingerprint'=>FixtureData::SCHEMA_FINGERPRINT,
       'source_watermark'=>$asOf,
       'source_revision'=>'synthetic-ingestion-1','source_complete'=>true,
       'source_rows'=>count($facts),'expected_rows'=>count($facts),
       'missing_slices'=>0,
       'fact_sha256'=>SourceProvenance::hashFacts($facts),
       'scope_hash'=>SourceProvenance::scopeHash($trustedScope,$provenanceKey),
    ];
    $signed=['claims'=>$claims,'signature'=>SourceProvenance::signForSyntheticFixture($claims,$provenanceKey)];
    $cupFacts=[$cup];
    $cupClaims=$claims;
    $cupClaims['dataset']='CUP_PUBLIC';
    $cupClaims['scope_hash']=SourceProvenance::scopeHash(AnnualSourceBundleGate::CUP_SCOPE,$provenanceKey);
    $cupClaims['source_revision']='synthetic-cup-1';
    $cupClaims['source_rows']=count($cupFacts);
    $cupClaims['expected_rows']=count($cupFacts);
    $cupClaims['fact_sha256']=SourceProvenance::hashFacts($cupFacts);
    $cupSigned=['claims'=>$cupClaims,'signature'=>SourceProvenance::signForSyntheticFixture($cupClaims,$provenanceKey)];
    $ledger=new ImmutableSnapshotLedger($pdo,$ledgerKey);
    $one=$ledger->appendAnnual(
        $trustedScope,1,$snapshot,$signed,$cupSigned,$provenanceKey,$asOf,$facts,$cupFacts);
    echo "M3B_PERSIST_ANNUAL_WRITE_PASS revision=1 hash=".$one['record_hash']."\n";
} else {
    if(!is_file($file))throw new RuntimeException('STOP_MISSING_TEST_SQLITE');
    $pdo=ReadOnlyStagingPdo::open($file,$parent);
    $ledger=new ImmutableSnapshotLedger($pdo,$ledgerKey);
    $record=$ledger->verifyAndRead($trustedScope,1);
    if($record['snapshot']['payload']['pvp']['verified_matches']!==3
       || $record['snapshot']['payload']['cup']['verified_games']!==2
       || strlen($record['snapshot']['source_provenance_bundle_sha256']??'')!==64){
        throw new RuntimeException('PERSISTED_ANNUAL_BUNDLE_MISMATCH');
    }
    if($mode==='deny-write'){
        try {$pdo->exec('DELETE FROM checkpoint2026_snapshots');}
        catch(Throwable $e){echo "M3B_PERSIST_READONLY_DENIED_PASS\n";exit(0);}
        throw new RuntimeException('READONLY_FAILED_OPEN');
    }
    echo "M3B_PERSIST_REOPEN_VERIFY_PASS revision=1 hash=".$record['record_hash']."\n";
}
