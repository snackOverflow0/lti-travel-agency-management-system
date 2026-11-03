
FROM node:22-alpine AS frontend
WORKDIR /build
COPY web/package*.json ./
RUN npm ci
COPY web/ ./
RUN npm run build

FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql mysqli \
    && a2enmod rewrite headers

COPY server/app/ /var/www/app/
COPY server/public/ /var/www/html/
COPY --from=frontend /build/dist/ /var/www/html/

RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    'AllowOverride All' \
    'Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/lti.conf \
    && a2enconf lti

ENV APACHE_DOCUMENT_ROOT=/var/www/html
ENV LTI_APP_DIR=/var/www/app
ENV LTI_CONFIG=/var/www/app/config/config.render.php
ENV PORT=80
EXPOSE 80
