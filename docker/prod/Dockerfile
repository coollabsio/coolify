FROM serversideup/php:8.2-fpm-nginx-v2.2.1 as base
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist

FROM node:20 as static-assets
WORKDIR /app
COPY . .
COPY --from=base --chown=9999:9999 /var/www/html .
RUN npm install
RUN npm run build

FROM serversideup/php:8.2-fpm-nginx-v2.2.1

ARG TARGETPLATFORM
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2024.4.1
ARG POSTGRES_VERSION=15
ARG CI=true

WORKDIR /var/www/html

RUN apt-get update
# Postgres version requirements
RUN apt install dirmngr ca-certificates software-properties-common gnupg gnupg2 apt-transport-https curl -y
RUN curl -fSsL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor | tee /usr/share/keyrings/postgresql.gpg > /dev/null

RUN echo deb [arch=amd64,arm64,ppc64el signed-by=/usr/share/keyrings/postgresql.gpg] http://apt.postgresql.org/pub/repos/apt/ jammy-pgdg main | tee -a /etc/apt/sources.list.d/postgresql.list

RUN apt-get update
RUN apt-get install postgresql-client-$POSTGRES_VERSION -y

# Coolify requirements
RUN apt-get install -y php8.2-pgsql openssh-client git git-lfs jq lsof vim
RUN apt-get -y autoremove && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

COPY docker/prod/nginx.conf /etc/nginx/conf.d/custom.conf

COPY --from=base --chown=9999:9999 /var/www/html .

COPY --chown=9999:9999 . .
RUN composer dump-autoload

COPY --from=static-assets --chown=9999:9999 /app/public/build ./public/build
COPY --chmod=755 docker/prod/etc/s6-overlay/ /etc/s6-overlay/

RUN php artisan route:cache
RUN php artisan view:cache

RUN echo "alias ll='ls -al'" >>/etc/bash.bashrc
RUN echo "alias a='php artisan'" >>/etc/bash.bashrc
RUN echo "alias logs='tail -f storage/logs/laravel.log'" >>/etc/bash.bashrc

RUN mkdir -p /usr/local/bin

RUN /bin/bash -c "if [[ ${TARGETPLATFORM} == 'linux/amd64' ]]; then \
    echo 'amd64' && \
    curl -sSL https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64 -o /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared \
    ;fi"

RUN /bin/bash -c "if [[ ${TARGETPLATFORM} == 'linux/arm64' ]]; then \
    echo 'arm64' && \
    curl -L https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64 -o /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared \
    ;fi"

RUN { \
    echo 'upload_max_filesize=256M'; \
    echo 'post_max_size=256M'; \
  } > /etc/php/current_version/cli/conf.d/upload-limits.ini

COPY --from=minio/mc:RELEASE.2024-09-09T07-53-10Z /usr/bin/mc /usr/bin/mc
RUN chmod +x /usr/bin/mc
