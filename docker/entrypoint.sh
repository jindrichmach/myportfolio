#!/usr/bin/env sh
# Entrypoint Docker image (nginx + php-fpm + cronie).
set -eu

# Dynamický port (Railway/Heroku/Fly přidělují port z env).
PORT="${PORT:-80}"
if [ "$PORT" != "80" ]; then
  sed -i "s/listen          80 default_server;/listen          ${PORT} default_server;/" /etc/nginx/nginx.conf
fi

# RAM tuning: na malém hostu sniž počet php-fpm workerů (každý ~30–60 MB).
# Default 8 (rozumný strop); PHP_FPM_MAX_CHILDREN=4 pro ~512 MB–1 GB RAM stroje.
if [ -n "${PHP_FPM_MAX_CHILDREN:-}" ]; then
  sed -i "s/^pm.max_children = .*/pm.max_children = ${PHP_FPM_MAX_CHILDREN}/" \
    /usr/local/etc/php-fpm.d/zz-myportfolio.conf
fi
# Volitelně sniž opcache shared paměť (default 128 MB) — OPCACHE_MEMORY=64 pro tiny host.
if [ -n "${OPCACHE_MEMORY:-}" ]; then
  sed -i "s/^opcache.memory_consumption = .*/opcache.memory_consumption = ${OPCACHE_MEMORY}/" \
    /usr/local/etc/php/conf.d/myportfolio.ini
fi

# --- migrace ---------------------------------------------------------------
if [ "${MYPORTFOLIO_SKIP_MIGRATIONS:-0}" != "1" ]; then
  attempts="${MYPORTFOLIO_MIGRATE_ATTEMPTS:-20}"
  delay="${MYPORTFOLIO_MIGRATE_DELAY:-3}"
  current_attempt=1
  while :; do
    if php /var/www/html/api/bin/migrate.php; then
      break
    fi
    if [ "$current_attempt" -ge "$attempts" ]; then
      echo "Migration failed after $attempts attempts. Aborting startup." >&2
      exit 1
    fi
    echo "Migration attempt $current_attempt/$attempts failed. Retrying in ${delay}s..." >&2
    current_attempt=$((current_attempt + 1))
    sleep "$delay"
  done
fi

# --- vestavěný cron (cronie) -----------------------------------------------
# Cron nedědí ENV kontejneru → vydumpujeme ho pro wrapper (0640 root:www-data,
# obsahuje tajemství). cronie čte /etc/cron.d/myportfolio (Vixie formát s user polem).
if [ "${MYPORTFOLIO_ENABLE_CRON:-1}" != "0" ]; then
  export -p > /etc/myportfolio-cron.env
  chmod 0640 /etc/myportfolio-cron.env
  chown root:www-data /etc/myportfolio-cron.env 2>/dev/null || true
  # Selhání cronu nesmí shodit kontejner (web poběží dál). cronie crond daemonizuje sám.
  if crond; then
    echo "[entrypoint] vestavěný cron spuštěn (logy v \${MYPORTFOLIO_DATA_DIR}/log/cron)"
  else
    echo "[entrypoint] VAROVÁNÍ: cron se nepodařilo spustit — pokračuji bez něj" >&2
  fi
fi

# --- web server ------------------------------------------------------------
# php-fpm v popředí (-F) ale na pozadí shellu → zachová stderr do `docker logs`.
# nginx exec v popředí jako hlavní proces (PID 1 = tini reapuje zombie po fpm).
php-fpm -F &
exec nginx -g 'daemon off;'
