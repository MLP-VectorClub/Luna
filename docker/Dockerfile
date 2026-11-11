# Luna: one build, two runtime images (targets: fpm, web). The fpm image also runs Horizon/queue workers, the scheduler and
# the migration job (different command, same image).
#   docker buildx bake -f docker/docker-bake.hcl            (context is the repository root)
ARG PHP_IMAGE=php:8.5-fpm-trixie
ARG NODE_IMAGE=node:24-trixie-slim

FROM ${PHP_IMAGE} AS php-base
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_pgsql redis igbinary zip sockets \
 && rm -rf /var/lib/apt/lists/* /tmp/*

FROM --platform=$BUILDPLATFORM composer:2 AS composer-bin
FROM ${NODE_IMAGE} AS node-bin

# ---------------------------------------------------------------------------------------------------------
FROM php-base AS build
ARG GIT_COMMIT_HASH=unknown
ARG GIT_COMMIT_TIMESTAMP=
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts
# svgo (the only runtime Node dependency: media optimization runs node_modules/.bin/svgo); pnpm only for this install
COPY --from=node-bin /usr/local/bin/node /usr/local/bin/node
COPY --from=node-bin /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && npm install -g pnpm@11 \
 && CI=true pnpm install --frozen-lockfile --prod --filter . \
 && npm uninstall -g pnpm
# dummy env only so artisan can boot while building; real env is injected at runtime. No config:cache/optimize here.
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=production DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_DRIVER=array \
    sh -c 'php artisan package:discover --ansi && mkdir -p storage/api-docs && php artisan l5-swagger:generate'
# config/l5-swagger.php reads packages/api-types/package.json for the API version, keep only that file
RUN rm -rf tests docs/contract .git .env \
 && find packages -mindepth 1 -not -path packages/api-types -not -path packages/api-types/package.json -delete 2>/dev/null; \
    find packages/api-types -mindepth 1 -not -name package.json -delete

# ---------------------------------------------------------------------------------------------------------
FROM php-base AS fpm
ARG GIT_COMMIT_HASH=unknown
ARG GIT_COMMIT_TIMESTAMP=
ENV GIT_COMMIT_HASH=${GIT_COMMIT_HASH} GIT_COMMIT_TIMESTAMP=${GIT_COMMIT_TIMESTAMP}
# image optimizers used by spatie/laravel-medialibrary (config/media-library.php) and node for svgo
RUN apt-get update && apt-get install -y --no-install-recommends jpegoptim pngquant optipng gifsicle \
 && rm -rf /var/lib/apt/lists/*
COPY --from=node-bin /usr/local/bin/node /usr/local/bin/node
RUN { echo "memory_limit = 256M"; echo "expose_php = Off"; echo "upload_max_filesize = 20M"; echo "post_max_size = 24M"; \
      echo "session.upload_progress.enabled = 0"; echo "opcache.enable=1"; echo "opcache.enable_cli=0"; \
      echo "opcache.validate_timestamps=0"; echo "opcache.memory_consumption=96"; echo "opcache.max_accelerated_files=20000"; \
    } > /usr/local/etc/php/conf.d/99-app.ini
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /app/ ./
# storage and bootstrap/cache are group-writable (the Pod sets fsGroup to the www-data gid, 33)
RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
             storage/media-library/temp bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache \
 && find storage bootstrap/cache -type d -exec chmod g+s {} +
USER www-data
EXPOSE 9000

# ---------------------------------------------------------------------------------------------------------
FROM nginx:1-alpine-slim AS web
# static files only (public/index.php is the entry point passed to php-fpm in the fpm container, same absolute paths)
COPY --from=build /app/public /var/www/html/public
