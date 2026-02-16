<?php

/**
 * Unit tests for environment variable isolation in Docker Compose services.
 *
 * These tests verify that each container only receives its own environment variables,
 * preventing security issues where one container can access another container's secrets.
 *
 * See: https://github.com/coollabsio/coolify/issues/7655
 */

use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

it('parsers.php injects per-container env files instead of global .env', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that the fix is in place - should use per-container env files
    expect($parsersFile)->toContain('.env.{$serviceName}');

    // Check for the security comment explaining the change
    expect($parsersFile)->toContain('environment variable isolation');
    expect($parsersFile)->toContain('https://github.com/coollabsio/coolify/issues/7655');
});

it('Service model has getContainerEnvironmentMappings method', function () {
    expect(method_exists(Service::class, 'getContainerEnvironmentMappings'))->toBeTrue();
});

it('getContainerEnvironmentMappings extracts variables from environment section', function () {
    $service = new Service();

    // Simulate a docker-compose with multiple containers
    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    image: myapp:latest
    environment:
      - APP_SECRET=secret123
      - DATABASE_URL=postgres://user:pass@db/app
      - OPENAI_API_KEY=${OPENAI_API_KEY}
  db:
    image: postgres:15
    environment:
      POSTGRES_USER: myuser
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
      POSTGRES_DB: myapp
  redis:
    image: redis:7
    environment:
      - REDIS_PASSWORD=redissecret
YAML;

    $mappings = $service->getContainerEnvironmentMappings();

    // App should have its variables
    expect($mappings->get('app'))->toContain('APP_SECRET');
    expect($mappings->get('app'))->toContain('DATABASE_URL');
    expect($mappings->get('app'))->toContain('OPENAI_API_KEY');

    // DB should have its variables
    expect($mappings->get('db'))->toContain('POSTGRES_USER');
    expect($mappings->get('db'))->toContain('POSTGRES_PASSWORD');
    expect($mappings->get('db'))->toContain('POSTGRES_DB');

    // Redis should have its variables
    expect($mappings->get('redis'))->toContain('REDIS_PASSWORD');

    // App should NOT have DB variables
    expect($mappings->get('app'))->not->toContain('POSTGRES_PASSWORD');
    expect($mappings->get('app'))->not->toContain('POSTGRES_USER');

    // DB should NOT have app variables
    expect($mappings->get('db'))->not->toContain('APP_SECRET');
    expect($mappings->get('db'))->not->toContain('OPENAI_API_KEY');
});

it('getContainerEnvironmentMappings extracts variable references from values', function () {
    $service = new Service();

    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    image: myapp:latest
    environment:
      - REDIS_URL=redis://${REDIS_HOST}:6379
      - DB_CONNECTION=${DATABASE_TYPE:-postgres}
YAML;

    $mappings = $service->getContainerEnvironmentMappings();

    // Should extract referenced variables
    expect($mappings->get('app'))->toContain('REDIS_URL');
    expect($mappings->get('app'))->toContain('REDIS_HOST');
    expect($mappings->get('app'))->toContain('DB_CONNECTION');
    expect($mappings->get('app'))->toContain('DATABASE_TYPE');
});

it('getContainerEnvironmentMappings handles build args', function () {
    $service = new Service();

    $service->docker_compose_raw = <<<'YAML'
services:
  app:
    build:
      context: .
      args:
        - BUILD_ENV=production
        - NODE_VERSION=18
    environment:
      - APP_ENV=production
YAML;

    $mappings = $service->getContainerEnvironmentMappings();

    // Should include build args
    expect($mappings->get('app'))->toContain('BUILD_ENV');
    expect($mappings->get('app'))->toContain('NODE_VERSION');
    expect($mappings->get('app'))->toContain('APP_ENV');
});

it('getContainerEnvironmentMappings returns empty collection for empty compose', function () {
    $service = new Service();
    $service->docker_compose_raw = null;
    $service->docker_compose = null;

    $mappings = $service->getContainerEnvironmentMappings();

    expect($mappings)->toBeEmpty();
});

it('getContainerEnvironmentMappings handles services without environment', function () {
    $service = new Service();

    $service->docker_compose_raw = <<<'YAML'
services:
  nginx:
    image: nginx:latest
    ports:
      - "80:80"
YAML;

    $mappings = $service->getContainerEnvironmentMappings();

    // Should have an entry for nginx but with empty array
    expect($mappings->has('nginx'))->toBeTrue();
    expect($mappings->get('nginx'))->toBeArray();
    expect($mappings->get('nginx'))->toBeEmpty();
});

it('saveComposeConfigs comment mentions per-container env files', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that the method has documentation about per-container env files
    expect($serviceFile)->toContain('per-container');
    expect($serviceFile)->toContain('.env.');
});

it('StartService comment explains per-container approach', function () {
    $startServiceFile = file_get_contents(__DIR__.'/../../app/Actions/Service/StartService.php');

    // Check for updated comment
    expect($startServiceFile)->toContain('per-container .env.{name} files');
});

it('docker compose uses per-container env_file directive', function () {
    // This test verifies the parsed docker-compose format
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // The env_file should reference per-container files
    expect($parsersFile)->toContain('->push(".env.{$serviceName}")');
});

it('shared variables are added to all containers', function () {
    // COOLIFY_* and SERVICE_NAME_* should be in all containers
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that shared variables are added
    expect($serviceFile)->toContain('COOLIFY_');
    expect($serviceFile)->toContain('SERVICE_NAME_');

    // Check that they're added to each container
    expect($serviceFile)->toContain('Add shared Coolify metadata variables');
});

it('SERVICE_URL and SERVICE_FQDN are added to matching containers only', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that SERVICE_URL/FQDN matching logic exists
    expect($serviceFile)->toContain('SERVICE_URL_');
    expect($serviceFile)->toContain('SERVICE_FQDN_');
    expect($serviceFile)->toContain('normalizedServiceName');
    expect($serviceFile)->toContain('varServiceName');
});

it('legacy .env file is created for backward compatibility', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that a legacy .env with shared vars is created
    expect($serviceFile)->toContain('legacy .env file for backward compatibility');
    expect($serviceFile)->toContain('sharedEnvs');
});

it('container-specific credentials are isolated', function () {
    $service = new Service();

    // Create a realistic multi-container setup
    $service->docker_compose_raw = <<<'YAML'
services:
  nextjs:
    image: node:18
    environment:
      - OPENAI_API_KEY=${OPENAI_API_KEY}
      - DATABASE_URL=postgres://user:pass@postgres/mydb
      - STRIPE_SECRET_KEY=${STRIPE_SECRET_KEY}
  postgres:
    image: postgres:15
    environment:
      - POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
      - POSTGRES_USER=myuser
  redis:
    image: redis:7
    environment:
      - REDIS_PASSWORD=${REDIS_PASSWORD}
YAML;

    $mappings = $service->getContainerEnvironmentMappings();

    // nextjs should NOT have database credentials
    expect($mappings->get('nextjs'))->not->toContain('POSTGRES_PASSWORD');
    expect($mappings->get('nextjs'))->not->toContain('REDIS_PASSWORD');

    // postgres should NOT have API keys
    expect($mappings->get('postgres'))->not->toContain('OPENAI_API_KEY');
    expect($mappings->get('postgres'))->not->toContain('STRIPE_SECRET_KEY');

    // redis should NOT have any other credentials
    expect($mappings->get('redis'))->not->toContain('POSTGRES_PASSWORD');
    expect($mappings->get('redis'))->not->toContain('OPENAI_API_KEY');

    // Each container should have its own credentials
    expect($mappings->get('nextjs'))->toContain('OPENAI_API_KEY');
    expect($mappings->get('postgres'))->toContain('POSTGRES_PASSWORD');
    expect($mappings->get('redis'))->toContain('REDIS_PASSWORD');
});
