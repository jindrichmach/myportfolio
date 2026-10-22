#!/usr/bin/env bash
# Spustí PHPUnit testovou sadu (api/tests/) a build frontendu.
#
# Použití:
#   cmd/test.sh                      # PHPUnit (všechny sady) + pnpm build
#   cmd/test.sh --testsuite=Unit     # argumenty jdou do PHPUnit
#   cmd/test.sh --filter=LotEngine
#   SKIP_WEB=1 cmd/test.sh           # jen PHP testy
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php8.5 || command -v php)}"

cd "$PROJECT_ROOT/api"
if [[ ! -f vendor/bin/phpunit ]]; then
  echo "ERROR: vendor/bin/phpunit chybí. Spusť: cd api && composer install" >&2
  exit 1
fi
"$PHP_BIN" vendor/bin/phpunit --colors=auto "$@"

if [[ "${SKIP_WEB:-0}" != "1" ]]; then
  cd "$PROJECT_ROOT/web"
  pnpm build
fi
