#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec -T web php bin/phpbbcli.php extension:disable uii/portal
docker compose exec -T web php bin/phpbbcli.php cache:purge
