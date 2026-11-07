<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/service/navigation.php';
require dirname(__DIR__).'/service/acp_policy.php';
require dirname(__DIR__).'/service/staff.php';
require dirname(__DIR__).'/service/roles.php';
use uii\portal\service\navigation as N;
use uii\portal\service\acp_policy as A;
use uii\portal\service\staff as S;
$count=0;
function verifyManagement($value,$message) { global $count; if (!$value) { throw new RuntimeException($message); } $count++; }
function invalidManagement($value,$message) { try { N::validate($value); } catch (InvalidArgumentException $e) { verifyManagement(true,$message); return; } throw new RuntimeException($message); }
$default=N::defaults();verifyManagement(N::validate($default)===$default,'Default layout valid');
$custom=$default;$custom[]=['id'=>'new_group','title'=>'Дополнительная группа','items'=>[]];$custom[4]['items'][]=array_pop($custom[0]['items']);
verifyManagement(count(N::validate($custom))===5,'New group and moved item valid');
$custom=array_reverse($custom);verifyManagement(N::validate($custom)[0]['id']==='new_group','Group reordering valid');
foreach ([1,2,3,4] as $level) {
    foreach (N::visible($custom,$level,false) as $group) foreach ($group['items'] as $id) {
        verifyManagement(N::catalog()[$id][4]<=$level && $id!=='navigation','Moving cannot grant privileges');
    }
}
$admin=N::visible($default,4,true);verifyManagement(in_array('navigation',array_merge(...array_column($admin,'items')),true),'Native administrator sees editor');
$missing=$default;array_pop($missing[3]['items']);invalidManagement($missing,'Cannot remove management recovery link');
$duplicate=$default;$duplicate[0]['items'][]='tasks';invalidManagement($duplicate,'Duplicate links rejected');
$unknown=$default;$unknown[0]['items'][0]='javascript:alert(1)';invalidManagement($unknown,'Arbitrary link rejected');
$bad=$default;$bad[0]['title']='<img src=x onerror=alert(1)>';invalidManagement($bad,'HTML group title rejected');
$bad=$default;$bad[0]['title']=str_repeat('я',41);invalidManagement($bad,'Long Unicode title rejected');
$bad=$default;$bad[0]['title']="Строка\nДругая";invalidManagement($bad,'Newline rejected');
$bad=$default;$bad[1]['id']=$bad[0]['id'];invalidManagement($bad,'Duplicate group IDs rejected');
invalidManagement('not an array','Wrong structure rejected');
$bad=$default;for($i=0;$i<5;$i++)$bad[]=['id'=>'extra_'.$i,'title'=>'Ещё','items'=>[]];invalidManagement($bad,'More than eight groups rejected');
verifyManagement(A::disabled('board','message') && A::disabled('acp_board','feed'),'Both native module aliases disabled');
foreach (['acp_bbcodes','acp_icons','acp_groups','acp_email','acp_jabber','acp_profile'] as $module) { verifyManagement(A::disabled($module,'any'),'Unused ACP module disabled'); }
foreach ([['board','security'],['board','cookie'],['board','auth'],['board','server'],['board','post'],['database','backup'],['database','restore'],['forums','manage'],['permissions','setting_group_local'],['extensions','main'],['logs','admin'],['captcha','visual']] as [$module,$mode]) { verifyManagement(!A::disabled($module,$mode),'Required ACP module preserved'); }
verifyManagement(S::reason('transferred')==='transferred' && S::reason('left')==='left','Departure reasons valid');
try { S::reason('DROP TABLE'); throw new RuntimeException('Invalid reason accepted'); } catch (InvalidArgumentException $e) { verifyManagement(true,'Invalid reason rejected'); }
verifyManagement(uii\portal\service\roles::label(4)==='Руководство фракции','Roles replace positions');


// Exercise actual controller authorization and save preconditions without a live database.
require dirname(__DIR__,4).'/vendor/autoload.php';
require dirname(__DIR__,4).'/phpbb/request/request_interface.php';
require dirname(__DIR__).'/controller/dashboard.php';
define('USER_FOUNDER',3);
function add_form_key($name) {}
function check_form_key($name) { return $GLOBALS['management_csrf'] ?? false; }
class ManagementRedirect extends RuntimeException {}
function redirect($url) { throw new ManagementRedirect($url); }
$db=new class {
    public $affected=1;public $updates=0;public $histories=0;public $transactions=[];private $query='';
    function sql_query($sql) { $this->query=$sql;if(str_starts_with($sql,'UPDATE'))$this->updates++;if(str_starts_with($sql,'INSERT'))$this->histories++;return 1; }
    function sql_fetchrow($r) { return str_contains($this->query,'config_value') ? ['config_value'=>1] : ['version'=>3,'payload'=>json_encode(N::defaults())]; }
    function sql_freeresult($r) {} function sql_escape($s){return str_replace("'","''",$s);}
    function sql_build_array($type,$data){return 'payload = \'test\'';}
    function sql_affectedrows(){return $this->affected;}function sql_transaction($action){$this->transactions[]=$action;}
};
$user=(object)['data'=>['is_registered'=>true,'user_id'=>2,'user_type'=>USER_FOUNDER]];
$auth=new class {public $native=true;function acl_get($key){return $key==='a_'?$this->native:true;}};
$request=new class {public $post=false;public $version=3;public $raw='';function is_set_post($key){return $this->post;}function variable($key,$default){return $key==='version'?$this->version:$default;}function raw_variable($key,$default,$kind){return $this->raw;}};
$template=new class {public $vars=[];function assign_vars($vars){$this->vars=$vars;}function assign_block_vars($key,$value){}};
$helper=new class {function render($name,$title){return 'navigation';}function route($name,$args=[]){return '/uii/navigation';}};
$controller=new uii\portal\controller\dashboard($helper,$template,$user,$auth,$db,'test_',$request);
$level=new ReflectionProperty($controller,'actorLevel');$level->setAccessible(true);
foreach ([1,2,3] as $role) {$level->setValue($controller,$role);try{$controller->navigation();throw new RuntimeException('Unauthorized editor');}catch(Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e){verifyManagement(true,'Lower role editor denied even with native admin flag');}}
$level->setValue($controller,4);$auth->native=false;try{$controller->navigation();throw new RuntimeException('Missing native permission accepted');}catch(Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e){verifyManagement(true,'Role 4 still needs native administration');}
$auth->native=true;verifyManagement($controller->navigation()==='navigation','Administrator can view editor');
$request->post=true;$request->raw=json_encode(N::defaults());$GLOBALS['management_csrf']=false;
$controller->navigation();verifyManagement(str_contains($template->vars['NAV_ERROR'],'формы истёк'),'Controller rejects CSRF');
$GLOBALS['management_csrf']=true;$request->version=2;$controller->navigation();verifyManagement(str_contains($template->vars['NAV_ERROR'],'изменено другим'),'Controller rejects stale version');
$request->version=3;$request->raw='{"invalid":true}';$controller->navigation();verifyManagement($db->updates===0,'Invalid layout never reaches database update');
$request->raw=json_encode(N::defaults());$db->affected=0;$controller->navigation();verifyManagement(end($db->transactions)==='rollback' && $db->histories===0,'Concurrent edit rolls back without audit success');
$db->affected=1;try{$controller->navigation();throw new RuntimeException('Missing save redirect');}catch(ManagementRedirect $e){verifyManagement(end($db->transactions)==='commit' && $db->histories===1,'Validated save commits with audit and redirect');}
echo "Passed management checks: $count\n";
