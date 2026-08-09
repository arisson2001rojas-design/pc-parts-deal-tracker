ARG PHP_VERSION=8.4

FROM jez500/pricebuddy-tests-${PHP_VERSION}:latest

WORKDIR /app

COPY composer.json composer.lock /app/

RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . /app

RUN mkdir -p storage/framework/sessions \
    storage/framework/cache/data \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    storage/app/public \
    && cp .env.ci .env \
    && composer dump-autoload --no-interaction
