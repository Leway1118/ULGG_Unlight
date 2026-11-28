<?php
declare(strict_types=1);
// Transaction executor: bounded official fields only, no DDL or protected field updates.
function assetNorm($field,$v) {
    if($field==='require_json'){
        if(is_string($v))$v=json_decode($v,true,512,JSON_THROW_ON_ERROR);
        if(is_array($v))$v=$v['require']??$v['cost']??$v;
        return $v;
    }
    if($field==='range_mask'){
        $r=array_filter(explode(',',(string)$v),fn($x)=>$x!=='');$r=array_values(array_unique(array_map('intval',$r)));sort($r);return implode(',',$r);
    }
    if(preg_match('/^(HP|ATK|DEF|cost|phase|[LR][1-5])$/D',$field))return $v===null?null:(int)$v;
    if(preg_match('/^skill[1-4]_code$/D',$field))return $v===''?null:$v;
    return $v;
}
function assetCanonical($v){
    if(!is_array($v))return $v;
    if($v!==[]&&array_keys($v)!==range(0,count($v)-1))ksort($v);
    foreach($v as &$x)$x=assetCanonical($x);
    return $v;
}
function assetEqual($field,$a,$b):bool{return assetCanonical(assetNorm($field,$a))===assetCanonical(assetNorm($field,$b));}
function assetWriteValue($field,$v){return $field==='require_json'?json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):$v;}
function assetScope(PDO $db,array $repairs=[]):array{
    $cards=$db->query("SELECT id,ico,level,chara_code,filename FROM unlight")->fetchAll(PDO::FETCH_ASSOC);
    $cost=$db->query("SELECT ID,on_stage FROM cost_unlight")->fetchAll(PDO::FETCH_KEY_PAIR);
    $active=[];
    foreach($cards as $r){$id=(int)$r['id'];if($id<1||!trim((string)$r['ico'])||!preg_match('/^[LR][1-5]$/D',(string)$r['level']))continue;
        $group=intdiv($id-1,10)+1;if((int)($cost[$group]??0)!==1)continue;
        $cc=sprintf('cc%03d',$group);$level=$r['level'];$filename=$cc.($level[0]==='R'?'_r':'_').sprintf('%02d',(int)$level[1]);
        if($r['chara_code']!==$cc||$r['filename']!==$filename){
            // Exact old/new values must be in the reviewed plan; do not bypass the guard.
            $fields=$repairs[$id]??[];
            $expected=['chara_code'=>$cc,'filename'=>$filename];
            $legacy=in_array($id,[541,542,543,544,545,551,552,553,554,555],true)&&$r['chara_code']===$cc&&$r['filename']===$cc.'_r'.sprintf('%02d',(int)$level[1]);
            $blank=in_array($id,[691,692,693,694,695,696],true)&&$r['chara_code']===''&&$r['filename']==='';
            if(!$legacy&&!$blank)throw new RuntimeException('unreviewed identity mismatch '.$id);
            foreach($expected as $k=>$v){
                if($r[$k]===$v)continue;
                if(!isset($fields[$k])||$fields[$k]['before']!==$r[$k]||$fields[$k]['after']!==$v)throw new RuntimeException('identity repair mismatch '.$id.' '.$k);
                $r[$k]=$v;
            }
        }
        $active[$id]=$r;
    }
    ksort($active);return $active;
}
function assetExecute(PDO $db,array $plan,bool $commit=false,?string $backupFile=null,?callable $verify=null):array {
    if(($plan['schema']??null)!==1)throw new RuntimeException('plan version');
    if($commit&&(!$backupFile||file_exists($backupFile)))throw new RuntimeException('unique private backup required');
    $tables=['unlight'=>['id',['HP','ATK','DEF','cost','skill1_code','skill2_code','skill3_code','skill4_code','chara_code','filename']],
             'cost_unlight'=>['ID',['L1','L2','L3','L4','L5','R1','R2','R3','R4','R5']],
             'unlight_skill'=>['skill_code',['name_tcn','info_tcn','phase','range_mask','require_json']]];
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$lock=$mysql?' FOR UPDATE':'';
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    if($mysql){
        $triggerCount=(int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('unlight','unlight_skill','cost_unlight','unlight_skill_tag')")->fetchColumn();
        if($triggerCount)throw new RuntimeException('target table triggers require separate review');
        $q=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('unlight','unlight_skill','cost_unlight','unlight_skill_tag')")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach(['unlight','unlight_skill','cost_unlight','unlight_skill_tag'] as $t)if(($q[$t]??'')!=='InnoDB')throw new RuntimeException('not transactional '.$t);
    }
    $db->beginTransaction();
    try {
        if($mysql){
            foreach(['SELECT id FROM unlight FOR UPDATE','SELECT ID FROM cost_unlight FOR UPDATE','SELECT id FROM unlight_skill FOR UPDATE'] as $sql)$db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        }
        $repairs=[];
        foreach($plan['changes'] as $change){
            if($change['table']!=='unlight')continue;
            $identity=array_intersect_key($change['fields'],array_flip(['chara_code','filename']));
            if($identity)$repairs[(int)$change['key']['id']]=$identity;
        }
        $active=assetScope($db,$repairs);
        if(array_keys($active)!==($plan['scope']['released_ids']??null))throw new RuntimeException('released scope changed');
        $before=[];$inserted=[];$seen=[];
        foreach($plan['changes'] as $change){
            $t=$change['table'];if(!isset($tables[$t]))throw new RuntimeException('forbidden table');
            [$pk,$allowed]=$tables[$t];$key=$change['key'][$pk]??null;
            if($key===null||isset($seen[$t][(string)$key]))throw new RuntimeException('duplicate/invalid key');$seen[$t][(string)$key]=true;
            if($t==='unlight'){
                if(!isset($active[(int)$key]))throw new RuntimeException('unreleased card');
                foreach(array_intersect_key($change['fields'],array_flip(['chara_code','filename'])) as $field=>$value)
                    if($value['after']!==$active[(int)$key][$field]||$value['before']===$value['after'])throw new RuntimeException('invalid identity change '.$key);
            }
            if($t==='cost_unlight'){
                foreach(array_keys($change['fields']) as $level){$eligible=false;foreach($active as $id=>$card)if(intdiv($id-1,10)+1===(int)$key&&$card['level']===$level)$eligible=true;
                    if(!$eligible)throw new RuntimeException('unreleased cost');}
            }
            $fields=array_keys($change['fields']);if(!$fields||array_diff($fields,$allowed))throw new RuntimeException('forbidden field');
            $s=$db->prepare('SELECT '.implode(',',$fields).' FROM '.$t.' WHERE '.$pk.'=?'.$lock);$s->execute([$key]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('missing row '.$t.' '.$key);
            foreach($change['fields'] as $field=>$v)if(!assetEqual($field,$row[$field],$v['before']))throw new RuntimeException('stale before '.$t.' '.$key.' '.$field);
            $before[]=['table'=>$t,'key'=>$change['key'],'values'=>$row,'after'=>array_map(fn($v)=>$v['after'],$change['fields'])];
        }
        foreach($plan['new_skills'] as $new){
            $code=$new['skill_code'];$sid=(int)$new['source_card_id'];$f=$new['fields'];
            if(!isset($active[$sid])||$new['character_code']!==$active[$sid]['chara_code']||!preg_match('/^cc\d+_sk\d+(?:_[a-zA-Z0-9]+)?$/D',$code)||!str_starts_with($code,$new['character_code'].'_')||!preg_match('/^[a-f0-9]{64}$/D',$new['source_hash'])||array_diff(array_keys($f),$tables['unlight_skill'][1])||count($f)!==5)throw new RuntimeException('invalid new skill');
            $s=$db->prepare('SELECT skill_code FROM unlight_skill WHERE skill_code=?'.$lock);$s->execute([$code]);if($s->fetchColumn()!==false)throw new RuntimeException('skill already exists '.$code);
            $s=$db->prepare('SELECT name FROM unlight WHERE id=?');$s->execute([$sid]);$name=$s->fetchColumn();
            $s=$db->prepare('INSERT INTO unlight_skill(skill_code,source_card_id,character_code,character_name_tcn,name_tcn,info_tcn,phase,range_mask,require_json,source_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$code,$sid,$new['character_code'],$name,$f['name_tcn'],$f['info_tcn'],$f['phase'],$f['range_mask'],assetWriteValue('require_json',$f['require_json']),$new['source_hash']]);
            $inserted[]=$code;
        }
        // Preserve tags and player notes by never including them in UPDATE columns.
        $ordered=$plan['changes'];usort($ordered,fn($a,$b)=>($a['table']==='unlight_skill'?0:1)<=>($b['table']==='unlight_skill'?0:1));
        foreach($ordered as $change){
            $t=$change['table'];$pk=$tables[$t][0];$key=$change['key'][$pk];$sets=[];$args=[];
            foreach($change['fields'] as $field=>$v){$sets[]=$field.'=?';$args[]=assetWriteValue($field,$v['after']);}
            $args[]=$key;$s=$db->prepare('UPDATE '.$t.' SET '.implode(',',$sets).' WHERE '.$pk.'=?');$s->execute($args);
            $fields=array_keys($change['fields']);$s=$db->prepare('SELECT '.implode(',',$fields).' FROM '.$t.' WHERE '.$pk.'=?');$s->execute([$key]);$row=$s->fetch(PDO::FETCH_ASSOC);
            foreach($change['fields'] as $field=>$v)if(!$row||!assetEqual($field,$row[$field],$v['after']))throw new RuntimeException('postcheck '.$t.' '.$key.' '.$field);
        }
        foreach($plan['new_skills'] as $new){
            $s=$db->prepare('SELECT name_tcn,info_tcn,phase,range_mask,require_json FROM unlight_skill WHERE skill_code=?');$s->execute([$new['skill_code']]);$row=$s->fetch(PDO::FETCH_ASSOC);
            foreach($new['fields'] as $field=>$v)if(!$row||!assetEqual($field,$row[$field],$v))throw new RuntimeException('insert postcheck '.$new['skill_code']);
        }
        if($verify!==null)$verify($db);
        $result=['status'=>$commit?'COMMITTED':'ROLLED_BACK_REHEARSAL','updated'=>count($before),'inserted'=>count($inserted),'source_hashes'=>$plan['source_hashes']];
        if($commit){
            $payload=['status'=>'PRECOMMIT','result'=>$result,'before'=>$before,'inserted'=>$inserted,'new_skills'=>$plan['new_skills']];
            $handle=fopen($backupFile,'x');if(!$handle)throw new RuntimeException('backup create failed');
            try{chmod($backupFile,0600);$blob=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
                if(fwrite($handle,$blob)!==strlen($blob)||!fflush($handle))throw new RuntimeException('backup write failed');
                if(function_exists('fsync')&&!fsync($handle))throw new RuntimeException('backup sync failed');
            }finally{fclose($handle);}
            $db->commit();
        }else $db->rollBack();
        return $result;
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
    // Candidate intentionally permits only rehearsal until service/audit integration is verified.
    if(($argv[1]??'')!=='rehearse'){fwrite(STDERR,"HOLD: release integration not approved by gates\n");exit(2);}
    require ($argv[2]??'/var/www/html/unlight').'/config.php';
    $pdo=$db??$pdo??null;if(!$pdo instanceof PDO)throw new RuntimeException('PDO unavailable');
    $plan=json_decode(file_get_contents($argv[3]??''),true,512,JSON_THROW_ON_ERROR);
    echo json_encode(assetExecute($pdo,$plan,false),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
}
