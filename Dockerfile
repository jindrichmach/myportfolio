# MyPortfolio — Alpine + nginx + php-fpm.
#
#   - jediné /data volume (log/, storage/, private/, cfg.local.php); www-data uid 33
#   - konfigurace přes ENV (MYPORTFOLIO_*) nebo namountovaný cfg.docker.php
#   - migrace na startu, vestavěný cron (cronie) generovaný z CronCatalog
#   - DockerfileDataDirEnvTest: NESMÍ tu být `ENV MYPORTFOLIO_DATA_DIR` (řídí compose)
#
# Build:  docker build -t myportfolio:latest .

# ---------- Stage 1: frontend ----------
FROM node:25-alpine AS web-build
WORKDIR /app
COPY web/package.json web/pnpm-lock.yaml web/pnpm-workspace.yaml ./
RUN corepack enable && corepack prepare pnpm@latest --activate \
 && pnpm install --frozen-lockfile
COPY web/ ./
# Manuál se přibalí do frontendu (import z ../manual při buildu)
COPY manual/ /manual/
RUN pnpm build

# ---------- Stage 2: composer ----------
FROM composer:2 AS php-deps
WORKDIR /app
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-reqs
COPY api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative

# ---------- Stage 3: runtime ----------
FROM php:8.5-fpm-alpine AS runtime

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# mariadb-client = mariadb-dump pro cron-backup.php; tzdata kvůli Europe/Prague;
# cronie kvůli /etc/cron.d Vixie formátu (busybox crond by ho nezvládl).
RUN install-php-extensions \
        pdo_mysql gd mbstring intl zip opcache bcmath redis \
 && apk add --no-cache nginx cronie tini tzdata mariadb-client \
 && rm -rf /var/cache/apk/*

# www-data uid 82 → 33 (Debian-kompatibilní vlastnictví /data volume).
RUN apk add --no-cache --virtual .uidgid shadow \
 && groupmod -g 33 www-data \
 && usermod -u 33 -g 33 www-data \
 && apk del .uidgid \
 && mkdir -p /var/lib/nginx /var/lib/nginx/tmp /var/log/nginx /run \
 && chown -R www-data:www-data /var/lib/nginx /var/log/nginx

RUN { \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 20M'; \
        echo 'post_max_size = 25M'; \
        echo 'max_execution_time = 60'; \
        echo 'date.timezone = Europe/Prague'; \
        echo 'expose_php = Off'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 128'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/myportfolio.ini

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-myportfolio.conf
RUN rm -f /etc/nginx/http.d/default.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
RUN chmod +x docker/entrypoint.sh
COPY --from=web-build --chown=www-data:www-data /app/dist ./web/dist
COPY --from=php-deps  --chown=www-data:www-data /app/vendor ./api/vendor

# Vestavěný cron — wrapper + crontab z CronCatalog (cronie čte /etc/cron.d/*).
RUN cp docker/cron-run.sh /usr/local/bin/myportfolio-cron-run \
 && chmod 0755 /usr/local/bin/myportfolio-cron-run \
 && php tools/generateDockerCrontab.php > /etc/cron.d/myportfolio \
 && chmod 0644 /etc/cron.d/myportfolio

# Stub cfg.php — konfigurace přes ENV nebo namountovaný cfg.php.
RUN echo '<?php return [];' > cfg.php && chown www-data:www-data cfg.php

RUN mkdir -p /data/log /data/storage /data/private \
 && chown -R www-data:www-data /data
VOLUME ["/data"]

# Default stateful adresáře (fallback pro custom compose bez DATA_DIR).
RUN mkdir -p log storage private && chown -R www-data:www-data log storage private

EXPOSE 80
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/var/www/html/docker/entrypoint.sh"]
