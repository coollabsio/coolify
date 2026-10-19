<?php

use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    Queue::fake();
    Process::fake(fn () => Process::result(output: 'OK'));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Project::factory()->create(['team_id' => $this->team->id])->environments()->first();
    $destinationAttributes = [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];

    $application = Application::factory()->create($destinationAttributes);
    $service = Service::factory()->create($destinationAttributes + ['server_id' => $server->id]);
    $serviceApplication = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $service->id,
        'image' => 'nginx:alpine',
    ]);
    $database = StandalonePostgresql::create($destinationAttributes + [
        'name' => 'storage-db',
        'image' => 'postgres:17-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
    ]);

    $this->targets = [
        'applications' => ["/api/v1/applications/{$application->uuid}/storages", [], $application],
        'services' => ["/api/v1/services/{$service->uuid}/storages", ['resource_uuid' => $serviceApplication->uuid], $serviceApplication],
        'databases' => ["/api/v1/databases/{$database->uuid}/storages", [], $database],
    ];
});

function storageContentAbilityHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'storage-content-ability-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

test('write-only token cannot create a file storage with content', function (string $type) {
    [$url, $payload] = $this->targets[$type];

    $this->withHeaders(storageContentAbilityHeaders($this->user, $this->team->id, ['write']))
        ->postJson($url, [...$payload, 'type' => 'file', 'mount_path' => '/etc/app.conf', 'content' => 'listen 8080;'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy. Coolify writes the file content on the server immediately, so it needs a token with the deploy permission.');

    expect(LocalFileVolume::query()->count())->toBe(0);
    Queue::assertNotPushed(ServerStorageSaveJob::class);
})->with(['applications', 'services', 'databases']);

test('write-only token can create a directory storage', function (string $type) {
    [$url, $payload, $resource] = $this->targets[$type];

    $this->withHeaders(storageContentAbilityHeaders($this->user, $this->team->id, ['write']))
        ->postJson($url, [...$payload, 'type' => 'file', 'is_directory' => true, 'fs_path' => 'data', 'mount_path' => '/data'])
        ->assertCreated();

    expect($resource->fileStorages()->where('is_directory', true)->count())->toBe(1);
})->with(['applications', 'services', 'databases']);

test('write and deploy token creates a file storage with content', function (string $type) {
    [$url, $payload, $resource] = $this->targets[$type];

    $this->withHeaders(storageContentAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->postJson($url, [...$payload, 'type' => 'file', 'mount_path' => '/etc/app.conf', 'content' => 'listen 8080;'])
        ->assertCreated();

    expect($resource->fileStorages()->where('mount_path', '/etc/app.conf')->value('content'))->toBe('listen 8080;');
    Queue::assertPushed(ServerStorageSaveJob::class);
})->with(['applications', 'services', 'databases']);
