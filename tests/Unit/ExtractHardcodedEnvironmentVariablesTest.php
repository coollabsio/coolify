<?php

test('extracts simple environment variables from docker-compose', function () {
    $yaml = <<<'YAML'
services:
  app:
    image: nginx
    environment:
      - NODE_ENV=production
      - PORT=3000
YAML;

    $result = extractHardcodedEnvironmentVariables($yaml);

    expect($result)->toHaveCount(2)
        ->and($result[0]['key'])->toBe('NODE_ENV')
        ->and($result[0]['value'])->toBe('production')
        ->and($result[0]['service_name'])->toBe('app')
        ->and($result[1]['key'])->toBe('PORT')
        ->and($result[1]['value'])->toBe('3000')
        ->and($result[1]['service_name'])->toBe('app');
});

test('extracts environment variables with inline comments', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      - NODE_ENV=production  # Production environment
      - DEBUG=false  # Disable debug mode
YAML;

    $result = extractHardcodedEnvironmentVariables($yaml);

    expect($result)->toHaveCount(2)
        ->and($result[0]['comment'])->toBe('Production environment')
        ->and($result[1]['comment'])->toBe('Disable debug mode');
});

test('handles multiple services', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      - APP_ENV=prod
  db:
    environment:
      - POSTGRES_DB=mydb
YAML;

    $result = extractHardcodedEnvironmentVariables($yaml);

    expect($result)->toHaveCount(2)
        ->and($result[0]['key'])->toBe('APP_ENV')
        ->and($result[0]['service_name'])->toBe('app')
        ->and($result[1]['key'])->toBe('POSTGRES_DB')
        ->and($result[1]['service_name'])->toBe('db');
});

test('handles associative array format', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      NODE_ENV: production
      PORT: 3000
YAML;

    $result = extractHardcodedEnvironmentVariables($yaml);

    expect($result)->toHaveCount(2)
        ->and($result[0]['key'])->toBe('NODE_ENV')
        ->and($result[0]['value'])->toBe('production')
        ->and($result[1]['key'])->toBe('PORT')
        ->and($result[1]['value'])->toBe(3000); // Integer values stay as integers from YAML
});

test('handles environment variables without values', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      - API_KEY
      - DEBUG=false
YAML;

    $result = extractHardcodedEnvironmentVariables($yaml);

    expect($result)->toHaveCount(2)
        ->and($result[0]['key'])->toBe('API_KEY')
        ->and($result[0]['value'])->toBe('') // Variables without values get empty string, not null
        ->and($result[1]['key'])->toBe('DEBUG')
        ->and($result[1]['value'])->toBe('false');
});

test('returns empty collection for malformed YAML', function () {
    $yaml = 'invalid: yaml: content::: [[[';

    $result = extractHardcodedEnvironmentVariables($yaml);

    expect($result)->toBeEmpty();
});

test('returns empty collection for empty compose file', function () {
    $result = extractHardcodedEnvironmentVariables('');

    expect($result)->toBeEmpty();
});

test('returns empty collection when no services defined', function () {
    $yaml = <<<'YAML'
version: '3.8'
networks:
  default:
YAML;

    $result = extractHardcodedEnvironmentVariables($yaml);

    expect($result)->toBeEmpty();
});

test('returns empty collection when service has no environment section', function () {
    $yaml = <<<'YAML'
services:
  app:
    image: nginx
YAML;

    $result = extractHardcodedEnvironmentVariables($yaml);

    expect($result)->toBeEmpty();
});

test('handles mixed associative and array format', function () {
    $yaml = <<<'YAML'
services:
  app:
    environment:
      - NODE_ENV=production
      PORT: 3000
YAML;

    $result = extractHardcodedEnvironmentVariables($yaml);

    // Mixed format is invalid YAML and returns empty collection
    expect($result)->toBeEmpty();
});
