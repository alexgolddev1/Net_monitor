FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bash git libicu-dev libzip-dev unzip \
    && docker-php-ext-install intl pdo_mysql opcache zip sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
