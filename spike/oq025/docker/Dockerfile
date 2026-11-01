# OQ-025 spike image: one Dockerfile for Moodle 4.3 and 5.2.
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-apache
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libicu-dev libxml2-dev libonig-dev curl \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip intl opcache mysqli soap mbstring exif
RUN a2enmod rewrite && echo "ServerName localhost" >> /etc/apache2/apache2.conf
ARG MOODLE_TGZ_URL
RUN cd /tmp && curl -fsSL -o moodle.tgz "${MOODLE_TGZ_URL}" && sha256sum moodle.tgz \
    && tar -xzf moodle.tgz && cp -a moodle/. /var/www/html/ && rm -rf moodle moodle.tgz
# 5.x serves from public/, 4.3 from the root.
ARG MOODLE_DOCROOT=/var/www/html
RUN if [ "$MOODLE_DOCROOT" != "/var/www/html" ]; then \
      sed -i "s|DocumentRoot /var/www/html|DocumentRoot ${MOODLE_DOCROOT}|" /etc/apache2/sites-available/000-default.conf; fi \
    && printf '<Directory %s>\n  AllowOverride All\n  Require all granted\n</Directory>\n' "$MOODLE_DOCROOT" > /etc/apache2/conf-available/moodle.conf \
    && a2enconf moodle
RUN mkdir -p /var/moodledata && chown -R www-data:www-data /var/moodledata /var/www/html
COPY php.ini /usr/local/etc/php/conf.d/spike.ini
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
WORKDIR /var/www/html
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
