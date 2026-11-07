FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libicu-dev libpng-dev libwebp-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install mysqli mbstring intl gd zip opcache \
 && a2enmod rewrite headers deflate && rm -rf /var/lib/apt/lists/*

COPY deployment/apache/uii-static.conf /etc/apache2/conf-available/uii-static.conf
COPY deployment/php/uii-opcache.ini /usr/local/etc/php/conf.d/uii-opcache.ini
RUN a2enconf uii-static

COPY deployment/php/uii-uploads.ini /usr/local/etc/php/conf.d/uii-uploads.ini
COPY deployment/apache/uii-uploads.conf /etc/apache2/conf-available/uii-uploads.conf
RUN a2enconf uii-uploads

COPY deployment/apache/uii-errors.conf /etc/apache2/conf-available/uii-errors.conf
RUN a2enconf uii-errors
