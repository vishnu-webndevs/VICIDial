FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

FROM php:8.2-cli-alpine
WORKDIR /var/www/html

RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
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

USER www-data
EXPOSE 8088
CMD ["sh", "-lc", "php artisan storage:link || true; php artisan serve --host=0.0.0.0 --port=${PORT:-8088}"]
