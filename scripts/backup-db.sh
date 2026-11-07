#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
mkdir -p snapshots
stamp=$(date +%Y%m%d-%H%M%S)
out="snapshots/database-$stamp.sql"
docker compose exec -T db sh -c 'exec mariadb-dump --single-transaction --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > "$out.part"
test -s "$out.part"
mv "$out.part" "$out"
echo "Сохранено: $out"
files_out="snapshots/files-$stamp.tar"
docker compose exec -T web tar -C /var/www/html -cf - files > "$files_out.part"
test -s "$files_out.part"
mv "$files_out.part" "$files_out"
echo "Сохранены вложения и фото: $files_out"
