FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite sqlite3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p /var/www/html/database /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/database /var/www/html/logs

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r "exit((int) !@file_get_contents('http://127.0.0.1/'));"
