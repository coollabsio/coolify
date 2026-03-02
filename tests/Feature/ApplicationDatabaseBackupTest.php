<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createApplicationWithDatabase(): array
{
    $team = Team::factory()->create();

    $project = Project::create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $team->id,
    ]);

    $environment = Environment::create([
        'name' => 'test-env-'.Illuminate\Support\Str::random(8),
        'project_id' => $project->id,
    ]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
    ]);

    $destination = StandaloneDocker::create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'name' => 'test-docker',
        'server_id' => $server->id,
        'network' => 'coolify',
    ]);

    $application = Application::create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'name' => 'my-compose-app',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
    ]);

    ApplicationSetting::create([
        'application_id' => $application->id,
    ]);

    $serviceDatabase = ServiceDatabase::create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'name' => 'postgres',
        'application_id' => $application->id,
        'image' => 'postgres:16',
    ]);

    return [$team, $application, $serviceDatabase, $server];
}

it('creates a ServiceDatabase owned by an Application', function () {
    [$team, $application, $serviceDatabase] = createApplicationWithDatabase();

    expect($serviceDatabase->application_id)->toBe($application->id)
        ->and($serviceDatabase->service_id)->toBeNull()
        ->and($serviceDatabase->application->id)->toBe($application->id);
});

it('resolves team through application relationship', function () {
    [$team, $application, $serviceDatabase] = createApplicationWithDatabase();

    expect($serviceDatabase->team())->not->toBeNull()
        ->and($serviceDatabase->team()->id)->toBe($team->id);
});

it('resolves server through application destination', function () {
    [$team, $application, $serviceDatabase, $server] = createApplicationWithDatabase();

    expect($serviceDatabase->server())->not->toBeNull()
        ->and($serviceDatabase->server()->id)->toBe($server->id);
});

it('lists application databases in the databases relationship', function () {
    [$team, $application, $serviceDatabase] = createApplicationWithDatabase();

    expect($application->databases)->toHaveCount(1)
        ->and($application->databases->first()->id)->toBe($serviceDatabase->id);
});

it('detects backup availability for postgres image', function () {
    [$team, $application, $serviceDatabase] = createApplicationWithDatabase();

    expect($serviceDatabase->isBackupSolutionAvailable())->toBeTrue();
});

it('returns correct database type for postgres image', function () {
    [$team, $application, $serviceDatabase] = createApplicationWithDatabase();

    expect($serviceDatabase->databaseType())->toBe('standalone-postgresql');
});

it('returns correct workdir for application-owned database', function () {
    [$team, $application, $serviceDatabase] = createApplicationWithDatabase();

    $expected = base_configuration_dir().'/applications/'.$application->uuid;
    expect($serviceDatabase->workdir())->toBe($expected);
});

it('creates scheduled backup for application-owned database', function () {
    [$team, $application, $serviceDatabase] = createApplicationWithDatabase();

    $backup = ScheduledDatabaseBackup::create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'database_id' => $serviceDatabase->id,
        'database_type' => ServiceDatabase::class,
        'frequency' => '0 0 * * *',
        'team_id' => $team->id,
    ]);

    expect($backup->database)->toBeInstanceOf(ServiceDatabase::class)
        ->and($backup->database->id)->toBe($serviceDatabase->id)
        ->and($serviceDatabase->scheduledBackups)->toHaveCount(1);
});
