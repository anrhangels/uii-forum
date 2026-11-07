<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/hosting.php';
use uii_deploy\hosting as H;
try {
    if (count($argv)<3) { fwrite(STDERR,"Использование: php setup-spaceweb.php /полный/путь/public_html domain.ru [--apply] [--unlock]\n");exit(2); }
    $root=realpath($argv[1]);$domain=H::domain($argv[2]);
    if (!$root || !is_file($root.'/config.php') || !is_file($root.'/common.php')) { throw new RuntimeException('Не найден корень phpBB с config.php.'); }
    foreach (['mysqli','mbstring','gd','json','xml','dom','ctype','hash','filter','session','zlib'] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException('Не включено PHP-расширение '.$extension.'. Проверьте также версию PHP для CLI.'); } }
    if (PHP_VERSION_ID<80100) { throw new RuntimeException('Требуется PHP 8.1+, целевая версия - 8.3.'); }
    require $root.'/config.php';H::prefix($table_prefix);
    foreach ([$dbname,$dbuser,$dbpasswd] as $value) { if ($value==='' || str_starts_with($value,'SPACEWEB_')) { throw new RuntimeException('Сначала заполните реквизиты базы в config.php.'); } }
    if (!defined('UII_EVIDENCE_PATH') || !is_dir(UII_EVIDENCE_PATH) || !is_readable(UII_EVIDENCE_PATH) || !is_writable(UII_EVIDENCE_PATH)) { throw new RuntimeException('Проверьте расположение и права uii-private/evidence.'); }
    $private=realpath(UII_EVIDENCE_PATH);
    if ($private===$root || str_starts_with($private,$root.'/')) { throw new RuntimeException('Фото должны находиться вне публичного корня сайта.'); }
    foreach (['cache','files','store'] as $directory) { if (!is_writable($root.'/'.$directory)) { throw new RuntimeException('Нет прав записи в '.$directory); } }
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    try { $db=new mysqli($dbhost,$dbuser,$dbpasswd,$dbname,$dbport!==''?(int)$dbport:3306);$db->set_charset('utf8mb4'); }
    catch (Throwable $e) { throw new RuntimeException('Подключение к базе не удалось. Проверьте config.php и порт MySQL в панели SpaceWeb.'); }
    try {
        $db->query('SELECT nav_id FROM `'.$table_prefix.'uii_navigation` WHERE nav_id = 1');
        $db->query('SELECT uii_block_reason, uii_block_note FROM `'.$table_prefix.'users` LIMIT 1');
        $rows=$db->query('SELECT payload FROM `'.$table_prefix.'uii_cases`',MYSQLI_USE_RESULT);
        $photos=0;while($row=$rows->fetch_assoc())$photos+=H::photos([$row['payload']],$private);
        $rows->free();
    } catch (Throwable $e) { throw new RuntimeException('Нет актуальных таблиц портала. Импортируйте свежую базу после миграции v014.'); }
    echo 'PHP '.PHP_VERSION.'; найдено и проверено фото: '.$photos.PHP_EOL;
    if (!in_array('--apply',$argv,true)) { echo "Проверка завершена без изменений базы. Для настройки добавьте --apply.\n";exit; }
    $db->begin_transaction();
    try {
        $statement=$db->prepare('UPDATE `'.$table_prefix.'config` SET config_value = ? WHERE config_name = ?');
        foreach (H::settings($domain,'uii_sweb_'.bin2hex(random_bytes(6))) as $name=>$value) { $statement->bind_param('ss',$value,$name);$statement->execute(); }
        $db->query("UPDATE `".$table_prefix."users` SET uii_blocked = 1, uii_block_reason = 'other', uii_block_note = 'Тестовая учётная запись: доступ закрыт при переносе' WHERE LEFT(username,7) = 'Qatest_' AND user_type <> 3");
        $db->query('DELETE FROM `'.$table_prefix.'sessions`');$db->query('DELETE FROM `'.$table_prefix.'sessions_keys`');
        $db->commit();
    } catch (Throwable $e) { $db->rollback();throw new RuntimeException('Настройка базы не завершилась. Изменения откатились; проверьте импорт.'); }
    echo "Домен/HTTPS/cookie настроены, старые сеансы сброшены, QA-аккаунты заблокированы. Сам форум оставлен выключенным для проверки администратором.\n";
    if (in_array('--unlock',$argv,true)) { if (is_file($root.'/.uii-deploy.lock') && !unlink($root.'/.uii-deploy.lock')) { throw new RuntimeException('Не удалось убрать .uii-deploy.lock.'); }echo "Вход администратора доступен. После проверки включите форум через ACP.\n"; }
    else { echo "Перед входом администратора удалите .uii-deploy.lock в файловом менеджере.\n"; }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }
