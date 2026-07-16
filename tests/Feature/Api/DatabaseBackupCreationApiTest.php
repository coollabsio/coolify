<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test DB',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'image' => 'postgres:15',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->s3Storage = S3Storage::create([
        'name' => 'test-s3',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);
});

function backupHeaders(): array
{
    return [
        'Authorization' => 'Bearer '.test()->bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('POST /api/v1/databases/{uuid}/backups', function () {
    test('rejects backup configurations for unsupported database types', function () {
        $database = StandaloneRedis::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Redis DB',
            'status' => 'running',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$database->uuid}/backups", [
                'frequency' => 'daily',
            ]);

        $response->assertUnprocessable()
            ->assertJson([
                'message' => 'Scheduled backups are not supported for this database type.',
            ]);

        expect(ScheduledDatabaseBackup::count())->toBe(0);
    });

    test('defaults clickhouse backups to its configured database', function () {
        $database = StandaloneClickhouse::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'ClickHouse DB',
            'clickhouse_admin_user' => 'default',
            'clickhouse_admin_password' => 'password',
            'clickhouse_db' => 'analytics',
            'image' => 'clickhouse/clickhouse-server:25.11',
            'status' => 'running',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$database->uuid}/backups", [
                'frequency' => 'daily',
            ]);

        $response->assertCreated();

        $backup = ScheduledDatabaseBackup::where('uuid', $response->json('uuid'))->firstOrFail();

        expect($backup->databases_to_backup)->toBe('analytics')
            ->and($backup->database_type)->toBe(StandaloneClickhouse::class);
    });

    test('creates backup configuration with valid frequency', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                'frequency' => 'daily',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid', 'message']);
        $response->assertJson(['message' => 'Backup configuration created successfully.']);
    });

    test('creates backup with valid cron expression', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                'frequency' => '0 2 * * *',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid', 'message']);
    });

    test('accepts all predefined frequency values', function () {
        $frequencies = ['every_minute', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'];

        foreach ($frequencies as $frequency) {
            $response = $this->withHeaders(backupHeaders())
                ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                    'frequency' => $frequency,
                ]);

            $response->assertStatus(201, "Expected 201 for frequency '{$frequency}', got {$response->status()}");
        }
    });

    test('validates frequency is required', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                'enabled' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['frequency']);
    });

    test('rejects invalid frequency format', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                'frequency' => 'invalid-frequency',
            ]);

        $response->assertStatus(422);
    });

    test('validates s3_storage_uuid required when save_s3 is true', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                'frequency' => 'daily',
                'save_s3' => true,
            ]);

        $response->assertStatus(422);
    });

    test('validates retention fields are integers with minimum 0', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                'frequency' => 'daily',
                'database_backup_retention_amount_locally' => -1,
            ]);

        $response->assertStatus(422);
    });

    test('rejects extra fields not in allowed list', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                'frequency' => 'daily',
                'invalid_field' => 'invalid_value',
            ]);

        $response->assertStatus(422);
    });

    test('rejects request without authentication', function () {
        $response = $this->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
        ]);

        $response->assertStatus(401);
    });

    test('returns 404 for non-existent database uuid', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson('/api/v1/databases/non-existent-uuid/backups', [
                'frequency' => 'daily',
            ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Database not found.']);
    });

    test('creates backup with s3 storage via API token', function () {
        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
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

    test('rejects s3_storage_uuid from another team', function () {
        $otherTeam = Team::factory()->create();
        $otherS3 = S3Storage::create([
            'name' => 'other-s3',
            'region' => 'us-east-1',
            'key' => 'other-key',
            'secret' => 'other-secret',
            'bucket' => 'other-bucket',
            'endpoint' => 'https://s3.example.com',
            'team_id' => $otherTeam->id,
            'is_usable' => true,
        ]);

        $response = $this->withHeaders(backupHeaders())
            ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
                'frequency' => '0 2 * * 0',
                'save_s3' => true,
                's3_storage_uuid' => $otherS3->uuid,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['s3_storage_uuid']);
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

        $response = $this->withHeaders(backupHeaders())
            ->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
                'save_s3' => true,
                's3_storage_uuid' => $this->s3Storage->uuid,
            ]);

        $response->assertStatus(200);
        $backup->refresh();
        expect($backup->s3_storage_id)->toBe($this->s3Storage->id);
        expect($backup->save_s3)->toBeTrue();
    });

    test('rejects s3_storage_uuid from another team on update', function () {
        $otherTeam = Team::factory()->create();
        $otherS3 = S3Storage::create([
            'name' => 'other-s3',
            'region' => 'us-east-1',
            'key' => 'other-key',
            'secret' => 'other-secret',
            'bucket' => 'other-bucket',
            'endpoint' => 'https://s3.example.com',
            'team_id' => $otherTeam->id,
            'is_usable' => true,
        ]);

        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'enabled' => true,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders(backupHeaders())
            ->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", [
                'save_s3' => true,
                's3_storage_uuid' => $otherS3->uuid,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['s3_storage_uuid']);
    });
});
