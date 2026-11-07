<?php
// Dedicated QA administrator; never changes an existing user's credentials.
if (PHP_SAPI !== 'cli') { exit(1); }
define('IN_PHPBB',true);
$phpbb_root_path=dirname(__DIR__,2).'/runtime/board/';$phpEx='php';
require $phpbb_root_path.'common.php';
require_once $phpbb_root_path.'includes/functions_user.php';
$user->data['user_id']=ANONYMOUS;$user->ip='127.0.0.1';
$file=dirname(__DIR__,2).'/runtime/test-results/audit-account.json';
if (($argv[1] ?? '')==='disable') {
    if (!is_file($file)) { exit; }
    $account=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $id=(int)$account['id'];
    $r=$db->sql_query('SELECT username FROM '.USERS_TABLE.' WHERE user_id='.$id);$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);
    if (!$row || $row['username']!==$account['username'] || !str_starts_with($row['username'],'Qatest_Audit')) { throw new RuntimeException('QA identity mismatch'); }
    // IDs start at this run's dedicated account; preserve all earlier QA/user data.
    $r=$db->sql_query('SELECT user_id,username FROM '.USERS_TABLE.' WHERE user_id >= '.$id.' AND user_type = '.USER_NORMAL);
    $ids=[];while ($candidate=$db->sql_fetchrow($r)) { if (str_starts_with($candidate['username'],'Qatest_')) { $ids[]=(int)$candidate['user_id']; } }$db->sql_freeresult($r);
    if ($ids) {
        $db->sql_query('UPDATE '.USERS_TABLE.' SET '.$db->sql_build_array('UPDATE',['uii_blocked'=>1,'uii_block_reason'=>'other','uii_block_note'=>'QA: аудит 1.0.0 завершён; тестовая запись']).' WHERE '.$db->sql_in_set('user_id',$ids));
        $db->sql_query('DELETE FROM '.SESSIONS_TABLE.' WHERE '.$db->sql_in_set('session_user_id',$ids));
        $db->sql_query('DELETE FROM '.SESSIONS_KEYS_TABLE.' WHERE '.$db->sql_in_set('user_id',$ids));
    }
    $db->sql_query('DELETE FROM '.ACL_USERS_TABLE.' WHERE user_id='.$id);
    $r=$db->sql_query("SELECT group_id FROM ".GROUPS_TABLE." WHERE group_name='ADMINISTRATORS'");$administrators=$db->sql_fetchrow($r);$db->sql_freeresult($r);
    if ($administrators) { group_user_del((int)$administrators['group_id'],[$id]); }
    echo 'QA accounts blocked for this run: '.count($ids).PHP_EOL;
    $db->sql_query('DELETE FROM '.SESSIONS_TABLE.' WHERE session_user_id='.$id);
    $db->sql_query('DELETE FROM '.SESSIONS_KEYS_TABLE.' WHERE user_id='.$id);
    unlink($file);echo "Dedicated QA account disabled; sessions and local password removed.\n";exit;
}
if (is_file($file)) { throw new RuntimeException('QA account already exists; disable it first'); }
$name='Qatest_Audit'.substr(bin2hex(random_bytes(4)),0,6);$password=bin2hex(random_bytes(20));
$r=$db->sql_query("SELECT group_id,group_name FROM ".GROUPS_TABLE." WHERE group_name IN ('REGISTERED','ADMINISTRATORS','UII_FACTION')");
$groups=[];while($row=$db->sql_fetchrow($r)) { $groups[$row['group_name']]=(int)$row['group_id']; }$db->sql_freeresult($r);
if (count($groups)!==3) { throw new RuntimeException('Required portal groups missing'); }
$id=user_add(['username'=>$name,'user_password'=>$phpbb_container->get('passwords.manager')->hash($password),'user_email'=>'','group_id'=>$groups['REGISTERED'],'user_type'=>USER_NORMAL,'user_lang'=>'ru','uii_gender'=>'male','uii_force_password'=>0]);
if (!$id) { throw new RuntimeException('QA creation failed'); }
foreach (['ADMINISTRATORS','UII_FACTION'] as $group) { group_user_add($groups[$group],[$id]); }
$old=umask(0077);file_put_contents($file,json_encode(['id'=>$id,'username'=>$name,'password'=>$password],JSON_THROW_ON_ERROR));umask($old);
echo "Dedicated QA administrator created; credentials kept private.\n";
