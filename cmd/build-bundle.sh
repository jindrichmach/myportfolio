#!/usr/bin/env bash
# Sestaví produkční balíček myportfolio-X.Y.Z.tar.gz (+ .sha256) pro aktualizaci nativní instalace.
#
# Použití:
#   cmd/build-bundle.sh <X.Y.Z> [<out-dir>]    # out-dir výchozí ${TMPDIR:-/tmp}; musí být mimo repozitář
#
# Zdrojem jsou soubory sledované gitem (pracovní kopie). Sestavení běží v dočasné kopii,
# takže lokální api/vendor ani web/node_modules se nemění. Verze musí souhlasit se souborem VERSION.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Použití: $0 <X.Y.Z> [<out-dir>]" >&2
  exit 2
fi
VERSION_ARG="$1"
OUT_DIR="${2:-${TMPDIR:-/tmp}}"

if [[ ! "$VERSION_ARG" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]]; then
  echo "ERROR: neplatná verze '$VERSION_ARG' (čekám X.Y.Z)." >&2
  exit 2
fi

FILE_VERSION="$(tr -d '[:space:]' < "$PROJECT_ROOT/VERSION")"
if [[ "$FILE_VERSION" != "$VERSION_ARG" ]]; then
  echo "ERROR: verze $VERSION_ARG nesouhlasí se souborem VERSION ($FILE_VERSION)." >&2
  exit 1
fi

mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
case "$OUT_DIR/" in
  "$PROJECT_ROOT"/*) echo "ERROR: out-dir musí být mimo repozitář ($OUT_DIR)." >&2; exit 2 ;;
esac

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
SRC="$WORK/src"
mkdir -p "$SRC"

cd "$PROJECT_ROOT"
git ls-files -z --cached --exclude-standard | tar --null --ignore-failed-read -cf - -T - 2>/dev/null | tar -xf - -C "$SRC"

echo "==> composer install --no-dev"
(cd "$SRC/api" && composer install --no-dev --no-progress --no-interaction --prefer-dist --optimize-autoloader)

echo "==> pnpm build"
(cd "$SRC/web" && pnpm install --frozen-lockfile && pnpm build)

BUNDLE="myportfolio-$VERSION_ARG.tar.gz"
PREFIX="myportfolio-$VERSION_ARG"

echo "==> $BUNDLE"
cd "$SRC"
tar -czf "$WORK/$BUNDLE" \
  --exclude='./.git' \
  --exclude='./.github' \
  --exclude='./.claude' \
  --exclude='./.vscode' \
  --exclude='./docs' \
  --exclude='./private' \
  --exclude='./storage/*' \
  --exclude='./log/*' \
  --exclude='./cfg.php' \
  --exclude='./cfg.local.php' \
  --exclude='./cfg.docker.php' \
  --exclude='./.env' \
  --exclude='./api/tests' \
  --exclude='./api/.phpunit.cache' \
  --exclude='./web/src' \
  --exclude='./web/tests' \
  --exclude='./web/node_modules' \
  --exclude='./web/public' \
  --exclude='./web/index.html' \
  --exclude='./web/package.json' \
  --exclude='./web/pnpm-lock.yaml' \
  --exclude='./web/pnpm-workspace.yaml' \
  --exclude='./web/tsconfig*.json' \
  --exclude='./web/vite.config.ts' \
  --exclude='*.log' \
  --transform "s,^\.\(/\|$\),$PREFIX/," \
  .

mv "$WORK/$BUNDLE" "$OUT_DIR/$BUNDLE"
(cd "$OUT_DIR" && sha256sum "$BUNDLE" > "$BUNDLE.sha256")
ls -lh "$OUT_DIR/$BUNDLE" "$OUT_DIR/$BUNDLE.sha256"
