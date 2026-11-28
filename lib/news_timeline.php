<?php
declare(strict_types=1);
const ULGG_NEWS_TYPES = [
 'feature'=>['新功能','SYSTEM_NOTICE'], 'update'=>['功能更新','SYSTEM_NOTICE'],
 'data'=>['資料更新','SYSTEM_NOTICE'], 'fix'=>['問題修正','SYSTEM_NOTICE'],
 'maintenance'=>['維護','MAINT_NOTICE'], 'event'=>['活動','EVENT_NOTICE'],
 'rank'=>['排行榜','RANK_NOTICE'], 'system'=>['系統公告','SYSTEM_NOTICE']
];
function ulNewsH($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function ulNewsAdmin(array $s):bool{return (int)($s['permission']??0)>=2;}
function ulNewsCsrf(array &$s):string{
 if(!isset($s['news_csrf'])||!is_string($s['news_csrf']))$s['news_csrf']=bin2hex(random_bytes(32));
 return $s['news_csrf'];
}
function ulNewsCheckCsrf(array $s,$token):bool{
 return ulNewsAdmin($s)&&is_string($token)&&is_string($s['news_csrf']??null)&&hash_equals($s['news_csrf'],$token);
}
function ulNewsLink($v):?string{
 if(!is_string($v))return null;
 $s=trim($v);if($s===''||strlen($s)>255||preg_match('/[\x00-\x20\x7F\\\\]/',$s))return null;
 if(str_starts_with($s,'/')&&!str_starts_with($s,'//')&&!str_contains(rawurldecode($s),'..')&&!preg_match('/[\x00-\x20\x7F\\\\]/',rawurldecode($s))&&!str_starts_with(rawurldecode($s),'//'))return $s;
 if(filter_var($s,FILTER_VALIDATE_URL)===false||strtolower((string)parse_url($s,PHP_URL_SCHEME))!=='https'||parse_url($s,PHP_URL_USER)!==null||parse_url($s,PHP_URL_PASS)!==null)return null;
 return $s;
}
function ulNewsType(array $r):array{
 $title=(string)($r['title']??'');$cat=(string)($r['cat']??'');
 foreach(ULGG_NEWS_TYPES as $key=>[$label,$stored]){
  $p='【'.$label.'】';
  if($cat===$stored&&str_starts_with($title,$p))return ['type'=>$key,'label'=>$label,'title'=>substr($title,strlen($p))];
 }
 $key=['SYSTEM_NOTICE'=>'system','MAINT_NOTICE'=>'maintenance','EVENT_NOTICE'=>'event','RANK_NOTICE'=>'rank'][$cat]??'system';
 return ['type'=>$key,'label'=>ULGG_NEWS_TYPES[$key][0],'title'=>$title];
}
function ulNewsNormalize(array $in):array{
 foreach(['type','title','body','link'] as $k)if(!is_string($in[$k]??''))throw new InvalidArgumentException('欄位格式錯誤');
 $type=$in['type']??'';
 if(!isset(ULGG_NEWS_TYPES[$type]))throw new InvalidArgumentException('公告分類無效');
 $title=trim($in['title']??'');$body=trim($in['body']??'');$link=trim($in['link']??'');
 if(!preg_match('//u',$title)||!preg_match('//u',$body)||preg_match('/[\x00-\x1F\x7F]/',$title)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$body))throw new InvalidArgumentException('文字格式錯誤');
 if($title===''||mb_strlen($title,'UTF-8')>90||$body===''||mb_strlen($body,'UTF-8')>4000)throw new InvalidArgumentException('標題 1–90 字，內容 1–4000 字');
 if($link!==''&&ulNewsLink($link)===null)throw new InvalidArgumentException('連結須為站內路徑或 HTTPS 網址');
 [$label,$cat]=ULGG_NEWS_TYPES[$type];
 return ['cat'=>$cat,'title'=>'【'.$label.'】'.$title,'desc'=>$body,'link'=>$link];
}
function ulNewsNow():string{return (new DateTimeImmutable('now',new DateTimeZone('Asia/Taipei')))->format('Y-m-d H:i:s');}
function ulNewsList(PDO $db,bool $admin=false,?string $type=null,int $page=1,?string $now=null):array{
 $now=$now??ulNewsNow();$page=max(1,min(1000,$page));$offset=($page-1)*20;
 $where=[];$params=[];
 if(!$admin){$where[]='is_active=1';$where[]='(start_at IS NULL OR start_at<=?)';$params[]=$now;}
 if($type!==null&&isset(ULGG_NEWS_TYPES[$type])){
  [$label,$cat]=ULGG_NEWS_TYPES[$type];$where[]='cat=?';$params[]=$cat;
  if(in_array($type,['feature','update','data','fix'],true)){$where[]='title LIKE ?';$params[]='【'.$label.'】%';}
  elseif($type==='system')foreach(['feature','update','data','fix'] as $k){$where[]='title NOT LIKE ?';$params[]='【'.ULGG_NEWS_TYPES[$k][0].'】%';}
 }
 $sql='SELECT id,cat,title,`desc`,link,start_at,end_at,created_at,is_active FROM ulgg_notices'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY COALESCE(start_at,created_at) DESC,id DESC LIMIT 21 OFFSET '.$offset;
 $q=$db->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 return ['rows'=>array_slice($rows,0,20),'has_next'=>count($rows)>20,'page'=>$page];
}
function ulNewsFind(PDO $db,int $id,bool $lock=false):?array{
 $q=$db->prepare('SELECT id,cat,title,`desc`,link,start_at,end_at,created_at,is_active FROM ulgg_notices WHERE id=?'.($lock&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
 $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
function ulNewsRevision(?array $row):string{return hash('sha256',(string)json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));}
function ulNewsSave(PDO $db,array $data,?int $id,string $revision,bool $publish,?string $now=null):int{
 $now=$now??ulNewsNow();
 $db->beginTransaction();
 try{
  if($id!==null){
   $old=ulNewsFind($db,$id,true);
   if(!$old||!hash_equals(ulNewsRevision($old),$revision))throw new RuntimeException('公告已被修改，請重新預覽');
   if(!$publish&&(int)$old['is_active']===1)throw new RuntimeException('已發布公告不可直接改為草稿，請先撤下');
   $start=$publish?($old['start_at']?:$now):$old['start_at'];$end=$old['end_at'];
   if($publish&&(int)$old['is_active']!==1){$start=$now;$end=null;}
   $q=$db->prepare('UPDATE ulgg_notices SET cat=?,title=?,`desc`=?,link=?,is_active=?,start_at=?,end_at=? WHERE id=?');
   $q->execute([$data['cat'],$data['title'],$data['desc'],$data['link'],$publish?1:0,$start,$end,$id]);
  }else{
   $q=$db->prepare('INSERT INTO ulgg_notices (cat,title,`desc`,link,is_active,start_at,end_at,created_at) VALUES (?,?,?,?,?,?,NULL,?)');
   $q->execute([$data['cat'],$data['title'],$data['desc'],$data['link'],$publish?1:0,$publish?$now:null,$now]);
   $id=(int)$db->lastInsertId();if($id<1)throw new RuntimeException('無法確認新增公告 ID');
  }
  $db->commit();return $id;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ulNewsWithdraw(PDO $db,int $id,string $revision):void{
 $db->beginTransaction();
 try{
  $old=ulNewsFind($db,$id,true);
  if(!$old||(int)$old['is_active']!==1||!hash_equals(ulNewsRevision($old),$revision))throw new RuntimeException('公告狀態已變更');
  $q=$db->prepare('UPDATE ulgg_notices SET is_active=0 WHERE id=?');$q->execute([$id]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ulNewsDraftFromReceipt(array $r):array{
 $release=($r['status']??null)==='RELEASE_PASS';
 $assets=($r['status']??null)==='ASSETS_SYNC_PASS'&&($r['verified']??null)==='PASS'&&is_string($r['generation']??null)&&preg_match('/\\A[a-f0-9]{64}\\z/D',$r['generation'])&&($r['release']??null)==='CARD-ASSETS-'.$r['generation'];
 if((!$release&&!$assets)||($r['exit_code']??null)!==0||!is_string($r['release']??null)||!preg_match('/\\A[A-Za-z0-9_.-]{1,80}\\z/D',$r['release'])||!is_array($r['public_news']??null))throw new InvalidArgumentException('缺少成功發布憑證或公開公告內容');
 return ulNewsNormalize($r['public_news']);
}
function ulNewsInsertDraft(PDO $db,array $receipt,?string $now=null):array{
 $data=ulNewsDraftFromReceipt($receipt);$now=$now??ulNewsNow();
 $db->beginTransaction();
 try{
  $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
  $q=$db->prepare('SELECT id FROM ulgg_notices WHERE cat=? AND title=? AND `desc`=? AND COALESCE(link,\'\')=? ORDER BY id DESC LIMIT 1'.$lock);
  $q->execute([$data['cat'],$data['title'],$data['desc'],$data['link']]);$id=$q->fetchColumn();
  if($id!==false){$db->commit();return ['status'=>'DRAFT_EXISTS','id'=>(int)$id];}
  $q=$db->prepare('INSERT INTO ulgg_notices (cat,title,`desc`,link,is_active,start_at,end_at,created_at) VALUES (?,?,?,?,0,NULL,NULL,?)');
  $q->execute([$data['cat'],$data['title'],$data['desc'],$data['link'],$now]);$id=(int)$db->lastInsertId();
  if($id<1)throw new RuntimeException('draft ID missing');
  $db->commit();return ['status'=>'DRAFT_CREATED','id'=>$id];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
