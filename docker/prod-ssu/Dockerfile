FROM node:19 as static-assets
WORKDIR /app
COPY . .
RUN npm install
RUN npm run build

FROM serversideup/php:8.2-fpm-nginx
WORKDIR /var/www/html
ARG POSTGRES_VERSION=15
RUN apt-get update && apt-get install -y php-pgsql openssh-client git git-lfs jq
RUN apt-get -y autoremove && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist

COPY --chown=9999:9999 . .
RUN composer dump-autoload

COPY --from=static-assets --chown=9999:9999 /app/public/build ./public/build
COPY --chmod=755 docker/prod-ssu/etc/s6-overlay/ /etc/s6-overlay/

RUN php artisan route:cache
RUN php artisan view:cache

RUN echo "alias ll='ls -al'" >>/etc/bash.bashrc
RUN echo "alias a='php artisan'" >>/etc/bash.bashrc
RUN echo "alias mfs='php artisan migrate:fresh --seed'" >>/etc/bash.bashrc
RUN echo "alias cda='composer dump-autoload'" >>/etc/bash.bashrc
RUN echo "alias run='./scripts/run'" >>/etc/bash.bashrc
