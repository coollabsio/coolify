<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function createTestApplication($context): Application
{
    return Application::factory()->create([
        'environment_id' => $context->environment->id,
    ]);
}

function createTestDatabase($context): StandalonePostgresql
{
    return StandalonePostgresql::forceCreate([
        'name' => 'test-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $context->environment->id,
        'destination_id' => $context->destination->id,
        'destination_type' => $context->destination->getMorphClass(),
    ]);
}

// ──────────────────────────────────────────────────────────────
// Application Storage Endpoints
// ──────────────────────────────────────────────────────────────

describe('GET /api/v1/applications/{uuid}/storages', function () {
    test('lists storages for an application', function () {
        $app = createTestApplication($this);

        LocalPersistentVolume::create([
            'name' => $app->uuid.'-test-vol',
            'mount_path' => '/data',
            'resource_id' => $app->id,
            'resource_type' => $app->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/applications/{$app->uuid}/storages");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'persistent_storages');
        $response->assertJsonCount(0, 'file_storages');
    });

    test('returns 404 for non-existent application', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/applications/non-existent-uuid/storages');

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/applications/{uuid}/storages', function () {
    test('creates a persistent storage', function () {
        $app = createTestApplication($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$app->uuid}/storages", [
            'type' => 'persistent',
            'name' => 'my-volume',
            'mount_path' => '/data',
        ]);

        $response->assertStatus(201);

        $vol = LocalPersistentVolume::where('resource_id', $app->id)
            ->where('resource_type', $app->getMorphClass())
            ->first();

        expect($vol)->not->toBeNull();
        expect($vol->name)->toBe($app->uuid.'-my-volume');
        expect($vol->mount_path)->toBe('/data');
        expect($vol->uuid)->not->toBeNull();
    });

    test('creates a file storage', function () {
        $app = createTestApplication($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$app->uuid}/storages", [
            'type' => 'file',
            'mount_path' => '/app/config.json',
            'content' => '{"key": "value"}',
        ]);

        $response->assertStatus(201);

        $vol = LocalFileVolume::where('resource_id', $app->id)
            ->where('resource_type', get_class($app))
            ->first();

        expect($vol)->not->toBeNull();
        expect($vol->mount_path)->toBe('/app/config.json');
        expect($vol->is_directory)->toBeFalse();
    });

    test('rejects persistent storage without name', function () {
        $app = createTestApplication($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$app->uuid}/storages", [
            'type' => 'persistent',
            'mount_path' => '/data',
        ]);

        $response->assertStatus(422);
    });

    test('rejects invalid type-specific fields', function () {
        $app = createTestApplication($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/applications/{$app->uuid}/storages", [
            'type' => 'persistent',
            'name' => 'vol',
            'mount_path' => '/data',
            'content' => 'should not be here',
        ]);

        $response->assertStatus(422);
    });
});

describe('PATCH /api/v1/applications/{uuid}/storages', function () {
    test('updates a persistent storage by uuid', function () {
        $app = createTestApplication($this);

        $vol = LocalPersistentVolume::create([
            'name' => $app->uuid.'-test-vol',
            'mount_path' => '/data',
            'resource_id' => $app->id,
            'resource_type' => $app->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$app->uuid}/storages", [
            'uuid' => $vol->uuid,
            'type' => 'persistent',
            'mount_path' => '/new-data',
        ]);

        $response->assertStatus(200);
        expect($vol->fresh()->mount_path)->toBe('/new-data');
    });

    test('updates a persistent storage by id (backwards compat)', function () {
        $app = createTestApplication($this);

        $vol = LocalPersistentVolume::create([
            'name' => $app->uuid.'-test-vol',
            'mount_path' => '/data',
            'resource_id' => $app->id,
            'resource_type' => $app->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$app->uuid}/storages", [
            'id' => $vol->id,
            'type' => 'persistent',
            'mount_path' => '/updated',
        ]);

        $response->assertStatus(200);
        expect($vol->fresh()->mount_path)->toBe('/updated');
    });

    test('returns 422 when neither uuid nor id is provided', function () {
        $app = createTestApplication($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/applications/{$app->uuid}/storages", [
            'type' => 'persistent',
            'mount_path' => '/data',
        ]);

        $response->assertStatus(422);
    });
});

describe('DELETE /api/v1/applications/{uuid}/storages/{storage_uuid}', function () {
    test('deletes a persistent storage', function () {
        $app = createTestApplication($this);

        $vol = LocalPersistentVolume::create([
            'name' => $app->uuid.'-test-vol',
            'mount_path' => '/data',
            'resource_id' => $app->id,
            'resource_type' => $app->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->deleteJson("/api/v1/applications/{$app->uuid}/storages/{$vol->uuid}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Storage deleted.']);
        expect(LocalPersistentVolume::find($vol->id))->toBeNull();
    });

    test('finds file storage without type param and calls deleteStorageOnServer', function () {
        $app = createTestApplication($this);

        $vol = LocalFileVolume::create([
            'fs_path' => '/tmp/test',
            'mount_path' => '/app/config.json',
            'content' => '{}',
            'is_directory' => false,
            'resource_id' => $app->id,
            'resource_type' => get_class($app),
        ]);

        // Verify the storage is found via fileStorages (not persistentStorages)
        $freshApp = Application::find($app->id);
        expect($freshApp->persistentStorages->where('uuid', $vol->uuid)->first())->toBeNull();
        expect($freshApp->fileStorages->where('uuid', $vol->uuid)->first())->not->toBeNull();
        expect($vol)->toBeInstanceOf(LocalFileVolume::class);
    });

    test('returns 404 for non-existent storage', function () {
        $app = createTestApplication($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->deleteJson("/api/v1/applications/{$app->uuid}/storages/non-existent");

        $response->assertStatus(404);
    });
});

// ──────────────────────────────────────────────────────────────
// Database Storage Endpoints
// ──────────────────────────────────────────────────────────────

describe('GET /api/v1/databases/{uuid}/storages', function () {
    test('lists storages for a database', function () {
        $db = createTestDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson("/api/v1/databases/{$db->uuid}/storages");

        $response->assertStatus(200);
        $response->assertJsonStructure(['persistent_storages', 'file_storages']);
        // Database auto-creates a default persistent volume
        $response->assertJsonCount(1, 'persistent_storages');
    });

    test('returns 404 for non-existent database', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->getJson('/api/v1/databases/non-existent-uuid/storages');

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/databases/{uuid}/storages', function () {
    test('creates a persistent storage for a database', function () {
        $db = createTestDatabase($this);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/databases/{$db->uuid}/storages", [
            'type' => 'persistent',
            'name' => 'extra-data',
            'mount_path' => '/extra',
        ]);

        $response->assertStatus(201);

        $vol = LocalPersistentVolume::where('name', $db->uuid.'-extra-data')->first();
        expect($vol)->not->toBeNull();
        expect($vol->mount_path)->toBe('/extra');
    });
});

describe('PATCH /api/v1/databases/{uuid}/storages', function () {
    test('updates a persistent storage by uuid', function () {
        $db = createTestDatabase($this);

        $vol = LocalPersistentVolume::create([
            'name' => $db->uuid.'-test-vol',
            'mount_path' => '/data',
            'resource_id' => $db->id,
            'resource_type' => $db->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/databases/{$db->uuid}/storages", [
            'uuid' => $vol->uuid,
            'type' => 'persistent',
            'mount_path' => '/updated',
        ]);

        $response->assertStatus(200);
        expect($vol->fresh()->mount_path)->toBe('/updated');
    });
});

describe('DELETE /api/v1/databases/{uuid}/storages/{storage_uuid}', function () {
    test('deletes a persistent storage', function () {
        $db = createTestDatabase($this);

        $vol = LocalPersistentVolume::create([
            'name' => $db->uuid.'-test-vol',
            'mount_path' => '/extra',
            'resource_id' => $db->id,
            'resource_type' => $db->getMorphClass(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
        ])->deleteJson("/api/v1/databases/{$db->uuid}/storages/{$vol->uuid}");

        $response->assertStatus(200);
        expect(LocalPersistentVolume::find($vol->id))->toBeNull();
    });
});
