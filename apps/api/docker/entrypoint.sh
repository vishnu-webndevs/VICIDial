#!/bin/sh
set -e

# Setup storage permissions
mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions storage/logs storage/app/public
chown -R www-data:www-data storage bootstrap/cache
php artisan storage:link || true

# Start supervisor
exec /usr/bin/supervisord -c /etc/supervisord.conf
