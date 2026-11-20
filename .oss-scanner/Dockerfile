# Anthropic OSS Scanner image for Coolify.
#
# The scanner builds this file with the git checkout as context, then removes
# network access. Everything an offline audit needs (Composer, including
# require-dev, and the Vite build) is fetched here.
#
# Contract: the checkout lives at /src. This is an audit environment, not the
# production runtime in docker/production/Dockerfile (no nginx, cloudflared,
# MinIO client, or terminal daemon).
#
# .dockerignore excludes vendor/, node_modules/, public/build/, and .env.
# Those are produced below. .env is copied from the committed .env.testing
# fixture (sqlite :memory:, array cache, sync queue). No production or Stripe
# credentials are written into the image.

FROM php:8.4-cli

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    GIT_TERMINAL_PROMPT=0 \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1

# Node 24 matches docker/production/Dockerfile. The extension installer is
# pinned so the PHP package set does not drift with an unpinned download.
ARG NODE_VERSION=24.22.1
ARG PHP_EXTENSION_INSTALLER_VERSION=2.12.3

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        xz-utils \
        pkg-config; \
    curl -fsSL "https://github.com/mlocati/docker-php-extension-installer/releases/download/${PHP_EXTENSION_INSTALLER_VERSION}/install-php-extensions" \
        -o /usr/local/bin/install-php-extensions; \
    chmod +x /usr/local/bin/install-php-extensions; \
    install-php-extensions \
        pdo_pgsql \
        pdo_mysql \
        pdo_sqlite \
        redis \
        gd \
        zip \
        bcmath \
        intl \
        sockets \
        pcntl \
        opcache \
        gmp; \
    php -r 'foreach (["pdo_pgsql","pdo_mysql","pdo_sqlite","redis","gd","zip","bcmath","intl","sockets","pcntl","gmp"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "missing php extension: {$ext}\n"); exit(1); } }'; \
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php; \
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --2; \
    rm /tmp/composer-setup.php; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
        amd64) node_arch="x64" ;; \
        arm64) node_arch="arm64" ;; \
        *) echo "Unsupported architecture: $arch" >&2; exit 1 ;; \
    esac; \
    curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-${node_arch}.tar.xz" \
        -o /tmp/node.tar.xz; \
    tar -xJf /tmp/node.tar.xz -C /usr/local --strip-components=1; \
    rm /tmp/node.tar.xz; \
    node --version; \
    npm --version; \
    composer --version; \
    rm -rf /var/lib/apt/lists/*

COPY . /src
WORKDIR /src

# .env.testing already has a committed APP_KEY used by Pest. Generate a dummy
# key only if that line is empty. Never copy .env.production or live secrets.
RUN set -eux; \
    cp .env.testing .env; \
    if ! grep -Eq '^APP_KEY=.+' .env; then \
        echo "APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));')" >> .env; \
    fi; \
    mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        storage/app \
        bootstrap/cache; \
    chmod -R a+rwX storage bootstrap/cache; \
    git config --global --add safe.directory /src

# post-autoload-dump runs `php artisan package:discover`. The testing env
# uses sqlite :memory:, an array cache, and the sync queue, so this does not
# need a live Postgres or Redis.
RUN composer install --no-interaction --prefer-dist --no-progress \
    && php artisan --version

# Playwright's npm package is installed from the lockfile. Browser binaries
# are skipped: the audit target is the PHP/Laravel app, and the network is
# gone after this build. PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD is set above.
RUN npm ci \
    && npm run build \
    && { test -f public/build/manifest.json || test -f public/build/.vite/manifest.json; }

# Cheap Laravel boots against sqlite :memory: (see phpunit.xml). A failing
# test must not abort the image build.
RUN php artisan test --compact \
        tests/Unit/ValidServerIpTest.php \
        tests/Unit/ValidGitRepositoryUrlTest.php \
    || echo "WARNING: cheap unit tests reported failures"
