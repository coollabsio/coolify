<?php

use App\Models\V5\Server as V5Server;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\Support\V5TestSchema;

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');

    V5TestSchema::dropAllTables();
    V5TestSchema::createAllTables();

    V5Server::query()->create([
        'team_id' => 1,
        'created_by_user_id' => 1,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.5',
    ]);
});

afterEach(function () {
    V5TestSchema::dropAllTables();
});

function postFluxContainerStatus(string $bearer): TestResponse
{
    return test()->postJson('/api/v1/internal/flux/resource-status', [
        'resource_type' => 'container',
        'host_id' => '100.64.0.5',
        'container_id' => 'external-container-id',
        'container_name' => 'external-container',
        'status' => 'running',
    ], [
        'Authorization' => 'Bearer '.$bearer,
    ]);
}

it('accepts an inbound token listed in the rotatable token array', function () {
    Config::set('flux.laravel_api_token', null);
    Config::set('flux.laravel_api_tokens', ['old-token', 'new-token']);

    postFluxContainerStatus('new-token')->assertSuccessful();
    postFluxContainerStatus('old-token')->assertSuccessful();
});

it('still accepts the single fallback token alongside the array', function () {
    Config::set('flux.laravel_api_token', 'single-token');
    Config::set('flux.laravel_api_tokens', ['array-token']);

    postFluxContainerStatus('single-token')->assertSuccessful();
    postFluxContainerStatus('array-token')->assertSuccessful();
});

it('rejects a token that is in neither the array nor the fallback', function () {
    Config::set('flux.laravel_api_token', 'single-token');
    Config::set('flux.laravel_api_tokens', ['array-token']);

    postFluxContainerStatus('unknown-token')->assertUnauthorized();
});

it('rejects an empty bearer token even when no tokens are configured', function () {
    Config::set('flux.laravel_api_token', null);
    Config::set('flux.laravel_api_tokens', []);

    postFluxContainerStatus('')->assertUnauthorized();
});
