FROM php:8.2-apache

# SQLite3・開発ツールのインストール
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    git \
    curl \
    unzip \
    && docker-php-ext-install pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# mod_rewrite を有効化
RUN a2enmod rewrite

# data/ ディレクトリを作成・権限設定
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html