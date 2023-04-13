FROM node:19 as static-assets
WORKDIR /app
COPY . .
RUN npm install
RUN npm run build

FROM serversideup/php:8.2-fpm-nginx
WORKDIR /var/www/html
ARG POSTGRES_VERSION=15
RUN apt-get update && apt-get install -y php-pgsql openssh-client git git-lfs
RUN apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

COPY --chmod=755 docker/prod-ssu/etc/s6-overlay/ /etc/s6-overlay/
COPY --chown=9999:9999 . .
COPY --from=static-assets --chown=9999:9999 /app/public/build /var/www/html/public/build

RUN composer install --no-dev --optimize-autoloader
RUN php artisan route:cache
RUN php artisan view:cache
