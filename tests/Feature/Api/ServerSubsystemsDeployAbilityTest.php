<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Process::fake();
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
        'cache.stores.redis.driver' => 'array',
    ]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

function serverSubsystemsDeployHeaders(array $abilities): array
{
    return [
        'Authorization' => 'Bearer '.test()->user->createToken('server-subsystems', $abilities)->plainTextToken,
        'Accept' => 'application/json',
    ];
}

test('a write-only token cannot change a server subsystem that runs on the server', function (string $method, string $path, array $payload) {
    $this->withHeaders(serverSubsystemsDeployHeaders(['write']))
        ->json($method, "/api/v1/servers/{$this->server->uuid}{$path}", $payload)
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy');

    $settings = $this->server->settings->fresh();
    expect($settings->logdrain_axiom_dataset_name)->toBeNull()
        ->and((bool) $settings->is_sentinel_debug_enabled)->toBeFalse();
    Queue::assertNothingPushed();
    Process::assertNothingRan();
})->with([
    'log drains' => ['PATCH', '/log-drains', ['logdrain_axiom_dataset_name' => 'api-dataset']],
    'sentinel' => ['PATCH', '/sentinel', ['is_sentinel_debug_enabled' => true]],
    'docker cleanup run' => ['POST', '/docker-cleanup/run', []],
    'registry login' => ['POST', '/registries', ['registry' => 'ghcr.io', 'username' => 'octocat', 'password' => 'secret']],
]);

test('a write-only token cannot turn on unused volume deletion', function () {
    $this->server->settings->update(['delete_unused_volumes' => false]);

    $this->withHeaders(serverSubsystemsDeployHeaders(['write']))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/docker-cleanup", ['delete_unused_volumes' => true])
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy. Deleting unused volumes removes data on the server, so it needs a token with the deploy permission.');

    expect((bool) $this->server->settings->fresh()->delete_unused_volumes)->toBeFalse();
});

test('a write-only token can change docker cleanup settings that keep volumes', function (bool $volumesBefore, array $payload) {
    $this->server->settings->update(['delete_unused_volumes' => $volumesBefore]);

    $this->withHeaders(serverSubsystemsDeployHeaders(['write']))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/docker-cleanup", $payload)
        ->assertOk();

    $settings = $this->server->settings->fresh();
    foreach ($payload as $field => $value) {
        expect($settings->{$field})->toEqual($value);
    }
})->with([
    'unused networks' => [false, ['delete_unused_networks' => true, 'docker_cleanup_threshold' => 50]],
    'turn off volume deletion' => [true, ['delete_unused_volumes' => false]],
    'volume deletion already on' => [true, ['delete_unused_volumes' => true]],
]);

test('a write and deploy token can turn on unused volume deletion', function (array $abilities) {
    $this->server->settings->update(['delete_unused_volumes' => false]);

    $this->withHeaders(serverSubsystemsDeployHeaders($abilities))
        ->patchJson("/api/v1/servers/{$this->server->uuid}/docker-cleanup", ['delete_unused_volumes' => true])
        ->assertOk()
        ->assertJsonPath('delete_unused_volumes', true);

    expect((bool) $this->server->settings->fresh()->delete_unused_volumes)->toBeTrue();
})->with([
    'write and deploy' => [['write', 'deploy']],
    'root' => [['root']],
]);
