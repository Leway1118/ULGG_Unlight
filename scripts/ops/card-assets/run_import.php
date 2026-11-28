<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require __DIR__.'/apply_plan.php';
function loadJson($p):array{
    $r=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($r))throw new RuntimeException('invalid JSON');
    return $r;
}
function verifySnapshot(PDO $pdo,array $cards,array $feats):array{
    $ci=[];$fi=[];foreach($cards as $c)if($c['kind']===0)$ci[$c['id']]=$c;
    foreach($feats as $f)$fi[$f['id']]=$f;
    $scope=assetScope($pdo);if(!$scope)throw new RuntimeException('empty scope');
    $db=$pdo->query('SELECT id,HP,ATK,DEF,cost,skill1_code,skill2_code,skill3_code,skill4_code FROM unlight')->fetchAll(PDO::FETCH_ASSOC);$rows=array_column($db,null,'id');
    $db=$pdo->query('SELECT skill_code,name_tcn,info_tcn,phase,range_mask,require_json FROM unlight_skill')->fetchAll(PDO::FETCH_ASSOC);$skills=array_column($db,null,'skill_code');
    $db=$pdo->query('SELECT ID,L1,L2,L3,L4,L5,R1,R2,R3,R4,R5 FROM cost_unlight')->fetchAll(PDO::FETCH_ASSOC);$costs=array_column($db,null,'ID');
    $refs=0;
    foreach($scope as $id=>$identity){
        $c=$ci[$id]??null;$r=$rows[$id]??null;
        if(!$c||!$r||$c['filename']!==$identity['filename']||$c['chara']!==$identity['chara_code'])throw new RuntimeException('verify identity '.$id);
        foreach(['HP'=>'hp','ATK'=>'atk','DEF'=>'def','cost'=>'cost'] as $k=>$v)if(!assetEqual($k,$r[$k],$c[$v]))throw new RuntimeException('verify card '.$id.' '.$k);
        $group=intdiv($id-1,10)+1;$level=$identity['level'];
        if(!assetEqual($level,$costs[$group][$level]??null,$c['cost']))throw new RuntimeException('verify calculator '.$id);
        for($n=1;$n<=4;$n++){
            $sid=$c['skill'][$n-1]??null;$f=$sid===null?null:($fi[$sid]??null);
            if($sid!==null&&!$f)throw new RuntimeException('verify unresolved skill '.$id);
            $code=$f['texture']??null;
            if(!assetEqual('skill'.$n.'_code',$r['skill'.$n.'_code'],$code))throw new RuntimeException('verify mapping '.$id.' '.$n);
            if($f){
                $s=$skills[$code]??null;if(!$s)throw new RuntimeException('verify missing skill '.$code);
                foreach(['name_tcn'=>$f['name_tcn'],'info_tcn'=>$f['caption_tcn'],'phase'=>$f['phase'],'range_mask'=>implode(',',$f['distance']),'require_json'=>$f['require']] as $k=>$v)
                    if(!assetEqual($k,$s[$k],$v))throw new RuntimeException('verify skill '.$code.' '.$k);
                $refs++;
            }
        }
    }
    return ['status'=>'PASS','released_cards_checked'=>count($scope),'skill_references_checked'=>$refs];
}
function rollbackAssets(PDO $pdo,array $backup):array{
    $allowed=['unlight'=>['id',['HP','ATK','DEF','cost','skill1_code','skill2_code','skill3_code','skill4_code','chara_code','filename']],
        'cost_unlight'=>['ID',['L1','L2','L3','L4','L5','R1','R2','R3','R4','R5']],
        'unlight_skill'=>['skill_code',['name_tcn','info_tcn','phase','range_mask','require_json']]];
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$lock=$mysql?' FOR UPDATE':'';
    $pdo->beginTransaction();
    try{
        if($mysql)foreach(['SELECT id FROM unlight FOR UPDATE','SELECT ID FROM cost_unlight FOR UPDATE','SELECT id FROM unlight_skill FOR UPDATE'] as $q)$pdo->query($q)->fetchAll(PDO::FETCH_COLUMN);
        $states=[];
        foreach($backup['before'] as $r){
            $t=$r['table'];if(!isset($allowed[$t]))throw new RuntimeException('rollback table');
            [$pk,$cols]=$allowed[$t];$fs=array_keys($r['values']);
            if(!$fs||array_diff($fs,$cols)||array_keys($r['after'])!==$fs)throw new RuntimeException('rollback fields');
            $s=$pdo->prepare('SELECT '.implode(',',$fs).' FROM '.$t.' WHERE '.$pk.'=?'.$lock);$s->execute([$r['key'][$pk]]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('rollback row absent');
            $before=true;$after=true;
            foreach($fs as $f){$before=$before&&assetEqual($f,$row[$f],$r['values'][$f]);$after=$after&&assetEqual($f,$row[$f],$r['after'][$f]);}
            if(!$before&&!$after)throw new RuntimeException('rollback drift '.$t.' '.$r['key'][$pk]);
            $states[]=$before?'before':'after';
        }
        foreach($backup['new_skills']??[] as $n){
            $s=$pdo->prepare('SELECT name_tcn,info_tcn,phase,range_mask,require_json,note_player_tcn,source_hash FROM unlight_skill WHERE skill_code=?'.$lock);$s->execute([$n['skill_code']]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row){$states[]='before';continue;}
            if(trim((string)$row['note_player_tcn'])!==''||$row['source_hash']!==$n['source_hash'])throw new RuntimeException('rollback new skill modified');
            foreach($n['fields'] as $f=>$v)if(!assetEqual($f,$row[$f],$v))throw new RuntimeException('rollback new skill modified');
            $s=$pdo->prepare('SELECT COUNT(*) FROM unlight_skill_tag WHERE skill_code=?');$s->execute([$n['skill_code']]);if((int)$s->fetchColumn())throw new RuntimeException('rollback new skill has tags');
            $states[]='after';
        }
        if(!in_array('after',$states,true)){$pdo->rollBack();return ['status'=>'ALREADY_ROLLED_BACK'];}
        if(in_array('before',$states,true))throw new RuntimeException('mixed rollback state; manual review required');
        foreach(array_reverse($backup['before']) as $r){
            $t=$r['table'];$pk=$allowed[$t][0];$fs=array_keys($r['values']);$values=array_values($r['values']);$values[]=$r['key'][$pk];
            $s=$pdo->prepare('UPDATE '.$t.' SET '.implode(',',array_map(fn($f)=>$f.'=?',$fs)).' WHERE '.$pk.'=?');$s->execute($values);
        }
        foreach($backup['new_skills']??[] as $n){
            $code=$n['skill_code'];$s=$pdo->prepare('SELECT COUNT(*) FROM unlight WHERE skill1_code=? OR skill2_code=? OR skill3_code=? OR skill4_code=?');$s->execute([$code,$code,$code,$code]);
            if((int)$s->fetchColumn())throw new RuntimeException('rollback new skill still referenced '.$code);
            $s=$pdo->prepare('DELETE FROM unlight_skill WHERE skill_code=?');$s->execute([$code]);
        }
        $pdo->commit();return ['status'=>'ROLLED_BACK','rows'=>count($backup['before']),'deleted_new_skills'=>count($backup['new_skills']??[])];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $mode=$argv[1]??'';$root=$argv[2]??'/var/www/html/unlight';
    require $root.'/config.php';$pdo=$db??$pdo??null;
    if(!$pdo instanceof PDO)throw new RuntimeException('PDO unavailable');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    if($mode==='rollback')$result=rollbackAssets($pdo,loadJson($argv[3]));
    else{
        $dir=$argv[3];$plan=loadJson($dir.'/import-plan.json');$cards=loadJson($dir.'/CharaCards.json');$feats=loadJson($dir.'/Feats.json');$v=[];
        $verify=function(PDO $db)use($cards,$feats,&$v){$v=verifySnapshot($db,$cards,$feats);};
        if($mode==='verify')$result=verifySnapshot($pdo,$cards,$feats);
        elseif(in_array($mode,['rehearse','apply'],true)){
            $result=assetExecute($pdo,$plan,$mode==='apply',$argv[4]??null,$verify);$result['verification']=$v;
        }else throw new RuntimeException('unknown mode');
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
}
