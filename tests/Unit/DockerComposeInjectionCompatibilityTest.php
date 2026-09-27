<?php

/**
 * validateDockerComposeForInjection() must block shell injection without rejecting valid Compose files.
 * Git-based Docker Compose applications and Services use the same rules.
 */
function composeTemplateFiles(): array
{
    $files = glob(dirname(__DIR__, 2).'/templates/compose/*.{yaml,yml}', GLOB_BRACE);

    return collect($files)->mapWithKeys(fn (string $file): array => [basename($file) => [$file]])->all();
}

test('every service template passes the Compose injection validator', function (string $file) {
    validateDockerComposeForInjection(file_get_contents($file));

    expect(true)->toBeTrue();
})->with(composeTemplateFiles());

test('the service template corpus is not empty', function () {
    expect(count(composeTemplateFiles()))->toBeGreaterThan(300);
});

test('realistic application Compose files pass the injection validator', function (string $compose) {
    validateDockerComposeForInjection($compose);

    expect(true)->toBeTrue();
})->with([
    'long syntax volumes, bind mounts and named volumes' => <<<'YAML'
services:
  app:
    build:
      context: ./apps/web
      dockerfile: Dockerfile.prod
      args:
        NODE_ENV: production
    volumes:
      - type: bind
        source: ./config/app.conf
        target: /etc/app/app.conf
        read_only: true
      - type: bind
        source: /srv/shared/uploads
        target: /app/uploads
      - type: volume
        source: app-cache
        target: /app/cache
        volume:
          nocopy: true
      - type: tmpfs
        target: /app/tmp
      - ./data:/app/data
      - ./logs:/app/logs:rw
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ~/backups:/backups
      - app-data:/var/lib/app
      - "./my data:/app/spaced"
volumes:
  app-cache:
  app-data:
    driver: local
YAML,
    'variables in images, environment, ports, labels, build contexts and volumes' => <<<'YAML'
services:
  api:
    image: ${REGISTRY:-ghcr.io}/acme/api:${API_TAG:-latest}
    build:
      context: ${BUILD_CONTEXT:-.}
      args:
        - VERSION=${VERSION}
    environment:
      DATABASE_URL: postgres://${DB_USER}:${DB_PASS:-secret}@db:5432/${DB_NAME}
      FEATURE_FLAGS: "${FLAGS:-a,b;c|d&e}"
      SERVICE_FQDN_API_3000: /api
    ports:
      - "${API_PORT:-3000}:3000"
    labels:
      - traefik.http.routers.api.rule=Host(`${API_HOST}`) && PathPrefix(`/api`)
      - "com.example.description=Backend > frontend | edge"
    volumes:
      - ${DATA_DIR:-./data}:/data
      - ${HOME}/.config/api:/config:ro
      - type: bind
        source: ${CERT_DIR:-./certs}
        target: /certs
      - type: bind
        source: ${PWD}/secrets
        target: /run/secrets/app
YAML,
    'network names that mix variables and text' => <<<'YAML'
services:
  web:
    image: nginx:1.27
    networks:
      - project
      - env
      - plain
networks:
  project:
    name: ${COMPOSE_PROJECT_NAME}_default
  env:
    name: app-${APP_ENV:-prod}-net
  plain:
    external: true
    name: $SHARED_PREFIX.edge
YAML,
    'networks with variable name field and service network forms' => <<<'YAML'
services:
  web:
    image: nginx:1.27
    networks:
      - frontend
      - backend
  worker:
    image: acme/worker
    networks:
      backend:
        aliases:
          - queue-worker
        ipv4_address: 172.20.0.10
      shared:
networks:
  frontend:
  backend:
    driver: bridge
    ipam:
      config:
        - subnet: 172.20.0.0/16
  shared:
    external: true
    name: ${SHARED_NETWORK:-edge.proxy_net}
YAML,
    'anchors, merge keys, x- extensions and profiles' => <<<'YAML'
x-logging: &default-logging
  driver: json-file
  options:
    max-size: "10m"
x-common: &common
  restart: unless-stopped
  logging: *default-logging
  environment: &common-env
    TZ: UTC
  volumes:
    - ./shared:/shared:ro
services:
  web:
    <<: *common
    image: nginx
    profiles: [frontend]
  debug:
    <<: *common
    image: busybox
    profiles:
      - debug
    environment:
      <<: *common-env
      DEBUG: "1"
YAML,
    'depends_on conditions, healthchecks, commands and entrypoints with shell syntax' => <<<'YAML'
services:
  db:
    image: postgres:16
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB} || exit 1"]
      interval: 5s
      timeout: 5s
      retries: 10
  cache:
    image: redis:7
    command: redis-server --requirepass "$${REDIS_PASSWORD}" --save 60 1 > /dev/null
    healthcheck:
      test: redis-cli -a "$$REDIS_PASSWORD" ping | grep PONG && echo ok; exit 0
  app:
    image: acme/app
    entrypoint: ["/bin/sh", "-c"]
    command:
      - |
        until nc -z db 5432; do sleep 1; done
        php artisan migrate --force && exec php-fpm < /dev/null
    working_dir: /var/www
    depends_on:
      db:
        condition: service_healthy
      cache:
        condition: service_started
        restart: true
  cron:
    image: acme/app
    command: sh -c 'echo "$(date) start" >> /tmp/cron.log & crond -f'
    depends_on: [app]
YAML,
    'configs, secrets, service names with dots and dashes' => <<<'YAML'
services:
  api.v2:
    image: acme/api
    secrets:
      - db_password
    configs:
      - source: app_config
        target: /etc/app/config.yml
  web-frontend_1:
    image: acme/web
    container_name: my-web
secrets:
  db_password:
    file: ./secrets/db_password.txt
configs:
  app_config:
    content: |
      key: value; other | thing
YAML,
]);

test('Compose injection payloads are rejected', function (string $compose, string $message) {
    expect(fn () => validateDockerComposeForInjection($compose))->toThrow(Exception::class, $message);
})->with([
    'service name command substitution' => ["services:\n  web\$(touch /tmp/pwned):\n    image: nginx\n", 'Invalid Docker Compose service name'],
    'service name command separator' => ["services:\n  'web;touch /tmp/pwned':\n    image: nginx\n", 'Invalid Docker Compose service name'],
    'service name backtick' => ["services:\n  'web`id`':\n    image: nginx\n", 'Invalid Docker Compose service name'],
    'string volume source' => ["services:\n  web:\n    image: nginx\n    volumes:\n      - './data\$(touch /tmp/pwned):/data'\n", 'Invalid Docker volume definition'],
    'long volume source' => ["services:\n  web:\n    image: nginx\n    volumes:\n      - type: bind\n        source: '/srv;touch /tmp/pwned'\n        target: /data\n", 'Invalid Docker volume definition'],
    'long volume variable default' => ["services:\n  web:\n    image: nginx\n    volumes:\n      - type: bind\n        source: '\${DATA:-/srv\$(touch /tmp/pwned)}'\n        target: /data\n", 'Invalid Docker volume definition'],
    'service network key' => ["services:\n  web:\n    image: nginx\n    networks:\n      'net\$(touch /tmp/pwned)': {}\n", 'Invalid Docker Compose service network'],
    'service network list item' => ["services:\n  web:\n    image: nginx\n    networks:\n      - 'net;touch /tmp/pwned'\n", 'Invalid Docker Compose service network'],
    'top-level network key' => ["services:\n  web:\n    image: nginx\nnetworks:\n  'net\$(touch /tmp/pwned)':\n    driver: bridge\n", 'Invalid Docker Compose network name'],
    'top-level network name field' => ["services:\n  web:\n    image: nginx\nnetworks:\n  edge:\n    name: 'edge;touch /tmp/pwned'\n", 'Invalid Docker Compose network name field'],
    'mixed network name with command substitution' => ["services:\n  web:\n    image: nginx\nnetworks:\n  edge:\n    name: '\${PREFIX}\$(touch /tmp/pwned)'\n", 'Invalid Docker Compose network name field'],
    'mixed network name with a separator' => ["services:\n  web:\n    image: nginx\nnetworks:\n  edge:\n    name: '\${PREFIX}_net;touch /tmp/pwned'\n", 'Invalid Docker Compose network name field'],
    'mixed network name with an unsafe default' => ["services:\n  web:\n    image: nginx\nnetworks:\n  edge:\n    name: 'app-\${ENV:-x y}'\n", 'Invalid Docker Compose network name field'],
    'network name variable with an unsafe default' => ["services:\n  web:\n    image: nginx\nnetworks:\n  edge:\n    name: '\${NET:-x\$(touch /tmp/pwned)}'\n", 'Invalid Docker Compose network name field'],
]);
