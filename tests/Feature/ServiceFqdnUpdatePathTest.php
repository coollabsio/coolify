<?php

/**
 * Feature tests to verify that FQDN updates don't cause path duplication
 * for services with path-based SERVICE_URL/SERVICE_FQDN template variables.
 *
 * This tests the fix for GitHub issue #7363 where Appwrite and MindsDB services
 * had their paths duplicated (e.g., /v1/realtime/v1/realtime) after FQDN updates.
 *
 * IMPORTANT: These tests require database access and must be run inside Docker:
 * docker exec coolify php artisan test --filter ServiceFqdnUpdatePathTest
 */

use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Appwrite realtime service does not duplicate path on FQDN update', function () {
    // Create a server
    $server = Server::factory()->create([
        'name' => 'test-server',
        'ip' => '127.0.0.1',
    ]);

    // Load Appwrite template
    $appwriteTemplate = file_get_contents(base_path('templates/compose/appwrite.yaml'));

    // Create Appwrite service
    $service = Service::factory()->create([
        'server_id' => $server->id,
        'name' => 'appwrite-test',
        'docker_compose_raw' => $appwriteTemplate,
    ]);

    // Create the appwrite-realtime service application
    $serviceApp = ServiceApplication::factory()->create([
        'service_id' => $service->id,
        'name' => 'appwrite-realtime',
        'fqdn' => 'https://test.abc/v1/realtime',
    ]);

    // Parse the service (simulates initial setup)
    $service->parse();

    // Get environment variable
    $urlVar = $service->environment_variables()
        ->where('key', 'SERVICE_URL_APPWRITE')
        ->first();

    // Initial setup should have path once
    expect($urlVar)->not->toBeNull()
        ->and($urlVar->value)->not->toContain('/v1/realtime/v1/realtime')
        ->and($urlVar->value)->toContain('/v1/realtime');

    // Simulate user updating FQDN
    $serviceApp->fqdn = 'https://newdomain.com/v1/realtime';
    $serviceApp->save();

    // Call parse again (this is where the bug occurred)
    $service->parse();

    // Check that path is not duplicated
    $urlVar = $service->environment_variables()
        ->where('key', 'SERVICE_URL_APPWRITE')
        ->first();

    expect($urlVar)->not->toBeNull()
        ->and($urlVar->value)->not->toContain('/v1/realtime/v1/realtime')
        ->and($urlVar->value)->toContain('/v1/realtime');
})->skip('Requires database and Appwrite template - run in Docker');

test('Appwrite console service does not duplicate /console path', function () {
    $server = Server::factory()->create();
    $appwriteTemplate = file_get_contents(base_path('templates/compose/appwrite.yaml'));
    $service = Service::factory()->create([
        'server_id' => $server->id,
        'docker_compose_raw' => $appwriteTemplate,
    ]);

    $serviceApp = ServiceApplication::factory()->create([
        'service_id' => $service->id,
        'name' => 'appwrite-console',
        'fqdn' => 'https://test.abc/console',
    ]);

    // Parse service
    $service->parse();

    // Update FQDN
    $serviceApp->fqdn = 'https://newdomain.com/console';
    $serviceApp->save();

    // Parse again
    $service->parse();

    // Verify no duplication
    $urlVar = $service->environment_variables()
        ->where('key', 'SERVICE_URL_APPWRITE')
        ->first();

    expect($urlVar)->not->toBeNull()
        ->and($urlVar->value)->not->toContain('/console/console')
        ->and($urlVar->value)->toContain('/console');
})->skip('Requires database and Appwrite template - run in Docker');

test('MindsDB service does not duplicate /api path', function () {
    $server = Server::factory()->create();
    $mindsdbTemplate = file_get_contents(base_path('templates/compose/mindsdb.yaml'));
    $service = Service::factory()->create([
        'server_id' => $server->id,
        'docker_compose_raw' => $mindsdbTemplate,
    ]);

    $serviceApp = ServiceApplication::factory()->create([
        'service_id' => $service->id,
        'name' => 'mindsdb',
        'fqdn' => 'https://test.abc/api',
    ]);

    // Parse service
    $service->parse();

    // Update FQDN multiple times
    $serviceApp->fqdn = 'https://domain1.com/api';
    $serviceApp->save();
    $service->parse();

    $serviceApp->fqdn = 'https://domain2.com/api';
    $serviceApp->save();
    $service->parse();

    // Verify no duplication after multiple updates
    $urlVar = $service->environment_variables()
        ->where('key', 'SERVICE_URL_API')
        ->orWhere('key', 'LIKE', 'SERVICE_URL_%')
        ->first();

    expect($urlVar)->not->toBeNull()
        ->and($urlVar->value)->not->toContain('/api/api');
})->skip('Requires database and MindsDB template - run in Docker');

test('service without path declaration is not affected', function () {
    $server = Server::factory()->create();

    // Create a simple service without path in template
    $simpleTemplate = <<<'YAML'
services:
  redis:
    image: redis:7
    environment:
      - SERVICE_FQDN_REDIS
YAML;

    $service = Service::factory()->create([
        'server_id' => $server->id,
        'docker_compose_raw' => $simpleTemplate,
    ]);

    $serviceApp = ServiceApplication::factory()->create([
        'service_id' => $service->id,
        'name' => 'redis',
        'fqdn' => 'https://redis.test.abc',
    ]);

    // Parse service
    $service->parse();

    $fqdnBefore = $service->environment_variables()
        ->where('key', 'SERVICE_FQDN_REDIS')
        ->first()?->value;

    // Update FQDN
    $serviceApp->fqdn = 'https://redis.newdomain.com';
    $serviceApp->save();

    // Parse again
    $service->parse();

    $fqdnAfter = $service->environment_variables()
        ->where('key', 'SERVICE_FQDN_REDIS')
        ->first()?->value;

    // Should work normally without issues
    expect($fqdnAfter)->toBe('redis.newdomain.com')
        ->and($fqdnAfter)->not->toContain('//');
})->skip('Requires database - run in Docker');

test('multiple FQDN updates never cause path duplication', function () {
    $server = Server::factory()->create();
    $appwriteTemplate = file_get_contents(base_path('templates/compose/appwrite.yaml'));
    $service = Service::factory()->create([
        'server_id' => $server->id,
        'docker_compose_raw' => $appwriteTemplate,
    ]);

    $serviceApp = ServiceApplication::factory()->create([
        'service_id' => $service->id,
        'name' => 'appwrite-realtime',
        'fqdn' => 'https://test.abc/v1/realtime',
    ]);

    // Update FQDN 10 times and parse each time
    for ($i = 1; $i <= 10; $i++) {
        $serviceApp->fqdn = "https://domain{$i}.com/v1/realtime";
        $serviceApp->save();
        $service->parse();

        // Check path is never duplicated
        $urlVar = $service->environment_variables()
            ->where('key', 'SERVICE_URL_APPWRITE')
            ->first();

        expect($urlVar)->not->toBeNull()
            ->and($urlVar->value)->not->toContain('/v1/realtime/v1/realtime')
            ->and($urlVar->value)->toContain('/v1/realtime');
    }
})->skip('Requires database and Appwrite template - run in Docker');
