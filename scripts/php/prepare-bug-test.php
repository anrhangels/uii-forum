<?php
if (PHP_SAPI!=='cli') {exit;}
$expected=realpath(dirname(__DIR__,2).'/runtime/cases-v2-sandbox');
if (!$expected || realpath($argv[1] ?? '')!==$expected.'/site' || realpath($argv[2] ?? '')!==$expected.'/private') {throw new RuntimeException('Only the isolated cases-v2 sandbox is allowed.');}
if (is_file($expected.'/private/bug-account.json')) {echo 'Existing isolated QA user reused',PHP_EOL;exit;}

define('IN_PHPBB',true);$phpbb_root_path=$argv[1].'/';$phpEx='php';require $phpbb_root_path.'common.php';require $phpbb_root_path.'includes/functions_user.php';
$private=$argv[2];$account=['username'=>'Bug_'.ucfirst(bin2hex(random_bytes(4))),'password'=>bin2hex(random_bytes(12))];
$r=$db->sql_query("SELECT group_id FROM ".$table_prefix."groups WHERE group_name = 'UII_STAFF'");$group=(int)$db->sql_fetchfield('group_id');$db->sql_freeresult($r);
$id=user_add(['username'=>$account['username'],'user_password'=>$phpbb_container->get('passwords.manager')->hash($account['password']),'user_email'=>'bug-'.bin2hex(random_bytes(4)).'@example.test','group_id'=>$group,'user_type'=>USER_NORMAL,'user_lang'=>'ru','user_timezone'=>'Europe/Moscow','user_ip'=>'127.0.0.1','user_regdate'=>time(),'user_style'=>(int)$config['default_style'],'uii_force_password'=>0]);
$account['user_id']=$id;file_put_contents($private.'/bug-account.json',json_encode($account));chmod($private.'/bug-account.json',0600);echo 'QA user created',PHP_EOL;
