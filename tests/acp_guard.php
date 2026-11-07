<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$root=dirname(__DIR__).'/app/';$phpbb_root_path=$root;require $root.'vendor/autoload.php';
foreach (['roles','avatars','navigation','acp_policy','web_updater'] as $class) { require $root.'ext/uii/portal/service/'.$class.'.php'; }
require $root.'ext/uii/portal/event/listener.php';
define('USER_FOUNDER',3);define('ADMIN_START',true);define('MODULES_TABLE','test_modules');
function generate_board_url(){return 'http://127.0.0.1:8088';}
$user=new class {public $data=['user_type'=>3,'user_id'=>2,'is_registered'=>true,'username'=>'Test_Admin'];public $page=['page_name'=>'index.php'];function add_lang_ext($vendor,$file){}};
$request=new class {public $args=[];function variable($key,$default){return $this->args[$key]??$default;}function server($key,$default=''){return $key==='REQUEST_URI'?'/adm/index.php':$default;}};
$auth=new class {function acl_get($key){return true;}};
$template=new class {function assign_vars($data){}function assign_block_vars($block,$data){}};
$helper=new class {function route($name,$args=[]){return '/app.php/'.$name;}};
$db=new class {public $query='';function sql_query($sql){$this->query=$sql;return 1;}function sql_fetchrow($r){return ['module_basename'=>'acp_board','module_mode'=>'avatar'];}function sql_freeresult($r){}};
$config=['uii_navigation_ready'=>0];$table_prefix='test_';$phpEx='php';
$reflection=new ReflectionClass(uii\portal\event\listener::class);$listener=$reflection->newInstanceWithoutConstructor();
foreach (['user'=>$user,'request'=>$request,'template'=>$template,'helper'=>$helper] as $key=>$value){$property=$reflection->getProperty($key);$property->setAccessible(true);$property->setValue($listener,$value);}
$count=0;set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);},E_USER_WARNING);
try {
    foreach ([['board','avatar'],['acp_board','message'],['groups','manage'],['users','groups'],['12','']] as [$module,$mode]) {
        $request->args=['i'=>$module,'mode'=>$mode];
        try{$listener->guard();throw new RuntimeException('Disabled ACP endpoint accepted');}catch(ErrorException $e){if(!str_contains($e->getMessage(),'Этот раздел отключён'))throw $e;$count++;}
        if ($module==='12' && !str_contains($db->query,"module_class='acp'")) { throw new RuntimeException('Numeric module resolved in wrong namespace'); }
    }
    foreach ([['board','security'],['board','settings'],['database','backup']] as [$module,$mode]) {
        $request->args=['i'=>$module,'mode'=>$mode];$listener->guard();$count++;
    }
} finally {restore_error_handler();}
echo "Passed actual ACP guard checks: $count\n";
