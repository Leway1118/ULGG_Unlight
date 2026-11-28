<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$root=$argv[1]??'/var/www/html/unlight';
require $root.'/config.php';
$pdo=$db??$pdo??null;
if (!$pdo instanceof PDO) throw new RuntimeException('PDO unavailable');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET TRANSACTION READ ONLY');
$pdo->beginTransaction();
try {
$out=['php'=>PHP_VERSION,'checked_at'=>gmdate('c'),'tables'=>[]];
foreach(['unlight','unlight_skill','unlight_skill_tag','cost_unlight'] as $table){
  $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
  $engine=$q->fetchColumn();
  $q=$pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_ASSOC);
  $q=$pdo->prepare('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');$q->execute([$table]);
  $out['tables'][$table]=['engine'=>$engine,'columns'=>$columns,'indexes'=>$q->fetchAll(PDO::FETCH_ASSOC)];
}
$out['cards']=$pdo->query('SELECT id,level,ico,chara_code,filename,HP,ATK,DEF,cost,skill1_code,skill2_code,skill3_code,skill4_code FROM unlight ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$out['skills']=$pdo->query('SELECT skill_code,name_tcn,info_tcn,phase,range_mask,require_json FROM unlight_skill ORDER BY skill_code')->fetchAll(PDO::FETCH_ASSOC);
$out['costs']=$pdo->query('SELECT ID,on_stage,L1,L2,L3,L4,L5,R1,R2,R3,R4,R5 FROM cost_unlight ORDER BY ID')->fetchAll(PDO::FETCH_ASSOC);
$out['notes_count']=(int)$pdo->query("SELECT COUNT(*) FROM unlight_skill WHERE note_player_tcn IS NOT NULL AND note_player_tcn<>''")->fetchColumn();
$out['tags_count']=(int)$pdo->query('SELECT COUNT(*) FROM unlight_skill_tag')->fetchColumn();
$pdo->rollBack();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); fwrite(STDERR,"DB_PREFLIGHT_FAILED; inspect authorized CLI configuration/schema locally.\n"); exit(2); }
