#!/usr/bin/env bash
# cron-cleanup.sh — wrapper pro api/bin/cron-cleanup.php (frekvence viz CronCatalog / cmd/crontab)
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php8.5 || command -v php)}"
LOG_DIR="${MYPORTFOLIO_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-cleanup.php" "$@" \
    >> "$LOG_DIR/cleanup-$(date +%Y-%m-%d).log" 2>&1
