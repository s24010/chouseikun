FROM php:8.2-cli

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install zip

WORKDIR /app

COPY . .

RUN composer install --no-dev --optimize-autoloader

ENV PORT=10000

EXPOSE 10000

CMD php -S 0.0.0.0:$PORT -t webroot