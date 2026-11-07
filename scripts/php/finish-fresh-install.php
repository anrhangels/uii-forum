<?php
/** CLI only, distributed outside the web root in the fresh-install kit. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$site=realpath($argv[1] ?? '');$private=realpath($argv[2] ?? '');
if (!$site || !$private || $site===$private || str_starts_with($private,$site.DIRECTORY_SEPARATOR) || !is_file($site.'/.uii-fresh-install')) {
    fwrite(STDERR,"Использование: php finish-fresh-install.php /путь/public_html /путь/приватной-папки\nНужен маркер нового установочного пакета. Приватная папка должна существовать вне корня сайта.\n");exit(1);
}
if (!is_file($site.'/config.php') || filesize($site.'/config.php')===0) { throw new RuntimeException('Сначала завершите установку phpBB: config.php пуст'); }
define('IN_PHPBB',true);$phpbb_root_path=$site.'/';$phpEx='php';
require $phpbb_root_path.'common.php';
$user->data['user_id']=ANONYMOUS; $user->ip='127.0.0.1';
if (PHP_VERSION_ID<80100) { throw new RuntimeException('Для УИИ нужен PHP 8.1 или новее'); }
if (empty($config['board_startdate'])) { throw new RuntimeException('Сначала завершите установку phpBB'); }
$config->set('board_disable',1);
$manager=$phpbb_container->get('ext.manager');
if (!$manager->is_enabled('uii/portal')) { $manager->enable('uii/portal'); }
if (!$manager->is_enabled('uii/portal')) { throw new RuntimeException('Не удалось включить УИИ'); }
$r=$db->sql_query("SELECT * FROM ".STYLES_TABLE." WHERE style_path='prosilver'");$parent=$db->sql_fetchrow($r);$db->sql_freeresult($r);
if (!$parent) { throw new RuntimeException('Нужен родительский стиль prosilver'); }
$r=$db->sql_query("SELECT style_id FROM ".STYLES_TABLE." WHERE style_path='uii_modern'");$style=$db->sql_fetchrow($r);$db->sql_freeresult($r);
if (!$style) {
    $data=['style_name'=>'UII Modern','style_copyright'=>'UII Portal','style_active'=>1,'style_path'=>'uii_modern','bbcode_bitfield'=>$parent['bbcode_bitfield'],'style_parent_id'=>(int)$parent['style_id'],'style_parent_tree'=>'prosilver'];
    $db->sql_query('INSERT INTO '.STYLES_TABLE.' '.$db->sql_build_array('INSERT',$data));$style=['style_id'=>(int)$db->sql_nextid()];
}
$styleId=(int)$style['style_id'];
$db->sql_query('UPDATE '.STYLES_TABLE.' SET style_active=1 WHERE style_id='.$styleId);
if ($manager->is_enabled('phpbb/viglink')) { $manager->disable('phpbb/viglink'); }
$config->set('default_style',$styleId);$config->set('override_user_style',1);
foreach (['default_lang'=>'ru','board_timezone'=>'Europe/Moscow','require_activation'=>3,'email_enable'=>0,'allow_cdn'=>0] as $key=>$value) { $config->set($key,$value); }
// Keep originals private. A failure leaves the board disabled and the marker intact.
if (is_dir($site.'/install')) {
    $backup=$private.'/uii-installer-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
    if (!rename($site.'/install',$backup)) { throw new RuntimeException('Не удалось убрать установщик: перенесите install за пределы сайта вручную'); }
}
$cache->purge();
if (!chmod($site.'/config.php',0640)) { throw new RuntimeException('Не удалось ограничить права config.php'); }
if (!unlink($site.'/.uii-fresh-install')) { throw new RuntimeException('Не удалось убрать установочный маркер'); }
$config->set('board_disable',0);
echo "УИИ настроен. Регистрация закрыта. Установщик убран. Проверьте вход основателя, создание сотрудника и дела, а также приватную выдачу фотографий.\n";
