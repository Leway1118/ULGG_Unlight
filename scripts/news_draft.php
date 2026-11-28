<?php
declare(strict_types=1);
// Invoke only from the existing authorized deployment runner AFTER verified RELEASE_PASS.
// Receipt is supplied over stdin; public copy is explicit, never mined from private logs.
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once __DIR__.'/../lib/news_timeline.php';
try{
 $raw=stream_get_contents(STDIN,16385);
 if(strlen($raw)>16384)throw new RuntimeException('receipt too large');
 $r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($r))throw new RuntimeException('invalid receipt');
 $draft=ulNewsDraftFromReceipt($r);
 if(($argv[1]??'')==='--preview'){
  echo json_encode(['status'=>'DRAFT_PREVIEW','public_news'=>$draft],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;exit;
 }
 if(($argv[1]??'')!=='--insert-draft')throw new RuntimeException('explicit draft insertion mode required');
 // Existing CLI configuration and authorization; never accept arbitrary DB credentials.
 require_once __DIR__.'/../config.php';$pdo=$db??$pdo??null;
 if(!$pdo instanceof PDO)throw new RuntimeException('PDO unavailable');
 $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $result=ulNewsInsertDraft($pdo,$r);echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"NEWS_DRAFT_FAILED; no automatic publication\n");exit(1);}
