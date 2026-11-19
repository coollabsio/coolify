<?php

use App\Jobs\DatabaseBackupJob;
use App\Models\Application;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Project::factory()->create(['team_id' => $this->team->id])->environments()->first();
    $this->destinationAttributes = [
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ];

    $this->database = StandalonePostgresql::create($this->destinationAttributes + [
        'name' => 'deploy-ability-db',
        'image' => 'postgres:17-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'is_public' => false,
    ]);

    Queue::fake();
    Http::fake();
});

function deployAbilityHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'cloud-init-public-backup-ability-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

dataset('cloud providers', [
    'hetzner' => ['hetzner', ['location' => 'nbg1', 'server_type' => 'cx11', 'image' => 15512617], 'hetzner_ssh_key_ids', [123]],
    'vultr' => ['vultr', ['region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os_id' => 2284], 'vultr_ssh_key_ids', ['key-1']],
    'digitalocean' => ['digitalocean', ['region' => 'nyc1', 'size' => 's-1vcpu-1gb', 'image' => 'ubuntu-24-04-x64'], 'digitalocean_ssh_key_ids', [123]],
]);

function cloudServerPayload(object $context, string $provider, array $providerFields, ?string $tokenUuid = null): array
{
    $token = CloudProviderToken::create([
        'team_id' => $context->team->id,
        'provider' => $provider,
        'name' => 'Test token',
        'token' => 'test-api-token',
    ]);
    $privateKey = PrivateKey::create([
        'team_id' => $context->team->id,
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
    ]);

    return $providerFields + [
        'cloud_provider_token_uuid' => $tokenUuid ?? $token->uuid,
        'name' => 'deploy-ability-server',
        'private_key_uuid' => $privateKey->uuid,
    ];
}

test('write-only token cannot create a cloud server with a cloud-init script or extra SSH keys', function (string $provider, array $providerFields, string $sshKeyField, array $sshKeyIds, string $extra) {
    $payload = cloudServerPayload($this, $provider, $providerFields);
    $payload += $extra === 'cloud_init_script'
        ? ['cloud_init_script' => "#cloud-config\nruncmd:\n  - echo hello\n"]
        : [$sshKeyField => $sshKeyIds];
    $serverCount = Server::query()->count();

    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write']))
        ->postJson("/api/v1/servers/{$provider}", $payload)
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy. A cloud-init script or extra SSH keys run as root on the new server, so it needs a token with the deploy permission.');

    Http::assertNothingSent();
    expect(Server::query()->count())->toBe($serverCount);
})->with('cloud providers')->with(['cloud_init_script', 'ssh_key_ids']);

test('write and deploy token passes the cloud-init check', function (string $provider, array $providerFields, string $sshKeyField, array $sshKeyIds) {
    // An unknown provider token stops the request after the ability check, before any provider call.
    $payload = cloudServerPayload($this, $provider, $providerFields, 'missing-token') + [
        'cloud_init_script' => "#cloud-config\nruncmd:\n  - echo hello\n",
        $sshKeyField => $sshKeyIds,
    ];

    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->postJson("/api/v1/servers/{$provider}", $payload)
        ->assertNotFound();

    Http::assertNothingSent();
})->with('cloud providers');

test('write-only token can create a cloud server request without cloud-init or extra SSH keys', function (string $provider, array $providerFields) {
    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write']))
        ->postJson("/api/v1/servers/{$provider}", cloudServerPayload($this, $provider, $providerFields, 'missing-token'))
        ->assertNotFound();
})->with('cloud providers');

test('write-only token cannot make a database public', function () {
    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write']))
        ->patchJson("/api/v1/databases/{$this->database->uuid}", ['is_public' => true, 'public_port' => 25432])
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing required permissions: deploy. Making a database public or private starts or stops its proxy on the server, so it needs a token with the deploy permission.');

    expect((bool) $this->database->fresh()->is_public)->toBeFalse();
    Queue::assertNothingPushed();
});

test('write-only token can send an unchanged is_public value', function () {
    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write']))
        ->patchJson("/api/v1/databases/{$this->database->uuid}", ['is_public' => false, 'name' => 'renamed-db'])
        ->assertOk();

    expect($this->database->fresh()->name)->toBe('renamed-db');
});

test('write and deploy token can make a database public', function () {
    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->patchJson("/api/v1/databases/{$this->database->uuid}", ['is_public' => true, 'public_port' => 25432])
        ->assertOk();

    expect((bool) $this->database->fresh()->is_public)->toBeTrue();
});

test('database update rejects a public port outside 1024-65535', function (int $port) {
    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->patchJson("/api/v1/databases/{$this->database->uuid}", ['public_port' => $port])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_port');

    expect($this->database->fresh()->public_port)->toBeNull();
})->with([80, 70000]);

test('service database is_public change needs the deploy permission', function (array $abilities, int $status) {
    $service = Service::factory()->create($this->destinationAttributes + [
        'server_id' => $this->server->id,
        'docker_compose_raw' => "services:\n  postgres:\n    image: postgres:17-alpine\n",
    ]);
    $serviceDatabase = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'postgres',
        'service_id' => $service->id,
        'image' => 'postgres:17-alpine',
        'is_public' => false,
    ]);

    $response = $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, $abilities))
        ->patchJson("/api/v1/services/{$service->uuid}/databases/{$serviceDatabase->uuid}", ['is_public' => true, 'public_port' => 25433])
        ->assertStatus($status);

    if ($status === 403) {
        $response->assertJsonPath('message', 'Missing required permissions: deploy. Making a database public or private starts or stops its proxy on the server, so it needs a token with the deploy permission.');
    }
    expect((bool) $serviceDatabase->fresh()->is_public)->toBe($status === 200);
})->with([
    'write only' => [['write'], 403],
    'write and deploy' => [['write', 'deploy'], 200],
]);

test('backup_now needs the deploy permission when creating a database backup', function (array $abilities, int $status) {
    $response = $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, $abilities))
        ->postJson("/api/v1/databases/{$this->database->uuid}/backups", ['frequency' => 'daily', 'backup_now' => true])
        ->assertStatus($status);

    if ($status === 403) {
        $response->assertJsonPath('message', 'Missing required permissions: deploy. A backup that runs now copies the database on the server, so it needs a token with the deploy permission.');
        expect(ScheduledDatabaseBackup::query()->count())->toBe(0);
        Queue::assertNotPushed(DatabaseBackupJob::class);
    } else {
        Queue::assertPushed(DatabaseBackupJob::class);
    }
})->with([
    'write only' => [['write'], 403],
    'write and deploy' => [['write', 'deploy'], 201],
]);

test('write-only token can create a database backup schedule without backup_now', function () {
    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write']))
        ->postJson("/api/v1/databases/{$this->database->uuid}/backups", ['frequency' => 'daily'])
        ->assertCreated();

    Queue::assertNotPushed(DatabaseBackupJob::class);
});

test('backup_now needs the deploy permission when updating a database backup', function (array $abilities, int $status) {
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => 'daily',
        'database_id' => $this->database->id,
        'database_type' => $this->database->getMorphClass(),
        'team_id' => $this->team->id,
    ]);

    $response = $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, $abilities))
        ->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", ['frequency' => 'hourly', 'backup_now' => true])
        ->assertStatus($status);

    if ($status === 403) {
        $response->assertJsonPath('message', 'Missing required permissions: deploy. A backup that runs now copies the database on the server, so it needs a token with the deploy permission.');
        expect($backup->fresh()->frequency)->toBe('daily');
        Queue::assertNotPushed(DatabaseBackupJob::class);
    } else {
        expect($backup->fresh()->frequency)->toBe('hourly');
        Queue::assertPushed(DatabaseBackupJob::class);
    }
})->with([
    'write only' => [['write'], 403],
    'write and deploy' => [['write', 'deploy'], 200],
]);

test('stop_during_backup needs the deploy permission for a volume backup schedule', function (array $abilities, bool $stopDuringBackup, int $status) {
    $application = Application::factory()->create($this->destinationAttributes);
    $volume = LocalPersistentVolume::create([
        'name' => 'deploy-ability-volume',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    $response = $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, $abilities))
        ->putJson("/api/v1/applications/{$application->uuid}/storages/{$volume->uuid}/backups", [
            'frequency' => '0 2 * * *',
            'stop_during_backup' => $stopDuringBackup,
        ])
        ->assertStatus($status);

    if ($status === 403) {
        $response->assertJsonPath('message', 'Missing required permissions: deploy. A backup that stops the containers needs a token with the deploy permission.');
        expect(ScheduledVolumeBackup::query()->count())->toBe(0);
    } else {
        expect(ScheduledVolumeBackup::query()->sole()->stop_during_backup)->toBe($stopDuringBackup);
    }
})->with([
    'write only, stop' => [['write'], true, 403],
    'write only, no stop' => [['write'], false, 201],
    'write and deploy, stop' => [['write', 'deploy'], true, 201],
]);

test('write-only token cannot run a volume backup now', function () {
    $application = Application::factory()->create($this->destinationAttributes);
    $volume = LocalPersistentVolume::create([
        'name' => 'deploy-ability-volume',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    $this->withHeaders(deployAbilityHeaders($this->user, $this->team->id, ['write']))
        ->postJson("/api/v1/applications/{$application->uuid}/storages/{$volume->uuid}/backups/run")
        ->assertForbidden();

    Queue::assertNothingPushed();
});
