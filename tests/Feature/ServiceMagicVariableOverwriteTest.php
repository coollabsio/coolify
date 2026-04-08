<?php

/**
 * Feature tests to verify that magic (referenced) SERVICE_URL_/SERVICE_FQDN_
 * variables do not overwrite values set by direct template declarations or updateCompose().
 *
 * This tests the fix for GitHub issue #8912 where generic SERVICE_URL and SERVICE_FQDN
 * variables remained stale after changing a service domain in the UI, while
 * port-specific variants updated correctly.
 *
 * Root cause: The magic variables section in serviceParser() used updateOrCreate()
 * which overwrote values from direct template declarations with auto-generated FQDNs.
 * Fix: Changed to firstOrCreate() so magic references don't overwrite existing values.
 *
 * IMPORTANT: These tests require database access and must be run inside Docker:
 * docker exec coolify php artisan test --filter ServiceMagicVariableOverwriteTest
 */

use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('generic SERVICE_URL/FQDN vars update after domain change when referenced by other services', function () {
    $server = Server::factory()->create([
        'name' => 'test-server',
        'ip' => '127.0.0.1',
    ]);

    // Compose template where:
    // - nginx directly declares SERVICE_FQDN_NGINX_8080 (Section 1)
    // - backend references ${SERVICE_URL_NGINX} and ${SERVICE_FQDN_NGINX} (Section 2 - magic)
    $template = <<<'YAML'
services:
  nginx:
    image: nginx:latest
    environment:
      - SERVICE_FQDN_NGINX_8080
    ports:
      - "8080:80"
  backend:
    image: node:20-alpine
    environment:
      - PUBLIC_URL=${SERVICE_URL_NGINX}
      - PUBLIC_FQDN=${SERVICE_FQDN_NGINX}
YAML;

    $service = Service::factory()->create([
        'server_id' => $server->id,
        'name' => 'test-service',
        'docker_compose_raw' => $template,
    ]);

    $serviceApp = ServiceApplication::factory()->create([
        'service_id' => $service->id,
        'name' => 'nginx',
        'fqdn' => null,
    ]);

    // Initial parse - generates auto FQDNs
    $service->parse();

    $baseUrl = $service->environment_variables()->where('key', 'SERVICE_URL_NGINX')->first();
    $baseFqdn = $service->environment_variables()->where('key', 'SERVICE_FQDN_NGINX')->first();
    $portUrl = $service->environment_variables()->where('key', 'SERVICE_URL_NGINX_8080')->first();
    $portFqdn = $service->environment_variables()->where('key', 'SERVICE_FQDN_NGINX_8080')->first();

    // All four variables should exist after initial parse
    expect($baseUrl)->not->toBeNull('SERVICE_URL_NGINX should exist');
    expect($baseFqdn)->not->toBeNull('SERVICE_FQDN_NGINX should exist');
    expect($portUrl)->not->toBeNull('SERVICE_URL_NGINX_8080 should exist');
    expect($portFqdn)->not->toBeNull('SERVICE_FQDN_NGINX_8080 should exist');

    // Now simulate user changing domain via UI (EditDomain::submit flow)
    $serviceApp->fqdn = 'https://my-nginx.example.com:8080';
    $serviceApp->save();

    // updateCompose() runs first (sets correct values)
    updateCompose($serviceApp);

    // Then parse() runs (should NOT overwrite the correct values)
    $service->parse();

    // Reload all variables
    $baseUrl = $service->environment_variables()->where('key', 'SERVICE_URL_NGINX')->first();
    $baseFqdn = $service->environment_variables()->where('key', 'SERVICE_FQDN_NGINX')->first();
    $portUrl = $service->environment_variables()->where('key', 'SERVICE_URL_NGINX_8080')->first();
    $portFqdn = $service->environment_variables()->where('key', 'SERVICE_FQDN_NGINX_8080')->first();

    // ALL variables should reflect the custom domain
    expect($baseUrl->value)->toBe('https://my-nginx.example.com')
        ->and($baseFqdn->value)->toBe('my-nginx.example.com')
        ->and($portUrl->value)->toBe('https://my-nginx.example.com:8080')
        ->and($portFqdn->value)->toBe('my-nginx.example.com:8080');
})->skip('Requires database - run in Docker');

test('magic variable references do not overwrite direct template declarations on initial parse', function () {
    $server = Server::factory()->create([
        'name' => 'test-server',
        'ip' => '127.0.0.1',
    ]);

    // Backend references the port-specific variable via magic syntax
    $template = <<<'YAML'
services:
  app:
    image: nginx:latest
    environment:
      - SERVICE_FQDN_APP_3000
    ports:
      - "3000:3000"
  worker:
    image: node:20-alpine
    environment:
      - API_URL=${SERVICE_URL_APP_3000}
YAML;

    $service = Service::factory()->create([
        'server_id' => $server->id,
        'name' => 'test-service',
        'docker_compose_raw' => $template,
    ]);

    ServiceApplication::factory()->create([
        'service_id' => $service->id,
        'name' => 'app',
        'fqdn' => null,
    ]);

    // Parse the service
    $service->parse();

    $portUrl = $service->environment_variables()->where('key', 'SERVICE_URL_APP_3000')->first();
    $portFqdn = $service->environment_variables()->where('key', 'SERVICE_FQDN_APP_3000')->first();

    // Port-specific vars should have port as a URL port suffix (:3000),
    // NOT baked into the subdomain (app-3000-uuid.sslip.io)
    expect($portUrl)->not->toBeNull();
    expect($portFqdn)->not->toBeNull();
    expect($portUrl->value)->toContain(':3000');
    // The domain should NOT have 3000 in the subdomain
    $urlWithoutPort = str($portUrl->value)->before(':3000')->value();
    expect($urlWithoutPort)->not->toContain('3000');
})->skip('Requires database - run in Docker');

test('parsers.php uses firstOrCreate for magic variable references', function () {
    $parsersFile = file_get_contents(base_path('bootstrap/helpers/parsers.php'));

    // Find the magic variables section (Section 2) which processes ${SERVICE_*} references
    // It should use firstOrCreate, not updateOrCreate, to avoid overwriting values
    // set by direct template declarations (Section 1) or updateCompose()

    // Look for the specific pattern: the magic variables section creates FQDN and URL pairs
    // after the "Also create the paired SERVICE_URL_*" and "Also create the paired SERVICE_FQDN_*" comments

    // Extract the magic variables section (between "$magicEnvironments->count()" and the end of the foreach)
    $magicSectionStart = strpos($parsersFile, '$magicEnvironments->count() > 0');
    expect($magicSectionStart)->not->toBeFalse('Magic variables section should exist');

    $magicSection = substr($parsersFile, $magicSectionStart, 5000);

    // Count updateOrCreate vs firstOrCreate in the magic section
    $updateOrCreateCount = substr_count($magicSection, 'updateOrCreate');
    $firstOrCreateCount = substr_count($magicSection, 'firstOrCreate');

    // Magic section should use firstOrCreate for SERVICE_URL/FQDN variables
    expect($firstOrCreateCount)->toBeGreaterThanOrEqual(4, 'Magic variables section should use firstOrCreate for SERVICE_URL/FQDN pairs')
        ->and($updateOrCreateCount)->toBe(0, 'Magic variables section should not use updateOrCreate for SERVICE_URL/FQDN variables');
});
