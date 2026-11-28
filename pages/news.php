<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../lib/news_timeline.php';
$manage=isset($_GET['manage'])&&$_GET['manage']==='1';
$admin=ulNewsAdmin($_SESSION??[]);
if($manage&&!$admin){http_response_code(403);exit('Forbidden');}
$pdo=$db;$error='';$preview=null;
$route='/pages/news.php';$manageRoute=$route.'?manage=1';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!$manage||!ulNewsCheckCsrf($_SESSION??[],$_POST['csrf']??null)){http_response_code(403);exit('Forbidden');}
 $action=$_POST['action']??'';
 try{
  if($action==='preview'){
   $raw=$_POST['id']??'';$id=$raw===''?null:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if($id===false)throw new InvalidArgumentException('公告 ID 無效');
   $old=$id===null?null:ulNewsFind($pdo,$id);
   if($id!==null&&(!$old||!is_string($_POST['revision']??null)||!hash_equals(ulNewsRevision($old),$_POST['revision'])))throw new RuntimeException('公告已被修改，請重新載入');
   $preview=['data'=>ulNewsNormalize($_POST),'id'=>$id,'revision'=>ulNewsRevision($old),'active'=>(int)($old['is_active']??0),'nonce'=>bin2hex(random_bytes(24)),'expires'=>time()+900];
   $_SESSION['news_preview']=$preview;
  }elseif($action==='draft'||$action==='publish'){
   $v=$_SESSION['news_preview']??null;
   if(!is_array($v)||!is_string($_POST['nonce']??null)||!hash_equals($v['nonce'],$_POST['nonce'])||$v['expires']<time())throw new RuntimeException('預覽已失效，請重新預覽');
   unset($_SESSION['news_preview']);
   ulNewsSave($pdo,$v['data'],$v['id'],$v['revision'],$action==='publish');
   $_SESSION['news_flash']=$action==='publish'?'公告已發布':'草稿已儲存';
   header('Location: '.$manageRoute,true,303);exit;
  }elseif($action==='withdraw'){
   $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$id||!is_string($_POST['revision']??null))throw new InvalidArgumentException('公告 ID 無效');
   ulNewsWithdraw($pdo,$id,$_POST['revision']);
   $_SESSION['news_flash']='公告已撤下';header('Location: '.$manageRoute,true,303);exit;
  }else throw new InvalidArgumentException('操作無效');
 }catch(InvalidArgumentException|RuntimeException $e){
  if($e instanceof PDOException){$error='公告操作失敗，請稍後重試。';error_log('[NEWS] DB operation failed');}
  else $error=$e->getMessage();
 }catch(Throwable $e){$error='公告操作失敗，請稍後重試。';error_log('[NEWS] operation failed');}
}
$type=is_string($_GET['type']??null)&&isset(ULGG_NEWS_TYPES[$_GET['type']])?$_GET['type']:null;
$page=max(1,min(1000,(int)($_GET['p']??1)));
$edit=null;$result=['rows'=>[],'page'=>$page,'has_next'=>false];$unavailable=false;
try{
 $result=ulNewsList($pdo,$manage,$type,$page);
 if($manage&&isset($_GET['edit'])){$id=filter_var($_GET['edit'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id)$edit=ulNewsFind($pdo,$id);}
}catch(Throwable $e){$unavailable=true;error_log('[NEWS] read failed');}
$pageTitleText=$manage?'公告管理':'網站公告';
$pageTitleFull=$pageTitleText.' | UL.GG 戰績網';$seoTitle=$pageTitleFull;$activeMenu='news';
if($manage){header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');}
$h='ulNewsH';$csrf=$manage?ulNewsCsrf($_SESSION):'';
$flash=$manage?($_SESSION['news_flash']??''):'';if($manage)unset($_SESSION['news_flash']);
function ulNewsUrl(bool $manage,?string $type,int $p=1):string{
 $a=[];if($manage)$a['manage']='1';if($type!==null)$a['type']=$type;if($p>1)$a['p']=$p;
 return '/pages/news.php'.($a?'?'.http_build_query($a,'','&',PHP_QUERY_RFC3986):'');
}
ob_start();
?>
<style>
/* Timeline layout only; common cards, controls and colors come from theme.css. */
.ul-news{max-width:1200px;margin:0 auto;min-width:0;color:var(--text-main)}
.ul-news .news-heading{padding:16px 20px;margin:0 0 14px}
.ul-news .news-heading-title{font-size:21px;line-height:1.35;margin:0 0 8px;color:var(--text-main);font-weight:700}
.ul-news .news-heading-title small{display:inline-block;color:var(--accent);font-size:13px;font-weight:400;margin-left:6px}
.ul-news .news-filter{display:flex;flex-wrap:wrap;gap:6px;margin:14px 0 20px}
.ul-news .news-control{display:inline-flex;align-items:center;justify-content:center;min-height:36px;margin:0;padding:7px 12px;border:1px solid var(--border-glow);background-color:var(--bg-card-mid);color:var(--text-main);border-radius:8px;font:inherit;font-size:13px;cursor:pointer;text-decoration:none}
.ul-news .news-control.is-active{color:var(--text-main);border-color:var(--accent)}
.ul-news .news-control:focus-visible,.ul-news a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.ul-news .news-list{list-style:none;margin:0;padding:0 0 0 20px;border-left:1px solid var(--border-glow)}
.ul-news .news-item{position:relative;margin:0 0 14px;padding:16px 20px;overflow-wrap:anywhere}
.ul-news .news-item::before{content:"";position:absolute;left:-27px;top:22px;width:11px;height:11px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 3px var(--bg-card-mid)}
.ul-news .news-meta{color:var(--text-soft);font-size:12px;margin-bottom:8px}
.ul-news .news-meta time{color:var(--accent);font-variant-numeric:tabular-nums}
.ul-news .news-body{white-space:pre-wrap;line-height:1.7;color:var(--text-main)}
.ul-news .news-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.ul-news .news-panel{padding:16px 20px;margin-bottom:14px}
.ul-news #news-publish-preview{scroll-margin-top:calc(var(--navbar-height,56px) + 20px)}
.ul-news #news-publish-preview:target{border-color:var(--accent)}
.ul-news .news-editor label{display:block;margin:12px 0 5px;color:var(--text-soft)}
.ul-news .news-editor input:not([type=hidden]),.ul-news .news-editor select,.ul-news .news-editor textarea{width:100%;max-width:100%;background:var(--bg-card-mid);color:var(--text-main);border:1px solid var(--border-glow);border-radius:6px;padding:9px}
.ul-news .news-editor textarea{min-height:140px;resize:vertical}
.ul-news .news-title{font-size:17px;line-height:1.45;margin:0 0 9px;color:var(--text-main);font-weight:700}
.ul-news .news-nav{display:flex;gap:12px;justify-content:space-between;margin:12px 0}
.ul-news .news-detail,.ul-news .news-nav a{color:var(--accent);text-decoration:none}
.ul-news .news-detail:hover,.ul-news .news-nav a:hover{text-decoration:underline}
@media(max-width:600px){.ul-news .news-heading,.ul-news .news-panel,.ul-news .news-item{padding:12px 14px}.ul-news .news-filter{gap:6px;margin:12px 0 16px}.ul-news .news-control{min-height:40px;padding:7px 10px}.ul-news .news-list{padding-left:14px}.ul-news .news-item::before{left:-21px}.ul-news .news-heading-title{font-size:19px}.ul-news .news-title{font-size:16px}}
</style>
<section class="ul-news" id="ulgg-news-timeline">
 <div class="card-dark news-heading">
  <h1 class="news-heading-title"><?= $h($pageTitleText) ?> <small>News &amp; Updates</small></h1>
  <p class="news-meta">UL.GG 網站功能、資料更新、維護與活動消息。與首頁公告使用相同資料來源。</p>
  <?php if($admin):?><a class="sidebar-link news-control" href="<?= $h($manage?$route:$manageRoute) ?>"><?= $manage?'查看公開 Timeline':'管理公告' ?></a><?php endif;?>
 </div>
 <?php if($error!==''):?><p role="alert" class="card-dark" style="padding:12px;color:var(--danger)"><?= $h($error) ?></p><?php endif;?>
 <?php if($flash!==''):?><p role="status" class="card-dark" style="padding:12px"><?= $h($flash) ?></p><?php endif;?>
 <?php if($manage&&!$unavailable):?>
 <?php $et=$edit?ulNewsType($edit):['type'=>'update','title'=>'']; ?>
 <div class="card-dark news-editor news-panel">
  <h2 class="news-title"><?= $edit?'編輯公告':'新增公告草稿' ?></h2>
  <form method="post" action="<?= $h($manageRoute.'#news-publish-preview') ?>">
   <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
   <input type="hidden" name="action" value="preview">
   <input type="hidden" name="id" value="<?= $h($edit['id']??'') ?>">
   <input type="hidden" name="revision" value="<?= $h(ulNewsRevision($edit)) ?>">
   <label for="news-type">分類</label><select id="news-type" name="type"><?php foreach(ULGG_NEWS_TYPES as $k=>$v):?><option value="<?= $h($k) ?>" <?= $k===$et['type']?'selected':'' ?>><?= $h($v[0]) ?></option><?php endforeach;?></select>
   <label for="news-title">標題</label><input id="news-title" name="title" maxlength="90" required value="<?= $h($et['title']) ?>">
   <label for="news-body">公告內容</label><textarea id="news-body" name="body" maxlength="4000" required><?= $h($edit['desc']??'') ?></textarea>
   <label for="news-link">相關連結（選填）</label><input id="news-link" name="link" maxlength="255" value="<?= $h($edit['link']??'') ?>">
   <div class="news-actions"><button class="sidebar-link news-control is-active" type="submit">預覽並發布</button><?php if($edit):?><a class="sidebar-link news-control" href="<?= $h($manageRoute) ?>">取消編輯</a><?php endif;?></div>
  </form>
 </div>
 <?php if($preview):$pt=ulNewsType($preview['data']);?>
 <div class="card-dark news-panel" id="news-publish-preview" tabindex="-1" aria-label="發布預覽">
  <p class="news-meta">發布預覽 · <?= $h($pt['label']) ?> · 尚未套用本次變更</p>
  <h2 class="news-title"><?= $h($pt['title']) ?></h2><div class="news-body"><?= $h($preview['data']['desc']) ?></div>
  <?php if($preview['data']['link']!==''):?><p><?= $h($preview['data']['link']) ?></p><?php endif;?>
  <form class="news-actions" method="post" action="<?= $h($manageRoute) ?>">
   <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="nonce" value="<?= $h($preview['nonce']) ?>">
   <?php if(!$preview['active']):?><button class="sidebar-link news-control" name="action" value="draft">儲存草稿</button><?php endif;?>
   <button class="sidebar-link news-control is-active" name="action" value="publish">確認發布</button>
  </form>
 </div>
 <?php endif;endif;?>
 <nav class="news-filter" aria-label="公告分類">
  <a class="sidebar-link news-control <?= $type===null?'is-active':'' ?>" aria-current="<?= $type===null?'page':'false' ?>" href="<?= $h(ulNewsUrl($manage,null)) ?>">全部</a>
  <?php foreach(ULGG_NEWS_TYPES as $k=>$v):?><a class="sidebar-link news-control <?= $type===$k?'is-active':'' ?>" aria-current="<?= $type===$k?'page':'false' ?>" href="<?= $h(ulNewsUrl($manage,$k)) ?>"><?= $h($v[0]) ?></a><?php endforeach;?>
 </nav>
 <?php if($unavailable):?><p class="card-dark news-panel">公告暫時無法載入，請稍後再試。</p>
 <?php elseif(!$result['rows']):?><p class="card-dark news-panel">目前沒有符合條件的公告。</p>
 <?php else:?><ol class="news-list">
 <?php foreach($result['rows'] as $row):$t=ulNewsType($row);$link=ulNewsLink($row['link']);$at=$row['start_at']?:$row['created_at'];$historical=!empty($row['end_at'])&&$row['end_at']<ulNewsNow();?>
  <li class="card-dark news-item" id="news-<?= (int)$row['id'] ?>">
   <div class="news-meta"><time><?= $h(substr((string)$at,0,10)) ?></time> · <?= $h($t['label']) ?><?= $historical?' · 歷史公告':'' ?>
   <?php if($manage):?> · <?= (int)$row['is_active']===1?(!empty($row['start_at'])&&$row['start_at']>ulNewsNow()?'已排定':'已發布'):(empty($row['start_at'])?'草稿／停用':'已撤下／停用') ?><?php endif;?></div>
   <h2 class="news-title"><?= $h($t['title']) ?></h2>
   <div class="news-body"><?= $h($row['desc']) ?></div>
   <?php if($link!==null):?><div class="news-actions"><a href="<?= $h($link) ?>" class="news-detail" rel="noopener noreferrer">詳細內容 →</a></div><?php endif;?>
   <?php if($manage):?><div class="news-actions">
    <a class="sidebar-link news-control" href="<?= $h($manageRoute.'&edit='.(int)$row['id']) ?>">編輯</a>
    <?php if((int)$row['is_active']===1):?><form method="post" action="<?= $h($manageRoute) ?>"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="revision" value="<?= $h(ulNewsRevision($row)) ?>"><button class="sidebar-link news-control" name="action" value="withdraw" onclick="return confirm('確定撤下這則公告？')">撤下</button></form><?php endif;?>
   </div><?php endif;?>
  </li>
 <?php endforeach;?></ol><?php endif;?>
 <nav class="news-nav" aria-label="分頁"><?php if($page>1):?><a href="<?= $h(ulNewsUrl($manage,$type,$page-1)) ?>">← 上一頁</a><?php else:?><span></span><?php endif;?><?php if($result['has_next']):?><a href="<?= $h(ulNewsUrl($manage,$type,$page+1)) ?>">下一頁 →</a><?php endif;?></nav>
</section>
<?php
$pageContent=ob_get_clean();
include __DIR__.'/../layout/base.php';
