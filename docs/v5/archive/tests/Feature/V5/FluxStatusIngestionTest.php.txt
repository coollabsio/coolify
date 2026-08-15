<?php

use App\Actions\V5\Flux\ApplyFluxResourceStatusUpdate;
use App\Enums\V5\ApplicationStatus;
use App\Enums\V5\ContainerState;
use App\Enums\V5\ServerStatus;
use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ContainerStatus;
use App\Models\V5\Server as V5Server;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V5TestSchema;

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');

    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_container_statuses');
    Schema::dropIfExists('v5_servers');

    V5TestSchema::createServersTable();
    V5TestSchema::createApplicationsTable();
    V5TestSchema::createContainerStatusesTable();
});

afterEach(function () {
    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_container_statuses');
    Schema::dropIfExists('v5_servers');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createFluxIngestionServer(array $overrides = []): V5Server
{
    return V5Server::query()->create([
        'team_id' => 1,
        'created_by_user_id' => 1,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => ServerStatus::Installed->value,
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createFluxIngestionApplication(V5Server $server, array $overrides = []): V5Application
{
    return V5Application::query()->create([
        'team_id' => $server->team_id,
        'project_id' => 1,
        'environment_id' => 1,
        'server_id' => $server->id,
        'created_by_user_id' => 1,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => ApplicationStatus::Running->value,
        'status_message' => 'Container started.',
        'runtime_container_id' => 'nginx-container-id',
        ...$overrides,
    ]);
}

it('maps unknown flux application status values to unknown and logs a warning', function () {
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);
    Log::spy();

    $server = createFluxIngestionServer();
    $application = createFluxIngestionApplication($server);

    ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'nginx-container-id',
        'status' => 'Exploded',
    ]);

    expect($application->refresh()->status)->toBe(ApplicationStatus::Unknown->value);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'unknown flux resource status')
            && ($context['raw_status'] ?? null) === 'Exploded'
            && ($context['status_enum'] ?? null) === ApplicationStatus::class)
        ->once();
});

it('maps unknown flux container states to unknown when upserting container statuses', function () {
    Log::spy();

    $server = createFluxIngestionServer();

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'container',
        'host_id' => '100.64.0.5',
        'container_id' => 'external-container-id',
        'container_name' => 'external-container',
        'status' => 'levitating',
    ]);

    expect($resource)->toBeInstanceOf(ContainerStatus::class)
        ->and($resource->status)->toBe(ContainerState::Unknown->value);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => ($context['raw_status'] ?? null) === 'levitating'
            && ($context['status_enum'] ?? null) === ContainerState::class)
        ->once();
});

it('still applies known statuses case insensitively without warnings', function () {
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);
    Log::spy();

    $server = createFluxIngestionServer();
    $application = createFluxIngestionApplication($server);

    ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'nginx-container-id',
        'status' => 'EXITED',
    ]);

    expect($application->refresh()->status)->toBe(ApplicationStatus::Exited->value);

    Log::shouldNotHaveReceived('warning');
});

it('drops flux application status updates that are older than the persisted observation', function () {
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);
    Log::spy();

    $server = createFluxIngestionServer();
    $application = createFluxIngestionApplication($server, [
        'status_observed_at' => '2026-07-05T12:00:00Z',
    ]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'nginx-container-id',
        'status' => 'exited',
        'observed_at' => '2026-07-05T11:00:00Z',
    ]);

    expect($resource)->toBeInstanceOf(V5Application::class)
        ->and($application->refresh()->status)->toBe(ApplicationStatus::Running->value)
        ->and($application->status_observed_at->toIso8601String())->toBe('2026-07-05T12:00:00+00:00');

    Log::shouldHaveReceived('debug')
        ->withArgs(fn (string $message): bool => str_contains($message, 'stale flux application status'))
        ->once();

    Event::assertNotDispatched(V5CanvasResourceUpdated::class);
});

it('applies flux application status updates with a newer observation and persists the timestamp', function () {
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createFluxIngestionServer();
    $application = createFluxIngestionApplication($server, [
        'status_observed_at' => '2026-07-05T10:00:00Z',
    ]);

    ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'nginx-container-id',
        'status' => 'exited',
        'status_message' => 'Container exited.',
        'observed_at' => '2026-07-05T12:00:00Z',
    ]);

    expect($application->refresh()->status)->toBe(ApplicationStatus::Exited->value)
        ->and($application->status_message)->toBe('Container exited.')
        ->and($application->status_observed_at->toIso8601String())->toBe('2026-07-05T12:00:00+00:00');

    Event::assertDispatched(V5CanvasResourceUpdated::class);
});

it('keeps applying flux application status updates that carry no observation timestamp', function () {
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createFluxIngestionServer();
    $application = createFluxIngestionApplication($server, [
        'status_observed_at' => '2026-07-05T12:00:00Z',
    ]);

    ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'nginx-container-id',
        'status' => 'exited',
    ]);

    expect($application->refresh()->status)->toBe(ApplicationStatus::Exited->value)
        ->and($application->status_observed_at->toIso8601String())->toBe('2026-07-05T12:00:00+00:00');
});

it('drops stale flux server status updates but applies newer observations', function () {
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);
    Log::spy();

    $server = createFluxIngestionServer([
        'status_observed_at' => '2026-07-05T12:00:00Z',
    ]);

    ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'server',
        'host_id' => '100.64.0.5',
        'status' => 'unreachable',
        'observed_at' => '2026-07-05T11:59:59Z',
    ]);

    expect($server->refresh()->status)->toBe(ServerStatus::Installed->value);

    Log::shouldHaveReceived('debug')
        ->withArgs(fn (string $message): bool => str_contains($message, 'stale flux server status'))
        ->once();

    ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'server',
        'host_id' => '100.64.0.5',
        'status' => 'unreachable',
        'observed_at' => '2026-07-05T12:00:01Z',
    ]);

    expect($server->refresh()->status)->toBe(ServerStatus::Unreachable->value)
        ->and($server->status_observed_at->toIso8601String())->toBe('2026-07-05T12:00:01+00:00');
});

it('drops stale flux container status updates that are older than the persisted observation', function () {
    Log::spy();

    $server = createFluxIngestionServer();
    ContainerStatus::query()->create([
        'team_id' => $server->team_id,
        'server_id' => $server->id,
        'container_id' => 'external-container-id',
        'container_name' => 'external-container',
        'status' => ContainerState::Running->value,
        'status_observed_at' => '2026-07-05T12:00:00Z',
    ]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'container',
        'host_id' => '100.64.0.5',
        'container_id' => 'external-container-id',
        'container_name' => 'external-container',
        'status' => 'exited',
        'observed_at' => '2026-07-05T11:00:00Z',
    ]);

    expect($resource)->toBeInstanceOf(ContainerStatus::class)
        ->and($resource->status)->toBe(ContainerState::Running->value);

    Log::shouldHaveReceived('debug')
        ->withArgs(fn (string $message): bool => str_contains($message, 'stale flux container status'))
        ->once();
});

it('logs a warning when a flux host id matches multiple v5 servers', function () {
    Log::spy();

    $serverOne = createFluxIngestionServer();
    $serverTwo = createFluxIngestionServer([
        'name' => 'edge-02',
        'host' => '203.0.113.11',
    ]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'server',
        'host_id' => '100.64.0.5',
        'status' => 'unreachable',
    ]);

    expect($resource)->toBeNull()
        ->and($serverOne->refresh()->status)->toBe(ServerStatus::Installed->value)
        ->and($serverTwo->refresh()->status)->toBe(ServerStatus::Installed->value);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'matches multiple v5 servers')
            && ($context['host_id'] ?? null) === '100.64.0.5'
            && ($context['server_ids'] ?? null) === [$serverOne->id, $serverTwo->id])
        ->once();
});

it('accepts flux resource status http payloads with a valid observed_at timestamp', function () {
    Config::set('flux.laravel_api_token', 'test-flux-token');
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createFluxIngestionServer();
    $application = createFluxIngestionApplication($server);

    $this
        ->withToken('test-flux-token')
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'application',
            'host_id' => '100.64.0.5',
            'container_name' => 'coolify-v5-nginx-1',
            'container_id' => 'nginx-container-id',
            'status' => 'exited',
            'observed_at' => '2026-07-05T12:00:00Z',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Resource status updated.');

    expect($application->refresh()->status)->toBe(ApplicationStatus::Exited->value)
        ->and($application->status_observed_at->toIso8601String())->toBe('2026-07-05T12:00:00+00:00');
});

it('rejects flux resource status http payloads with an invalid observed_at value', function () {
    Config::set('flux.laravel_api_token', 'test-flux-token');

    $this
        ->withToken('test-flux-token')
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'application',
            'host_id' => '100.64.0.5',
            'status' => 'exited',
            'observed_at' => 'not-a-timestamp',
        ])
        ->assertInvalid(['observed_at']);
});
