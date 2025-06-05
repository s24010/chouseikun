FROM php:8.2-cli

# システム依存関係とPHP拡張機能をインストール
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

# Composerをインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 作業ディレクトリを設定
WORKDIR /app

# まずcomposerファイルをコピー
COPY composer.json ./
COPY composer.lock* ./

# Composerをrootで実行することを許可
ENV COMPOSER_ALLOW_SUPERUSER=1

# 依存関係をインストール（dev依存関係を含む）
RUN composer install --optimize-autoloader --no-scripts

# 残りのプロジェクトファイルをコピー
COPY . .

# 必要なディレクトリを作成し、権限を設定
RUN mkdir -p tmp logs && \
    chmod -R 777 tmp logs

# 環境をproductionに設定
ENV APP_ENV=production
ENV DEBUG=false

# post-installスクリプトを実行
RUN composer run-script post-install-cmd || true

# PORTを設定
ENV PORT=10000

# ポートを公開
EXPOSE 10000

# PHP内蔵サーバーを起動
CMD php -S 0.0.0.0:$PORT -t webroot