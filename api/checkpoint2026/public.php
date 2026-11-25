<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/checkpoint2026/StagingGateway.php';
require_once __DIR__.'/../../lib/checkpoint2026/SourceCoverageGate.php';
require_once __DIR__.'/../../lib/checkpoint2026/CupPublicReader.php';
require_once __DIR__.'/../../lib/checkpoint2026/ResponseSnapshot.php';
require_once __DIR__.'/../../tests/checkpoint2026/FixtureData.php';

use Ulgg\Checkpoint2026\StagingGateway;
use Ulgg\Checkpoint2026\SourceCoverageGate;
use Ulgg\Checkpoint2026\CupPublicReader;
use Ulgg\Checkpoint2026\ResponseSnapshot;
use Ulgg\Checkpoint2026\Tests\FixtureData;

StagingGateway::requireStage();
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
    StagingGateway::json(['ok'=>false,'error'=>'method_not_allowed'],405,false);
}
// Public API must never accept a subject/account selector.
foreach(['player','username','steam_id','user_id','region'] as $key){
    if(array_key_exists($key,$_GET)){
        StagingGateway::json(['ok'=>false,'error'=>'account_selector_forbidden'],403,false);
    }
}
try{
    $asOf='2026-11-24 10:00:00';
    $cup=CupPublicReader::read(FixtureData::pdo(),2026,$asOf);
    $gate=SourceCoverageGate::assess(FixtureData::manifest(),$asOf);
    StagingGateway::json(ResponseSnapshot::publicCup($cup,$gate),200,false);
}catch(Throwable $e){
    StagingGateway::json(['ok'=>false,'error'=>'staging_internal_failure'],503,false);
}
