FROM php:8.2-apache

# 必要なPHP拡張モジュールをインストール
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# mod_rewrite を有効化（フロントコントローラー用）
RUN a2enmod rewrite

# Apache の AllowOverride を設定
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# タイムゾーン設定
RUN echo "date.timezone = Asia/Tokyo" >> /usr/local/etc/php/php.ini
RUN echo "session.cookie_httponly = On" >> /usr/local/etc/php/php.ini
RUN echo "session.use_strict_mode = On" >> /usr/local/etc/php/php.ini

# セッションセキュリティ設定
RUN echo "session.cookie_samesite = Lax" >> /usr/local/etc/php/php.ini

WORKDIR /var/www/html
