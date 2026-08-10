# Versions
# https://hub.docker.com/r/serversideup/php/tags?name=8.4-fpm-nginx-alpine
ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine
# https://github.com/minio/mc/releases
ARG MINIO_VERSION=RELEASE.2025-05-21T01-59-54Z
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2025.7.0
# https://github.com/coollabsio/coold/releases/tag/nightly
ARG COOLIFY_FLUX_VERSION=nightly
ARG COOLIFY_CLI_VERSION=nightly
# https://www.postgresql.org/support/versioning/
# Note: We are using version 18 of the postgres client (while still using postgres 15 for the postgres server) as version 15 has been removed from Alpine 3.23+ https://pkgs.alpinelinux.org/packages?name=postgresql*-client&branch=v3.23&repo=&arch=x86_64&origin=&flagged=&maintainer=
ARG POSTGRES_VERSION=18
# https://nginx.org/en/linux_packages.html
ARG NGINX_VERSION=1.31.2-r1

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
ARG TARGETARCH
ARG POSTGRES_VERSION
ARG CLOUDFLARED_VERSION
ARG COOLIFY_FLUX_VERSION
ARG COOLIFY_FLUX_CHECKSUM
ARG COOLIFY_CLI_VERSION
ARG COOLIFY_CLI_CHECKSUM
ARG NGINX_VERSION

WORKDIR /var/www/html

USER root

# Install patched Nginx from the official nginx.org Alpine repository
RUN set -eux; \
    apk add --no-cache ca-certificates curl; \
    NGINX_ALPINE_VERSION="$(egrep -o '^[0-9]+\.[0-9]+' /etc/alpine-release)"; \
    NGINX_REPOSITORY="https://nginx.org/packages/mainline/alpine/v${NGINX_ALPINE_VERSION}/main"; \
    sed -i 's|http://nginx.org/packages|https://nginx.org/packages|g' /etc/apk/repositories; \
    grep -qxF "@nginx ${NGINX_REPOSITORY}" /etc/apk/repositories || echo "@nginx ${NGINX_REPOSITORY}" >> /etc/apk/repositories; \
    curl -fsSL https://nginx.org/keys/nginx_signing.rsa.pub -o /etc/apk/keys/nginx_signing.rsa.pub; \
    apk add --no-cache --upgrade "nginx@nginx=${NGINX_VERSION}"; \
    rm -f /etc/nginx/nginx.conf /etc/nginx/conf.d/default.conf; \
    nginx -v

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# Install PostgreSQL repository and keys
RUN apk upgrade --no-cache && \
    apk add --no-cache gnupg && \
    mkdir -p /usr/share/keyrings && \
    curl -fSsL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor > /usr/share/keyrings/postgresql.gpg


# Install system dependencies
RUN apk add --no-cache \
    postgresql${POSTGRES_VERSION}-client \
    openssh-client \
    openssl \
    git \
    git-lfs \
    jq \
    lsof \
    vim

# Install PHP extensions
RUN install-php-extensions sockets

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

# Install Flux from coold nightly release based on architecture
RUN set -eux; \
    echo "Flux checksum: ${COOLIFY_FLUX_CHECKSUM}"; \
    mkdir -p /usr/local/bin /run/coolify /etc/coolify; \
    chown -R www-data:www-data /run/coolify /etc/coolify; \
    case "${TARGETARCH:-}" in \
        amd64|arm64) FLUX_ARCH="${TARGETARCH}" ;; \
        "") \
            case "$(uname -m)" in \
                x86_64) FLUX_ARCH="amd64" ;; \
                aarch64) FLUX_ARCH="arm64" ;; \
                *) echo "unsupported Flux arch: $(uname -m)" >&2; exit 1 ;; \
            esac ;; \
        *) echo "unsupported Flux TARGETARCH: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL --retry 3 --max-time 120 \
        -o /tmp/flux.tar.gz \
        "https://github.com/coollabsio/coold/releases/download/${COOLIFY_FLUX_VERSION}/flux-linux-musl-${FLUX_ARCH}.tar.gz"; \
    tar -xzf /tmp/flux.tar.gz -C /tmp; \
    test -f /tmp/flux; \
    install -m 0755 /tmp/flux /usr/local/bin/flux; \
    rm -f /tmp/flux /tmp/flux.tar.gz

# Install coolify from coold nightly release based on architecture
RUN set -eux; \
    echo "Coolify CLI checksum: ${COOLIFY_CLI_CHECKSUM}"; \
    mkdir -p /usr/local/bin; \
    case "${TARGETARCH:-}" in \
        amd64|arm64) COOLIFY_CLI_ARCH="${TARGETARCH}" ;; \
        "") \
            case "$(uname -m)" in \
                x86_64) COOLIFY_CLI_ARCH="amd64" ;; \
                aarch64) COOLIFY_CLI_ARCH="arm64" ;; \
                *) echo "unsupported coolify arch: $(uname -m)" >&2; exit 1 ;; \
            esac ;; \
        *) echo "unsupported coolify TARGETARCH: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL --retry 3 --max-time 120 \
        -o /tmp/coolify.tar.gz \
        "https://github.com/coollabsio/coold/releases/download/${COOLIFY_CLI_VERSION}/coolify-linux-musl-${COOLIFY_CLI_ARCH}.tar.gz"; \
    tar -xzf /tmp/coolify.tar.gz -C /tmp; \
    test -f /tmp/coolify; \
    install -m 0755 /tmp/coolify /usr/local/bin/coolify; \
    rm -f /tmp/coolify /tmp/coolify.tar.gz

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

# Stay as root for s6 init so bind-mounted workspaces work even when the host
# tree is root-owned (CI, Jean, rootful Docker). PHP-FPM/nginx still run as
# www-data via their service configs. Do NOT set USER www-data here — that
# makes init-setup (composer install) fail with "vendor could not be created".
USER root
