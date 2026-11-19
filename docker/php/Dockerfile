# Image PHP 8.3 commune à l'API (php-fpm), Reverb, la file d'attente et le scheduler.
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd intl bcmath pcntl sockets opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/fleettrack.ini
COPY docker/php/entrypoint.sh /usr/local/bin/fleettrack-entrypoint
RUN chmod +x /usr/local/bin/fleettrack-entrypoint

WORKDIR /var/www/backend
ENTRYPOINT ["fleettrack-entrypoint"]
CMD ["php-fpm"]
