<?php
if (PHP_SAPI!=='cli') {exit(1);}
require dirname(__DIR__).'/app/vendor/autoload.php';
foreach (['github_releases','update_package','update_backup','web_updater'] as $file) {require dirname(__DIR__).'/app/ext/uii/portal/service/'.$file.'.php';}
define('PHPBB_VERSION','3.3.19');
use uii\portal\service\github_releases as G;
use uii\portal\service\update_package as P;
$count=0;
function ok($yes,$message) {global $count;if (!$yes) {throw new RuntimeException($message);}$count++;}
function rejects($fn,$message) {try {$fn();}catch (Throwable $e) {ok(true,$message);return;}throw new RuntimeException($message);}
ok(G::repository('https://github.com/anrhangels/uii-forum.git')==='anrhangels/uii-forum','Repository normalization');
foreach (['https://evil.example/x/y','owner/../repo','owner/repo?q=x','/etc/passwd','owner/repo#x'] as $repo) {rejects(fn()=>G::repository($repo),'Bad repo');}
foreach (['config.php','files/photo.bin','store/credentials.json','ext/uii/portal/../../config.php','ext/uii/portal/tests/secret.php','styles/uii_modern/config.php','ext/uii/portal/data.sql'] as $path) {ok(!P::allowed($path),'Protected path');}
ok(P::allowed('ext/uii/portal/controller/updates.php'),'Allowed source');
$history=[];$handler=new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(302,['Location'=>'https://release-assets.githubusercontent.com/signed/test']),new GuzzleHttp\Psr7\Response(200,[],'content')]);$stack=GuzzleHttp\HandlerStack::create($handler);$stack->push(GuzzleHttp\Middleware::history($history));$client=new GuzzleHttp\Client(['handler'=>$stack]);
$transport=new class($client) extends G {public function read($url,$token){return $this->request($url,$token,'application/octet-stream',1000);}};
ok($transport->read('https://api.github.com/repos/a/b/releases/assets/1','secret_test')==='content','Redirect download');
ok($history[0]['request']->getHeaderLine('Authorization')==='Bearer secret_test' && !$history[1]['request']->hasHeader('Authorization'),'No credential forwarding to asset CDN');
rejects(fn()=>$transport->read('http://api.github.com/x','token'),'Plain HTTP refused');rejects(fn()=>$transport->read('https://evil.example/x','token'),'Untrusted host refused');
$client=new GuzzleHttp\Client(['handler'=>GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(403,[],'secret_test')]))]);
$transport=new class($client) extends G {public function read(){return $this->request('https://api.github.com/x','secret_test','application/json',100);}};
try {$transport->read();throw new LogicException('403 accepted');}catch (RuntimeException $e) {ok(strpos($e->getMessage(),'secret_test')===false,'No secret in API error');}
$source=dirname(__DIR__).'/release/web-update/uii-web-update.zip';$path=sys_get_temp_dir().'/uii-valid-'.bin2hex(random_bytes(5)).'.zip';copy($source,$path);$z=new ZipArchive();$z->open($path);$m=json_decode($z->getFromName('manifest.json'),true);$m['min_installed']='1.2.0';$z->addFromString('manifest.json',json_encode($m));$z->close();$manifest=P::inspect($path,'anrhangels/uii-forum','1.2.0');ok($manifest['version']==='1.3.0','Compatible valid ZIP');
rejects(fn()=>P::inspect($path,'anrhangels/uii-forum','1.3.0'),'No reinstall or downgrade');rejects(fn()=>P::inspect($path,'other/repository','1.2.0'),'Pinned repository');
$temp=sys_get_temp_dir().'/uii-update-tests-'.bin2hex(random_bytes(5));mkdir($temp,0700);
try {
 foreach (['traversal','duplicate','sql','symlink','hash'] as $kind) {
  $file=$temp.'/'.$kind.'.zip';copy($path,$file);$zip=new ZipArchive();$zip->open($file);
  if ($kind==='traversal') {$zip->addFromString('payload/ext/uii/portal/../../config.php','bad');}
  if ($kind==='duplicate') {$zip->addFromString('MANIFEST.JSON','bad');}
  if ($kind==='sql') {$zip->addFromString('database.sql','bad');}
  if ($kind==='symlink') {$zip->addFromString('payload/ext/uii/portal/link.php','target');$zip->setExternalAttributesName('payload/ext/uii/portal/link.php',ZipArchive::OPSYS_UNIX,0120777<<16);}
  if ($kind==='hash') {$zip->addFromString('payload/ext/uii/portal/composer.json','{}');}
  $zip->close();rejects(fn()=>P::inspect($file,'anrhangels/uii-forum','1.2.0'),'Malicious ZIP '.$kind);unlink($file);
 }
}finally{rmdir($temp);unlink($path);}
echo "Passed updater security contracts: $count\n";
