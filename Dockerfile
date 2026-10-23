FROM php:8.3-fpm

# ffmpeg/ffprobe — проверка и подготовка аудио; pcntl + redis — очередь и Horizon.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev ffmpeg \
    && docker-php-ext-install pdo_mysql mbstring bcmath intl zip opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Лимиты загрузки записей звонков. Должны быть не меньше calls.max_file_size_mb (config/calls.php)
# и согласованы с client_max_body_size в docker/nginx.conf.
RUN { \
      echo 'upload_max_filesize=110M'; \
      echo 'post_max_size=112M'; \
      echo 'max_file_uploads=20'; \
      echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/zz-avtoklass.ini

# PHP-FPM работает от www-data. Для разработки с примонтированным каталогом его UID/GID
# совпадают с пользователем хоста, иначе Laravel не сможет писать в storage/ и bootstrap/cache.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
