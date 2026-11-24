<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/checkpoint2026/StagingGateway.php';
require_once __DIR__.'/../../lib/checkpoint2026/IdentityGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/IdentityReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPFactReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/SourceCoverageGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/PvPAnnualAggregator.php';
require_once __DIR__.'/../../lib/checkpoint2026/CupPublicReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/ResponseSnapshot.php';
require_once __DIR__.'/../../tests/checkpoint2026/FixtureData.php';

use Ulgg\Checkpoint2026\StagingGateway;
use Ulgg\Checkpoint2026\IdentityReader;
use Ulgg\Checkpoint2026\PvPFactReader;
use Ulgg\Checkpoint2026\SourceCoverageGate;
use Ulgg\Checkpoint2026\PvPAnnualAggregator;
use Ulgg\Checkpoint2026\CupPublicReader;
use Ulgg\Checkpoint2026\ResponseSnapshot;
use Ulgg\Checkpoint2026\Tests\FixtureData;

StagingGateway::requireStage();
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){
    StagingGateway::json(['ok'=>false,'error'=>'method_not_allowed'],405);
}
StagingGateway::beginSession();
// No client-controlled person/account selection, even when apparently equal
// to the logged-in account. This prevents accidental IDOR entrypoints.
foreach(['player','username','steam_id','user_id','region','view_as'] as $key){
    if(array_key_exists($key,$_GET)){
        StagingGateway::json(['ok'=>false,'error'=>'account_selector_forbidden'],403);
    }
}
if(empty($_SESSION['steam_id']) || empty($_SESSION['user_id'])){
    StagingGateway::json(['ok'=>false,'error'=>'authentication_required'],401);
}

try {
    $pdo=FixtureData::pdo(); // Synthetic memory DB; never production config.php
    $subject=IdentityReader::resolve($pdo,$_SESSION);
    if($subject['status']!=='VERIFIED'){
        StagingGateway::json(['ok'=>false,'error'=>'identity_unverified'],403);
    }
    $asOf='2026-11-24 10:00:00';
    $facts=PvPFactReader::readForSession($pdo,$_SESSION,$asOf);
    if($facts['identity']['status']!=='VERIFIED'){
        StagingGateway::json(['ok'=>false,'error'=>'identity_unverified'],403);
    }
    $expected=$subject['subject']['region']==='TW'?4:1;
    $gate=SourceCoverageGate::assess(
        FixtureData::manifest(['observed_rows'=>count($facts['rows']),'expected_rows'=>$expected]),
        $asOf
    );
    $pvp=PvPAnnualAggregator::aggregate($facts['rows'],[
        'year'=>2026,'region'=>$subject['subject']['region'],
        'player_name'=>$subject['subject']['username'],
        'as_of'=>$asOf,'source_verified'=>$gate['source_verified']
    ]);
    $cup=CupPublicReader::read($pdo,2026,$asOf);
    $response=ResponseSnapshot::personal($subject,$pvp,$cup,$gate);
    StagingGateway::json($response);
} catch(Throwable $e){
    // Never leak SQL, Steam ID or account names on failure.
    StagingGateway::json(['ok'=>false,'error'=>'staging_internal_failure'],503);
}
