#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export LD_LIBRARY_PATH="$PWD/runtime/usr/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec runtime/usr/bin/php -n -d extension="$PWD/runtime/usr/lib/php/modules/mysqli.so" -d error_reporting=22527 "$@"
