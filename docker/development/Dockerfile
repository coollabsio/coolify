# Versions
# https://hub.docker.com/r/serversideup/php/tags?name=8.4-fpm-nginx-alpine
ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine
# https://github.com/minio/mc/releases
ARG MINIO_VERSION=RELEASE.2024-11-17T19-35-25Z
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2024.11.1
# https://www.postgresql.org/support/versioning/
ARG POSTGRES_VERSION=15

# =================================================================
# Get MinIO client
# =================================================================
FROM minio/mc:${MINIO_VERSION} AS minio-client

# =================================================================
# Final Stage: Production image
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION}

ARG USER_ID
ARG GROUP_ID
ARG TARGETPLATFORM
ARG POSTGRES_VERSION
ARG CLOUDFLARED_VERSION

WORKDIR /var/www/html

USER root

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# Install PostgreSQL repository and keys
RUN apk add --no-cache gnupg && \
    mkdir -p /usr/share/keyrings && \
    curl -fSsL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor > /usr/share/keyrings/postgresql.gpg

# Install system dependencies
RUN apk add --no-cache \
    postgresql${POSTGRES_VERSION}-client \
    openssh-client \
    git \
    git-lfs \
    jq \
    lsof \
    vim

# Configure shell aliases
RUN echo "alias ll='ls -al'" >> /etc/profile && \
    echo "alias a='php artisan'" >> /etc/profile && \
    echo "alias logs='tail -f storage/logs/laravel.log'" >> /etc/profile

# Install Cloudflared based on architecture
RUN mkdir -p /usr/local/bin && \
    if [ "${TARGETPLATFORM}" = "linux/amd64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64" -o /usr/local/bin/cloudflared; \
    elif [ "${TARGETPLATFORM}" = "linux/arm64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64" -o /usr/local/bin/cloudflared; \
    fi && \
    chmod +x /usr/local/bin/cloudflared

# Configure PHP
COPY docker/development/etc/php/conf.d/zzz-custom-php.ini /usr/local/etc/php/conf.d/zzz-custom-php.ini
ENV PHP_OPCACHE_ENABLE=0

# Configure Nginx and S6 overlay
COPY docker/development/etc/nginx/conf.d/custom.conf /etc/nginx/conf.d/custom.conf
COPY docker/development/etc/nginx/site-opts.d/http.conf /etc/nginx/site-opts.d/http.conf
COPY --chmod=755 docker/development/etc/s6-overlay/ /etc/s6-overlay/

RUN mkdir -p /etc/nginx/conf.d && \
    chown -R www-data:www-data /etc/nginx && \
    chmod -R 755 /etc/nginx

# Install MinIO client
COPY --from=minio-client /usr/bin/mc /usr/bin/mc
RUN chmod +x /usr/bin/mc

# Switch to non-root user
USER www-data
