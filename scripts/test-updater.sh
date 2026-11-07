#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 scripts/package-web-update.py
python3 tests/web_release.py release/web-update/uii-web-update.zip
bash scripts/php-local.sh -d extension="$PWD/runtime/usr/lib/php/modules/zip.so" tests/updater.php
bash scripts/php-local.sh tests/templates.php
if [[ ${UII_UPDATER_INTEGRATION:-0} == 1 ]]; then
    # Requires a fresh, independent runtime/updater-sandbox with v017 applied.
    bash scripts/php-local.sh -d extension="$PWD/runtime/usr/lib/php/modules/zip.so" \
        -d extension="$PWD/runtime/usr/lib/php/modules/sqlite3.so" tests/updater_integration.php
fi
if [[ ${UII_UPDATER_BROWSER:-0} == 1 ]]; then
    PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/updater_ui.cjs
fi
