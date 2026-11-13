<?php
/**
 * UL.GG Production COST consistency audit (read-only; CLI only).
 * Compares currently staged cards with unlight cards that have an actual
 * card illustration. Unreleased R cards have prefilled values but no image,
 * so they must NOT be auto-published merely because their cost is nonzero.
 */
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require '/var/www/html/unlight/config.php';
$p=$db ?? $pdo ?? null;
if (!$p instanceof PDO){fwrite(STDERR,"STOP=NO_DATABASE\n");exit(2);}
$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$levels=['L1','L2','L3','L4','L5','R1','R2','R3','R4','R5'];
$byId=[];
foreach($p->query('SELECT * FROM cost_unlight')->fetchAll(PDO::FETCH_ASSOC) as $r)
  $byId[(int)$r['ID']]=$r;
$staged=count(array_filter($byId,fn($r)=>(int)($r['on_stage']??0)===1 && isset($r['L1'])));
$all=$p->query("SELECT id,name,level,cost,ico,updated_at FROM unlight WHERE level REGEXP '^[LR][1-5]$' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$drift=[]; $suspects=[]; $checked=0;
foreach($all as $c){
  $group=intdiv((int)$c['id']-1,10)+1;
  $r=$byId[$group]??null;
  if(!$r || !in_array($c['level'],$levels,true))continue;
  $stage=(int)($r['on_stage']??0)===1;
  $hasImage=trim((string)($c['ico']??''))!=='';
  $actual=(int)$c['cost'];
  if($stage && $hasImage){
    $checked++;
    $cost=$r[$c['level']];
    if($actual<=0 || $cost===null || (int)$cost!==$actual){
      $drift[]=['id'=>(int)$c['id'],'name'=>$c['name'],'level'=>$c['level'],'unlight_cost'=>$actual,'calculator_cost'=>$cost];
    }
  }
  if(!$stage && $hasImage && (int)$c['id']%10===1 && strtotime($c['updated_at'])>=strtotime('-14 days')){
    $suspects[]=['id'=>(int)$c['id'],'name'=>$c['name'],'updated_at'=>$c['updated_at']];
  }
}
// Checked reference card costs (community Wiki entries are not official game data).
// Konrad R5 (ID 420) = 34: https://unlightrevive.wikiru.jp/?キャラクター/コンラッド
$expected=[
  240=>28, 420=>34, 470=>31, 544=>17, 554=>17, 568=>27, 588=>28,
  691=>9,692=>12,693=>17,694=>18,695=>23,696=>22
];
$byCard=[];
foreach($all as $c)$byCard[(int)$c['id']]=$c;
foreach($expected as $id=>$expectedCost){
  $c=$byCard[$id]??null;
  $r=$byId[intdiv($id-1,10)+1]??null;
  $actual=$c?(int)$c['cost']:null;
  $cost=($c && $r)?$r[$c['level']]:null;
  if(!$c || !$r || (int)$r['on_stage']!==1 || $actual!==$expectedCost || $cost===null || (int)$cost!==$expectedCost){
    $drift[]=['id'=>$id,'name'=>$c['name']??null,'level'=>$c['level']??null,
      'expected'=>$expectedCost,'unlight_cost'=>$actual,'calculator_cost'=>$cost];
  }
}
$unique=[];
foreach($drift as $r)$unique[$r['id']]=$r;
ksort($unique);
echo 'STATUS='.(count($unique)===0?'PASS':'DRIFT')."\n";
echo "STAGED_CHARACTERS=$staged\n";
echo "CHECKED_ILLUSTRATED_CARDS=$checked\n";
echo "VERIFIED_RELEASE_ENTRIES=".count($expected)."\n";
echo 'DRIFT_COUNT='.count($unique)."\n";
foreach($unique as $r)echo 'DRIFT='.json_encode($r,JSON_UNESCAPED_UNICODE)."\n";
echo 'REVIEW_CANDIDATES='.count($suspects)."\n";
foreach($suspects as $r)echo 'REVIEW='.json_encode($r,JSON_UNESCAPED_UNICODE)."\n";
exit($unique?2:0);
