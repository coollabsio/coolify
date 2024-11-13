# Versions
# https://hub.docker.com/r/serversideup/php/tags?name=8.3-fpm-nginx-alpine
ARG SERVERSIDEUP_PHP_VERSION=8.3-fpm-nginx-alpine
# https://github.com/minio/mc/releases
ARG MINIO_VERSION=RELEASE.2024-11-05T11-29-45Z
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2024.11.0
# https://www.postgresql.org/support/versioning/ - Can not updated automatically so keep it at 15
ARG POSTGRES_VERSION=15


FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION} AS base

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist


FROM node:20-alpine AS static-assets
WORKDIR /app
COPY . .
COPY --from=base --chown=www-data:www-data /var/www/html .
RUN npm ci && \
    npm run build


FROM minio/mc:${MINIO_VERSION} AS minio-client

FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION}

ARG TARGETPLATFORM
ARG POSTGRES_VERSION
ARG CLOUDFLARED_VERSION
ARG CI=true

WORKDIR /var/www/html

USER root

# Install PostgreSQL repository and key
RUN apk add --no-cache gnupg && \
    mkdir -p /usr/share/keyrings && \
    curl -fSsL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor > /usr/share/keyrings/postgresql.gpg

# Install only required packages that aren't already in the base image
RUN apk add --no-cache \
    postgresql${POSTGRES_VERSION}-client \
    openssh-client \
    git \
    git-lfs \
    jq \
    lsof

# Configure shell aliases
RUN echo "alias ll='ls -al'" >> /etc/profile && \
    echo "alias a='php artisan'" >> /etc/profile && \
    echo "alias logs='tail -f storage/logs/laravel.log'" >> /etc/profile

# Setup Cloudflared based on architecture
RUN mkdir -p /usr/local/bin && \
    if [ "${TARGETPLATFORM}" = "linux/amd64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64" -o /usr/local/bin/cloudflared; \
    elif [ "${TARGETPLATFORM}" = "linux/arm64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64" -o /usr/local/bin/cloudflared; \
    fi && \
    chmod +x /usr/local/bin/cloudflared

# Configure PHP upload limits
RUN echo 'upload_max_filesize=256M' > /usr/local/etc/php/conf.d/upload-limits.ini && \
    echo 'post_max_size=256M' >> /usr/local/etc/php/conf.d/upload-limits.ini

# Enable PHP OPcache
ENV PHP_OPCACHE_ENABLE=1

# Copy application files
COPY --chown=www-data:www-data . .
COPY --from=base --chown=www-data:www-data /var/www/html .
COPY --from=static-assets --chown=www-data:www-data /app/public/build ./public/build

# Add composer dump-autoload before artisan commands
RUN composer dump-autoload

# Copy configuration files
COPY docker/prod/nginx.conf /etc/nginx/conf.d/custom.conf
COPY --chmod=755 docker/prod/etc/s6-overlay/ /etc/s6-overlay/

# Install Minio client
COPY --from=minio-client /usr/bin/mc /usr/bin/mc
RUN chmod +x /usr/bin/mc

# Optimize Laravel
USER www-data
RUN php artisan route:clear && \
    php artisan view:clear && \
    php artisan config:clear && \
    php artisan route:cache && \
    php artisan view:cache && \
    php artisan config:cache
