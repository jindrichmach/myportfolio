#!/usr/bin/env bash
# First-time install for the Docker stack (lokální build z Dockerfile).
#
#   1. Generates .env with random DB passwords (if missing)
#   2. Generates cfg.docker.php from cfg.sample.php with Docker-friendly defaults (if missing)
#   3. Builds the image (if missing) and brings the stack up
#   4. Waits for the app (entrypoint runs migrations) and prints the setup wizard URL
#
# Idempotent — safe to re-run. Interaktivní průvodce: cmd/install.sh (volá tento skript).
# Volitelné ENV: APP_PORT (8080), APP_URL (http://localhost:$APP_PORT), APP_ENV (development | production).
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2; exit 1
fi

# --- 1. .env ---------------------------------------------------------------
if [[ ! -f .env ]]; then
  echo "==> Generating .env with random DB password…"
  DB_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -d '=+/' | head -c 28)
  DB_PASSWORD=$(openssl rand -base64 24      | tr -d '=+/' | head -c 28)
  cat > .env <<EOF
# MyPortfolio — Docker compose env (gitignored)
APP_PORT=${APP_PORT:-8080}
DB_PORT=3308
DB_NAME=myportfolio
DB_USER=myportfolio
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
DB_PASSWORD=${DB_PASSWORD}
EOF
  echo "    .env written (passwords randomised)"
else
  echo "==> .env already exists (skipping)"
fi
set -a; . ./.env; set +a

# --- 2. cfg.docker.php -----------------------------------------------------
# Oddělené od cfg.php, aby stejný checkout mohl běžet nativně i v Dockeru.
if [[ ! -f cfg.docker.php ]]; then
  echo "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults…"
  PEPPER=$(openssl rand -base64 32)
  ENC_KEY=$(openssl rand -base64 32)
  cp cfg.sample.php cfg.docker.php
  # cfg.sample.php má DVA řádky `'host' => '127.0.0.1',` (db, pak redis).
  APP_URL="${APP_URL:-http://localhost:${APP_PORT}}"
  APP_ENV="${APP_ENV:-development}"
  perl -i -pe '
      BEGIN { $n = 0 }
      if (/host.*127\.0\.0\.1/) {
          $n++;
          s/127\.0\.0\.1/db/    if $n == 1;
          s/127\.0\.0\.1/redis/ if $n == 2;
      }
  ' cfg.docker.php
  sed -i \
      -e "s|'name'    => 'myportfolio',|'name'    => '${DB_NAME}',|" \
      -e "s|'user'    => 'myportfolio',|'user'    => '${DB_USER}',|" \
      -e "s|'pass'    => 'CHANGE-ME',|'pass'    => '${DB_PASSWORD}',|" \
      -e "s|'pepper' => 'CHANGE-ME',|'pepper' => '${PEPPER}',|" \
      -e "s|'secret_encryption_key' => '',|'secret_encryption_key' => '${ENC_KEY}',|" \
      -e "s|'env'    => 'production',|'env'    => '${APP_ENV}',|" \
      -e "s|'url'    => 'https://dev.example.com',|'url'    => '${APP_URL}',|" \
      cfg.docker.php
  # Přes HTTP nefungují Secure cookies (__Host- prefix) — jen pro HTTPS je ponecháme.
  if [[ "$APP_URL" != https://* ]]; then
    sed -i \
        -e "s|'cookie_name'   => '__Host-myportfolio_session',|'cookie_name'   => 'myportfolio_session',|" \
        -e "s|'cookie_secure' => true,|'cookie_secure' => false,|" \
        -e "s|'trusted_cookie_name'     => '__Host-myportfolio_td',|'trusted_cookie_name'     => 'myportfolio_td',|" \
        cfg.docker.php
  fi
  echo "    cfg.docker.php written — doplň SMTP a případně captcha / IP allowlist"
else
  echo "==> cfg.docker.php already exists (skipping)"
fi

# --- 3. build + up ---------------------------------------------------------
if ! docker image inspect myportfolio:latest >/dev/null 2>&1; then
  echo "==> Building image…"
  docker compose build app
fi
echo "==> Starting stack…"
docker compose up -d db app

# --- 4. wait ---------------------------------------------------------------
# Migrace spouští entrypoint před web serverem; /api/health vrací 200 i před setupem.
echo "==> Waiting for app to become available (entrypoint runs migrations)…"
for i in {1..90}; do
  if curl -fsS -o /dev/null "http://localhost:${APP_PORT}/api/health"; then
    echo "    App ready."
    break
  fi
  sleep 2
  if [[ $i -eq 90 ]]; then
    echo "ERROR: App failed to respond in 180s. Check 'docker compose logs app'." >&2
    exit 1
  fi
done

echo ""
echo "============================================================"
echo " MyPortfolio is up at:  http://localhost:${APP_PORT}"
echo " The browser will land on the setup wizard (admin account)."
echo ""
echo " Useful:"
echo "   docker compose logs -f app    # tail app logs"
echo "   docker compose down           # stop stack (data persists)"
echo "   docker compose down -v        # stop + WIPE volumes (destroys DB)"
echo "============================================================"
