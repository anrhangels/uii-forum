#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
for file in app/ext/uii/portal/tests/{workflow,roles,identity,evidence,management}.php tests/{case_contracts,templates,localization,request_cache,acp_guard}.php; do
    bash scripts/php-local.sh "$file"
done
UII_TEST_GD="$PWD/runtime/usr/lib/php/modules/gd.so" python3 tests/evidence_upload.py
bash scripts/php-local.sh -d extension="$PWD/runtime/usr/lib/php/modules/gd.so" tests/image_optimizer.php
python3 tests/visual_assets.py
if [[ ${UII_CASE_HTTP:-0} == 1 ]]; then
    python3 tests/cases_v2.py
    mkdir -p runtime/test-results
    PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/cases_v2_ui.cjs
fi
