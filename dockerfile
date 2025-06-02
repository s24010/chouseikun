FROM php:8.2-cli

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 安裝必要的 PHP 擴展
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install zip

# 設定工作目錄
WORKDIR /app

# 複製專案檔案
COPY . .

# 安裝 Composer 依賴
RUN composer install --no-dev --optimize-autoloader

# 設定 Render 使用的 PORT
ENV PORT=10000

# 暴露端口
EXPOSE 10000

# 啟動 PHP 內建伺服器
CMD php -S 0.0.0.0:$PORT -t webroot