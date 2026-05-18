FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

FROM php:8.2-cli-alpine
WORKDIR /var/www/html

RUN apk add --no-cache \
        bash \
        ca-certificates \
        curl \
        git \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        openssl \
        zip \
    && docker-php-ext-install \
        intl \
        mbstring \
        pdo \
        pdo_mysql \
        zip

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public

# Recreating storage structure in CMD because mounted volumes might be empty
EXPOSE 8088
CMD ["sh", "-lc", "mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions storage/logs storage/app/public && chown -R www-data:www-data storage bootstrap/cache && php artisan storage:link || true; php artisan queue:work --daemon --sleep=1 --tries=3 --timeout=120 & exec php artisan serve --host=0.0.0.0 --port=${PORT:-8088}"]
