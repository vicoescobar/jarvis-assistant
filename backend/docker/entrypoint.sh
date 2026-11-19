#!/bin/sh
set -e

mkdir -p /var/www/storage/app/sqlite \
         /var/www/storage/framework/cache \
         /var/www/storage/framework/sessions \
         /var/www/storage/framework/views \
         /var/www/storage/logs \
         /var/www/bootstrap/cache \
         /var/www/user_data

if [ ! -f /var/www/storage/app/sqlite/database.sqlite ]; then
    touch /var/www/storage/app/sqlite/database.sqlite
fi

php artisan migrate --force || true
php artisan jarvis:seed-owner || true

php-fpm -D
exec nginx -g "daemon off;"
