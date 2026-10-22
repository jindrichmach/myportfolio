#!/usr/bin/env bash
# MyPortfolio — instalační průvodce.
#
#   cmd/install.sh              # zeptá se na způsob nasazení a všechna nastavení
#   cmd/install.sh --docker     # Docker (docker compose: app + MariaDB)
#   cmd/install.sh --native     # nativně na Linuxu (PHP 8.5 + MariaDB + Apache/nginx)
#   cmd/install.sh --yes        # bez dotazů, s výchozími hodnotami (lze kombinovat)
#
# Skript připraví prostředí (konfigurace, databáze, závislosti, build, migrace, cron) a na konci
# vypíše adresu webového průvodce — tam se zakládá správce, SMTP a volitelně ukázková data.
# Je idempotentní: existující cfg.php / .env ponechá, migrace jsou idempotentní.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MODE=""
ASSUME_YES=0
for arg in "$@"; do
  case "$arg" in
    --docker) MODE=docker ;;
    --native) MODE=native ;;
    --yes|-y) ASSUME_YES=1 ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) echo "Neznámá volba: $arg" >&2; exit 1 ;;
  esac
done

# ── Pomocné funkce ─────────────────────────────────────────────────────────
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
info()  { printf '  %s\n' "$*"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn()  { printf '  \033[33m!\033[0m %s\n' "$*"; }
fail()  { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
step()  { printf '\n\033[1;35m── %s\033[0m\n' "$*"; }

# ask VAR "Otázka" "výchozí"
ask() {
  local __var=$1 __prompt=$2 __default=${3:-} __reply=""
  if [[ $ASSUME_YES -eq 1 ]]; then
    printf -v "$__var" '%s' "$__default"; return
  fi
  read -r -p "  $__prompt${__default:+ [$__default]}: " __reply || true
  printf -v "$__var" '%s' "${__reply:-$__default}"
}
# ask_secret VAR "Otázka" "výchozí (nezobrazí se)"
ask_secret() {
  local __var=$1 __prompt=$2 __default=${3:-} __reply=""
  if [[ $ASSUME_YES -eq 1 ]]; then
    printf -v "$__var" '%s' "$__default"; return
  fi
  read -r -s -p "  $__prompt${__default:+ [Enter = vygenerovat]}: " __reply || true
  echo
  printf -v "$__var" '%s' "${__reply:-$__default}"
}
# confirm "Otázka" y|n  → návratový kód 0 = ano
confirm() {
  local __prompt=$1 __default=${2:-n} __reply=""
  if [[ $ASSUME_YES -eq 1 ]]; then
    [[ $__default == y ]]; return
  fi
  read -r -p "  $__prompt [$([[ $__default == y ]] && echo A/n || echo a/N)]: " __reply || true
  __reply=${__reply:-$__default}
  [[ $__reply =~ ^[aAyY] ]]
}
random_secret() { openssl rand -base64 "${1:-24}" | tr -d '=+/\n' | head -c "${2:-28}"; }
port_open() { (exec 3<>"/dev/tcp/$1/$2") 2>/dev/null; }

bold "MyPortfolio — instalace"
info "Správa osobních investic. Průvodce připraví prostředí; účet správce založíš v prohlížeči."

if [[ -z $MODE ]]; then
  step "Způsob nasazení"
  info "1) Docker — vše v kontejnerech (doporučeno pro server i NAS)"
  info "2) Nativně — PHP 8.5, MariaDB a webový server přímo na tomto počítači"
  ask choice "Volba" "1"
  case "$choice" in 1|docker) MODE=docker ;; 2|native) MODE=native ;; *) fail "Neplatná volba: $choice" ;; esac
fi

# ═══════════════════════════════════════════════════════════════════════════
# Docker
# ═══════════════════════════════════════════════════════════════════════════
if [[ $MODE == docker ]]; then
  step "Docker"
  command -v docker >/dev/null 2>&1 || fail "Chybí docker (https://docs.docker.com/engine/install/)."
  docker compose version >/dev/null 2>&1 || fail "Chybí plugin 'docker compose' (v2)."
  ok "docker $(docker --version | sed 's/Docker version //; s/,.*//')"
  if [[ -f .env && -f cfg.docker.php ]]; then
    warn ".env a cfg.docker.php už existují — ponechám je (pro novou konfiguraci je nejdřív smaž)."
  else
    ask APP_PORT "Port aplikace na tomto počítači" "8080"
    ask APP_URL "Veřejná adresa (URL) aplikace" "http://localhost:${APP_PORT}"
    if [[ $APP_URL == https://* ]]; then def_env=production; else def_env=development; fi
    ask APP_ENV "Prostředí (production | development)" "$def_env"
    export APP_PORT APP_URL APP_ENV
  fi
  "$ROOT/cmd/docker-install.sh"
  step "Hotovo"
  info "Otevři v prohlížeči ${APP_URL:-http://localhost:${APP_PORT:-8080}}/setup a dokonči průvodce:"
  info "  správce → zabezpečení → e-mail (SMTP) → ukázková data → shrnutí."
  info "Plánované úlohy (ceny, kurzy, zálohy, reporty) běží v kontejneru samy."
  exit 0
fi

# ═══════════════════════════════════════════════════════════════════════════
# Nativní instalace
# ═══════════════════════════════════════════════════════════════════════════
step "1/7 Kontrola požadavků"
PHP_BIN="${PHP_BIN:-$(command -v php8.5 || command -v php || true)}"
[[ -n $PHP_BIN ]] || fail "Chybí PHP 8.5 (php8.5 nebo php v PATH)."
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.5.0", ">=") ? 0 : 1);' || fail "Potřeba PHP 8.5+, $PHP_BIN je $("$PHP_BIN" -r 'echo PHP_VERSION;')."
ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') ($PHP_BIN)"
has_ext() { "$PHP_BIN" -r "exit(extension_loaded('$1') ? 0 : 1);"; }
missing=(); optional=()
# Povinná: DB, řetězce, šifrování; zip = import XLSX/ZIP a zálohy, gd = ikony, simplexml = XLSX, curl = ceny a kurzy
for ext in pdo_mysql mbstring openssl zip gd simplexml curl; do has_ext "$ext" || missing+=("$ext"); done
# Doporučená: rychlejší BigDecimal (bcmath / gmp), IMAP avíza (iconv)
for ext in bcmath iconv; do has_ext "$ext" || optional+=("$ext"); done
[[ ${#missing[@]} -eq 0 ]] || fail "Chybí rozšíření PHP: ${missing[*]} (Debian/Ubuntu: apt install $(printf 'php8.5-%s ' "${missing[@]}"))."
ok "rozšíření PHP"
[[ ${#optional[@]} -eq 0 ]] || warn "Doporučená rozšíření: ${optional[*]} (aplikace funguje i bez nich)."
command -v composer >/dev/null 2>&1 && ok "composer" || fail "Chybí composer (https://getcomposer.org/download/)."
command -v node >/dev/null 2>&1 && ok "node $(node --version)" || fail "Chybí Node.js 20.19+ / 22.12+ (build frontendu)."
if ! command -v pnpm >/dev/null 2>&1; then
  if command -v corepack >/dev/null 2>&1 && confirm "pnpm chybí — zapnout ho přes corepack?" y; then
    corepack enable pnpm || fail "corepack enable pnpm selhal (zkus sudo)."
  else
    fail "Chybí pnpm (npm i -g pnpm nebo corepack enable pnpm)."
  fi
fi
ok "pnpm $(pnpm --version)"
DB_CLIENT="$(command -v mariadb || command -v mysql || true)"
[[ -n $DB_CLIENT ]] && ok "klient databáze ($DB_CLIENT)" || warn "Klient mariadb/mysql nenalezen — databázi nezaložím, musí existovat."

# ── Konfigurace ────────────────────────────────────────────────────────────
step "2/7 Konfigurace (cfg.php)"
WRITE_CFG=1
KEEP_PEPPER=""; KEEP_KEY=""
# Zálohy konfigurace mimo kořen webu (storage/ webserver nevydá) — obsahují heslo DB, pepper a šifrovací klíč
CFG_BACKUP_DIR="${MYPORTFOLIO_DATA_DIR:-$PWD}/storage/backup/config"
for stray in cfg.php.bak.*; do
  [[ -f $stray ]] || continue
  mkdir -p "$CFG_BACKUP_DIR" && chmod 700 "$CFG_BACKUP_DIR"
  mv "$stray" "$CFG_BACKUP_DIR/" && warn "starší záloha $stray přesunuta z kořene webu do $CFG_BACKUP_DIR"
done
if [[ -f cfg.php ]]; then
  if confirm "cfg.php už existuje. Přepsat novou konfigurací?" n; then
    mkdir -p "$CFG_BACKUP_DIR" && chmod 700 "$CFG_BACKUP_DIR"
    CFG_BACKUP="$CFG_BACKUP_DIR/cfg.php.$(date +%Y%m%d%H%M%S)"
    install -m 600 cfg.php "$CFG_BACKUP"; ok "záloha $CFG_BACKUP (mimo web)"
    # Pepper a šifrovací klíč se zachovají — nové by zneplatnily hesla uživatelů i uložená tajemství (SMTP, IMAP, burzy)
    KEEP_PEPPER="$("$PHP_BIN" -r '$c = require "cfg.php"; echo $c["app"]["pepper"] ?? "";')"
    KEEP_KEY="$("$PHP_BIN" -r '$c = require "cfg.php"; echo $c["app"]["secret_encryption_key"] ?? "";')"
  else
    WRITE_CFG=0; ok "ponechávám stávající cfg.php"
  fi
fi

CREATE_DB=0
if [[ $WRITE_CFG -eq 1 ]]; then
  ask APP_URL "Veřejná adresa (URL) aplikace" "http://localhost"
  APP_URL="${APP_URL%/}"
  if [[ $APP_URL == https://* ]]; then def_env=production; else def_env=development; fi
  ask APP_ENV "Prostředí (production | development)" "$def_env"
  [[ $APP_ENV == production || $APP_ENV == development ]] || fail "Prostředí musí být production nebo development."
  ask TIMEZONE "Časové pásmo" "Europe/Prague"

  info ""
  info "Databáze MariaDB 11+"
  ask DB_HOST "Server" "127.0.0.1"
  ask DB_PORT "Port" "3306"
  ask DB_NAME "Název databáze" "myportfolio"
  ask DB_USER "Uživatel" "myportfolio"
  DB_PASS_DEFAULT="$(random_secret)"
  ask_secret DB_PASS "Heslo uživatele" "$DB_PASS_DEFAULT"
  if [[ -n $DB_CLIENT ]] && confirm "Založit databázi a uživatele teď (potřebuji účet s právem CREATE USER, např. root)?" y; then
    CREATE_DB=1
    ask DB_ADMIN "Administrátorský účet databáze" "root"
    ask_secret DB_ADMIN_PASS "Jeho heslo (prázdné = přihlášení přes unix socket)" ""
  fi

  info ""
  if port_open 127.0.0.1 6379; then def_redis=y; info "Redis běží na 127.0.0.1:6379."; else def_redis=n; fi
  REDIS_ENABLED=false
  if confirm "Použít Redis (rate limiting a ochrana proti hádání hesel)?" "$def_redis"; then
    REDIS_ENABLED=true
    ask REDIS_HOST "Redis server" "127.0.0.1"
    ask REDIS_PORT "Redis port" "6379"
  fi
fi

# ── Databáze ───────────────────────────────────────────────────────────────
step "3/7 Databáze"
if [[ $CREATE_DB -eq 1 ]]; then
  CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT; chmod 600 "$CNF"
  {
    echo "[client]"
    echo "host=$DB_HOST"; echo "port=$DB_PORT"; echo "user=$DB_ADMIN"
    [[ -n $DB_ADMIN_PASS ]] && echo "password=\"${DB_ADMIN_PASS//\"/\\\"}\""
  } > "$CNF"
  [[ $DB_HOST == 127.0.0.1 || $DB_HOST == localhost ]] && DB_USER_HOST=localhost || DB_USER_HOST=%
  DB_PASS_SQL="${DB_PASS//\\/\\\\}"; DB_PASS_SQL="${DB_PASS_SQL//\'/\\\'}"
  "$DB_CLIENT" --defaults-extra-file="$CNF" <<SQL || fail "Založení databáze selhalo."
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_USER_HOST' IDENTIFIED BY '$DB_PASS_SQL';
ALTER USER '$DB_USER'@'$DB_USER_HOST' IDENTIFIED BY '$DB_PASS_SQL';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$DB_USER_HOST';
FLUSH PRIVILEGES;
SQL
  ok "databáze $DB_NAME a uživatel $DB_USER@$DB_USER_HOST"
else
  info "Databázi nezakládám — musí existovat (CREATE DATABASE … utf8mb4)."
fi

if [[ $WRITE_CFG -eq 1 ]]; then
  if [[ $APP_URL == https://* ]]; then
    COOKIE=(--set "session.cookie_name=__Host-myportfolio_session" --set session.cookie_secure=true --set "auth.email_otp.trusted_cookie_name=__Host-myportfolio_td")
  else
    COOKIE=(--set session.cookie_name=myportfolio_session --set session.cookie_secure=false --set auth.email_otp.trusted_cookie_name=myportfolio_td)
  fi
  [[ $APP_ENV == production ]] && REDIS_PREFIX="myportfolio:prod:" || REDIS_PREFIX="myportfolio:dev:"
  REDIS=(--set "redis.enabled=$REDIS_ENABLED" --set "redis.prefix=s:$REDIS_PREFIX")
  [[ $REDIS_ENABLED == true ]] && REDIS+=(--set "redis.host=s:$REDIS_HOST" --set "redis.port=$REDIS_PORT")
  "$PHP_BIN" api/bin/install-config.php --out=cfg.php \
    --set "app.env=$APP_ENV" --set "app.debug=false" --set "app.url=s:$APP_URL" --set "app.timezone=s:$TIMEZONE" \
    --set "app.pepper=s:${KEEP_PEPPER:-$(openssl rand -base64 32)}" --set "app.secret_encryption_key=s:${KEEP_KEY:-$(openssl rand -base64 32)}" \
    --set "db.host=s:$DB_HOST" --set "db.port=$DB_PORT" --set "db.name=s:$DB_NAME" --set "db.user=s:$DB_USER" --set "db.pass=s:$DB_PASS" \
    "${COOKIE[@]}" "${REDIS[@]}" || fail "Zápis cfg.php selhal."
  chmod 640 cfg.php
  if [[ -n $KEEP_PEPPER ]]; then ok "cfg.php (pepper a šifrovací klíč zachované ze stávající konfigurace)"
  else ok "cfg.php (náhodný pepper a šifrovací klíč; SMTP nastavíš ve webovém průvodci)"; fi
fi

# ── Závislosti a build ─────────────────────────────────────────────────────
step "4/7 Závislosti a build frontendu"
APP_ENV_NOW="$("$PHP_BIN" -r '$c = require "cfg.php"; echo $c["app"]["env"] ?? "production";')"
if [[ $APP_ENV_NOW == production ]]; then COMPOSER_FLAGS=(--no-dev --optimize-autoloader); else COMPOSER_FLAGS=(); fi
INSTALL_LOG="$ROOT/log/install.log"; mkdir -p "$ROOT/log"; : > "$INSTALL_LOG"
(cd api && composer install --no-interaction --no-progress "${COMPOSER_FLAGS[@]}") >>"$INSTALL_LOG" 2>&1 \
  || { tail -20 "$INSTALL_LOG"; fail "composer install selhal (celý výpis: log/install.log)."; }
ok "PHP závislosti"
(cd web && pnpm install --frozen-lockfile && pnpm build) >>"$INSTALL_LOG" 2>&1 \
  || { tail -20 "$INSTALL_LOG"; fail "Build frontendu selhal (celý výpis: log/install.log)."; }
ok "frontend (web/dist)"

# ── Migrace a adresáře ─────────────────────────────────────────────────────
step "5/7 Migrace databáze"
mkdir -p storage/uploads storage/backup storage/cache log
"$PHP_BIN" api/bin/migrate.php >>"$INSTALL_LOG" 2>&1 \
  || { tail -20 "$INSTALL_LOG"; fail "Migrace selhaly — zkontroluj přístup k databázi v cfg.php (log/install.log)."; }
ok "schéma databáze"
WEB_USER_DEFAULT="$(ps -eo user,comm 2>/dev/null | awk '/apache2|httpd|nginx|php-fpm/ && $1 != "root" {print $1; exit}')"
ask WEB_USER "Uživatel webového serveru (zápis do storage/ a log/)" "${WEB_USER_DEFAULT:-www-data}"
if id "$WEB_USER" >/dev/null 2>&1; then
  if chown -R "$WEB_USER" storage log 2>/dev/null || sudo -n chown -R "$WEB_USER" storage log 2>/dev/null; then
    ok "storage/ a log/ patří uživateli $WEB_USER"
  else
    warn "Nepodařilo se změnit vlastníka — spusť: sudo chown -R $WEB_USER $ROOT/storage $ROOT/log"
  fi
else
  warn "Uživatel $WEB_USER neexistuje — oprávnění k storage/ a log/ nastav ručně."
fi

# ── Cron ───────────────────────────────────────────────────────────────────
step "6/7 Plánované úlohy (cron)"
CRON_LINES="$(grep -v '^#' cmd/crontab | grep -v '^\s*$' | sed "s|/var/www/myportfolio|$ROOT|g")"
info "Ceny, kurzy ČNB, historie, zálohy, reporty a upozornění:"
printf '%s\n' "$CRON_LINES" | sed 's/^/    /'
if command -v crontab >/dev/null 2>&1 && confirm "Přidat tyto úlohy do crontabu uživatele $(whoami)?" n; then
  { crontab -l 2>/dev/null | grep -v "$ROOT/cmd/cron-" || true; echo "# MyPortfolio ($ROOT)"; printf '%s\n' "$CRON_LINES"; } | crontab -
  ok "crontab aktualizován (staré řádky MyPortfolio nahrazeny)"
else
  info "Přidáš později: crontab -e (řádky výše, viz cmd/crontab)."
fi

# ── Webový server ──────────────────────────────────────────────────────────
step "7/7 Webový server"
APP_URL_NOW="$("$PHP_BIN" -r '$c = require "cfg.php"; echo rtrim($c["app"]["url"] ?? "", "/");')"
info "Apache: DocumentRoot = $ROOT, AllowOverride All, mod_rewrite + mod_headers (pravidla jsou v .htaccess)."
info "nginx + php-fpm: vzor v docker/nginx.conf (root $ROOT, /api/ → api/public/index.php, SPA → web/dist)."
info "Rychlá zkouška bez webového serveru:  $PHP_BIN -S 127.0.0.1:8000 api/bin/dev-server-router.php"

bold ""
bold "Hotovo. Otevři ${APP_URL_NOW:-adresu aplikace}/setup a dokonči průvodce v prohlížeči:"
info "správce → zabezpečení → e-mail (SMTP) → ukázková data → shrnutí."
