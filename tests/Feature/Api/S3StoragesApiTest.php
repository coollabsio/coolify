<?php

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::query()->whereKey(0)->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;
});

function createS3StorageForTeam(Team $team, array $overrides = []): S3Storage
{
    return S3Storage::create(array_merge([
        'team_id' => $team->id,
        'name' => 'Team S3 Storage',
        'description' => 'Test storage',
        'region' => 'us-east-1',
        'key' => 'test-access-key',
        'secret' => 'test-secret-key',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.amazonaws.com',
        'is_usable' => false,
    ], $overrides));
}

function validS3StoragePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'My S3 Storage',
        'description' => 'Backup storage',
        'endpoint' => 'https://s3.amazonaws.com',
        'bucket' => 'coolify-backups',
        'region' => 'us-east-1',
        'key' => 'AKIAEXAMPLEKEY',
        'secret' => 'example-secret-value',
    ], $overrides);
}

describe('GET /api/v1/s3-storages', function () {
    test('lists all s3 storages for the team', function () {
        createS3StorageForTeam($this->team, ['name' => 'Storage One', 'bucket' => 'bucket-one']);
        createS3StorageForTeam($this->team, ['name' => 'Storage Two', 'bucket' => 'bucket-two']);
        createS3StorageForTeam($this->team, ['name' => 'Storage Three', 'bucket' => 'bucket-three']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/s3-storages');

        $response->assertStatus(200);
        $response->assertJsonCount(3);
        $response->assertJsonStructure([
            '*' => ['uuid', 'name', 'description', 'endpoint', 'bucket', 'region', 'is_usable', 'team_id', 'created_at', 'updated_at'],
        ]);
    });

    test('does not include storages from other teams', function () {
        createS3StorageForTeam($this->team);

        $otherTeam = Team::factory()->create();
        createS3StorageForTeam($otherTeam, ['name' => 'Other Team Storage', 'bucket' => 'other-bucket']);
        createS3StorageForTeam($otherTeam, ['name' => 'Other Team Storage 2', 'bucket' => 'other-bucket-2']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/s3-storages');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    });

    test('rejects request without authentication', function () {
        $response = $this->getJson('/api/v1/s3-storages');
        $response->assertStatus(401);
    });

    test('read token does not include key and secret values', function () {
        createS3StorageForTeam($this->team, [
            'key' => 'hidden-access-key',
            'secret' => 'hidden-secret-key',
        ]);

        $readToken = $this->user->createToken('read-token', ['read'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/s3-storages');

        $response->assertSuccessful();
        expect($response->getContent())->not->toContain('hidden-access-key');
        expect($response->getContent())->not->toContain('hidden-secret-key');
        expect($response->getContent())->not->toContain('"key":');
        expect($response->getContent())->not->toContain('"secret":');
    });

    test('read sensitive token includes key and secret values', function () {
        createS3StorageForTeam($this->team, [
            'key' => 'visible-access-key',
            'secret' => 'visible-secret-key',
        ]);

        $readSensitiveToken = $this->user->createToken('read-sensitive-token', ['read', 'read:sensitive'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readSensitiveToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/s3-storages');

        $response->assertSuccessful();
        $response->assertJsonFragment([
            'key' => 'visible-access-key',
            'secret' => 'visible-secret-key',
        ]);
    });

    test('root token includes key and secret values', function () {
        createS3StorageForTeam($this->team, [
            'key' => 'root-access-key',
            'secret' => 'root-secret-key',
        ]);

        $rootToken = $this->user->createToken('root-token', ['root'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$rootToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/s3-storages');

        $response->assertSuccessful();
        $response->assertJsonFragment([
            'key' => 'root-access-key',
            'secret' => 'root-secret-key',
        ]);
    });
});

describe('GET /api/v1/s3-storages/{uuid}', function () {
    test('gets s3 storage by UUID', function () {
        $storage = createS3StorageForTeam($this->team, [
            'name' => 'Primary Backup',
            'bucket' => 'primary-backup',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/s3-storages/{$storage->uuid}");

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'name' => 'Primary Backup',
            'bucket' => 'primary-backup',
            'region' => 'us-east-1',
        ]);
    });

    test('returns 404 for non-existent storage', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson('/api/v1/s3-storages/non-existent-uuid');

        $response->assertStatus(404);
    });

    test('cannot access storage from another team', function () {
        $otherTeam = Team::factory()->create();
        $storage = createS3StorageForTeam($otherTeam);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/s3-storages/{$storage->uuid}");

        $response->assertStatus(404);
    });

    test('read token does not include key and secret by UUID', function () {
        $storage = createS3StorageForTeam($this->team, [
            'key' => 'hidden-detail-key',
            'secret' => 'hidden-detail-secret',
        ]);

        $readToken = $this->user->createToken('read-token', ['read'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/s3-storages/{$storage->uuid}");

        $response->assertSuccessful();
        expect($response->getContent())->not->toContain('hidden-detail-key');
        expect($response->getContent())->not->toContain('hidden-detail-secret');
    });

    test('read sensitive token includes key and secret by UUID', function () {
        $storage = createS3StorageForTeam($this->team, [
            'key' => 'visible-detail-key',
            'secret' => 'visible-detail-secret',
        ]);

        $readSensitiveToken = $this->user->createToken('read-sensitive-token', ['read', 'read:sensitive'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readSensitiveToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/s3-storages/{$storage->uuid}");

        $response->assertSuccessful();
        $response->assertJsonFragment([
            'key' => 'visible-detail-key',
            'secret' => 'visible-detail-secret',
        ]);
    });
});

describe('POST /api/v1/s3-storages', function () {
    test('creates an s3 storage', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/s3-storages', validS3StoragePayload());

        $response->assertStatus(201);
        $response->assertJsonStructure(['uuid']);

        $this->assertDatabaseHas('s3_storages', [
            'team_id' => $this->team->id,
            'name' => 'My S3 Storage',
            'bucket' => 'coolify-backups',
            'region' => 'us-east-1',
        ]);
    });

    test('validates name is required', function () {
        $payload = validS3StoragePayload();
        unset($payload['name']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/s3-storages', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    test('validates key is required', function () {
        $payload = validS3StoragePayload();
        unset($payload['key']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/s3-storages', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['key']);
    });

    test('validates secret is required', function () {
        $payload = validS3StoragePayload();
        unset($payload['secret']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/s3-storages', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['secret']);
    });

    test('validates bucket format', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/s3-storages', validS3StoragePayload([
            'bucket' => 'Invalid_Bucket',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['bucket']);
    });

    test('rejects unsafe endpoints', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/s3-storages', validS3StoragePayload([
            'endpoint' => 'http://127.0.0.1:9000',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['endpoint']);
    });

    test('rejects extra fields not in allowed list', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/s3-storages', validS3StoragePayload([
            'invalid_field' => 'invalid_value',
        ]));

        $response->assertStatus(422);
    });
});

describe('PATCH /api/v1/s3-storages/{uuid}', function () {
    test('updates s3 storage name', function () {
        $storage = createS3StorageForTeam($this->team, ['name' => 'Old Name']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/s3-storages/{$storage->uuid}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('s3_storages', [
            'uuid' => $storage->uuid,
            'name' => 'New Name',
        ]);
    });

    test('updates multiple fields', function () {
        $storage = createS3StorageForTeam($this->team);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/s3-storages/{$storage->uuid}", [
            'name' => 'Updated Storage',
            'region' => 'eu-west-1',
            'bucket' => 'updated-bucket',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('s3_storages', [
            'uuid' => $storage->uuid,
            'name' => 'Updated Storage',
            'region' => 'eu-west-1',
            'bucket' => 'updated-bucket',
        ]);
    });

    test('rejects empty body', function () {
        $storage = createS3StorageForTeam($this->team);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/s3-storages/{$storage->uuid}", []);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Invalid request.',
            'error' => 'Invalid JSON.',
        ]);
    });

    test('cannot update storage from another team', function () {
        $otherTeam = Team::factory()->create();
        $storage = createS3StorageForTeam($otherTeam);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/s3-storages/{$storage->uuid}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(404);
    });

    test('rejects extra fields on update', function () {
        $storage = createS3StorageForTeam($this->team);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson("/api/v1/s3-storages/{$storage->uuid}", [
            'name' => 'New Name',
            'team_id' => 999,
        ]);

        $response->assertStatus(422);
    });
});

describe('DELETE /api/v1/s3-storages/{uuid}', function () {
    test('deletes s3 storage', function () {
        $storage = createS3StorageForTeam($this->team);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/s3-storages/{$storage->uuid}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'S3 storage deleted.']);

        $this->assertDatabaseMissing('s3_storages', [
            'uuid' => $storage->uuid,
        ]);
    });

    test('cannot delete storage from another team', function () {
        $otherTeam = Team::factory()->create();
        $storage = createS3StorageForTeam($otherTeam);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/s3-storages/{$storage->uuid}");

        $response->assertStatus(404);
    });

    test('returns 404 for non-existent storage', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson('/api/v1/s3-storages/non-existent-uuid');

        $response->assertStatus(404);
    });
});

describe('POST /api/v1/s3-storages/{uuid}/validate', function () {
    test('validates a working s3 storage connection', function () {
        $storage = createS3StorageForTeam($this->team);

        $disk = Mockery::mock();
        $disk->expects('files')->once()->andReturn([]);
        Storage::expects('build')->once()->andReturn($disk);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/s3-storages/{$storage->uuid}/validate");

        $response->assertStatus(200);
        $response->assertJson([
            'valid' => true,
            'message' => 'S3 storage connection is valid.',
        ]);

        expect($storage->fresh()->is_usable)->toBeTrue();
    });

    test('detects an invalid s3 storage connection', function () {
        $storage = createS3StorageForTeam($this->team, ['is_usable' => true]);

        $disk = Mockery::mock();
        $disk->expects('files')
            ->once()
            ->andThrow(new RuntimeException('Access Denied'));
        Storage::expects('build')->once()->andReturn($disk);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/s3-storages/{$storage->uuid}/validate");

        $response->assertStatus(200);
        $response->assertJson([
            'valid' => false,
            'message' => 'Access Denied',
        ]);

        expect($storage->fresh()->is_usable)->toBeFalse();
    });

    test('cannot validate storage from another team', function () {
        $otherTeam = Team::factory()->create();
        $storage = createS3StorageForTeam($otherTeam);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/s3-storages/{$storage->uuid}/validate");

        $response->assertStatus(404);
    });

    test('writes an audit log entry when validating storage', function () {
        $storage = createS3StorageForTeam($this->team, ['name' => 'Audit Storage']);

        $disk = Mockery::mock();
        $disk->expects('files')->once()->andReturn([]);
        Storage::expects('build')->once()->andReturn($disk);

        $auditChannel = Mockery::mock();
        $auditChannel->shouldReceive('info')
            ->once()
            ->with('api.s3_storage.validated', Mockery::on(function (array $context) use ($storage) {
                return $context['s3_storage_uuid'] === $storage->uuid
                    && $context['s3_storage_name'] === 'Audit Storage'
                    && $context['valid'] === true;
            }));

        Log::shouldReceive('channel')->with('audit')->andReturn($auditChannel);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/s3-storages/{$storage->uuid}/validate")
            ->assertOk();
    });
});
