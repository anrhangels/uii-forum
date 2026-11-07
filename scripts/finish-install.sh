#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec -T web php -r '
define("IN_PHPBB", true);
$phpbb_root_path = "/var/www/html/";
$phpEx = "php";
require $phpbb_root_path . "common.php";
if (empty($config["board_startdate"])) { fwrite(STDERR, "Installation incomplete\n"); exit(1); }
$config->set("require_activation", 3);
$config->set("email_enable", 0);
echo "Public registration disabled\n";
'
# Перемещаем установщик за пределы публичного каталога, сохраняя файлы.
if [ -d app/install ]; then
  mkdir -p backups
  target="backups/install-$(date +%Y%m%d-%H%M%S)"
  test ! -e "$target"
  mv app/install "$target"
fi
docker compose exec -T web chmod 644 /var/www/html/config.php
echo 'Установщик убран из публичного каталога. Регистрация отключена.'
echo 'Форум: http://127.0.0.1:8088/'
echo 'Закрытый доступ к чтению ещё требует настройки прав.'
