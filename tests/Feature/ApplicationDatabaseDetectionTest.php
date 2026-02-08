<?php

/**
 * Tests for database detection in Docker Compose deployments via GitHub App.
 *
 * @see https://github.com/coollabsio/coolify/issues/7528
 */

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create the basic entities needed for an Application
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
    ]);
});

describe('ServiceDatabase model with Application support', function () {
    test('ServiceDatabase can be associated with an Application', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $database = ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        expect($database->application_id)->toBe($application->id);
        expect($database->application)->toBeInstanceOf(Application::class);
        expect($database->application->id)->toBe($application->id);
    });

    test('ServiceDatabase getParentResource returns Application when application_id is set', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $database = ServiceDatabase::create([
            'name' => 'mysql',
            'image' => 'mysql:8',
            'application_id' => $application->id,
        ]);

        expect($database->getParentResource())->toBeInstanceOf(Application::class);
        expect($database->getParentResource()->id)->toBe($application->id);
    });

    test('ServiceDatabase team() returns correct team for Application-based database', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $database = ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        expect($database->team())->not->toBeNull();
        expect($database->team()->id)->toBe($this->team->id);
    });

    test('ServiceDatabase workdir returns application configuration directory', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $database = ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        $workdir = $database->workdir();
        expect($workdir)->toContain($application->uuid);
        expect($workdir)->toContain('applications');
    });

    test('ServiceDatabase getServer returns server for Application-based database', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $database = ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        expect($database->getServer())->not->toBeNull();
        expect($database->getServer()->id)->toBe($this->server->id);
    });
});

describe('Application model with databases relationship', function () {
    test('Application has databases relationship', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        expect($application->databases())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    test('Application databases returns associated ServiceDatabase records', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        ServiceDatabase::create([
            'name' => 'redis',
            'image' => 'redis:7',
            'application_id' => $application->id,
        ]);

        expect($application->databases->count())->toBe(2);
        expect($application->databases->pluck('name')->toArray())->toContain('postgres', 'redis');
    });

    test('ServiceDatabase records are deleted when Application is force deleted', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $database = ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        $databaseId = $database->id;

        $application->forceDelete();

        expect(ServiceDatabase::find($databaseId))->toBeNull();
    });
});

describe('ServiceDatabase backup support for Application databases', function () {
    test('Application-based ServiceDatabase supports scheduled backups', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $database = ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        // Verify that scheduledBackups relationship exists
        expect($database->scheduledBackups())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });

    test('Application-based ServiceDatabase isBackupSolutionAvailable returns true for supported databases', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $postgresDb = ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        $mysqlDb = ServiceDatabase::create([
            'name' => 'mysql',
            'image' => 'mysql:8',
            'application_id' => $application->id,
        ]);

        $mariaDb = ServiceDatabase::create([
            'name' => 'mariadb',
            'image' => 'mariadb:10',
            'application_id' => $application->id,
        ]);

        $mongoDb = ServiceDatabase::create([
            'name' => 'mongo',
            'image' => 'mongo:6',
            'application_id' => $application->id,
        ]);

        expect($postgresDb->isBackupSolutionAvailable())->toBeTrue();
        expect($mysqlDb->isBackupSolutionAvailable())->toBeTrue();
        expect($mariaDb->isBackupSolutionAvailable())->toBeTrue();
        expect($mongoDb->isBackupSolutionAvailable())->toBeTrue();
    });

    test('Application-based ServiceDatabase databaseType returns correct type', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
        ]);

        $database = ServiceDatabase::create([
            'name' => 'postgres',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        expect($database->databaseType())->toBe('standalone-postgresql');
    });
});

describe('parseDockerComposeFile database detection for Application', function () {
    test('isDatabaseImage detects PostgreSQL', function () {
        expect(isDatabaseImage('postgres:15', []))->toBeTrue();
        expect(isDatabaseImage('postgres:latest', []))->toBeTrue();
        expect(isDatabaseImage('postgres', []))->toBeTrue();
    });

    test('isDatabaseImage detects MySQL', function () {
        expect(isDatabaseImage('mysql:8', []))->toBeTrue();
        expect(isDatabaseImage('mysql:latest', []))->toBeTrue();
        expect(isDatabaseImage('mysql', []))->toBeTrue();
    });

    test('isDatabaseImage detects MariaDB', function () {
        expect(isDatabaseImage('mariadb:10', []))->toBeTrue();
        expect(isDatabaseImage('mariadb:latest', []))->toBeTrue();
        expect(isDatabaseImage('mariadb', []))->toBeTrue();
    });

    test('isDatabaseImage detects MongoDB', function () {
        expect(isDatabaseImage('mongo:6', []))->toBeTrue();
        expect(isDatabaseImage('mongo:latest', []))->toBeTrue();
        expect(isDatabaseImage('mongo', []))->toBeTrue();
    });

    test('isDatabaseImage detects Redis', function () {
        expect(isDatabaseImage('redis:7', []))->toBeTrue();
        expect(isDatabaseImage('redis:latest', []))->toBeTrue();
        expect(isDatabaseImage('redis', []))->toBeTrue();
    });

    test('isDatabaseImage returns false for non-database images', function () {
        expect(isDatabaseImage('nginx:latest', []))->toBeFalse();
        expect(isDatabaseImage('node:18', []))->toBeFalse();
        expect(isDatabaseImage('php:8.2', []))->toBeFalse();
    });
});
