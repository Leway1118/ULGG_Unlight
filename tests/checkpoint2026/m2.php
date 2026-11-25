<?php
declare(strict_types=1);

/** M2 red/green tests. SQLite memory, synthetic Steam IDs, zero prod IO. */
require_once __DIR__.'/../../lib/checkpoint2026/IdentityGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPAnnualAggregator.php';
require_once __DIR__.'/../../lib/checkpoint2026/CupPublicReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/IdentityReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPFactReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/SourceCoverageGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/ResponseSnapshot.php';
require_once __DIR__.'/../../lib/checkpoint2026/StagingGateway.php';
require_once __DIR__.'/FixtureData.php';

use Ulgg\Checkpoint2026\IdentityReader;
use Ulgg\Checkpoint2026\PvPFactReader;
use Ulgg\Checkpoint2026\SourceCoverageGate;
use Ulgg\Checkpoint2026\ResponseSnapshot;
use Ulgg\Checkpoint2026\PvPAnnualAggregator;
use Ulgg\Checkpoint2026\CupPublicReader;
use Ulgg\Checkpoint2026\Tests\FixtureData;

const AS_OF = '2026-11-24 10:00:00';
$tests = [];
function t(string $title, Closure $cb): void {global $tests; $tests[$title]=$cb;}
function eq(mixed $got, mixed $wanted): void {
  if ($got !== $wanted) throw new RuntimeException('expected '.var_export($wanted,true).' got '.var_export($got,true));
}
function check(bool $value, string $reason='assertion_failed'):void {if(!$value) throw new RuntimeException($reason);}
function syntheticManifest(array $patch=[]):array { return array_replace(FixtureData::manifest(), $patch); }
function verifiedSnapshot():array {
  $pdo=FixtureData::pdo();
  $session=FixtureData::sessionA();
  $facts=PvPFactReader::readForSession($pdo,$session,AS_OF);
  $gate=SourceCoverageGate::assess(syntheticManifest(),AS_OF);
  $pvp=PvPAnnualAggregator::aggregate($facts['rows'],[
    'year'=>2026,'region'=>$facts['identity']['subject']['region'],
    'player_name'=>$facts['identity']['subject']['username'],
    'as_of'=>AS_OF,'source_verified'=>$gate['source_verified'],
  ]);
  $cup=CupPublicReader::read($pdo,2026,AS_OF);
  return ResponseSnapshot::personal($facts['identity'],$pvp,$cup,$gate);
}

t('identity_reader_authoritative_pdo_exact_match',function(){
  $pdo=FixtureData::pdo();
  $r=IdentityReader::resolve($pdo,FixtureData::sessionA());
  eq($r['status'],'VERIFIED');eq($r['subject']['username'],'SYNTH_A');eq($r['subject']['region'],'TW');
});
t('identity_reader_blocks_missing_new_pending_blacklisted',function(){
  $pdo=FixtureData::pdo();
  eq(IdentityReader::resolve($pdo,[])['status'],'IDENTITY_UNVERIFIED');
  eq(IdentityReader::resolve($pdo,FixtureData::sessionA(['user_id'=>999]))['status'],'IDENTITY_UNVERIFIED');
  $pdo->exec("UPDATE game_user SET ack=0 WHERE id=12");
  eq(IdentityReader::resolve($pdo,FixtureData::sessionA())['status'],'IDENTITY_UNVERIFIED');
  $pdo->exec("UPDATE game_user SET ack=1,black_list=1 WHERE id=12");
  eq(IdentityReader::resolve($pdo,FixtureData::sessionA())['status'],'IDENTITY_UNVERIFIED');
});
t('identity_reader_rejects_cross_account_and_forged_name',function(){
  $pdo=FixtureData::pdo();
  eq(IdentityReader::resolve($pdo,FixtureData::sessionA(['steam_id'=>'76561190000000001']))['status'],'IDENTITY_UNVERIFIED');
  eq(IdentityReader::resolve($pdo,FixtureData::sessionA(['username'=>'SYNTH_B']))['status'],'IDENTITY_UNVERIFIED');
  eq(IdentityReader::resolve($pdo,FixtureData::sessionA(['user_region'=>'JP']))['status'],'IDENTITY_UNVERIFIED');
});
t('pvp_reader_denies_before_query_for_invalid_identity',function(){
  $pdo=FixtureData::pdo();
  $r=PvPFactReader::readForSession($pdo,FixtureData::sessionA(['user_id'=>13]),AS_OF);
  eq($r['identity']['status'],'IDENTITY_UNVERIFIED');eq($r['rows'],[]);
});
t('pvp_reader_matches_trusted_username_and_region_only',function(){
  $pdo=FixtureData::pdo();
  $r=PvPFactReader::readForSession($pdo,FixtureData::sessionA(),AS_OF);
  eq($r['identity']['status'],'VERIFIED');
  eq(array_column($r['rows'],'match_id'),[1,2,3,6]);
  foreach($r['rows'] as $row){eq($row['region'],'TW');eq($row['player_name'],'SYNTH_A');}
  check(!isset($r['rows'][0]['steamID']),'steam ID returned in facts');
});
t('pvp_reader_recovers_other_account_same_name_only_other_region',function(){
  $pdo=FixtureData::pdo();
  $r=PvPFactReader::readForSession($pdo,FixtureData::sessionB(),AS_OF);
  eq($r['identity']['subject']['region'],'JP');
  eq(array_column($r['rows'],'match_id'),[5]);
});
t('pvp_reader_2026_time_cutoff_and_staging_pure_result',function(){
  $pdo=FixtureData::pdo();
  $r=PvPFactReader::readForSession($pdo,FixtureData::sessionA(),'2026-06-01 08:59:00');
  eq(array_column($r['rows'],'match_id'),[1]);
  check(!isset($r['rows'][0]['db_password']));
});
t('pvp_reader_aggregate_win_loss_draw_unknown_ack',function(){
  $pdo=FixtureData::pdo();
  $f=PvPFactReader::readForSession($pdo,FixtureData::sessionA(),AS_OF);
  $o=PvPAnnualAggregator::aggregate($f['rows'],['year'=>2026,'region'=>'TW',
    'player_name'=>'SYNTH_A','as_of'=>AS_OF,'source_verified'=>true]);
  eq([$o['verified_matches'],$o['wins'],$o['losses'],$o['draws'],$o['excluded_count']],[3,1,1,1,1]);
  eq($o['top_teams'][0]['team_key'],'11-22-33');
});
t('coverage_without_verified_manifest_is_unknown',function(){
  $r=SourceCoverageGate::assess([],AS_OF);
  eq($r['status'],'UNKNOWN');eq($r['source_verified'],false);
});
t('coverage_synthetic_complete_only_with_fingerprint_and_watermark',function(){
  $r=SourceCoverageGate::assess(syntheticManifest(),AS_OF);
  eq($r['status'],'VERIFIED');eq($r['source_verified'],true);
  eq($r['mode'],'SYNTHETIC_STAGING');
  check(strlen($r['schema_fingerprint'])===64);
});
t('coverage_partial_and_stale_never_trusted_as_zero',function(){
  eq(SourceCoverageGate::assess(syntheticManifest(['missing_slices'=>1]),AS_OF)['status'],'PARTIAL');
  eq(SourceCoverageGate::assess(syntheticManifest(['source_watermark'=>'2026-11-01 00:00:00']),AS_OF)['source_verified'],false);
  eq(SourceCoverageGate::assess(syntheticManifest(['schema_fingerprint'=>str_repeat('0',64)]),AS_OF)['source_verified'],false);
  eq(SourceCoverageGate::assess(syntheticManifest(['issuer'=>'CLIENT_BROWSER']),AS_OF)['source_verified'],false);
});
t('coverage_no_rows_valid_zero_only_if_complete',function(){
  eq(SourceCoverageGate::assess(syntheticManifest(['observed_rows'=>0,'expected_rows'=>0]),AS_OF)['source_verified'],true);
  eq(SourceCoverageGate::assess(syntheticManifest(['observed_rows'=>0,'expected_rows'=>10]),AS_OF)['source_verified'],false);
});
t('snapshot_private_allowlist_no_identity_or_secret',function(){
  $r=verifiedSnapshot();
  eq($r['access'],'PRIVATE');eq($r['readiness'],'STAGING_ONLY');
  eq($r['payload']['pvp']['verified_matches'],3);
  eq($r['payload']['cup']['personal_cup_status'],'UNKNOWN');
  $json=json_encode($r,JSON_THROW_ON_ERROR);
  foreach(['76561190000000000','SYNTH_A','steamID','steam_id','email','friend_code','SESSION','SYNTH_B'] as $secret){
    check(!str_contains($json,$secret),'leaked '.$secret);
  }
});
t('snapshot_digest_and_revision_are_deterministic',function(){
  $one=verifiedSnapshot();$two=verifiedSnapshot();
  eq($one,$two);eq(strlen($one['payload_sha256']),64);
  eq($one['definition_version'],'checkpoint2026/1.0.0-draft');
});
t('snapshot_rejects_unverified_subject',function(){
  $pdo=FixtureData::pdo();
  $identity=IdentityReader::resolve($pdo,FixtureData::sessionA(['user_id'=>123]));
  $gate=SourceCoverageGate::assess(syntheticManifest(),AS_OF);
  $pvp=PvPAnnualAggregator::aggregate([],['year'=>2026,'region'=>'TW','player_name'=>'SYNTH_A','as_of'=>AS_OF,'source_verified'=>false]);
  $cup=CupPublicReader::read($pdo,2026,AS_OF);
  try{ResponseSnapshot::personal($identity,$pvp,$cup,$gate);throw new RuntimeException('failed_open');}
  catch(InvalidArgumentException $e){eq($e->getMessage(),'IDENTITY_UNVERIFIED');}
});
t('snapshot_unknown_does_not_pretend_verified_zero',function(){
  $pdo=FixtureData::pdo();$id=IdentityReader::resolve($pdo,FixtureData::sessionA());
  $gate=SourceCoverageGate::assess(syntheticManifest(['missing_slices'=>1]),AS_OF);
  $pvp=PvPAnnualAggregator::aggregate([],['year'=>2026,'region'=>'TW','player_name'=>'SYNTH_A','as_of'=>AS_OF,'source_verified'=>$gate['source_verified']]);
  $cup=CupPublicReader::read($pdo,2026,AS_OF);
  $r=ResponseSnapshot::personal($id,$pvp,$cup,$gate);
  eq($r['payload']['pvp']['verified_matches'],null);
  eq($r['payload']['pvp']['status'],'UNKNOWN');
});
t('source_verified_client_boolean_is_ignored',function(){
  $r=SourceCoverageGate::assess(['source_verified'=>true,'mode'=>'PRODUCTION',
       'issuer'=>'CLIENT_BROWSER'],AS_OF);
  eq($r['source_verified'],false);eq($r['status'],'UNKNOWN');
});
t('identity_sql_injection_like_session_is_denied',function(){
  $p=FixtureData::pdo();
  $r=IdentityReader::resolve($p,FixtureData::sessionA(['username'=>"' OR 1=1 --"]));
  eq($r['status'],'IDENTITY_UNVERIFIED');
});
t('snapshot_rejects_cross_snapshot_time',function(){
  $p=FixtureData::pdo();$id=IdentityReader::resolve($p,FixtureData::sessionA());
  $coverage=SourceCoverageGate::assess(syntheticManifest(),AS_OF);
  $facts=PvPFactReader::readForSession($p,FixtureData::sessionA(),AS_OF);
  $pvp=PvPAnnualAggregator::aggregate($facts['rows'],['year'=>2026,'region'=>'TW',
    'player_name'=>'SYNTH_A','as_of'=>AS_OF,'source_verified'=>true]);
  $cup=CupPublicReader::read($p,2026,AS_OF);$cup['as_of']='2026-11-23 10:00:00';
  try{ResponseSnapshot::personal($id,$pvp,$cup,$coverage);throw new RuntimeException('failed_open');}
  catch(InvalidArgumentException $e){eq($e->getMessage(),'SNAPSHOT_WATERMARK_MISMATCH');}
});
t('production_cli_cannot_enter_staging_api',function(){
  eq(\Ulgg\Checkpoint2026\StagingGateway::permitted(),false);
});
t('snapshot_public_cup_only_published_no_private',function(){
  $pdo=FixtureData::pdo();$cup=CupPublicReader::read($pdo,2026,AS_OF);
  $r=ResponseSnapshot::publicCup($cup,SourceCoverageGate::assess(syntheticManifest(),AS_OF));
  eq($r['access'],'PUBLIC');eq($r['payload']['cup']['published_report_count'],1);
  eq($r['payload']['cup']['verified_games'],2);
  check(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'SYNTH_A'));
});
t('cup_public_reader_excludes_unpublished_and_invalid_rows',function(){
  $p=FixtureData::pdo();$r=CupPublicReader::read($p,2026,AS_OF);
  eq(count($r['reports']),1);eq($r['verified_games'],2);
});

$pass=0;$fail=0;$errors=[];
foreach($tests as $name=>$fn){
  try{$fn();$pass++;echo "PASS ".$name."\n";}
  catch(Throwable $e){$fail++;$errors[]=$name;echo "FAIL ".$name." :: ".get_class($e)." ".$e->getMessage()."\n";}
}
echo "M2_TDD pass=$pass fail=$fail total=".count($tests)."\n";
exit($fail===0?0:1);
