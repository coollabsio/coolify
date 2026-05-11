<?php

use App\Livewire\Project\Database\BackupEdit;
use App\Livewire\Project\Database\CreateScheduledBackup;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0]));
    config(['app.maintenance.driver' => 'file']);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->database = StandalonePostgresql::create([
        'name' => 'test-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->s3Storage = S3Storage::unguarded(fn () => S3Storage::create([
        'name' => 'test-s3',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]));
});

describe('Livewire database backup S3 storage validation', function () {
    test('create backup rejects tampered s3 storage id from another team', function () {
        $otherTeam = Team::factory()->create();
        $otherS3 = S3Storage::unguarded(fn () => S3Storage::create([
            'name' => 'other-s3-livewire',
            'region' => 'us-east-1',
            'key' => 'other-key',
            'secret' => 'other-secret',
            'bucket' => 'other-bucket',
            'endpoint' => 'https://s3.example.com',
            'team_id' => $otherTeam->id,
            'is_usable' => true,
        ]));

        Livewire::actingAs($this->user)
            ->test(CreateScheduledBackup::class, ['database' => $this->database])
            ->set('frequency', '0 2 * * 0')
            ->set('saveToS3', true)
            ->set('s3StorageId', $otherS3->id)
            ->call('submit')
            ->assertDispatched('error', 'The selected S3 storage is invalid for this team.');

        expect(ScheduledDatabaseBackup::where('s3_storage_id', $otherS3->id)->exists())->toBeFalse();
    });

    test('create backup rejects tampered s3 storage id even when s3 is disabled', function () {
        $otherTeam = Team::factory()->create();
        $otherS3 = S3Storage::unguarded(fn () => S3Storage::create([
            'name' => 'other-s3-disabled-livewire',
            'region' => 'us-east-1',
            'key' => 'other-key',
            'secret' => 'other-secret',
            'bucket' => 'other-bucket',
            'endpoint' => 'https://s3.example.com',
            'team_id' => $otherTeam->id,
            'is_usable' => true,
        ]));

        Livewire::actingAs($this->user)
            ->test(CreateScheduledBackup::class, ['database' => $this->database])
            ->set('frequency', '0 2 * * 0')
            ->set('saveToS3', false)
            ->set('s3StorageId', $otherS3->id)
            ->call('submit')
            ->assertDispatched('error', 'The selected S3 storage is invalid for this team.');

        expect(ScheduledDatabaseBackup::where('s3_storage_id', $otherS3->id)->exists())->toBeFalse();
    });

    test('edit backup rejects tampered s3 storage id from another team', function () {
        $otherTeam = Team::factory()->create();
        $otherS3 = S3Storage::unguarded(fn () => S3Storage::create([
            'name' => 'other-s3-edit-livewire',
            'region' => 'us-east-1',
            'key' => 'other-key',
            'secret' => 'other-secret',
            'bucket' => 'other-bucket',
            'endpoint' => 'https://s3.example.com',
            'team_id' => $otherTeam->id,
            'is_usable' => true,
        ]));

        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'enabled' => true,
            'save_s3' => true,
            's3_storage_id' => $this->s3Storage->id,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
            'database_backup_retention_amount_locally' => 0,
            'database_backup_retention_days_locally' => 0,
            'database_backup_retention_max_storage_locally' => 0,
            'database_backup_retention_amount_s3' => 0,
            'database_backup_retention_days_s3' => 0,
            'database_backup_retention_max_storage_s3' => 0,
            'backup_method' => 'dump',
            'pgbackrest_backup_type' => 'incr',
            'pgbackrest_require_wal_archive' => true,
            'disable_local_backup' => false,
            'dump_all' => false,
            'timeout' => 3600,
        ]);

        Livewire::actingAs($this->user)
            ->test(BackupEdit::class, ['backup' => $backup])
            ->set('s3StorageId', $otherS3->id)
            ->set('saveS3', true)
            ->call('instantSave')
            ->assertDispatched('error', 'The selected S3 storage is invalid for this team.');

        $backup->refresh();
        expect($backup->s3_storage_id)->toBe($this->s3Storage->id);
    });
});

describe('POST /api/v1/databases/{uuid}/backups', function () {
    test('creates backup with s3 storage via API token', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => '0 2 * * 0',
            'save_s3' => true,
            's3_storage_uuid' => $this->s3Storage->uuid,
            'enabled' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid', 'message']);

        $backup = ScheduledDatabaseBackup::where('uuid', $response->json('uuid'))->first();
        expect($backup)->not->toBeNull();
        expect($backup->s3_storage_id)->toBe($this->s3Storage->id);
        expect($backup->save_s3)->toBeTrue();
        expect($backup->team_id)->toBe($this->team->id);
    });

    test('creates pgbackrest postgres backup via API token', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => '0 2 * * 0',
            'save_s3' => true,
            's3_storage_uuid' => $this->s3Storage->uuid,
            'backup_method' => 'pgbackrest',
            'pgbackrest_backup_type' => 'incr',
            'enabled' => true,
            'database_backup_retention_amount_locally' => 5,
            'database_backup_retention_max_storage_s3' => 10,
        ]);

        $response->assertStatus(201);

        $backup = ScheduledDatabaseBackup::where('uuid', $response->json('uuid'))->first();
        expect($backup)->not->toBeNull();
        expect($backup->backup_method)->toBe('pgbackrest');
        expect($backup->pgbackrest_backup_type)->toBe('incr');
        expect($backup->pgbackrest_require_wal_archive)->toBeTrue();
        expect($backup->save_s3)->toBeTrue();
        expect($backup->s3_storage_id)->toBe($this->s3Storage->id);
        expect($backup->disable_local_backup)->toBeTrue();
        expect($backup->dump_all)->toBeTrue();
        expect($backup->databases_to_backup)->toBeNull();
        expect($backup->database_backup_retention_amount_locally)->toBe(0);
        expect((float) $backup->database_backup_retention_max_storage_s3)->toBe(0.0);
    });

    test('rejects pgbackrest backup without s3 storage', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
            'backup_method' => 'pgbackrest',
            'pgbackrest_backup_type' => 'incr',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['backup_method', 'save_s3', 's3_storage_uuid']);
    });

    test('rejects pgbackrest backup for non postgres database', function () {
        $mysql = StandaloneMysql::create([
            'name' => 'test-mysql',
            'image' => 'mysql:8',
            'mysql_root_password' => 'password',
            'mysql_user' => 'mysql',
            'mysql_password' => 'password',
            'mysql_database' => 'testdb',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$mysql->uuid}/backups", [
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_uuid' => $this->s3Storage->uuid,
            'backup_method' => 'pgbackrest',
            'pgbackrest_backup_type' => 'incr',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['backup_method']);
    });

    test('creates backup without s3 storage', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid', 'message']);
    });

    test('rejects s3_storage_uuid from another team', function () {
        $otherTeam = Team::factory()->create();
        $otherS3 = S3Storage::unguarded(fn () => S3Storage::create([
            'name' => 'other-s3',
            'region' => 'us-east-1',
            'key' => 'other-key',
            'secret' => 'other-secret',
            'bucket' => 'other-bucket',
            'endpoint' => 'https://s3.example.com',
            'team_id' => $otherTeam->id,
            'is_usable' => true,
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => '0 2 * * 0',
            'save_s3' => true,
            's3_storage_uuid' => $otherS3->uuid,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['s3_storage_uuid']);
    });

    test('validates frequency is required', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'enabled' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['frequency']);
    });

    test('validates s3_storage_uuid required when save_s3 is true', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
            'save_s3' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['s3_storage_uuid']);
    });

    test('rejects request without authentication', function () {
        $response = $this->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
        ]);

        $response->assertStatus(401);
    });
});

describe('PATCH /api/v1/databases/{uuid}/backups/{scheduled_backup_uuid}', function () {
    test('updates backup to use s3 storage via API token', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'enabled' => true,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
            'save_s3' => true,
            's3_storage_uuid' => $this->s3Storage->uuid,
        ]);

        $response->assertStatus(200);
        $backup->refresh();
        expect($backup->s3_storage_id)->toBe($this->s3Storage->id);
        expect($backup->save_s3)->toBeTrue();
    });

    test('updates backup to use pgbackrest via API token', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'enabled' => true,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
            'save_s3' => true,
            's3_storage_uuid' => $this->s3Storage->uuid,
            'backup_method' => 'pgbackrest',
            'pgbackrest_backup_type' => 'diff',
            'pgbackrest_require_wal_archive' => false,
        ]);

        $response->assertStatus(200);
        $backup->refresh();
        expect($backup->backup_method)->toBe('pgbackrest');
        expect($backup->pgbackrest_backup_type)->toBe('diff');
        expect($backup->pgbackrest_require_wal_archive)->toBeFalse();
        expect($backup->s3_storage_id)->toBe($this->s3Storage->id);
        expect($backup->save_s3)->toBeTrue();
        expect($backup->disable_local_backup)->toBeTrue();
        expect($backup->dump_all)->toBeTrue();
        expect($backup->databases_to_backup)->toBeNull();
    });

    test('rejects s3_storage_uuid from another team on update', function () {
        $otherTeam = Team::factory()->create();
        $otherS3 = S3Storage::unguarded(fn () => S3Storage::create([
            'name' => 'other-s3',
            'region' => 'us-east-1',
            'key' => 'other-key',
            'secret' => 'other-secret',
            'bucket' => 'other-bucket',
            'endpoint' => 'https://s3.example.com',
            'team_id' => $otherTeam->id,
            'is_usable' => true,
        ]));

        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'enabled' => true,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
            'save_s3' => true,
            's3_storage_uuid' => $otherS3->uuid,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['s3_storage_uuid']);
    });

});

describe('DELETE /api/v1/databases/{uuid}/backups/{scheduled_backup_uuid}/executions/{execution_uuid}', function () {
    test('rejects deleting a single pgbackrest execution from s3', function () {
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'enabled' => true,
            'save_s3' => true,
            's3_storage_id' => $this->s3Storage->id,
            'backup_method' => 'pgbackrest',
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);

        $execution = ScheduledDatabaseBackupExecution::create([
            'database_name' => 'postgres-cluster',
            'filename' => 'data/coolify/backups/databases/team/db/pgbackrest/coolify-backup/',
            'scheduled_database_backup_id' => $backup->id,
            'status' => 'success',
            's3_uploaded' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}/executions/{$execution->uuid}", [
            'delete_s3' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'A single pgBackRest execution cannot be deleted from S3 because executions share one repository. Delete the scheduled backup with delete_s3=true to remove the repository.');
    });
});
