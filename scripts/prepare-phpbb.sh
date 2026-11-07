#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[[ "$EUID" != 0 ]] || { echo 'Запускайте подготовку без sudo'; exit 1; }
for tool in curl unzip python3 sha256sum; do
  command -v "$tool" >/dev/null || { echo "Не найден $tool"; exit 1; }
done
if find app -mindepth 1 -print -quit | read -r _; then
  echo 'Каталог app непустой; файлы не перезаписаны.'; exit 1
fi
version=3.3.19
archive="downloads/phpBB-${version}.zip"
mkdir -p downloads app
curl --fail --location --retry 3 --connect-timeout 20 --max-time 300 \
  "https://download.phpbb.com/pub/release/3.3/${version}/phpBB-${version}.zip" -o "${archive}.part"
printf '%s  %s\n' '1530e5cabf7ecc9ecfaed3fa13da79f9e8a514623beafababc8800b5ce966de9' "${archive}.part" | sha256sum --check -
mv "${archive}.part" "$archive"
staging=$(mktemp -d "$PWD/downloads/extract.XXXXXX")
trap 'rm -rf -- "$staging"' EXIT
unzip -q "$archive" -d "$staging"
test -f "$staging/phpBB3/install/app.php"
cp -a "$staging/phpBB3/." app/
python3 - <<'PY'
import os,secrets
from pathlib import Path
p=Path('.env')
if not p.exists():
    fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,'w') as f:
        f.write('DB_NAME=uii\nDB_USER=uii\nDB_PASSWORD='+secrets.token_hex(24)+'\nDB_ROOT_PASSWORD='+secrets.token_hex(24)+'\n')
PY
# Разрешить запись установщику Apache, сохраняя закрытыми прочие каталоги.
sudo chown -R 33:33 app/cache app/files app/store app/images/avatars/upload
sudo chown 33:33 app/config.php
echo 'Подготовлено. Запуск: sudo bash scripts/start.sh'
echo 'Установщик: http://127.0.0.1:8088/install/app.php'
echo 'Реквизиты MySQL находятся в .env; сервер БД: db'
