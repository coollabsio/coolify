<?php

use App\Jobs\PostgresqlWalBaseBackupJob;
use App\Jobs\PostgresqlWalHealthCheckJob;
use App\Jobs\PostgresqlWalRestoreJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PostgresqlWalBackupExecution;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => 'member']);
    session(['currentTeam' => $this->team]);

    $this->tokens = [
        'all' => $this->owner->createToken('all', ['*'])->plainTextToken,
        'read' => $this->owner->createToken('read', ['read'])->plainTextToken,
        'write' => $this->owner->createToken('write', ['write'])->plainTextToken,
        'deploy' => $this->owner->createToken('deploy', ['deploy'])->plainTextToken,
        'member_write' => $this->member->createToken('member-write', ['write'])->plainTextToken,
        'member_deploy' => $this->member->createToken('member-deploy', ['deploy'])->plainTextToken,
    ];

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->database = createPostgresqlPitrApiDatabase(
        $this->environment,
        $this->destination,
        'pitr-postgres',
        'ghcr.io/coollabsio/postgres-walg:16',
    );
    $this->storage = createPostgresqlPitrApiStorage($this->team, 'primary');
    $this->configuration = PostgresqlWalBackupConfiguration::create([
        'team_id' => $this->team->id,
        'standalone_postgresql_id' => $this->database->id,
        's3_storage_id' => $this->storage->id,
        'enabled' => true,
        'postgres_major_version' => 16,
        'status' => 'warning',
    ]);
    PostgresqlWalBackupExecution::create([
        'postgresql_wal_backup_configuration_id' => $this->configuration->id,
        'operation' => 'health_check',
        'status' => 'success',
        'message' => 'WAL archiving is healthy.',
        'finished_at' => now(),
    ]);

    Queue::fake();
});

it('returns a credential-safe PITR configuration to readers', function () {
    $response = $this->withHeaders(pitrApiHeaders($this->tokens['read']))
        ->getJson(pitrApiUrl($this->database));

    $response->assertSuccessful()
        ->assertJsonPath('database_uuid', $this->database->uuid)
        ->assertJsonPath('configuration.s3_storage_uuid', $this->storage->uuid)
        ->assertJsonPath('configuration.status', 'warning')
        ->assertJsonCount(1, 'executions');

    expect($response->getContent())
        ->not->toContain('top-secret')
        ->not->toContain('access-key')
        ->not->toContain('"secret"')
        ->not->toContain('"key"');
});

it('returns not found for regular PostgreSQL and non-PostgreSQL databases', function () {
    $regularDatabase = createPostgresqlPitrApiDatabase(
        $this->environment,
        $this->destination,
        'regular-postgres',
        'postgres:16-alpine',
    );
    $redis = StandaloneRedis::create([
        'name' => 'redis',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->withHeaders(pitrApiHeaders($this->tokens['read']))
        ->getJson(pitrApiUrl($regularDatabase))
        ->assertNotFound();
    $this->withHeaders(pitrApiHeaders($this->tokens['read']))
        ->getJson(pitrApiUrl($redis))
        ->assertNotFound();
});

it('updates PITR settings with write ability and marks them pending restart', function () {
    $response = $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->putJson(pitrApiUrl($this->database), [
            'base_backup_frequency' => 'hourly',
            'archive_timeout_seconds' => 120,
            'wal_level' => 'logical',
            'retention_full_backups' => 5,
            'timeout' => 7200,
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('configuration.status', 'pending_restart')
        ->assertJsonPath('configuration.base_backup_frequency', 'hourly');

    $configuration = $this->configuration->fresh();
    expect($configuration->archive_timeout_seconds)->toBe(120)
        ->and($configuration->wal_level)->toBe('logical')
        ->and($configuration->retention_full_backups)->toBe(5)
        ->and($configuration->timeout)->toBe(7200);
});

it('reattaches only owned usable storage and does not queue a premature base backup', function () {
    $replacement = createPostgresqlPitrApiStorage($this->team, 'replacement');
    $this->configuration->update([
        's3_storage_id' => null,
        'enabled' => false,
        'status' => 'failed',
        'last_base_backup_at' => now()->subHours(2),
        'last_successful_base_backup_at' => now()->subHour(),
    ]);

    $response = $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->putJson(pitrApiUrl($this->database), [
            's3_storage_uuid' => $replacement->uuid,
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('configuration.s3_storage_uuid', $replacement->uuid)
        ->assertJsonPath('configuration.enabled', true)
        ->assertJsonPath('configuration.status', 'pending_restart')
        ->assertJsonPath('configuration.last_base_backup_at', null)
        ->assertJsonPath('configuration.last_successful_base_backup_at', null);
    Queue::assertNotPushed(PostgresqlWalBaseBackupJob::class);
});

it('keeps a detached configuration failed until storage is reattached', function () {
    $this->configuration->update([
        's3_storage_id' => null,
        'enabled' => false,
        'status' => 'failed',
    ]);

    $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->putJson(pitrApiUrl($this->database), [
            'base_backup_frequency' => 'hourly',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('s3_storage_uuid');

    expect($this->configuration->fresh()->status)->toBe('failed')
        ->and($this->configuration->fresh()->enabled)->toBeFalse();
});

it('rejects active storage swaps and cross-team storage reattachment', function () {
    $replacement = createPostgresqlPitrApiStorage($this->team, 'replacement');

    $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->putJson(pitrApiUrl($this->database), ['s3_storage_uuid' => $replacement->uuid])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('s3_storage_uuid');

    $otherTeam = Team::factory()->create();
    $foreignStorage = createPostgresqlPitrApiStorage($otherTeam, 'foreign');
    $this->configuration->update([
        's3_storage_id' => null,
        'enabled' => false,
        'status' => 'failed',
    ]);

    $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->putJson(pitrApiUrl($this->database), ['s3_storage_uuid' => $foreignStorage->uuid])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('s3_storage_uuid');
});

it('validates PITR settings and rejects unknown fields', function (array $payload, string $field) {
    $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->putJson(pitrApiUrl($this->database), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'frequency' => [['base_backup_frequency' => 'not a schedule'], 'base_backup_frequency'],
    'archive timeout' => [['archive_timeout_seconds' => 0], 'archive_timeout_seconds'],
    'WAL level' => [['wal_level' => 'minimal'], 'wal_level'],
    'retention' => [['retention_full_backups' => 0], 'retention_full_backups'],
    'timeout' => [['timeout' => 59], 'timeout'],
    'unknown field' => [['enabled' => false], 'enabled'],
]);

it('does not enable PITR on a regular PostgreSQL database through PUT', function () {
    $regularDatabase = createPostgresqlPitrApiDatabase(
        $this->environment,
        $this->destination,
        'regular-postgres',
        'postgres:16-alpine',
    );

    $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->putJson(pitrApiUrl($regularDatabase), ['base_backup_frequency' => 'daily'])
        ->assertNotFound();

    expect($regularDatabase->walBackupConfiguration()->exists())->toBeFalse();
});

it('requires backup policy authorization for every mutation', function (string $method, string $suffix, string $token, array $payload) {
    $this->withHeaders(pitrApiHeaders($this->tokens[$token]))
        ->json($method, pitrApiUrl($this->database, $suffix), $payload)
        ->assertForbidden();
})->with([
    'settings' => ['PUT', '', 'member_write', ['base_backup_frequency' => 'daily']],
    'health check' => ['POST', '/health-check', 'member_write', []],
    'base backup' => ['POST', '/base-backup', 'member_deploy', []],
    'restore' => ['POST', '/restore', 'member_deploy', [
        'target_time' => '2026-01-01T00:00:00Z',
        'name' => 'restored-postgres',
    ]],
]);

it('enforces token abilities and queues manual PITR operations', function () {
    $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->postJson(pitrApiUrl($this->database, '/base-backup'))
        ->assertForbidden();
    auth()->forgetGuards();
    $this->withHeaders(pitrApiHeaders($this->tokens['deploy']))
        ->postJson(pitrApiUrl($this->database, '/base-backup'))
        ->assertAccepted();

    auth()->forgetGuards();
    $this->withHeaders(pitrApiHeaders($this->tokens['deploy']))
        ->postJson(pitrApiUrl($this->database, '/health-check'))
        ->assertForbidden();
    auth()->forgetGuards();
    $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->postJson(pitrApiUrl($this->database, '/health-check'))
        ->assertAccepted();

    Queue::assertPushed(
        PostgresqlWalBaseBackupJob::class,
        fn (PostgresqlWalBaseBackupJob $job) => $job->retryWhenBusy,
    );
    Queue::assertPushed(PostgresqlWalHealthCheckJob::class);
});

it('queues restores with deploy ability and rejects invalid targets', function () {
    $validPayload = [
        'target_time' => now()->subMinute()->utc()->toIso8601ZuluString(),
        'name' => 'restored-postgres',
        'description' => 'Restored by API',
    ];

    $this->withHeaders(pitrApiHeaders($this->tokens['write']))
        ->postJson(pitrApiUrl($this->database, '/restore'), $validPayload)
        ->assertForbidden();
    auth()->forgetGuards();
    $this->withHeaders(pitrApiHeaders($this->tokens['deploy']))
        ->postJson(pitrApiUrl($this->database, '/restore'), $validPayload)
        ->assertAccepted()
        ->assertJsonStructure(['message', 'execution_uuid']);

    Queue::assertPushed(
        PostgresqlWalRestoreJob::class,
        fn (PostgresqlWalRestoreJob $job) => $job->name === 'restored-postgres'
            && $job->description === 'Restored by API',
    );

    auth()->forgetGuards();
    $this->withHeaders(pitrApiHeaders($this->tokens['deploy']))
        ->postJson(pitrApiUrl($this->database, '/restore'), [
            'target_time' => now()->subMinute()->format('Y-m-d\TH:i:s'),
            'name' => 'restored-postgres',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('target_time');
    auth()->forgetGuards();
    $this->withHeaders(pitrApiHeaders($this->tokens['deploy']))
        ->postJson(pitrApiUrl($this->database, '/restore'), [
            'target_time' => now()->addMinute()->utc()->toIso8601ZuluString(),
            'name' => 'restored-postgres',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('target_time');
});

function pitrApiHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ];
}

function pitrApiUrl(object $database, string $suffix = ''): string
{
    return "/api/v1/databases/{$database->uuid}/point-in-time-recovery{$suffix}";
}

function createPostgresqlPitrApiDatabase(
    Environment $environment,
    StandaloneDocker $destination,
    string $name,
    string $image,
): StandalonePostgresql {
    return StandalonePostgresql::create([
        'name' => $name,
        'image' => $image,
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'running:healthy',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createPostgresqlPitrApiStorage(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'team_id' => $team->id,
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'top-secret',
        'bucket' => 'bucket-'.$name,
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
    ]);
}
