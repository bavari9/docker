FROM php:8.2-apache

# Install system dependencies and Tesseract OCR (Unlimited & Free)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    tesseract-ocr \
    tesseract-ocr-eng \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite modules if needed
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy your PHP script into the container (e.g., index.php)
COPY . /var/www/html/

# Expose port 80 (Render uses PORT environment variable, Apache handles it or configure port mapping)
ENV PORT=10000
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
