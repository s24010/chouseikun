FROM php:8.2-cli

# 安裝系統依賴和 PHP 擴展
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設定工作目錄
WORKDIR /app

# 先複製 composer 檔案
COPY composer.json ./
# 如果有 composer.lock 也複製
COPY composer.lock* ./

# 允許 Composer 以 root 執行
ENV COMPOSER_ALLOW_SUPERUSER=1

# 診斷和安裝
RUN echo "PHP Version:" && php -v && \
    echo "Composer Version:" && composer --version && \
    echo "PHP Extensions:" && php -m && \
    echo "Starting composer install..." && \
    composer install --no-dev --optimize-autoloader --no-scripts -vvv 2>&1 || \
    (echo "Composer install failed with exit code $?" && \
     echo "Composer diagnose:" && \
     composer diagnose && \
     exit 1)

# 複製其餘的專案檔案
COPY . .

# 設定 config 目錄權限
RUN chmod -R 777 tmp logs

# 設定 PORT
ENV PORT=10000

# 暴露端口
EXPOSE 10000

# 啟動 PHP 內建伺服器
CMD php -S 0.0.0.0:$PORT -t webroot