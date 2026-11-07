<?php
if(PHP_SAPI!=='cli'){exit(1);}
define('IN_PHPBB',true);$phpbb_root_path=__DIR__.'/../../runtime/board/';$phpEx='php';require $phpbb_root_path.'common.php';
require $phpbb_root_path.'includes/acp/auth.php';
$id=(int)($argv[1] ?? 0);$operation=$argv[2] ?? '';
$r=$db->sql_query('SELECT username FROM '.USERS_TABLE.' WHERE user_id = '.$id);$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);
if(!$row || strpos($row['username'],'Qatest_')!==0){throw new RuntimeException('Только временные QA-аккаунты');}
$admin=new auth_admin();
if($operation==='grant'){$admin->acl_set('user',0,$id,['a_board'=>ACL_YES,'m_uii_manage'=>ACL_YES]);}
elseif($operation==='clear'){$db->sql_query('DELETE FROM '.ACL_USERS_TABLE.' WHERE user_id = '.$id);$admin->acl_clear_prefetch($id);}
else{throw new RuntimeException('Неизвестная операция');}
echo "QA permissions updated\n";
