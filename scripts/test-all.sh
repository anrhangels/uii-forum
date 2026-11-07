#!/usr/bin/env bash
set -euo pipefail
# Previous-version UI scenarios are opt-in; current forms use the isolated v2 suite.
if [[ ${UII_LEGACY_TESTS:-0} != 1 ]]; then
    bash "$(dirname "$0")/test-cases.sh"
    exec bash "$(dirname "$0")/test-bugs.sh"
fi
cd "$(dirname "$0")/.."
: "${UII_TEST_PASSWORD:?Задайте UII_TEST_PASSWORD — пароль тестового администратора}"
export UII_TEST_PASSWORD
mkdir -p runtime/test-results
bash scripts/php-local.sh app/ext/uii/portal/tests/workflow.php
bash scripts/php-local.sh app/ext/uii/portal/tests/roles.php
bash scripts/php-local.sh app/ext/uii/portal/tests/identity.php
bash scripts/php-local.sh app/ext/uii/portal/tests/evidence.php
bash scripts/php-local.sh app/ext/uii/portal/tests/management.php
python3 tests/evidence_upload.py
bash scripts/php-local.sh tests/localization.php
bash scripts/php-local.sh tests/request_cache.php
bash scripts/php-local.sh tests/templates.php
bash scripts/php-local.sh tests/acp_guard.php
python3 tests/visual_assets.py
python3 tests/statistics.py
python3 tests/staff_filters.py
python3 tests/spaceweb_package.py
bash scripts/php-local.sh tests/spaceweb_hosting.php
node tests/photo_drop.cjs
node tests/preferences.cjs
python3 tests/http_smoke.py
python3 tests/http_workflows.py
python3 -u tests/scenarios.py | tee runtime/test-results/scenarios.log
UII_QA_USER=$(python3 -c 'from pathlib import Path; import re; print(re.search(r"QA nickname: (\S+)",Path("runtime/test-results/scenarios.log").read_text()).group(1))')
export UII_QA_USER
python3 -u tests/requests_inspections.py
python3 -u tests/case_boundaries.py
python3 -u tests/accounts_security.py
python3 -u tests/timer_modes.py
python3 -u tests/roles_matrix.py
python3 -u tests/fixed_identity.py
if test -d runtime/browser/node_modules/playwright && test -d runtime/browsers; then
  PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/browser.cjs
  PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/workspace_ui.cjs
  PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/identity_ui.cjs
  PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/style_audit.cjs
  PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/case_refresh.cjs
  PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/management_ui.cjs
  PLAYWRIGHT_BROWSERS_PATH="$PWD/runtime/browsers" node tests/audit_ui.cjs
fi
