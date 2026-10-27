FROM php:8.4-cli

# Dependencias del sistema y extensiones de PHP:
# - pdo_pgsql: conexión a PostgreSQL.
# - gd (con JPEG, PNG y WebP): reprocesar las fotos de tickets. Al volver a guardar la imagen se
#   descartan sus metadatos EXIF (incluida la ubicación GPS del teléfono) y se comprueba que el
#   archivo sea de verdad una imagen y no algo disfrazado con extensión .jpg.
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql gd \
    && rm -rf /var/lib/apt/lists/*

# Copiar Composer oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
