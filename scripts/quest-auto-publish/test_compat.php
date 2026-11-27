<?php
declare(strict_types=1);
require_once __DIR__.'/contract_reference/inc/bootstrap.php';
require_once __DIR__.'/contract_reference/inc/helpers.php';
require_once __DIR__.'/contract_reference/inc/data.php';
$counts=[];
foreach(QPUB_WINDOWS as $w){
 foreach(['quests','regions'] as $kind){
  $doc=qpub_window_doc($w,$kind);
  $rows=qpub_window_rows($w,$kind);
  if(!isset($doc['count']) || count($rows)!==$doc['count']) throw new RuntimeException('row count mismatch');
  $counts[$w][$kind]=count($rows);
 }
}
echo json_encode(['counts'=>$counts,'available'=>qpub_data_available(),'warnings'=>qpub_contract_warnings()],JSON_THROW_ON_ERROR),PHP_EOL;
