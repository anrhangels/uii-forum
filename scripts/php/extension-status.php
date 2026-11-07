<?php
// Выполняется только CLI, передаётся контейнеру через stdin.
if (PHP_SAPI !== 'cli') { exit(1); }
define('IN_PHPBB', true);
$phpbb_root_path = '/var/www/html/';
$phpEx = 'php';
require $phpbb_root_path . 'common.php';
echo $phpbb_container->get('ext.manager')->is_enabled('uii/portal') ? 'enabled' : 'disabled';
