FROM php:8.2-cli

# 安裝系統依賴和 PHP 擴展
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設定工作目錄
WORKDIR /app

# 先複製 composer 檔案
COPY composer.json composer.lock* ./

# 允許 Composer 以 root 執行
ENV COMPOSER_ALLOW_SUPERUSER=1

# 安裝依賴（加入更多除錯資訊）
RUN composer install --no-dev --optimize-autoloader --no-scripts --verbose || \
    (echo "Composer install failed" && cat /app/composer.json && exit 1)

# 複製其餘的專案檔案
COPY . .

# 執行 post-install scripts
RUN composer run-script post-install-cmd --no-dev || true

# 設定 PORT
ENV PORT=10000

# 暴露端口
EXPOSE 10000

# 啟動 PHP 內建伺服器
CMD php -S 0.0.0.0:$PORT -t webroot