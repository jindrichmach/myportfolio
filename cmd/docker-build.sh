#!/usr/bin/env bash
# Build (or rebuild) the MyPortfolio Docker image.
#
# Usage: cmd/docker-build.sh [--no-cache] [--pull]
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2
  exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2
  exit 1
fi

echo "==> Building MyPortfolio image (this can take a few minutes on first run)…"
docker compose build "$@" app

echo ""
echo "==> Done. Next steps:"
echo "    cmd/docker-install.sh     # first-time setup (creates .env + cfg.docker.php)"
echo "    docker compose up -d      # start stack (after install)"
