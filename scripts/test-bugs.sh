#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
bash scripts/php-local.sh tests/bug_contracts.php
bash scripts/php-local.sh tests/templates.php
python3 tests/visual_assets.py
if [[ ${UII_BUG_HTTP:-0} == 1 ]]; then
    bash scripts/php-local.sh -d extension="$PWD/runtime/usr/lib/php/modules/sqlite3.so" \
        scripts/php/prepare-bug-test.php "$PWD/runtime/cases-v2-sandbox/site" "$PWD/runtime/cases-v2-sandbox/private"
    python3 tests/bugs.py
    mkdir -p runtime/test-results
    PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/bugs_ui.cjs
    PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/errors_ui.cjs
fi
