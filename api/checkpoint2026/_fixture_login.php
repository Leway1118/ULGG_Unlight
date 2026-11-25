<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/checkpoint2026/StagingGateway.php';
require_once __DIR__.'/../../tests/checkpoint2026/FixtureData.php';

use Ulgg\Checkpoint2026\StagingGateway;
use Ulgg\Checkpoint2026\Tests\FixtureData;

StagingGateway::requireStage();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    StagingGateway::json(['ok'=>false,'error'=>'method_not_allowed'],405);
}
StagingGateway::beginSession();
$input=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($input)) {
    StagingGateway::json(['ok'=>false,'error'=>'invalid_json'],400);
}
$who=$input['who']??null;
if($who === 'logout'){
    $_SESSION=[];
    session_regenerate_id(true);
    StagingGateway::json(['ok'=>true,'fixture'=>'LOGGED_OUT']);
}
if(!in_array($who,['A','B'],true)){
    StagingGateway::json(['ok'=>false,'error'=>'fixture_alias_required'],400);
}
session_regenerate_id(true);
$_SESSION = $who==='A' ? FixtureData::sessionA() : FixtureData::sessionB();
StagingGateway::json(['ok'=>true,'fixture'=>$who]);
