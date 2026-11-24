<?php
declare(strict_types=1);

/**
 * M3-B isolated synthetic-only TDD. SQLite disk files created only under a
 * fresh OS temp subdirectory and cleaned in finally. No prod DB or Steam.
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

const ASOF = '2026-11-24 10:00:00';
const SCOPE_A = 'SYNTHETIC_SCOPED_A_TW';
const SCOPE_B = 'SYNTHETIC_SCOPED_B_JP';
$checks=[];
function t(string $n, Closure $test):void {global $checks;$checks[$n]=$test;}
function eq(mixed $value,mixed $expected):void{
    if($value!==$expected)throw new RuntimeException('expected '.var_export($expected,true).' got '.var_export($value,true));
}
function yes(bool $condition,string $why='failed assertion'):void {if(!$condition)throw new RuntimeException($why);}
function blocked(Closure $action):void{
    try{$action();}catch(Throwable $e){return;}
    throw new RuntimeException('expected failure: fail closed');
}
function testKey():string {return hash('sha256','M3B_2026_FIXTURE_PROVENANCE_ONLY',true);}
function ledgerKey():string {return hash('sha256','M3B_2026_FIXTURE_LEDGER_ONLY',true);}
function facts():array{
    $db=FixtureData::pdo();
    return PvPFactReader::readForSession($db,FixtureData::sessionA(),ASOF)['rows'];
}
function evidence(array $rows,array $overrides=[]):array {
    $claim = [
        'contract'=>'checkpoint2026/provenance-v1',
        'dataset'=>'PVP_MATCH_FACT',
        'mode'=>'SYNTHETIC_STAGING',
        'issuer'=>'M3B_ISOLATED_TEST_ISSUER',
        'period_start'=>'2026-01-01 00:00:00',
        'as_of'=>ASOF,
        'timezone'=>'Asia/Taipei',
        'schema_fingerprint'=>FixtureData::SCHEMA_FINGERPRINT,
        'source_watermark'=>ASOF,
        'source_revision'=>'synthetic-ingestion-1',
        'source_complete'=>true,
        'source_rows'=>count($rows),
        'expected_rows'=>count($rows),
        'missing_slices'=>0,
        'fact_sha256'=>SourceProvenance::hashFacts($rows),
        'scope_hash'=>SourceProvenance::scopeHash(SCOPE_A,testKey()),
    ];
    $claim=array_replace($claim,$overrides);
    return ['claims'=>$claim,'signature'=>SourceProvenance::signForSyntheticFixture($claim,testKey())];
}
function cupRows():array{
    return [CupPublicReader::read(FixtureData::pdo(),2026,ASOF)];
}
function cupEvidence(array $rows,array $overrides=[]):array{
    return evidence($rows,array_replace([
       'dataset'=>'CUP_PUBLIC',
       'source_revision'=>'synthetic-cup-1',
       'scope_hash'=>SourceProvenance::scopeHash(AnnualSourceBundleGate::CUP_SCOPE,testKey()),
    ],$overrides));
}
function completeSnapshot(array $rows):array {
    $db=FixtureData::pdo();
    $identity=IdentityReader::resolve($db,FixtureData::sessionA());
    $cov=SourceCoverageGate::assess(FixtureData::manifest(),ASOF);
    $agg=PvPAnnualAggregator::aggregate($rows,[
        'year'=>2026,'region'=>'TW','player_name'=>'SYNTH_A',
        'as_of'=>ASOF,'source_verified'=>true
    ]);
    $cup=CupPublicReader::read($db,2026,ASOF);
    return ResponseSnapshot::personal($identity,$agg,$cup,$cov);
}
function freshLedger():array {
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    ImmutableSnapshotLedger::createSyntheticSchema($db);
    return [$db,new ImmutableSnapshotLedger($db,ledgerKey())];
}

t('steam_authoritative_identity_scope_is_opaque_and_stable',function(){
    $db=FixtureData::pdo();
    $a=IdentityScopeResolver::fromSession($db,FixtureData::sessionA(),testKey());
    $aa=IdentityScopeResolver::fromSession($db,FixtureData::sessionA(),testKey());
    $b=IdentityScopeResolver::fromSession($db,FixtureData::sessionB(),testKey());
    eq($a['status'],'VERIFIED');eq($a,$aa);
    eq(strlen($a['opaque_scope']),64);
    yes($a['opaque_scope']!==$b['opaque_scope']);
    yes(!str_contains(json_encode($a),'7656119'));
    yes(!str_contains(json_encode($a),'SYNTH_A'));
});
t('steam_opaque_scope_denies_idor_and_unverified_session',function(){
    $db=FixtureData::pdo();
    foreach([
       FixtureData::sessionA(['steam_id'=>'76561190000000001']),
       FixtureData::sessionA(['user_id'=>13]),
       FixtureData::sessionA(['username'=>'SYNTH_OPP']),
       FixtureData::sessionA(['user_region'=>'JP']),
       []
    ] as $invalid){
       $result=IdentityScopeResolver::fromSession($db,$invalid,testKey());
       eq($result['status'],'IDENTITY_UNVERIFIED');eq($result['opaque_scope'],null);
    }
    blocked(fn()=>IdentityScopeResolver::fromSession($db,FixtureData::sessionA(),'short'));
});
t('annual_source_bundle_and_ledger_bind_to_opaque_steam_subject',function(){
    $db=FixtureData::pdo();$pvp=facts();$cup=cupRows();
    $a=IdentityScopeResolver::fromSession($db,FixtureData::sessionA(),testKey())['opaque_scope'];
    $b=IdentityScopeResolver::fromSession($db,FixtureData::sessionB(),testKey())['opaque_scope'];
    $pvpProof=evidence($pvp,['scope_hash'=>SourceProvenance::scopeHash($a,testKey())]);
    $cupProof=cupEvidence($cup);
    [$storage,$ledger]=freshLedger();
    $r=$ledger->appendAnnual(
       $a,1,completeSnapshot($pvp),$pvpProof,$cupProof,
       testKey(),ASOF,$pvp,$cup
    );
    eq($ledger->verifyAndRead($a,1)['record_hash'],$r['record_hash']);
    blocked(fn()=>$ledger->verifyAndRead($b,1));
    blocked(fn()=>$ledger->appendAnnual(
       $b,1,completeSnapshot($pvp),$pvpProof,$cupProof,
       testKey(),ASOF,$pvp,$cup
    ));
});
t('provenance_signed_scope_watermark_count_and_schema',function(){
    $rows=facts();$r=SourceProvenance::verify(evidence($rows),testKey(),SCOPE_A,ASOF,$rows);
    eq($r['status'],'VERIFIED');eq($r['source_verified'],true);
    eq($r['mode'],'SYNTHETIC_STAGING');eq(strlen($r['provenance_sha256']),64);
});
t('provenance_rejects_signed_invalid_calendar_date_and_watermark',function(){
    $rows=facts();
    $e=evidence($rows,['source_watermark'=>'2026-99-99 99:99:99']);
    eq(SourceProvenance::verify($e,testKey(),SCOPE_A,ASOF,$rows)['source_verified'],false);
    $bad='2026-02-30 10:00:00';
    $e=evidence($rows,['as_of'=>$bad,'source_watermark'=>$bad]);
    eq(SourceProvenance::verify($e,testKey(),SCOPE_A,$bad,$rows)['source_verified'],false);
});
t('provenance_rejects_unsigned_and_browser_verified_flag',function(){
    $rows=facts();$e=evidence($rows);unset($e['signature']);
    eq(SourceProvenance::verify($e,testKey(),SCOPE_A,ASOF,$rows)['source_verified'],false);
    eq(SourceProvenance::verify(['source_verified'=>true],testKey(),SCOPE_A,ASOF,$rows)['status'],'UNKNOWN');
});
t('provenance_rejects_tampered_claim_even_if_client_sets_verified',function(){
    $rows=facts();$e=evidence($rows);$e['claims']['source_rows']=0;$e['source_verified']=true;
    eq(SourceProvenance::verify($e,testKey(),SCOPE_A,ASOF,$rows)['source_verified'],false);
});
t('provenance_rejects_wrong_key_and_cross_account_scope',function(){
    $rows=facts();$e=evidence($rows);
    eq(SourceProvenance::verify($e,ledgerKey(),SCOPE_A,ASOF,$rows)['source_verified'],false);
    eq(SourceProvenance::verify($e,testKey(),SCOPE_B,ASOF,$rows)['source_verified'],false);
});
t('provenance_rejects_incomplete_stale_bad_schema_and_asof',function(){
    $rows=facts();
    foreach([
      ['missing_slices'=>1],['source_complete'=>false],['expected_rows'=>999],
      ['source_watermark'=>'2026-11-01 00:00:00'],
      ['schema_fingerprint'=>str_repeat('0',64)],['as_of'=>'2027-01-01 00:00:00'],
      ['timezone'=>'UTC'],['mode'=>'PRODUCTION']
    ] as $override){
       $signed=evidence($rows,$override);
       yes(SourceProvenance::verify($signed,testKey(),SCOPE_A,ASOF,$rows)['source_verified']===false,'rejected provenance');
    }
});
t('provenance_rejects_modified_or_reordered_facts',function(){
    $rows=facts();$e=evidence($rows);
    $rows[0]['team_key']='999-999-999';
    eq(SourceProvenance::verify($e,testKey(),SCOPE_A,ASOF,$rows)['source_verified'],false);
});
t('provenance_verified_empty_zero_is_not_unknown',function(){
    $rows=[];$e=evidence($rows,['source_rows'=>0,'expected_rows'=>0]);
    eq(SourceProvenance::verify($e,testKey(),SCOPE_A,ASOF,$rows)['source_verified'],true);
    $e=evidence($rows,['expected_rows'=>1]);
    eq(SourceProvenance::verify($e,testKey(),SCOPE_A,ASOF,$rows)['source_verified'],false);
});
t('provenance_rejects_untrusted_credentials_and_issuer',function(){
    $rows=facts();$e=evidence($rows,['issuer'=>'CLIENT_BROWSER']);
    eq(SourceProvenance::verify($e,testKey(),SCOPE_A,ASOF,$rows)['source_verified'],false);
    blocked(fn()=>SourceProvenance::verify(evidence($rows),'short',SCOPE_A,ASOF,$rows));
});
t('cup_signed_provenance_separate_from_pvp',function(){
    $cup=cupRows();$proof=cupEvidence($cup);
    $p=SourceProvenance::verify($proof,testKey(),AnnualSourceBundleGate::CUP_SCOPE,ASOF,$cup,'CUP_PUBLIC');
    eq($p['source_verified'],true);
    eq(SourceProvenance::verify($proof,testKey(),SCOPE_A,ASOF,$cup)['source_verified'],false);
});
t('annual_source_bundle_requires_two_independent_source_claims',function(){
    $pvp=facts();$cup=cupRows();
    $bundle=AnnualSourceBundleGate::assess(
        evidence($pvp),cupEvidence($cup),testKey(),SCOPE_A,ASOF,$pvp,$cup);
    eq($bundle['status'],'VERIFIED');eq($bundle['source_verified'],true);
    eq(strlen($bundle['bundle_sha256']),64);
    $one=AnnualSourceBundleGate::assess(
        evidence($pvp),[],testKey(),SCOPE_A,ASOF,$pvp,$cup);
    eq($one['status'],'PARTIAL');eq($one['source_verified'],false);
});
t('annual_source_bundle_rejects_wrong_cup_or_replayed_pvp',function(){
    $pvp=facts();$cup=cupRows();
    $r=AnnualSourceBundleGate::assess(
        evidence($pvp),evidence($cup),testKey(),SCOPE_A,ASOF,$pvp,$cup);
    eq($r['source_verified'],false);
    $proof=cupEvidence($cup);$cup[0]['verified_games']=1000;
    $r=AnnualSourceBundleGate::assess(
        evidence($pvp),$proof,testKey(),SCOPE_A,ASOF,$pvp,$cup);
    eq($r['source_verified'],false);
});
t('annual_ledger_append_requires_verified_both_pvp_and_cup',function(){
    [$db,$ledger]=freshLedger();$pvp=facts();$cup=cupRows();
    blocked(fn()=>$ledger->appendAnnual(
        SCOPE_A,1,completeSnapshot($pvp),evidence($pvp),[],
        testKey(),ASOF,$pvp,$cup
    ));
    $result=$ledger->appendAnnual(
        SCOPE_A,1,completeSnapshot($pvp),evidence($pvp),cupEvidence($cup),
        testKey(),ASOF,$pvp,$cup
    );
    eq($result['revision'],1);
    $record=$ledger->verifyAndRead(SCOPE_A,1);
    eq($record['snapshot']['payload']['cup']['verified_games'],2);
    eq(strlen($record['snapshot']['source_provenance_bundle_sha256']),64);
});
t('annual_ledger_append_rejects_tampered_cup_source',function(){
    [$db,$ledger]=freshLedger();$pvp=facts();$cup=cupRows();
    $signed=cupEvidence($cup);
    $cup[0]['reports'][0]['title']='DRAFT_PRIVATE_OVERWRITE';
    blocked(fn()=>$ledger->appendAnnual(
        SCOPE_A,1,completeSnapshot($pvp),evidence($pvp),$signed,
        testKey(),ASOF,$pvp,$cup
    ));
});
t('read_only_sqlite_adapter_blocks_all_mutations',function(){
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ulgg-m3b-'.bin2hex(random_bytes(5));
    mkdir($dir,0700);
    $file=$dir.DIRECTORY_SEPARATOR.'fixture.sqlite';
    try{
        $writer=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $writer->exec('CREATE TABLE fixture(x INTEGER NOT NULL)');
        $writer->exec('INSERT INTO fixture VALUES (12)');
        unset($writer);
        $ro=ReadOnlyStagingPdo::open($file,$dir);
        eq((int)$ro->query('SELECT x FROM fixture')->fetchColumn(),12);
        blocked(fn()=>$ro->exec('INSERT INTO fixture VALUES (1)'));
        blocked(fn()=>$ro->exec('UPDATE fixture SET x=999'));
        blocked(fn()=>$ro->exec('DELETE FROM fixture'));
        blocked(fn()=>$ro->exec('CREATE TABLE other(x INT)'));
        blocked(fn()=>$ro->exec('DROP TABLE fixture'));
        eq((int)$ro->query('SELECT COUNT(*) FROM fixture')->fetchColumn(),1);
        unset($ro);
    }finally{
        if(is_file($file))unlink($file);
        rmdir($dir);
    }
});
t('read_only_adapter_rejects_path_escape',function(){
    blocked(fn()=>ReadOnlyStagingPdo::open('C:\outside\fake.sqlite',sys_get_temp_dir()));
});
t('ledger_append_revision_one_and_verify_hash_chain',function(){
    [$db,$ledger]=freshLedger();$rows=facts();$signed=evidence($rows);
    $r=$ledger->append(SCOPE_A,1,completeSnapshot($rows),$signed,testKey(),ASOF,$rows);
    eq($r['revision'],1);eq($r['status'],'SEALED_SYNTHETIC');
    eq(strlen($r['record_hash']),64);
    $read=$ledger->verifyAndRead(SCOPE_A,1);
    eq($read['revision'],1);eq($read['snapshot']['payload']['pvp']['verified_matches'],3);
});
t('ledger_sealing_wrong_key_fails_to_read',function(){
    [$db,$ledger]=freshLedger();$rows=facts();
    $ledger->append(SCOPE_A,1,completeSnapshot($rows),evidence($rows),testKey(),ASOF,$rows);
    $imposter=new ImmutableSnapshotLedger($db,testKey());
    blocked(fn()=>$imposter->verifyAndRead(SCOPE_A,1));
});
t('annual_ledger_refuses_unverified_cup_in_payload',function(){
    [$db,$ledger]=freshLedger();$pvp=facts();$cup=cupRows();
    $snapshot=completeSnapshot($pvp);
    $snapshot['payload']['cup']['status']='UNKNOWN';
    blocked(fn()=>$ledger->appendAnnual(
       SCOPE_A,1,$snapshot,evidence($pvp),cupEvidence($cup),
       testKey(),ASOF,$pvp,$cup
    ));
});
t('ledger_two_revisions_chain_and_original_remains',function(){
    [$db,$ledger]=freshLedger();$rows=facts();$signed=evidence($rows);
    $one=$ledger->append(SCOPE_A,1,completeSnapshot($rows),$signed,testKey(),ASOF,$rows);
    $two=$ledger->append(SCOPE_A,2,completeSnapshot($rows),$signed,testKey(),ASOF,$rows);
    yes($one['record_hash']!==$two['record_hash']);
    eq($ledger->verifyAndRead(SCOPE_A,1)['record_hash'],$one['record_hash']);
    eq($ledger->verifyAndRead(SCOPE_A,2)['previous_hash'],$one['record_hash']);
});
t('ledger_duplicate_revisions_and_gaps_rejected',function(){
    [$db,$ledger]=freshLedger();$rows=facts();$signed=evidence($rows);
    $ledger->append(SCOPE_A,1,completeSnapshot($rows),$signed,testKey(),ASOF,$rows);
    blocked(fn()=>$ledger->append(SCOPE_A,1,completeSnapshot($rows),$signed,testKey(),ASOF,$rows));
    blocked(fn()=>$ledger->append(SCOPE_A,3,completeSnapshot($rows),$signed,testKey(),ASOF,$rows));
});
t('ledger_rejects_cross_user_provenance',function(){
    [$db,$ledger]=freshLedger();$rows=facts();
    blocked(fn()=>$ledger->append(SCOPE_B,1,completeSnapshot($rows),evidence($rows),testKey(),ASOF,$rows));
});
t('ledger_rejects_forged_snapshot_and_missing_provenance',function(){
    [$db,$ledger]=freshLedger();$rows=facts();$s=completeSnapshot($rows);
    $s['payload']['pvp']['verified_matches']=999;
    blocked(fn()=>$ledger->append(SCOPE_A,1,$s,evidence($rows),testKey(),ASOF,$rows));
    blocked(fn()=>$ledger->append(SCOPE_A,1,completeSnapshot($rows),[],testKey(),ASOF,$rows));
});
t('ledger_db_triggers_block_mutation_and_deletion',function(){
    [$db,$ledger]=freshLedger();$rows=facts();
    $ledger->append(SCOPE_A,1,completeSnapshot($rows),evidence($rows),testKey(),ASOF,$rows);
    blocked(fn()=>$db->exec("UPDATE checkpoint2026_snapshots SET revision=50"));
    blocked(fn()=>$db->exec("DELETE FROM checkpoint2026_snapshots"));
    eq($ledger->verifyAndRead(SCOPE_A,1)['revision'],1);
});
t('ledger_tampering_after_trigger_bypass_is_detected',function(){
    [$db,$ledger]=freshLedger();$rows=facts();
    $ledger->append(SCOPE_A,1,completeSnapshot($rows),evidence($rows),testKey(),ASOF,$rows);
    $db->exec('DROP TRIGGER checkpoint2026_no_snapshot_update');
    $db->exec("UPDATE checkpoint2026_snapshots SET payload_json='{}'");
    blocked(fn()=>$ledger->verifyAndRead(SCOPE_A,1));
});
t('ledger_public_private_scopes_are_distinct',function(){
    [$db,$ledger]=freshLedger();$rows=facts();$e=evidence($rows);
    $ledger->append(SCOPE_A,1,completeSnapshot($rows),$e,testKey(),ASOF,$rows);
    eq($ledger->verifyAndRead(SCOPE_A,1)['snapshot']['access'],'PRIVATE');
    blocked(fn()=>$ledger->verifyAndRead(SCOPE_B,1));
});
t('identity_reader_on_read_only_fixture_denies_cross_account',function(){
    $pdo=FixtureData::pdo();$pdo->exec('PRAGMA query_only = ON');
    eq(IdentityReader::resolve($pdo,FixtureData::sessionA())['status'],'VERIFIED');
    eq(IdentityReader::resolve($pdo,FixtureData::sessionA(['steam_id'=>'76561190000000001']))['status'],'IDENTITY_UNVERIFIED');
    blocked(fn()=>$pdo->exec("UPDATE game_user SET ack=0"));
});
t('snapshot_staging_does_not_leak_real_account_ids',function(){
    $rows=facts();$s=completeSnapshot($rows);
    $json=json_encode($s,JSON_THROW_ON_ERROR);
    foreach(['76561190000000000','SYNTH_A','SYNTH_OPP','DB_PASSWORD','TW_AUTH_TOKEN'] as $needle) {
        yes(!str_contains($json,$needle),'PII leaked');
    }
});

$pass=0;$fail=0;
foreach($checks as $name=>$fn){
    try{$fn();$pass++;echo "PASS $name\n";}
    catch(Throwable $e){$fail++;echo "FAIL $name ".get_class($e).": ".$e->getMessage()."\n";}
}
echo "M3B_TDD pass=$pass fail=$fail total=".count($checks)."\n";
exit($fail===0?0:1);
