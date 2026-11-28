// ULGG_CARD_ASSETS_V3_R2: expected base COST from the last verified DB generation.
$assetReceiptPath='/var/lib/ulgg-card-assets/db-current.json';
if(is_file($assetReceiptPath)){
  try{
    $ar=json_decode((string)file_get_contents($assetReceiptPath),true,512,JSON_THROW_ON_ERROR);
    $ag=$ar['generation']??'';
    if(($ar['status']??'')!=='PASS'||!is_string($ag)||!preg_match('/\A[a-f0-9]{64}\z/D',$ag))throw new RuntimeException('invalid receipt');
    $ap='/var/lib/ulgg-card-assets/versions/'.$ag.'/CharaCards.json';
    if(!is_readable($ap)||hash_file('sha256',$ap)!==($ar['cards_sha256']??null))throw new RuntimeException('asset hash mismatch');
    $ao=json_decode((string)file_get_contents($ap),true,512,JSON_THROW_ON_ERROR);
    $ac=[];foreach($ao as $v)if(($v['kind']??null)===0)$ac[(int)$v['id']]=$v['cost'];
    foreach($expected as $i=>$v){if(!isset($ac[$i]))throw new RuntimeException('reference absent');$expected[$i]=$ac[$i];}
    foreach($all as $card){$i=(int)$card['id'];$gr=intdiv($i-1,10)+1;if((int)($byId[$gr]['on_stage']??0)!==1||trim((string)$card['ico'])==='')continue;if(!isset($ac[$i]))throw new RuntimeException('released card absent');$expected[$i]=$ac[$i];}
  }catch(Throwable $e){fwrite(STDERR,"STOP=OFFICIAL_COST_REFERENCE_INVALID\n");exit(2);}
}
