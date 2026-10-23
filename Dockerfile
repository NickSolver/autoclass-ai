FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring bcmath intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

# PHP-FPM работает от www-data. Для разработки с примонтированным каталогом его UID/GID
# совпадают с пользователем хоста, иначе Laravel не сможет писать в storage/ и bootstrap/cache.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
