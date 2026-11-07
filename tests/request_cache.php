<?php
if (PHP_SAPI!=='cli') { exit(1); }
require dirname(__DIR__).'/app/vendor/autoload.php';
foreach (['roles','avatars'] as $service) { require dirname(__DIR__).'/app/ext/uii/portal/service/'.$service.'.php'; }
require dirname(__DIR__).'/app/ext/uii/portal/event/listener.php';
define('USER_FOUNDER',3);
function generate_board_url() { return 'https://example.test'; }
$db=new class {
    public $queries=0;private $left=0;
    function sql_query($sql) { $this->queries++;$this->left=1;return 1; }
    function sql_fetchrow($r) { return $this->left-- > 0 ? ['group_name'=>'UII_STAFF'] : false; }
    function sql_freeresult($r) {}
};$table_prefix='qa_';
$class=new ReflectionClass(uii\portal\event\listener::class);$role=$class->getMethod('role');$role->setAccessible(true);
foreach ([7,8] as $id) {
    $listener=$class->newInstanceWithoutConstructor();$property=$class->getProperty('user');$property->setAccessible(true);$property->setValue($listener,(object)['data'=>['user_id'=>$id,'user_type'=>0,'is_registered'=>true]]);
    if ($role->invoke($listener)!==1 || $role->invoke($listener)!==1) { throw new RuntimeException('Wrong request role'); }
}
if ($db->queries!==2) { throw new RuntimeException('Role cache not isolated per request object'); }
$property->setValue($listener,(object)['data'=>['user_id'=>9,'user_type'=>3,'is_registered'=>true]]);
if ($role->invoke($listener)!==4 || $db->queries!==2) { throw new RuntimeException('Identity switch did not invalidate role'); }
$property->setValue($listener,(object)['data'=>['user_id'=>1,'user_type'=>0,'is_registered'=>false]]);
if ($role->invoke($listener)!==0 || $db->queries!==2) { throw new RuntimeException('Guest transition reused privileges'); }
$event=new ArrayObject(['poster_id'=>7,'row'=>['uii_gender'=>'female'],'post_row'=>[]]);$listener->fixedPostAvatar($event);
if ($db->queries!==2 || !str_contains($event['post_row']['POSTER_AVATAR'],'avatar-female.webp')) { throw new RuntimeException('Avatar caused SQL or ignored already selected data'); }
echo "PASS per-request role reuse/isolation and zero-query post avatars\n";
