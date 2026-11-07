#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if (( $# > 1 )); then echo 'Использование: sudo bash scripts/prepare-spaceweb.sh [домен.ru]' >&2; exit 2; fi
if (( $# == 1 )); then
  python3 - "$1" <<'PYDOMAIN'
import runpy,sys
try: runpy.run_path('scripts/prepare-spaceweb.py')['domain'](sys.argv[1])
except ValueError as error: raise SystemExit(str(error))
PYDOMAIN
fi
docker compose ps >/dev/null
# Повторяемое обновление с резервной копией. Сначала приводим базу к текущему коду.
bash scripts/apply-portal.sh
umask 077
mkdir -p runtime release
export_dir=$(mktemp -d "$PWD/runtime/spaceweb-export.XXXXXX")
paused=0
cleanup() {
  if (( paused )); then docker compose unpause web >/dev/null || true; fi
  python3 - "$export_dir" <<'PY'
import shutil,sys
shutil.rmtree(sys.argv[1])
PY
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
paused=1
docker compose pause web >/dev/null
docker compose exec -T db sh -c 'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb-dump --single-transaction --skip-comments --hex-blob --user=root "$MARIADB_DATABASE"' > "$export_dir/database.sql"
test -s "$export_dir/database.sql"
output="$PWD/release/spaceweb-complete-$(date +%Y%m%d-%H%M%S)"
domain_args=()
if (( $# == 1 )); then domain_args=(--domain "$1"); fi
python3 scripts/prepare-spaceweb.py --full --database "$export_dir/database.sql" --output "$output" "${domain_args[@]}"
if [[ -n "${SUDO_UID:-}" && -n "${SUDO_GID:-}" ]]; then chown -R "$SUDO_UID:$SUDO_GID" "$output"; fi
echo 'Полный пакет готов. Порядок загрузки: START_HERE.md внутри пакета.'
