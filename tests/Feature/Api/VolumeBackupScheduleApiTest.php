<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('cache.default', 'array');
    Config::set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'volume-backup-api-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->headers = [
        'Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken,
        'Content-Type' => 'application/json',
    ];

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $this->volume = LocalPersistentVolume::create([
        'name' => 'api-volume',
        'mount_path' => '/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $this->s3Storage = S3Storage::create([
        'name' => 'volume-backup-s3',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);
});

function createVolumeBackupApiToken($context, User $user, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'volume-backup-api-permission-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $context->team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

it('sets an application volume backup schedule through the API', function () {
    $response = $this->withHeaders($this->headers)
        ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups", [
            'frequency' => '0 2 * * *',
            'enabled' => true,
            'save_s3' => true,
            'disable_local_backup' => true,
            'stop_during_backup' => true,
            's3_storage_uuid' => $this->s3Storage->uuid,
            'retention_amount_locally' => 3,
            'retention_days_locally' => 4,
            'retention_max_storage_locally' => 5.5,
            'retention_amount_s3' => 6,
            'retention_days_s3' => 7,
            'retention_max_storage_s3' => 8.5,
            'timeout' => 600,
        ]);

    $response->assertCreated()->assertJsonStructure(['uuid', 'message']);

    $backup = ScheduledVolumeBackup::query()->sole();
    expect($backup->backupable->is($this->volume))->toBeTrue()
        ->and($backup->team_id)->toBe($this->team->id)
        ->and($backup->frequency)->toBe('0 2 * * *')
        ->and($backup->enabled)->toBeTrue()
        ->and($backup->save_s3)->toBeTrue()
        ->and($backup->disable_local_backup)->toBeTrue()
        ->and($backup->stop_during_backup)->toBeTrue()
        ->and($backup->s3_storage_id)->toBe($this->s3Storage->id)
        ->and($backup->retention_amount_locally)->toBe(3)
        ->and($backup->retention_days_locally)->toBe(4)
        ->and($backup->retention_max_storage_locally)->toBe(5.5)
        ->and($backup->retention_amount_s3)->toBe(6)
        ->and($backup->retention_days_s3)->toBe(7)
        ->and($backup->retention_max_storage_s3)->toBe(8.5)
        ->and($backup->timeout)->toBe(600);
});

it('updates the existing backup schedule instead of creating another one', function () {
    $this->volume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        'disable_local_backup' => true,
        's3_storage_id' => $this->s3Storage->id,
        'timeout' => 7200,
    ]);

    $response = $this->withHeaders($this->headers)
        ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups", [
            'frequency' => 'hourly',
            'enabled' => false,
            'save_s3' => false,
        ]);

    $response->assertOk();

    $backup = ScheduledVolumeBackup::query()->sole();
    expect($backup->frequency)->toBe('hourly')
        ->and($backup->enabled)->toBeFalse()
        ->and($backup->save_s3)->toBeFalse()
        ->and($backup->disable_local_backup)->toBeFalse()
        ->and($backup->s3_storage_id)->toBeNull()
        ->and($backup->timeout)->toBe(7200);
});

it('sets a directory backup schedule through the API', function () {
    $directory = LocalFileVolume::unguarded(fn () => LocalFileVolume::withoutEvents(fn () => LocalFileVolume::create([
        'uuid' => new_public_id(),
        'fs_path' => './uploads',
        'mount_path' => '/app/uploads',
        'is_directory' => true,
        'is_host_file' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ])));

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$directory->uuid}/backups", [
            'frequency' => 'daily',
        ])
        ->assertCreated();

    expect(ScheduledVolumeBackup::query()->sole()->backupable->is($directory))->toBeTrue();
});

it('refuses to delete an application directory before its backup schedule is removed', function () {
    Process::fake();
    $directory = LocalFileVolume::unguarded(fn () => LocalFileVolume::withoutEvents(fn () => LocalFileVolume::create([
        'uuid' => new_public_id(),
        'fs_path' => './uploads',
        'mount_path' => '/app/uploads',
        'is_directory' => true,
        'is_host_file' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ])));
    $directory->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
    ]);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/applications/{$this->application->uuid}/storages/{$directory->uuid}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Delete this directory backup schedule and its archives before deleting the directory.');

    expect($directory->fresh())->not->toBeNull();
    Process::assertNothingRan();
});

it('deletes an application volume backup schedule through the API', function () {
    $backup = $this->volume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        'frequency' => 'daily',
    ]);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups")
        ->assertOk()
        ->assertJsonPath('message', 'Storage backup schedule and archives deleted.');

    expect($backup->fresh())->toBeNull();
});

it('sets and deletes database and service volume backup schedules through their storage APIs', function () {
    $database = StandalonePostgresql::create([
        'name' => 'api-postgres',
        'image' => 'postgres:17-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $databaseVolume = $database->persistentStorages()->firstOrFail();

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $serviceApplication = ServiceApplication::create([
        'uuid' => new_public_id(),
        'name' => 'api-service-application',
        'image' => 'nginx:alpine',
        'service_id' => $service->id,
    ]);
    $serviceVolume = LocalPersistentVolume::create([
        'name' => 'service-api-volume',
        'mount_path' => '/data',
        'resource_id' => $serviceApplication->id,
        'resource_type' => $serviceApplication->getMorphClass(),
    ]);

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/databases/{$database->uuid}/storages/{$databaseVolume->uuid}/backups", ['frequency' => 'daily'])
        ->assertCreated();
    $this->withHeaders($this->headers)
        ->putJson("/api/v1/services/{$service->uuid}/storages/{$serviceVolume->uuid}/backups", ['frequency' => 'weekly'])
        ->assertCreated();

    expect($databaseVolume->scheduledBackups()->sole()->frequency)->toBe('daily')
        ->and($serviceVolume->scheduledBackups()->sole()->frequency)->toBe('weekly');

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/databases/{$database->uuid}/storages/{$databaseVolume->uuid}/backups")
        ->assertOk();
    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/services/{$service->uuid}/storages/{$serviceVolume->uuid}/backups")
        ->assertOk();

    expect($databaseVolume->scheduledBackups()->count())->toBe(0)
        ->and($serviceVolume->scheduledBackups()->count())->toBe(0);
});

it('rejects ineligible file storages and invalid schedule settings', function () {
    $file = LocalFileVolume::unguarded(fn () => LocalFileVolume::withoutEvents(fn () => LocalFileVolume::create([
        'uuid' => new_public_id(),
        'fs_path' => '/tmp/config.json',
        'mount_path' => '/app/config.json',
        'is_directory' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ])));

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$file->uuid}/backups", ['frequency' => 'daily'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['storage_uuid']);

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups", [
            'frequency' => 'not-a-frequency',
            'save_s3' => false,
            'disable_local_backup' => true,
            'unexpected' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['frequency', 'disable_local_backup', 'unexpected']);

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('rejects an s3 storage owned by another team', function () {
    $otherTeam = Team::factory()->create();
    $otherS3Storage = S3Storage::create([
        'name' => 'other-team-s3',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $otherTeam->id,
        'is_usable' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups", [
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_uuid' => $otherS3Storage->uuid,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['s3_storage_uuid']);

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('rejects schedule values larger than the database columns support', function () {
    $this->withHeaders($this->headers)
        ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups", [
            'frequency' => str_repeat('a', 256),
            'retention_days_locally' => 2147483648,
            'retention_days_s3' => 2147483648,
            'retention_max_storage_locally' => 10000000000,
            'retention_max_storage_s3' => 10000000000,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'frequency',
            'retention_days_locally',
            'retention_days_s3',
            'retention_max_storage_locally',
            'retention_max_storage_s3',
        ]);

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('requires authentication, write ability, and an admin team role', function () {
    $endpoint = "/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups";

    $this->putJson($endpoint, ['frequency' => 'daily'])->assertUnauthorized();

    $readToken = createVolumeBackupApiToken($this, $this->user, ['read']);
    $this->withToken($readToken)->putJson($endpoint, ['frequency' => 'daily'])->assertForbidden();

    auth()->forgetGuards();
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $memberWriteToken = createVolumeBackupApiToken($this, $member, ['write']);
    $this->withToken($memberWriteToken)->putJson($endpoint, ['frequency' => 'daily'])->assertForbidden();

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('does not set schedules through resources owned by another team', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $otherDatabase = StandalonePostgresql::create([
        'name' => 'other-team-postgres',
        'image' => 'postgres:17-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $otherService = Service::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    foreach (['applications' => $otherApplication, 'databases' => $otherDatabase, 'services' => $otherService] as $type => $resource) {
        $this->withHeaders($this->headers)
            ->putJson("/api/v1/{$type}/{$resource->uuid}/storages/{$this->volume->uuid}/backups", ['frequency' => 'daily'])
            ->assertNotFound();
    }

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});

it('does not set a schedule for storage outside the scoped parent', function () {
    $otherApplication = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $otherVolume = LocalPersistentVolume::create([
        'name' => 'other-application-volume',
        'mount_path' => '/data',
        'resource_id' => $otherApplication->id,
        'resource_type' => $otherApplication->getMorphClass(),
    ]);

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$otherVolume->uuid}/backups", ['frequency' => 'daily'])
        ->assertNotFound();

    expect(ScheduledVolumeBackup::query()->count())->toBe(0);
});
