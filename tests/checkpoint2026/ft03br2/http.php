<?php
declare(strict_types=1);
// Only isolated test containers. No DB connection or production session paths.
require '/repo/lib/checkpoint2026/PreviewAccess.php';
use ULGG\Checkpoint2026\PreviewAccess as A;
if (getenv('ULGG_FT03BR2_MODE') !== 'SYNTHETIC_OFFLINE_ONLY' || PHP_SAPI !== 'cli') throw new RuntimeException('fixture guard');
$dir=A::DIRECTORY;
$out='/out';
$root='/tmp/ft03br2';
$web=$root.'/www';
$sessions=$root.'/sessions';
$cases=[];
function ck(bool $value,string $name):void{
    global $cases;
    if(!$value)throw new RuntimeException($name);
    $cases[]=$name;
}
function put(string $path,string $body):void{if(file_put_contents($path,$body)!==strlen($body))throw new RuntimeException('write fixture');}
function bytes(string $path):string{$s=file_get_contents($path);if($s===false)throw new RuntimeException('read fixture');return $s;}
if (!is_dir($dir) || count(scandir($dir))!==2) throw new RuntimeException('private directory must be empty ephemeral tmpfs');
foreach([$root,$web,$web.'/pages',$web.'/pages/admin',$web.'/lib',$web.'/lib/checkpoint2026',$sessions] as $d)if(!mkdir($d,0700))throw new RuntimeException('fixture mkdir');
$html=bytes('/repo/tests/checkpoint2026/ft03cd/preview.html');
ck(hash('sha256',$html)===A::HTML_SHA256,'exact_v03_hash');
$manifest=json_encode(['version'=>'V0.3','commit'=>A::COMMIT,'sha256'=>A::HTML_SHA256,'mode'=>'SYNTHETIC_ONLY'],JSON_UNESCAPED_SLASHES)."\n";
put($dir.'/preview.html',$html);put($dir.'/manifest.json',$manifest);
$profile=bytes('/repo/pages/profile.php');
preg_match_all('/<!-- CP2026_ADMIN_ENTRY_BEGIN -->(.*?)<!-- CP2026_ADMIN_ENTRY_END -->/s',$profile,$matches);
ck(count($matches[1])===1,'exact_profile_fragment');
ck(strpos($profile,'CP2026_ADMIN_ENTRY_BEGIN')<strpos($profile,'id="fortune-profile"'),'profile_before_fortune');
put($web.'/lib/checkpoint2026/PreviewAccess.php',bytes('/repo/lib/checkpoint2026/PreviewAccess.php'));
put($web.'/pages/admin/checkpoint2026_preview.php',bytes('/repo/pages/admin/checkpoint2026_preview.php'));
// This file is a local fixture only and is never deployed. No authentication override route in the actual application.
$fixturePrefix=<<<'FIX'
<?php
session_start();
$isOwner=($_GET['other']??'')!=='1';
$isAdminView=($_GET['adminview']??'')==='1';
$isBannedUser=($_GET['banned']??'')==='1';
?>
<!doctype html><html lang="zh-Hant"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Synthetic profile fragment</title><style>body{margin:0;padding:12px;font:16px system-ui,sans-serif;color:#f0ecf7;background:#171922}*{box-sizing:border-box}.fixture-card{max-width:700px;margin:auto;border:1px solid #777;border-radius:12px;padding:16px}.btn{display:block;padding:12px;text-align:center;border:1px solid #dfbc80;color:#dfbc80;border-radius:6px;text-decoration:none}.w-100{width:100%}.small{font-size:13px}.badge{font-size:12px}.text-muted{color:#aaa}.mt-1{margin-top:4px}.pb-3{padding-bottom:12px}</style><main class="fixture-card"><h2>玩家：合成測試帳號</h2><p>年度回顧入口測試</p>
FIX;
put($web.'/pages/profile.php',$fixturePrefix.$matches[1][0].'<hr><h2 id="fixture-fortune">今日命運能力</h2></main></html>');
$valid=['permission'=>2,'ack'=>2,'user_id'=>12,'steam_id'=>'76561190000000000'];
$fixtures=[
 'fixtureadminint'=>$valid,
 'fixtureadminstr'=>array_replace($valid,['permission'=>'2','ack'=>'2','user_id'=>'12','steam_id'=>76561190000000000]),
 'fixturemember'=>array_replace($valid,['permission'=>'1','ack'=>'1']),
 'fixturemismatch'=>array_replace($valid,['ack'=>'1']),
 'fixturebadrole'=>array_replace($valid,['permission'=>'2evil','ack'=>'2evil']),
 'fixturebaduid'=>array_replace($valid,['user_id'=>'0']),
 'fixturebadsteam'=>array_replace($valid,['steam_id'=>'123']),
 'fixturemissing'=>array_diff_key($valid,['ack'=>true])
];
// Seed explicitly isolated, synthetic PHP sessions via native serialization, before output.
ini_set('session.save_path',$sessions);session_name('CPTEST');
ini_set('session.use_strict_mode','0');ini_set('session.use_cookies','0');
foreach($fixtures as $id=>$value){
    session_id($id);
    if(!session_start())throw new RuntimeException('fixture session');
    $_SESSION=$value;session_write_close();
}
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
if(!$socket)throw new RuntimeException('local port');
$addr=stream_socket_get_name($socket,false);fclose($socket);
$port=(int)substr(strrchr($addr,':'),1);
$process=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','session.name=CPTEST','-S','127.0.0.1:'.$port,'-t',$web],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
if(!is_resource($process))throw new RuntimeException('local server');
fclose($pipes[0]);
function req(string $route,string $sid='',string $method='GET'):array{
 global $port;
 $h="Connection: close\r\n";
 if($sid!=='')$h.="Cookie: CPTEST=".$sid."\r\n";
 $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$h,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>3]]);
 $body=@file_get_contents('http://127.0.0.1:'.$port.$route,false,$ctx);
 $headers=$http_response_header??[];
 $status=isset($headers[0])?(int)explode(' ',$headers[0])[1]:0;
 return [$status,is_string($body)?$body:'',implode("\n",$headers)];
}
function http(string $name,int $expected,string $sid='',string $suffix='',string $method='GET'):array{
 $r=req('/pages/admin/checkpoint2026_preview.php'.$suffix,$sid,$method);
 ck($r[0]===$expected,$name.'_HTTP_'.$expected);
 if($expected===200){
   ck(hash('sha256',$r[1])===A::HTML_SHA256,$name.'_exact_body');
   ck(stripos($r[2],'cache-control: private, no-store')!==false && stripos($r[2],'x-robots-tag: noindex')!==false && stripos($r[2],"connect-src 'none'")!==false,$name.'_headers');
 } else {ck(hash('sha256',$r[1])!==A::HTML_SHA256,$name.'_no_payload');}
 return $r;
}
function profile(string $name,string $sid,bool $show,string $q=''):string{
 $r=req('/pages/profile.php'.$q,$sid);
 ck($r[0]===200,$name.'_http');
 ck((strpos($r[1],'id="checkpoint2026-admin-entry"')!==false)===$show,$name.'_visibility');
 return $r[1];
}
try{
 for($i=0;$i<50;$i++){if(req('/pages/admin/checkpoint2026_preview.php')[0]!==0)break;usleep(100000);}
 http('flag_off_admin',404,'fixtureadminint');
 profile('flag_off_profile','fixtureadminint',false);
 put($dir.'/enabled.flag',"ON\n");
 http('anonymous',404);
 $authorized=http('admin_integer',200,'fixtureadminint');
 http('remember_string',200,'fixtureadminstr');
 http('attribution',200,'fixtureadminstr','?utm_source=chatgpt.com');
 foreach(['fixturemember','fixturemismatch','fixturebadrole','fixturebaduid','fixturebadsteam','fixturemissing','fixtureunknown'] as $sid)http($sid,404,$sid);
 http('anon_attribution',404,'','?utm_source=chatgpt.com');
 http('identity_override',404,'fixtureadminstr','?user=12');
 http('mixed_query',404,'fixtureadminstr','?utm_source=chatgpt.com&user=12');
 http('duplicate_query',404,'fixtureadminstr','?utm_source=chatgpt.com&utm_source=chatgpt.com');
 http('post',404,'fixtureadminstr','','POST');
 $p=profile('profile_admin','fixtureadminstr',true);
 $member=profile('profile_member','fixturemember',false);
 profile('profile_anon','',false);
 profile('profile_other','fixtureadminstr',false,'?other=1');
 profile('profile_adminview','fixtureadminstr',false,'?adminview=1');
 profile('profile_banned','fixtureadminstr',false,'?banned=1');
 profile('profile_mismatch','fixturemismatch',false);
 put($out.'/profile-admin.html',$p);put($out.'/profile-member.html',$member);
 put($out.'/authorized.html',$authorized[1]);
 put($dir.'/manifest.json',"{\"mode\":\"invalid\"}");
 http('bad_manifest',404,'fixtureadminstr');put($dir.'/manifest.json',$manifest);
 put($dir.'/preview.html','tampered');http('tampered',404,'fixtureadminstr');put($dir.'/preview.html',$html);
 unlink($dir.'/preview.html');symlink('/repo/tests/checkpoint2026/ft03cd/preview.html',$dir.'/preview.html');
 http('symlink',404,'fixtureadminstr');unlink($dir.'/preview.html');put($dir.'/preview.html',$html);
 // Rollback is an atomic flag removal, not a production operation.
 unlink($dir.'/enabled.flag');http('rollback_off',404,'fixtureadminstr');profile('rollback_profile','fixtureadminstr',false);
 put($dir.'/enabled.flag',"ON\n");http('restored',200,'fixtureadminstr');
 unlink($dir.'/enabled.flag');ck(!A::enabled(),'final_fixture_disabled');
 put($out.'/http-qa.json',json_encode(['status'=>'PASS','php'=>PHP_VERSION,'count'=>count($cases),'cases'=>$cases],JSON_PRETTY_PRINT)."\n");
 echo 'FT03BR2_HTTP_PASS PHP='.PHP_VERSION.' count='.count($cases)."\n";
 foreach($cases as $case)echo "PASS $case\n";
} finally {
 proc_terminate($process);proc_close($process);
}
