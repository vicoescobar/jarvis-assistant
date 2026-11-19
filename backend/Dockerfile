FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx \
        sqlite \
        sqlite-dev \
        curl \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-install -j$(nproc) \
        pdo_sqlite \
        mbstring \
        zip \
        opcache \
        pcntl \
    && deluser www-data 2>/dev/null || true \
    && delgroup www-data 2>/dev/null || true \
    && addgroup -g 1000 www-data \
    && adduser -u 1000 -G www-data -s /bin/sh -D www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer config audit.block-insecure false \
    && composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p /var/www/storage/app/sqlite \
                /var/www/storage/framework/cache \
                /var/www/storage/framework/sessions \
                /var/www/storage/framework/views \
                /var/www/storage/logs \
                /var/www/bootstrap/cache \
                /var/www/user_data \
                /var/lib/nginx/tmp \
    && chown -R www-data:www-data /var/www /var/lib/nginx /var/log/nginx /run/nginx 2>/dev/null || true \
    && chmod +x /var/www/docker/entrypoint.sh

COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf

USER www-data

EXPOSE 8080

ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
