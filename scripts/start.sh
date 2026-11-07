#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
command -v docker >/dev/null || { echo 'Требуется Docker с Compose'; exit 1; }
test -f .env || { echo 'Создайте .env по .env.example'; exit 1; }
for required_file in app/index.php app/common.php app/vendor/autoload.php; do
  test -f "$required_file" || { echo 'Сначала загрузите полный официальный пакет phpBB в app'; exit 1; }
done
docker compose up -d --build
