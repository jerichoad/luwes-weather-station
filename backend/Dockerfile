FROM serversideup/php:8.3-fpm-nginx-bookworm-v4.5.1

ENV APP_BASE_DIR="/var/www/html" \
    NGINX_WEBROOT="/var/www/html/public" \
    PHP_DATE_TIMEZONE="UTC" \
    SHOW_WELCOME_MESSAGE="false"

WORKDIR /var/www/html

USER root
COPY --chmod=755 ./docker/entrypoint.d/ /etc/entrypoint.d/
USER www-data

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data . ./
RUN composer dump-autoload --optimize
