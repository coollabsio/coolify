<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => false,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->storage = createPostgresqlPitrCreationStorage($this->team, 'primary');

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('keeps regular PostgreSQL creation unchanged', function () {
    $response = $this->get(postgresqlCreationUrl($this, [
        'database_image' => 'postgres:18-alpine',
    ]));

    $database = StandalonePostgresql::query()->sole();
    $response->assertRedirectToRoute('project.database.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'database_uuid' => $database->uuid,
    ]);
    expect($database->image)->toBe('postgres:18-alpine')
        ->and($database->walBackupConfiguration()->exists())->toBeFalse();
});

it('creates a PITR PostgreSQL database and configuration atomically', function () {
    $response = $this->get(postgresqlCreationUrl($this, [
        'database_mode' => 'pitr',
        'database_image' => 'ghcr.io/coollabsio/postgres-walg:18',
        's3_storage_uuid' => $this->storage->uuid,
    ]));

    $database = StandalonePostgresql::query()->sole();
    $configuration = PostgresqlWalBackupConfiguration::query()->sole();
    $response->assertRedirectToRoute('project.database.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'database_uuid' => $database->uuid,
    ]);
    expect($database->image)->toBe('ghcr.io/coollabsio/postgres-walg:18')
        ->and($configuration->database->is($database))->toBeTrue()
        ->and($configuration->team->is($this->team))->toBeTrue()
        ->and($configuration->s3->is($this->storage))->toBeTrue()
        ->and($configuration->enabled)->toBeTrue()
        ->and($configuration->status)->toBe('warning')
        ->and($configuration->postgres_major_version)->toBe(18);
});

it('requires owned usable S3 storage for PITR creation', function () {
    $foreignTeam = Team::factory()->create();
    $foreignStorage = createPostgresqlPitrCreationStorage($foreignTeam, 'foreign');

    $this->get(postgresqlCreationUrl($this, [
        'database_mode' => 'pitr',
        'database_image' => 'ghcr.io/coollabsio/postgres-walg:16',
        's3_storage_uuid' => $foreignStorage->uuid,
    ]))->assertSuccessful();

    expect(StandalonePostgresql::query()->count())->toBe(0)
        ->and(PostgresqlWalBackupConfiguration::query()->count())->toBe(0);
});

it('rejects unsupported images for PITR creation', function () {
    $this->get(postgresqlCreationUrl($this, [
        'database_mode' => 'pitr',
        'database_image' => 'postgres:18-alpine',
        's3_storage_uuid' => $this->storage->uuid,
    ]))->assertSuccessful();

    expect(StandalonePostgresql::query()->count())->toBe(0)
        ->and(PostgresqlWalBackupConfiguration::query()->count())->toBe(0);
});

function postgresqlCreationUrl(object $test, array $query): string
{
    return route('project.resource.create', [
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
    ]).'?'.http_build_query([
        'type' => 'postgresql',
        'destination' => $test->destination->uuid,
        'server_id' => $test->server->id,
        ...$query,
    ]);
}

function createPostgresqlPitrCreationStorage(Team $team, string $name): S3Storage
{
    return S3Storage::create([
        'team_id' => $team->id,
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket-'.$name,
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
    ]);
}
