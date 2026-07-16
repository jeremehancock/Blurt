# Minimal image for Blurt. No build step, no dependencies — just PHP + Apache.
# Drops into a Docker / Coolify + Nginx Proxy Manager setup with no extra wiring.
FROM php:8.3-apache

# Point Apache at public/ as the document root so only that folder is web-facing.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copy the application in.
COPY . /var/www/html

# The web server writes blurts to data/ at runtime; give it ownership.
# data/ lives ABOVE the docroot, so it is never web-accessible.
RUN mkdir -p /var/www/html/data/blurts /var/www/html/data/hidden /var/www/html/data/rate \
    && chown -R www-data:www-data /var/www/html/data

EXPOSE 80

# Configure at runtime via environment variables (see README):
#   ADMIN_PASSWORD_HASH, APP_SALT, TRUSTED_PROXIES, SITE_TITLE, etc.
