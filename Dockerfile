FROM php:8.1-fpm-bookworm

# Proxy support (pass via build args, leave empty if not needed)
ARG HTTP_PROXY=""
ARG HTTPS_PROXY=""
ENV http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=localhost,127.0.0.1,db

# Environment variables for APT
ENV DEBIAN_FRONTEND=noninteractive

# Optimization for apt
RUN echo "APT::Install-Recommends \"0\";" > /etc/apt/apt.conf.d/01norecommend && \
    echo "APT::Install-Suggests \"0\";" >> /etc/apt/apt.conf.d/01norecommend

# Dependencies for Moodle
RUN apt-get update && apt-get install -y --no-install-recommends \
    apt-transport-https \
    gettext \
    gnupg \
    locales \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    libxslt1-dev \
    libsodium-dev \
    libpq-dev \
    libmemcached-dev \
    libuuid1 \
    uuid-dev \
    unzip \
    git \
    postgresql-client \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Generate locales
RUN echo 'en_US.UTF-8 UTF-8' > /etc/locale.gen && \
    echo 'es_ES.UTF-8 UTF-8' >> /etc/locale.gen && \
    echo 'es_CL.UTF-8 UTF-8' >> /etc/locale.gen && \
    locale-gen

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    exif \
    intl \
    opcache \
    pgsql \
    soap \
    xsl \
    sodium \
    zip \
    mysqli \
    pdo_pgsql \
    mbstring \
    bcmath

# GD extension
RUN docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ && \
    docker-php-ext-install -j$(nproc) gd

# PECL extensions (configure proxy for pecl if set)
RUN if [ -n "${HTTP_PROXY}" ]; then pear config-set http_proxy ${HTTP_PROXY}; fi && \
    pecl install memcached redis apcu igbinary uuid && \
    docker-php-ext-enable memcached redis apcu igbinary uuid

# Moodle data directories
RUN mkdir -p /var/www/moodledata && \
    chown -R www-data:www-data /var/www/moodledata && \
    chmod -R 777 /var/www/moodledata

# Install Moodle Core
ARG MOODLE_VERSION
RUN git clone --depth 1 --branch ${MOODLE_VERSION} https://github.com/moodle/moodle.git /var/www/html && \
    chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

# Clear proxy from runtime
ENV http_proxy="" https_proxy="" no_proxy=""

COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
