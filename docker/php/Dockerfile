FROM php:7.4-fpm

RUN apt-get update && apt-get install -y \
    curl \
    libpcre3-dev \
    gcc \
    make \
    re2c \
    git \
    libssl-dev \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    libxml2-dev \
    libedit-dev \
    libsqlite3-dev \
    zlib1g-dev \
    libicu-dev \
    libonig-dev \
    unzip \
    autoconf \
    libpng-dev \
    imagemagick \
    libmagickwand-dev \
    libwebp-dev \
    libzip-dev

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN pecl channel-update pecl.php.net
RUN pecl install psr redis imagick phalcon-4.1.2 xdebug-3.1.0

RUN docker-php-ext-configure gd && \
    docker-php-ext-configure \
            gd --enable-gd --with-freetype --with-jpeg --with-webp

RUN docker-php-ext-install gettext gd pdo_mysql bcmath zip

COPY docker/php/extensions/. /usr/local/etc/php/conf.d/.

# Install project dependencies
WORKDIR /var/www/html
COPY . .

RUN php /usr/local/bin/composer install

CMD ["php-fpm"]
