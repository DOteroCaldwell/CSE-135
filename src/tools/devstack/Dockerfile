# PHP 8.3 matching the droplet, plus the one extension the app needs.
# The official php:8.3-cli image ships without pdo_mysql.
#
# HW5: chromium is the PDF renderer behind export.php. It is a system package on
# the droplet too (apt), which is why it lives here and not in any composer file.
FROM php:8.3-cli
RUN docker-php-ext-install pdo_mysql \
 && apt-get update \
 && apt-get install -y --no-install-recommends chromium fonts-liberation \
 && rm -rf /var/lib/apt/lists/*
