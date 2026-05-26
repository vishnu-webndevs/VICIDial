FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts


FROM php:8.2-fpm-alpine
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
        nginx \
        supervisor \
    && docker-php-ext-install \
        intl \
        mbstring \
        pdo \
        pdo_mysql \
        zip

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Setup config files
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public

EXPOSE 8088

CMD ["/usr/local/bin/entrypoint.sh"]
