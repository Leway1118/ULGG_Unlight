<?php
if((int)($_SESSION['permission']??0)<2)return;
// ULGG_CARD_ASSETS_CHANGE_TEXT_R1: admin-only read-only presentation of existing snapshots.
function ulAssetDiffRead(string $path): ?array {
    if(!is_file($path)||!is_readable($path)||str_replace('\\','/',(string)realpath($path))!==str_replace('\\','/',$path)||filesize($path)>10485760)return null;
    $v=json_decode((string)file_get_contents($path),true);
    return is_array($v)?$v:null;
}
function ulAssetDiffVersion(string $root,$g,string $file): ?array {
    if(!ulAssetsGeneration($g)||!in_array($file,['CharaCards.json','Feats.json','db_changes.json'],true))return null;
    return ulAssetDiffRead($root.'/versions/'.$g.'/'.$file);
}
function ulAssetDiffIndex(array $rows,string $key): array {
    $out=[];foreach($rows as $r)if(is_array($r)&&isset($r[$key])&&is_scalar($r[$key]))$out[$r[$key]]=$r;
    return $out;
}
function ulAssetDiffNames(PDO $pdo): array {
    // Read actual website names; no invented role names, no writes or new permissions.
    return [
        'cards'=>ulAssetDiffIndex($pdo->query('SELECT id,name,level FROM unlight')->fetchAll(PDO::FETCH_ASSOC),'id'),
        'groups'=>ulAssetDiffIndex($pdo->query('SELECT ID,name FROM cost_unlight')->fetchAll(PDO::FETCH_ASSOC),'ID'),
        'skills'=>ulAssetDiffIndex($pdo->query('SELECT skill_code,name_tcn FROM unlight_skill')->fetchAll(PDO::FETCH_ASSOC),'skill_code')
    ];
}
function ulAssetDiffRole(array $names,string $code): string {
    if(!preg_match('/^cc([0-9]+)(?:_|$)/D',$code,$m))return '';
    $g=(int)$m[1];$n=trim((string)($names['groups'][$g]['name']??''));
    if($n==='')$n=trim((string)($names['cards'][($g-1)*10+1]['name']??''));
    return $n!==''?$n:'尚無角色名稱（cc'.sprintf('%03d',$g).'）';
}
function ulAssetDiffCardTitle(array $names,array $card): string {
    $id=(int)($card['id']??0);$code=(string)($card['chara']??'');$kind=$card['kind']??null;
    $level=isset($card['level'])?((strpos((string)($card['filename']??''),'_r')!==false?'R':'L').$card['level']):'';
    if($kind===0){
        $name=trim((string)($names['cards'][$id]['name']??''));
        if($name==='')$name=ulAssetDiffRole($names,$code);
        if($name==='')$name='尚無角色名稱';
        return trim($name.' '.$level);
    }
    return '非角色卡 '.$code.'（ID '.$id.'）';
}
function ulAssetDiffSkillTitle(array $names,array $f): string {
    $code=(string)($f['texture']??$f['skill_code']??'');$name=trim((string)($f['name_tcn']??$names['skills'][$code]['name_tcn']??''));
    $role=ulAssetDiffRole($names,$code);
    return ($role!==''?$role.' · ':'').($name!==''?$name:'尚無技能名稱（'.$code.'）');
}
function ulAssetDiffLabel(string $k): string {
    $labels=['HP'=>'HP（生命）','hp'=>'HP（生命）','ATK'=>'ATK（攻擊）','atk'=>'ATK（攻擊）','DEF'=>'DEF（防禦）','def'=>'DEF（防禦）','cost'=>'COST','chara'=>'角色代碼','chara_code'=>'角色代碼','filename'=>'素材檔名','skill'=>'技能組合','skill1_code'=>'技能 1','skill2_code'=>'技能 2','skill3_code'=>'技能 3','skill4_code'=>'技能 4','name_tcn'=>'技能名稱','info_tcn'=>'技能效果','caption_tcn'=>'技能效果','phase'=>'發動階段','range_mask'=>'發動距離','distance'=>'發動距離','require_json'=>'發動條件','require'=>'發動條件','slot'=>'事件卡槽','next'=>'升級／進化條件','passive'=>'被動效果','rarity'=>'稀有度','level'=>'等級','kind'=>'卡片類型','effect_image'=>'效果素材','texture'=>'技能代碼'];
    return $labels[$k]??$k;
}
function ulAssetDiffJson($v): string {return (string)json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);}
function ulAssetDiffValue(string $key,$v,array $featIndex,array $codeIndex,array $names): string {
    if($v===null||$v==='')return '（未設定）';
    if($key==='skill'&&is_array($v)){
        $a=[];foreach($v as $id)$a[]=($featIndex[$id]['name_tcn']??'未解析技能 ID '.$id);
        return $a?implode('、',$a):'無技能';
    }
    if(preg_match('/^skill[1-4]_code$/D',$key)&&is_string($v)){
        return ($codeIndex[$v]['name_tcn']??$names['skills'][$v]['name_tcn']??'未解析技能').'（'.$v.'）';
    }
    if($key==='phase')return [0=>'攻擊（ATK）',1=>'防禦（DEF）',2=>'移動（MOV）'][(string)$v]??'未知階段 '.$v;
    if(in_array($key,['range_mask','distance'],true)){
        $a=is_array($v)?$v:explode(',',(string)$v);$labels=['近距離','中距離','遠距離'];
        return $a?implode('、',array_map(fn($r)=>$labels[(string)$r]??'未知距離 '.$r,$a)):'未設定距離';
    }
    if(in_array($key,['require','require_json'],true)){
        $a=is_string($v)?json_decode($v,true):$v;
        if(is_array($a))$a=$a['require']??$a['cost']??$a;
        if(!is_array($a))return is_string($v)?$v:ulAssetDiffJson($v);
        $types=['劍','槍','盾','移','特','任意'];$ops=['至少','等於','至多'];$out=[];
        foreach($a as $r){
            if(!is_array($r)||!isset($r['type'],$r['quantity'])){$out[]=ulAssetDiffJson($r);continue;}
            $t=is_array($r['type'])?$r['type']:[$r['type']];
            $label=implode('／',array_map(fn($x)=>$types[(string)$x]??'類型 '.$x,$t));
            $op=$ops[(string)($r['num']??0)]??'比較方式 '.(string)$r['num'];
            $out[]=$label.' '.$op.' '.$r['quantity'];
        }
        return $out?implode('；',$out):'無卡片條件';
    }
    if($key==='slot'&&is_array($v)){$a=['劍','槍','盾','移','特','花','黑','無'];return implode('、',array_map(fn($x)=>$a[(string)$x]??'未知 '.$x,$v));}
    return is_array($v)||is_object($v)||is_bool($v)?ulAssetDiffJson($v):(string)$v;
}
function ulAssetDiffTable(array $rows,string $title,bool $open=false): void {
    if(!$rows)return;
    $e='ulAssetsE';
    echo '<details class="mb-2"'.($open?' open':'').'><summary style="cursor:pointer;font-weight:600">'.$e($title).'（'.count($rows).' 筆）</summary>';
    echo '<div class="admin-table-wrap mt-2"><table class="ul-table admin-table"><thead><tr><th scope="col">角色／技能</th><th scope="col">異動內容</th></tr></thead><tbody>';
    foreach($rows as $r){
        echo '<tr><td style="width:30%;min-width:95px;overflow-wrap:anywhere;vertical-align:top"><strong>'.$e($r['title']).'</strong><div class="admin-panel-note">'.$e($r['code']).'</div></td><td style="white-space:pre-wrap;overflow-wrap:anywhere;vertical-align:top">';
        foreach($r['lines'] as $line)echo '<div class="mb-1">'.$e($line).'</div>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div></details>';
}
function ulAssetDiffLines(array $fields,array $oldFeat,array $newFeat,array $oldCodes,array $newCodes,array $names): array {
    $lines=[];
    foreach($fields as $k=>$v){
        if(!is_array($v)||!array_key_exists('before',$v)||!array_key_exists('after',$v))continue;
        $before=ulAssetDiffValue((string)$k,$v['before'],$oldFeat,$oldCodes,$names);
        $after=ulAssetDiffValue((string)$k,$v['after'],$newFeat,$newCodes,$names);
        $lines[]=ulAssetDiffLabel((string)$k).'：'.$before.' → '.$after;
    }
    return $lines;
}
function ulAssetDiffRender(PDO $pdo,array $status,?array $audit,string $root='/var/lib/ulgg-card-assets'): void {
    $g=$status['generation']??null;if(!ulAssetsGeneration($g))return;
    $e='ulAssetsE';
    $cards=ulAssetDiffVersion($root,$g,'CharaCards.json');$feats=ulAssetDiffVersion($root,$g,'Feats.json');
    if($cards===null||$feats===null){echo '<p class="admin-panel-note">異動明細暫不可讀取。</p>';return;}
    $names=ulAssetDiffNames($pdo);$ci=ulAssetDiffIndex($cards,'id');$fi=ulAssetDiffIndex($feats,'id');$fc=ulAssetDiffIndex($feats,'texture');
    echo '<section id="official-card-assets-change-text" class="mt-3"><h3 class="h6">異動明細（角色名稱／技能名稱）</h3>';
    $w=$status['week']??'';
    $weekly=is_string($w)&&preg_match('/\A[0-9]{4}-W[0-9]{2}\z/D',$w)?ulAssetDiffRead($root.'/weeks/'.$w.'/'.$g.'.json'):null;
    echo '<h4 class="h6 mt-3">本週官方素材異動</h4>';
    if(!$weekly||($weekly['generation']??null)!==$g||($weekly['week']??null)!==$w){echo '<p class="admin-panel-note">本週異動資料暫不可讀取。</p>';}
    elseif(empty($weekly['baseline_known'])){echo '<p class="admin-panel-note">首次建立快照，尚無前週基準；以下資料庫匯入明細並非本週官方改版。</p>';}
    else{
        $old=ulAssetDiffVersion($root,$weekly['baseline_generation']??null,'Feats.json');
        if($old===null){echo '<p class="admin-panel-note">前週技能快照不可讀取，無法可靠比對。</p>';}
        else{
            $ofi=ulAssetDiffIndex($old,'id');$ofc=ulAssetDiffIndex($old,'texture');$total=0;
            foreach(['cards'=>'角色及其他卡片','feats'=>'技能'] as $type=>$label){
                $rows=[];
                foreach($weekly[$type]['added']??[] as $r){
                    $fields=[];foreach($r as $k=>$v)if(in_array($k,['hp','atk','def','cost','skill','name_tcn','caption_tcn','phase','distance','require','slot'],true))$fields[$k]=['before'=>null,'after'=>$v];
                    $rows[]=['title'=>$type==='cards'?ulAssetDiffCardTitle($names,$r):ulAssetDiffSkillTitle($names,$r),'code'=>'新增 · '.($r['filename']??$r['texture']??'').' · ID '.$r['id'],'lines'=>ulAssetDiffLines($fields,$ofi,$fi,$ofc,$fc,$names)];
                }
                foreach($weekly[$type]['changed']??[] as $r){
                    $cur=($type==='cards'?$ci:$fi)[$r['id']]??['id'=>$r['id']];
                    $rows[]=['title'=>$type==='cards'?ulAssetDiffCardTitle($names,$cur):ulAssetDiffSkillTitle($names,$cur),'code'=>'修改 · '.($cur['filename']??$cur['texture']??'').' · ID '.$r['id'],'lines'=>ulAssetDiffLines($r['fields'],$ofi,$fi,$ofc,$fc,$names)];
                }
                $total+=count($rows);ulAssetDiffTable($rows,$label.'異動',true);
            }
            if(!$total)echo '<p class="admin-panel-note">本週沒有官方素材異動。</p>';
        }
    }
    echo '<h4 class="h6 mt-3">最近資料庫匯入明細</h4>';
    $db=($audit['status']??'')==='PASS'&&($audit['generation']??null)===$g?ulAssetDiffVersion($root,$g,'db_changes.json'):null;
    if(!$db||($db['generation']??null)!==$g){echo '<p class="admin-panel-note">尚無此快照的已驗證匯入紀錄。</p></section>';return;}
    echo '<p class="admin-panel-note">以網站實際入庫紀錄為準；首次修復也會列入。'.($e($db['applied_at']??'')).'</p>';
    $sections=['unlight'=>[],'cost_unlight'=>[],'unlight_skill'=>[],'new'=>[]];
    // Old DB skill codes can have old names; use the before name from the recorded change.
    $oldCodes=$fc;
    foreach($db['changes']??[] as $r)if($r['table']==='unlight_skill'&&isset($r['fields']['name_tcn']['before']))$oldCodes[$r['key']['skill_code']]['name_tcn']=$r['fields']['name_tcn']['before'];
    foreach($db['changes']??[] as $r){
        $t=$r['table'];$k=$r['key'];$fields=$r['fields'];$code='';
        if($t==='unlight'){$id=(int)$k['id'];$cur=$ci[$id]??['id'=>$id];$title=ulAssetDiffCardTitle($names,$cur);$code=($cur['filename']??'').' · ID '.$id;}
        elseif($t==='cost_unlight'){$id=(int)$k['ID'];$title=(string)($names['groups'][$id]['name']??ulAssetDiffRole($names,sprintf('cc%03d',$id)));$code=sprintf('cc%03d',$id);}
        elseif($t==='unlight_skill'){$code=(string)$k['skill_code'];$title=ulAssetDiffSkillTitle($names,$fc[$code]??['skill_code'=>$code]);}
        else continue;
        $sections[$t][]=['title'=>$title,'code'=>$code,'lines'=>ulAssetDiffLines($fields,$fi,$fi,$oldCodes,$fc,$names)];
    }
    foreach($db['new_skills']??[] as $r){
        $code=(string)$r['skill_code'];$fields=[];foreach($r['fields'] as $k=>$v)$fields[$k]=['before'=>null,'after'=>$v];
        $sections['new'][]=['title'=>ulAssetDiffSkillTitle($names,['skill_code'=>$code,'name_tcn'=>$r['fields']['name_tcn']??'']),'code'=>$code,'lines'=>ulAssetDiffLines($fields,$fi,$fi,$oldCodes,$fc,$names)];
    }
    foreach(['unlight'=>'角色卡更新','cost_unlight'=>'COST 更新','unlight_skill'=>'既有技能更新','new'=>'新增技能'] as $k=>$v)ulAssetDiffTable($sections[$k],$v,in_array($k,['unlight','cost_unlight'],true));
    if(!array_sum(array_map('count',$sections)))echo '<p class="admin-panel-note">沒有資料庫異動。</p>';
    echo '</section>';
}

require_once __DIR__.'/card_assets_status.php';
$as=ulAssetsRead('status.json');$ad=ulAssetsRead('db-audit.json');$aw=ulAssetsWarning();
$g=$as['generation']??null;$prev=$as['previous_generation']??null;
$e='ulAssetsE';
?>
<div class="admin-panel" id="official-card-assets">
 <div class="admin-panel-head"><h2 class="admin-panel-title">官方角色／技能素材更新</h2><span class="admin-panel-note">每週四 18:00（台灣時間）</span></div>
 <div class="admin-panel-body">
 <p><strong class="<?= $aw?'text-warning':'text-success' ?>"><?= $e($aw??'官方素材與網站資料庫同步正常') ?></strong></p>
 <?php if($as): ?>
 <p>角色 <?= $e($as['character_count']??'—') ?> 位／角色卡 <?= $e($as['character_cards']??'—') ?> 張／技能 <?= $e($as['feat_count']??'—') ?> 筆</p>
 <p>最後檢查：<?= $e(date('Y-m-d H:i:s',strtotime($as['checked_at']))) ?>；最近素材異動：<?= $e(date('Y-m-d H:i:s',strtotime($as['last_change_at']))) ?></p>
 <p>本週（<?= $e($as['week']??'') ?>）：<?php if(!empty($as['week_baseline'])): ?>卡片新增 <?= $e($as['week_cards']['added']??0) ?>／修改 <?= $e($as['week_cards']['changed']??0) ?>；技能新增 <?= $e($as['week_feats']['added']??0) ?>／修改 <?= $e($as['week_feats']['changed']??0) ?>。<?php else: ?>首次建立或缺少前週基準，不將初次快照計為本週新增。<?php endif; ?></p>
 <?php if(($ad['status']??'')==='PASS'&&($ad['generation']??null)===$g):$su=$ad['summary']??[]; ?>
 <p>最近資料庫匯入：角色更新 <?= $e($su['updated']['unlight']??0) ?>／COST 更新 <?= $e($su['updated']['cost_unlight']??0) ?>／技能更新 <?= $e($su['updated']['unlight_skill']??0) ?>／新增技能 <?= $e($su['new_skills']??0) ?>。已驗證 <?= $e($ad['verification']['released_cards_checked']??0) ?> 張上架角色卡。</p>
 <?php endif; ?>
 <?php if(ulAssetsGeneration($g)): ?>
 <p><a class="btn btn-sm btn-outline-secondary" href="/pages/admin/card_assets_download.php?generation=<?= $e($g) ?>&amp;file=weekly_changes.json">本週官方異動明細</a>
 <?php if(($ad['status']??'')==='PASS'&&($ad['generation']??null)===$g): ?><a class="btn btn-sm btn-outline-secondary" href="/pages/admin/card_assets_download.php?generation=<?= $e($g) ?>&amp;file=db_changes.json">正式 DB 匯入明細</a><?php endif; ?></p>
 <?php endif; ?>
 <?php foreach(['目前快照'=>$g,'前一版'=>$prev] as $label=>$id):if(!ulAssetsGeneration($id))continue; ?>
 <p><?= $e($label) ?>：<?php foreach(['CharaCards.json','Feats.json','changes.json'] as $f):?><a class="btn btn-sm btn-outline-secondary" href="/pages/admin/card_assets_download.php?generation=<?= $e($id) ?>&amp;file=<?= $e($f) ?>"><?= $e($f) ?></a> <?php endforeach;?></p>
 <?php endforeach;?>
 <?php if(isset($db)&&$db instanceof PDO):try{ulAssetDiffRender($db,$as,$ad);}catch(Throwable $ex){echo '<p class="admin-panel-note">異動明細暫無法載入，原同步狀態不受影響。</p>';}endif; ?>
 <p class="admin-panel-note">未解析技能 ID：<?= count($as['unresolved_skill_ids']??[]) ?>。尚未上架或缺少卡圖的資料不自動公開。</p>
 <?php endif;?>
 </div>
</div>
