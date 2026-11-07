#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec -T web php <<'PHP'
<?php
define('IN_PHPBB', true);
$phpbb_root_path = '/var/www/html/';
$phpEx = 'php';
require $phpbb_root_path . 'common.php';
$result = $db->sql_query("SELECT user_id, user_type FROM " . USERS_TABLE . " WHERE username_clean = 'test_admin'");
$account = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if (!$account || (int) $account['user_type'] !== USER_FOUNDER) {
    fwrite(STDERR, "Ожидаемый аккаунт основателя Test_Admin не найден; изменений нет.\n");
    exit(1);
}
$id = (int) $account['user_id'];
$password = bin2hex(random_bytes(12));
$data = [
    'user_password' => $phpbb_container->get('passwords.manager')->hash($password),
    'user_passchg' => time(),
    'user_login_attempts' => 0,
    'uii_force_password' => 1,
];
$db->sql_transaction('begin');
$db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $data) . ' WHERE user_id = ' . $id);
$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id = ' . $id);
$db->sql_query('DELETE FROM ' . SESSIONS_KEYS_TABLE . ' WHERE user_id = ' . $id);
$db->sql_transaction('commit');
echo "Логин: Test_Admin\nВременный пароль: " . $password . "\nПри входе задайте новый пароль.\n";
PHP
