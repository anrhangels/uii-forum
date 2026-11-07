<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/deployment/spaceweb/hosting.php';
require dirname(__DIR__).'/app/ext/uii/portal/service/evidence.php';
use uii_deploy\hosting as H;
$directory=sys_get_temp_dir().'/uii-private-test-'.bin2hex(random_bytes(6));mkdir($directory);
define('UII_EVIDENCE_PATH',$directory);
try {
    if (H::domain('FORUM.EXAMPLE.RU')!=='forum.example.ru')throw new RuntimeException('Domain normalization');
    foreach (['localhost','https://example.ru','example.ru/path','127.0.0.1',"abc.ru' OR 1=1"] as $bad) {
        try{H::domain($bad);throw new RuntimeException('Invalid domain accepted');}catch(InvalidArgumentException $e){}
    }
    $token=str_repeat('a',32);$storage=new uii\portal\service\evidence('/not/public');
    if ($storage->path($token)!==$directory.'/'.$token.'.bin')throw new RuntimeException('Private root not used');
    file_put_contents($storage->path($token),'image');
    if ($storage->usage()!==5)throw new RuntimeException('Settings/storage usage differ');
    $data=json_encode(['photos'=>[['token'=>$token,'size'=>5]]]);if(H::photos([$data],$directory)!==1)throw new RuntimeException('Photo audit');
    foreach ([json_encode(['photos'=>[['token'=>$token,'size'=>6]]]),json_encode(['photos'=>[['token'=>str_repeat('b',32),'size'=>5]]]),json_encode(['photos'=>[['token'=>'../config.php']]])] as $bad) {
        try{H::photos([$bad],$directory);throw new InvalidArgumentException('Missing/corrupt photo accepted');}catch(RuntimeException $e){}
    }
    $settings=H::settings('forum.example.ru','new_cookie');if($settings['cookie_secure']!=='1'||$settings['board_disable']!=='1'||$settings['server_port']!=='443')throw new RuntimeException('Target settings');
    echo "PASS SpaceWeb private storage, usage, photo audit, domain and closed-by-default HTTPS settings\n";
} finally {if(isset($token)&&is_file($directory.'/'.$token.'.bin'))unlink($directory.'/'.$token.'.bin');rmdir($directory);}
