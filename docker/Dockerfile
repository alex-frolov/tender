# Tender Platform — Dockerfile (multi-stage: dev / prod)
# Базовый образ: PHP 8.5-fpm (2026)
#
# Примечание: docker-php-ext-install в PHP 8.5 имеет баг (install-modules: cp modules/*),
# поэтому расширения ставятся вручную: исходники → phpize → configure → make install
# (паттерн из официального docker-library/php + примеры сборки с GitHub-тарболами).

# ---------- dev ----------
FROM php:8.5-fpm AS dev

# PHPIZE_DEPS — как в официальном образе
ENV PHPIZE_DEPS autoconf dpkg-dev file g++ gcc libc-dev make pkg-config re2c

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        ca-certificates curl xz-utils \
        git unzip \
        libpq-dev libzip-dev libicu-dev libssl-dev \
        librabbitmq-dev \
    ; \
    rm -rf /var/lib/apt/lists/*

# Вспомогательная функция: сборка расширения из исходников PHP (обходит баг install-modules)
# Использование: build_php_ext <dirname> [configure args...]
ENV PHP_EXT_BUILDER='true'
RUN cat > /usr/local/bin/build-php-ext <<'EOF'
#!/bin/bash
set -eux
ext="$1"; shift
cd "/usr/src/php/ext/$ext"
phpize
./configure --with-php-config=/usr/local/bin/php-config "$@"
make -j"$(nproc)"
# Обход бага PHP 8.5: make install не находит modules/* (install-modules)
# Копируем .so вручную в extension_dir
mkdir -p "$(php-config --extension-dir)"
cp ".libs/$ext.so" "$(php-config --extension-dir)/$ext.so"
docker-php-ext-enable "$ext"
EOF
RUN chmod +x /usr/local/bin/build-php-ext

# 1) Расширения из исходников PHP (pdo_pgsql, intl, zip, pcntl)
#    opcache — уже встроен в php:8.5-fpm (Zend OPcache), отдельно не ставим
RUN set -eux; \
    docker-php-source extract; \
    build-php-ext pdo_pgsql; \
    build-php-ext intl; \
    build-php-ext zip; \
    build-php-ext pcntl; \
    docker-php-source delete

# 2) redis — из GitHub-тарбола (phpredis 6.1.0), без igbinary (несовместим с PHP 8.5)
RUN set -eux; \
    mkdir -p /usr/src/php/ext/redis; \
    curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz \
      | tar -xz -C /usr/src/php/ext/redis --strip-components=1; \
    cd /usr/src/php/ext/redis; \
    phpize; \
    ./configure --with-php-config=/usr/local/bin/php-config; \
    make -j"$(nproc)"; \
    cp .libs/redis.so "$(php-config --extension-dir)/redis.so"; \
    docker-php-ext-enable redis

# 4) amqp (RabbitMQ) — из PECL-тарбола (обход бага pecl install)
RUN set -eux; \
    pecl download amqp; \
    mkdir -p /usr/src/php/ext/amqp; \
    tar xf amqp-*.tgz -C /usr/src/php/ext/amqp --strip-components=1; \
    rm -f amqp-*.tgz; \
    cd /usr/src/php/ext/amqp; \
    phpize; \
    ./configure --with-php-config=/usr/local/bin/php-config --with-amqp; \
    make -j"$(nproc)"; \
    cp .libs/amqp.so "$(php-config --extension-dir)/amqp.so"; \
    docker-php-ext-enable amqp

# 5) pcov (покрытие кода) — из PECL-тарбола (обход бага pecl install)
RUN set -eux; \
    pecl download pcov; \
    mkdir -p /usr/src/php/ext/pcov; \
    tar xf pcov-*.tgz -C /usr/src/php/ext/pcov --strip-components=1; \
    rm -f pcov-*.tgz; \
    cd /usr/src/php/ext/pcov; \
    phpize; \
    ./configure --with-php-config=/usr/local/bin/php-config; \
    make -j"$(nproc)"; \
    cp .libs/pcov.so "$(php-config --extension-dir)/pcov.so"; \
    docker-php-ext-enable pcov

# 6) Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 7) Лимиты загрузки файлов (см. комментарий в самом файле): дефолты PHP
# обрезали документы раньше проверок приложения.
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

WORKDIR /var/www

# Healthcheck'и задаются в compose-файлах на уровне сервиса (php-fpm не отвечает
# по HTTP на :9000 — это FastCGI, поэтому образного healthcheck здесь нет;
# см. docker-compose.prod.yml и operations/deployment.md).

# ---------- prod (приложение) ----------
FROM dev AS prod

# Auto-scripts composer'а (post-install-cmd) выполняют cache:clear в том env,
# который задан на момент install. Без APP_ENV консоль использует dev и грузит
# dev-only-бандлы (ZenstruckFoundryBundle и пр.), которые при --no-dev не
# установлены → cache:clear падает. Фиксируем prod до composer install.
ENV APP_ENV=prod

COPY --chown=www-data:www-data . /var/www
# --no-scripts: auto-scripts composer'а (cache:clear) при сборке тянут
# внешние сервисы (Redis — app cache adapter), которых в build-сети нет → hang.
# Кэш прод-контейнера собираем отдельно с --no-warmup (без инстанцирования
# сервисов); warmup выполняется при деплое, когда Redis/БД доступны
# (scripts/deploy.sh → migrate → cache:warmup).
RUN set -eux; \
    mkdir -p /var/www/var/cache /var/www/var/log /var/www/var/files /var/www/var/exports /var/www/var/share; \
    composer install --no-dev --no-interaction --optimize-autoloader --no-scripts \
    && php bin/console cache:clear --env=prod --no-warmup \
    && chown -R www-data:www-data /var/www/var \
    && rm -f "$(php-config --extension-dir)/pcov.so" /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini

USER www-data

# ---------- prod-минимальный ----------
# Снимаем runtime-инструменты сборки (composer, git, unzip) — сброс пользователя
# на root, т.к. prod заканчивается USER www-data (rm composer нужен права root).
FROM prod AS prod-minimal

USER root
RUN rm -f /usr/local/bin/composer \
    && apt-get purge -y git unzip \
    && apt-get autoremove -y
USER www-data