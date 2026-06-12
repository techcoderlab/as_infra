# STAGE 1: Build & Composer
FROM composer:latest AS builder
WORKDIR /app
COPY . .
# Only install production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# STAGE 2: Production Runtime
FROM php:8.4-fpm-alpine
RUN apk add --no-cache \
    libpq-dev \
    linux-headers \
    # Only keep runtime-essential libraries
    && docker-php-ext-install pdo_pgsql pgsql pcntl bcmath opcache \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS pcre-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    # CRITICAL: Remove build-deps after install to save space
    && apk del .build-deps

WORKDIR /var/www/html

# Copy ONLY the application and the vendor folder from the builder stage
COPY --from=builder /app /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
