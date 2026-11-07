#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
bash scripts/backup-db.sh
docker compose exec -T --user www-data web sh -c 'find ext/uii/portal -name "*.php" -print | while IFS= read -r file; do php -l "$file" || exit 1; done'
docker compose exec -T --user www-data web php ext/uii/portal/tests/workflow.php
docker compose exec -T --user www-data web php ext/uii/portal/tests/roles.php
docker compose exec -T --user www-data web php ext/uii/portal/tests/identity.php
docker compose exec -T --user www-data web php ext/uii/portal/tests/evidence.php
docker compose exec -T --user www-data web php ext/uii/portal/tests/management.php
status=$(docker compose exec -T --user www-data web php < scripts/php/extension-status.php)
case "$status" in
  enabled) echo 'Расширение уже включено; продолжаем обновление.' ;;
  disabled) docker compose exec -T --user www-data web php bin/phpbbcli.php extension:enable uii/portal ;;
  *) echo "Не удалось определить состояние расширения: $status" >&2; exit 1 ;;
esac
docker compose exec -T --user www-data web php bin/phpbbcli.php db:migrate
docker compose exec -T --user www-data web php bin/phpbbcli.php cache:purge
echo 'Портал включён. Откройте http://127.0.0.1:8088/ и нажмите Ctrl+F5.'
