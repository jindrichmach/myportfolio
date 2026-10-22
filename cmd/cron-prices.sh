#!/usr/bin/env bash
# cron-prices.sh — wrapper pro api/bin/cron-prices.php (frekvence viz CronCatalog / cmd/crontab)
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php8.5 || command -v php)}"
LOG_DIR="${MYPORTFOLIO_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-prices.php" "$@" \
    >> "$LOG_DIR/prices-$(date +%Y-%m-%d).log" 2>&1
