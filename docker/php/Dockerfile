ARG COMPOSER_IMAGE=composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b
ARG PHP_IMAGE=php:8.4-fpm-bookworm@sha256:0a5638c2c48eb04987107ae448a398dd18345bcf659e15fd99a422617e754264
FROM ${COMPOSER_IMAGE} AS composer
FROM ${PHP_IMAGE}

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl pcntl bcmath zip opcache \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/development.ini /usr/local/etc/php/conf.d/99-development.ini
WORKDIR /var/www/html
ENV COMPOSER_HOME=/tmp/composer
CMD ["php-fpm"]
