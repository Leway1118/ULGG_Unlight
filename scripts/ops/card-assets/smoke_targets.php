<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require ($argv[1]??'/var/www/html/unlight').'/config.php';
$p=$db??$pdo??null;
if(!$p instanceof PDO)throw new RuntimeException('PDO unavailable');
$plan=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$tags=$p->prepare('SELECT t.tag,s.name_tcn FROM unlight_skill_tag t JOIN unlight_skill_tag_dictionary d ON d.tag=t.tag JOIN unlight_skill s ON s.skill_code=t.skill_code WHERE t.skill_code=? ORDER BY t.tag LIMIT 1');
$target=null;
foreach($plan['changes'] as $r){
 if($r['table']!=='unlight_skill')continue;
 $tags->execute([$r['key']['skill_code']]);$v=$tags->fetch(PDO::FETCH_ASSOC);
 if($v&&$v['name_tcn']!==''){$target=$v;break;}
}
if(!$target){
 $target=$p->query("SELECT t.tag,s.name_tcn FROM unlight_skill_tag t JOIN unlight_skill_tag_dictionary d ON d.tag=t.tag JOIN unlight_skill s ON s.skill_code=t.skill_code WHERE s.name_tcn<>'' ORDER BY t.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
echo json_encode(['tag'=>$target],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
