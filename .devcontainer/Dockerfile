# Sukat Kalusugan — Codespaces staging (QA + pen-test only, NEVER production).
# PHP 8.2 + Apache (docroot = public_html/) + mysqli. No secrets in this file.
FROM php:8.2-apache

# mysqli for get_db_connection(); rewrite+headers for public_html/.htaccess.
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli \
    && a2enmod rewrite headers

# Serve public_html/ directly (repo root stays out of the docroot).
ENV APACHE_DOCUMENT_ROOT=/workspace/public_html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf 2>/dev/null || true

WORKDIR /workspace
