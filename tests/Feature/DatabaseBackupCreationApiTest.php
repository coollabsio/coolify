<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

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
