FROM php:8.3-fpm-alpine AS app

# Install dependencies including Python3 and Chromium for automation
RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev \
    icu-dev \
    icu-libs \
    python3 \
    py3-pip \
    chromium \
    chromium-chromedriver \
    nss \
    freetype \
    harfbuzz \
    ttf-freefont

# Configure and install PHP extensions
RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        bcmath \
        mbstring \
        opcache \
        intl

# Install Redis extension via PECL
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
# Append streaming timeout override — loads after www.conf (alphabetically last)
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zzz-streaming.conf

WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies (skip scripts - will run in entrypoint)
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

# Copy application code
COPY . .

# Install Python dependencies for automation scripts
COPY requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir --break-system-packages -r /tmp/requirements.txt

# Set Chromium environment variables for undetected-chromedriver
ENV CHROME_BIN=/usr/bin/chromium-browser \
    CHROMEDRIVER_PATH=/usr/bin/chromedriver \
    UC_DRIVER_CACHE=/tmp/uc_driver_cache

# Create undetected-chromedriver cache directory with proper permissions
RUN mkdir -p /tmp/uc_driver_cache \
    && chmod 777 /tmp/uc_driver_cache \
    && mkdir -p /tmp/iptv_sessions \
    && chmod 777 /tmp/iptv_sessions

# Set permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x scripts/*.py 2>/dev/null || true

# Copy and set entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]

# -----------------------------------------------
# Nginx stage - copies full Laravel app from app stage
# -----------------------------------------------
FROM nginx:alpine AS nginx

# Copy entire application from app stage
# Laravel's public/index.php needs vendor/, bootstrap/, and storage/ to work
COPY --from=app /var/www/html /var/www/html

# Copy nginx config
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Create nginx user and set permissions
RUN chown -R nginx:nginx /var/www/html

# Explicitly start nginx in foreground
CMD ["nginx", "-g", "daemon off;"]
