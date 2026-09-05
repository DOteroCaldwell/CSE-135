# PHP 8.3 matching the droplet, plus the one extension the app needs.
# The official php:8.3-cli image ships without pdo_mysql.
FROM php:8.3-cli
RUN docker-php-ext-install pdo_mysql
