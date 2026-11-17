<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 's3-destinations-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->headers = ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->database = StandalonePostgresql::create([
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app',
        'image' => 'postgres:16',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->volume = LocalPersistentVolume::create([
        'name' => 'data',
        'mount_path' => '/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $this->firstS3 = createBackupDestinationS3($this->team, 'first');
    $this->secondS3 = createBackupDestinationS3($this->team, 'second');
    $this->otherTeamS3 = createBackupDestinationS3(Team::factory()->create(), 'foreign');
});

function createBackupDestinationS3(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => $name.'-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
}

/**
 * @return list<string>
 */
function backupDestinationUuids(ScheduledDatabaseBackup|ScheduledVolumeBackup $backup): array
{
    return $backup->refresh()->selectedS3Storages()->pluck('uuid')->all();
}

describe('database backups', function () {
    test('creates a backup with several destinations and lists them primary first', function () {
        $response = $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_uuids' => [$this->firstS3->uuid, $this->secondS3->uuid],
            's3_storage_uuid' => $this->secondS3->uuid,
        ])->assertCreated();

        $backup = ScheduledDatabaseBackup::where('uuid', $response->json('uuid'))->sole();
        expect($backup->s3_storage_id)->toBe($this->secondS3->id)
            ->and(backupDestinationUuids($backup))->toBe([$this->secondS3->uuid, $this->firstS3->uuid]);

        $this->withHeaders($this->headers)->getJson("/api/v1/databases/{$this->database->uuid}/backups")
            ->assertOk()
            ->assertJsonPath('0.s3_storage_uuid', $this->secondS3->uuid)
            ->assertJsonPath('0.s3_storage_uuids', [$this->secondS3->uuid, $this->firstS3->uuid])
            ->assertJsonMissingPath('0.s3_storages')
            ->assertJsonMissingPath('0.s3');
    });

    test('uses the first listed destination as primary when s3_storage_uuid is not sent', function () {
        $response = $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_uuids' => [$this->secondS3->uuid, $this->firstS3->uuid],
        ])->assertCreated();

        expect(ScheduledDatabaseBackup::where('uuid', $response->json('uuid'))->sole()->s3_storage_id)->toBe($this->secondS3->id);
    });

    test('a single s3_storage_uuid that is already a destination becomes primary and keeps the others', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'save_s3' => true,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);
        $backup->syncS3Storages([$this->firstS3->id, $this->secondS3->id]);

        $this->withHeaders($this->headers)->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
            'save_s3' => true,
            's3_storage_uuid' => $this->secondS3->uuid,
        ])->assertOk();

        expect(backupDestinationUuids($backup))->toBe([$this->secondS3->uuid, $this->firstS3->uuid])
            ->and($backup->s3_storage_id)->toBe($this->secondS3->id);
    });

    test('a single new s3_storage_uuid replaces every destination', function () {
        $thirdS3 = createBackupDestinationS3($this->team, 'third');
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'save_s3' => true,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);
        $backup->syncS3Storages([$this->firstS3->id, $this->secondS3->id]);

        $this->withHeaders($this->headers)->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
            's3_storage_uuid' => $thirdS3->uuid,
        ])->assertOk();

        expect(backupDestinationUuids($backup))->toBe([$thirdS3->uuid])
            ->and($backup->s3_storage_id)->toBe($thirdS3->id);
    });

    test('updates the destination list', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);

        $this->withHeaders($this->headers)->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
            'save_s3' => true,
            's3_storage_uuids' => [$this->firstS3->uuid, $this->secondS3->uuid],
        ])->assertOk();

        expect(backupDestinationUuids($backup))->toBe([$this->firstS3->uuid, $this->secondS3->uuid])
            ->and((bool) $backup->save_s3)->toBeTrue();
    });

    test('rejects an S3 storage of another team without changing the backup', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_id' => $this->firstS3->id,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);
        $backup->syncS3Storages([$this->firstS3->id]);

        $this->withHeaders($this->headers)->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
            's3_storage_uuids' => [$this->firstS3->uuid, $this->otherTeamS3->uuid],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['s3_storage_uuids'])
            ->assertDontSee($this->otherTeamS3->name);

        expect(backupDestinationUuids($backup))->toBe([$this->firstS3->uuid]);
    });

    test('rejects invalid destination combinations', function (array $payload, string $errorField) {
        $payload = array_map(fn ($value) => is_array($value)
            ? array_map(fn (string $name) => $this->{$name}->uuid, $value)
            : (is_string($value) && property_exists($this, $value) ? $this->{$value}->uuid : $value), $payload);

        $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$this->database->uuid}/backups", ['frequency' => 'daily', ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField]);

        expect(ScheduledDatabaseBackup::count())->toBe(0);
    })->with([
        'primary outside the list' => [['s3_storage_uuids' => ['firstS3'], 's3_storage_uuid' => 'secondS3'], 's3_storage_uuid'],
        'save_s3 without destinations' => [['save_s3' => true, 's3_storage_uuids' => []], 's3_storage_uuid'],
        'foreign storage in the list' => [['save_s3' => true, 's3_storage_uuids' => ['firstS3', 'otherTeamS3']], 's3_storage_uuids'],
    ]);

    test('rejects clearing the destinations of a backup that saves to S3', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_id' => $this->firstS3->id,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);

        $this->withHeaders($this->headers)->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
            's3_storage_uuids' => [],
        ])->assertUnprocessable();

        expect($backup->refresh()->s3_storage_id)->toBe($this->firstS3->id);
    });
});

describe('volume backups', function () {
    test('sets several destinations and returns them primary first', function () {
        $this->withHeaders($this->headers)
            ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups", [
                'frequency' => 'daily',
                'save_s3' => true,
                's3_storage_uuids' => [$this->firstS3->uuid, $this->secondS3->uuid],
                's3_storage_uuid' => $this->secondS3->uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('s3_storage_uuid', $this->secondS3->uuid)
            ->assertJsonPath('s3_storage_uuids', [$this->secondS3->uuid, $this->firstS3->uuid]);

        expect(backupDestinationUuids(ScheduledVolumeBackup::sole()))->toBe([$this->secondS3->uuid, $this->firstS3->uuid]);
    });

    test('a single s3_storage_uuid replaces every destination', function () {
        $url = "/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups";
        $this->withHeaders($this->headers)->putJson($url, [
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_uuids' => [$this->firstS3->uuid, $this->secondS3->uuid],
        ])->assertCreated();

        $this->withHeaders($this->headers)->putJson($url, [
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_uuid' => $this->secondS3->uuid,
        ])->assertOk()->assertJsonPath('s3_storage_uuids', [$this->secondS3->uuid]);

        expect(backupDestinationUuids(ScheduledVolumeBackup::sole()))->toBe([$this->secondS3->uuid]);
    });

    test('rejects an S3 storage of another team', function () {
        $this->withHeaders($this->headers)
            ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups", [
                'frequency' => 'daily',
                'save_s3' => true,
                's3_storage_uuids' => [$this->firstS3->uuid, $this->otherTeamS3->uuid],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['s3_storage_uuids'])
            ->assertDontSee($this->otherTeamS3->name);

        expect(ScheduledVolumeBackup::count())->toBe(0);
    });

    test('rejects a primary destination outside the list', function () {
        $this->withHeaders($this->headers)
            ->putJson("/api/v1/applications/{$this->application->uuid}/storages/{$this->volume->uuid}/backups", [
                'frequency' => 'daily',
                'save_s3' => true,
                's3_storage_uuids' => [$this->firstS3->uuid],
                's3_storage_uuid' => $this->secondS3->uuid,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['s3_storage_uuid']);
    });
});
