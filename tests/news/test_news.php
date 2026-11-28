<?php
declare(strict_types=1);
require __DIR__.'/../../lib/news_timeline.php';
function ok($v,$msg){if(!$v)throw new RuntimeException($msg);}
function rejects(callable $f){try{$f();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('expected rejection');}
function test($name,callable $f){$f();echo "PASS $name\n";}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ulgg_notices (id INTEGER PRIMARY KEY AUTOINCREMENT,cat TEXT NOT NULL,title TEXT NOT NULL,[desc] TEXT NOT NULL,link TEXT,start_at TEXT,end_at TEXT,created_at TEXT NOT NULL,is_active INTEGER NOT NULL DEFAULT 0 CHECK(is_active IN (0,1)))');
$now='2026-11-28 14:00:00';
$in=['type'=>'feature','title'=>'合成測試公告','body'=>'測試內容','link'=>'/pages/calculator.php'];
$data=ulNewsNormalize($in);
$receipt=['status'=>'RELEASE_PASS','exit_code'=>0,'release'=>'TEST-1','public_news'=>$in];
$ids=fn($admin=false,$type=null,$p=1)=>array_map(fn($r)=>(int)$r['id'],ulNewsList($db,$admin,$type,$p,$now)['rows']);
test('role gate and CSRF',function(){ $s=['permission'=>2];$t=ulNewsCsrf($s);ok(ulNewsCheckCsrf($s,$t),'valid');ok(!ulNewsCheckCsrf($s,'wrong'),'invalid');$s['permission']=1;ok(!ulNewsCheckCsrf($s,$t),'non-admin');});
test('normalization native category and text',function()use($data){ok($data['cat']==='SYSTEM_NOTICE','category');ok(ulNewsType($data)['title']==='合成測試公告','title');});
test('unsafe URLs and validation',function()use($in){foreach(['javascript:alert(1)','//evil.test','/\\evil.test','/%2fexample.com','https://a:b@evil.test','/a/../b'] as $x){ok(ulNewsLink($x)===null,'URL accepted '.$x);}ok(ulNewsLink('https://example.com/news')!==null,'valid https');rejects(fn()=>ulNewsNormalize(array_replace($in,['body'=>''])));rejects(fn()=>ulNewsNormalize(array_replace($in,['type'=>'evil'])));});
test('escaped output',function(){ok(ulNewsH('<script>')==='&lt;script&gt;','XSS');});
test('failed or incomplete release never creates draft',function()use($db,$receipt){rejects(fn()=>ulNewsInsertDraft($db,array_replace($receipt,['exit_code'=>1])));rejects(fn()=>ulNewsInsertDraft($db,array_replace($receipt,['status'=>'FAILED'])));ok((int)$db->query('SELECT COUNT(*) FROM ulgg_notices')->fetchColumn()===0,'unexpected insert');});
$id=0;
test('verified receipt creates inactive draft only',function()use($db,$receipt,$ids,$now,&$id){$r=ulNewsInsertDraft($db,$receipt,$now);$id=$r['id'];ok($r['status']==='DRAFT_CREATED','created');ok($ids()===[],'draft public');ok($ids(true)===[$id],'admin draft missing');});
test('repeat receipt deduplicates',function()use($db,$receipt,$id,$now){$r=ulNewsInsertDraft($db,$receipt,$now);ok($r===['status'=>'DRAFT_EXISTS','id'=>$id],'duplicate');});
test('publish and category filter',function()use($db,$data,$id,$ids,$now){$old=ulNewsFind($db,$id);ulNewsSave($db,$data,$id,ulNewsRevision($old),true,$now);ok($ids(false,'feature')===[$id],'feature missing');ok($ids(false,'system')===[],'system leaked feature');});
test('stale edit rejected without mutation',function()use($db,$id,$data,$now){$old=ulNewsFind($db,$id);rejects(fn()=>ulNewsSave($db,$data,$id,str_repeat('0',64),true,$now));ok(ulNewsFind($db,$id)===$old,'changed');});
test('active notice cannot silently become draft',function()use($db,$id,$data,$now){$old=ulNewsFind($db,$id);rejects(fn()=>ulNewsSave($db,$data,$id,ulNewsRevision($old),false,$now));ok(ulNewsFind($db,$id)===$old,'changed');});
test('withdraw hides without deleting',function()use($db,$id,$ids){ulNewsWithdraw($db,$id,ulNewsRevision(ulNewsFind($db,$id)));ok($ids()===[]&&$ids(true)===[$id],'withdraw');});
test('historical active retained and future hidden',function()use($db,$ids){$db->exec("INSERT INTO ulgg_notices(cat,title,[desc],created_at,start_at,end_at,is_active) VALUES ('EVENT_NOTICE','Old','Body','2025-01-01','2025-01-01','2025-02-01',1),('MAINT_NOTICE','Future','Body','2026-11-28','2027-01-01',NULL,1)");ok($ids() === [2],'history/future');});
test('legacy category fallback',function()use($db){ok(ulNewsType(ulNewsFind($db,2))['type']==='event','legacy');});
test('pagination stable order',function()use($db,$ids,$now){for($i=0;$i<25;$i++)ulNewsSave($db,ulNewsNormalize(['type'=>'fix','title'=>'Test '.$i,'body'=>'Body','link'=>'']),null,'',true,$now);$a=ulNewsList($db,false,null,1,$now);$b=ulNewsList($db,false,null,2,$now);ok(count($a['rows'])===20&&$a['has_next']&&count($b['rows'])===6&&!$b['has_next'],'paging');ok(!array_intersect($ids(false,null,1),$ids(false,null,2)),'duplicate rows');});
test('verified asset receipt creates inactive draft',function()use($db,$receipt,$now){$g=str_repeat('a',64);$r=$receipt;$r['status']='ASSETS_SYNC_PASS';$r['verified']='PASS';$r['generation']=$g;$r['release']='CARD-ASSETS-'.$g;$r['public_news']['title']='Asset draft';$d=ulNewsInsertDraft($db,$r,$now);ok((int)ulNewsFind($db,$d['id'])['is_active']===0,'asset draft public');rejects(fn()=>ulNewsInsertDraft($db,array_replace($r,['verified'=>'FAIL']),$now));rejects(fn()=>ulNewsInsertDraft($db,array_replace($r,['release'=>'invalid']),$now));});
echo "15/15 PASS (synthetic SQLite schema, no production data)\n";
