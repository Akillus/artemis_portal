FROM php:8.2-cli-bookworm AS php-base

RUN apt-get update && apt-get install -y \
    curl \
    git \
    unzip \
    libicu-dev \
    libonig-dev \
    libsqlite3-dev \
    libzip-dev \
    && docker-php-ext-install \
    intl \
    mbstring \
    pdo_sqlite \
    zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM php-base AS vendor

COPY . .

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

FROM node:20-bookworm-slim AS frontend

WORKDIR /app/frontend

COPY frontend/package*.json ./

RUN npm ci

COPY frontend ./

RUN npm run build-local

FROM php-base

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=frontend /app/frontend/dist/ ./public/
COPY docker/php/entrypoint.sh /usr/local/bin/ariadne-entrypoint

RUN chmod +x /usr/local/bin/ariadne-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache database \
    && touch database/database.sqlite \
    && chown -R www-data:www-data storage bootstrap/cache database

EXPOSE 8000

ENTRYPOINT ["ariadne-entrypoint"]
