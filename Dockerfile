# Use official PHP image with Apache
FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    libcurl4-openssl-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        curl \
        mysqli \
        pdo_mysql \
        gd \
        zip \
        exif \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set working directory to Apache's document root
WORKDIR /var/www/html

# Copy application files to container
COPY . /var/www/html/

# Set proper permissions for Apache
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 644 /var/www/html/*.php 2>/dev/null || true

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Enable rate limiting module
RUN a2enmod ratelimit

# Configure rate limiting (global limits: 200 req/min, burst 50)
RUN echo '<Directory /var/www/html>\n\
    SetOutputFilter RATE_LIMIT\n\
    SetEnv rate-limit 200\n\
    SetEnv rate-initial-burst 50\n\
</Directory>' > /etc/apache2/conf-available/rate-limit.conf \
    && a2enconf rate-limit

# Start Apache in foreground
CMD ["apache2-foreground"]
