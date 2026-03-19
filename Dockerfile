# Use official PHP image with Apache
FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
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

# Start Apache in foreground
CMD ["apache2-foreground"]
