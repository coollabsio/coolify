<?php

use App\Livewire\Project\Shared\HealthChecks;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $team = Team::factory()->create();
    $this->healthcheckMigrationOwner = User::factory()->create();
    $team->members()->attach($this->healthcheckMigrationOwner->id, ['role' => 'owner']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $this->healthcheckMigrationEnvironment = $project->environments()->firstOrFail();
    $this->healthcheckMigrationDestination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
});

function keepDockerfileHealthcheckApplication(bool $healthCheckEnabled, bool $customHealthcheckFound): Application
{
    return Application::factory()->create([
        'environment_id' => test()->healthcheckMigrationEnvironment->id,
        'destination_id' => test()->healthcheckMigrationDestination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerfile',
        'health_check_enabled' => $healthCheckEnabled,
        'custom_healthcheck_found' => $customHealthcheckFound,
    ])->fresh();
}

test('disables the Coolify healthcheck only for applications with a Dockerfile HEALTHCHECK', function () {
    $dockerfileAndCoolify = keepDockerfileHealthcheckApplication(healthCheckEnabled: true, customHealthcheckFound: true);
    $dockerfileOnly = keepDockerfileHealthcheckApplication(healthCheckEnabled: false, customHealthcheckFound: true);
    $coolifyOnly = keepDockerfileHealthcheckApplication(healthCheckEnabled: true, customHealthcheckFound: false);
    $none = keepDockerfileHealthcheckApplication(healthCheckEnabled: false, customHealthcheckFound: false);

    (require database_path('migrations/2026_10_28_124421_keep_dockerfile_healthcheck_for_existing_applications.php'))->up();

    expect($dockerfileAndCoolify->fresh()->health_check_enabled)->toBeFalse()
        ->and($dockerfileAndCoolify->fresh()->custom_healthcheck_found)->toBeTrue()
        ->and($dockerfileOnly->fresh()->health_check_enabled)->toBeFalse()
        ->and($coolifyOnly->fresh()->health_check_enabled)->toBeTrue()
        ->and($none->fresh()->health_check_enabled)->toBeFalse();
});

test('the healthcheck page says whether the Dockerfile HEALTHCHECK is in use or replaced', function () {
    $this->actingAs($this->healthcheckMigrationOwner);

    Livewire::test(HealthChecks::class, ['resource' => keepDockerfileHealthcheckApplication(healthCheckEnabled: false, customHealthcheckFound: true)])
        ->assertSee('Dockerfile HEALTHCHECK in use')
        ->assertDontSee('Dockerfile HEALTHCHECK replaced');

    Livewire::test(HealthChecks::class, ['resource' => keepDockerfileHealthcheckApplication(healthCheckEnabled: true, customHealthcheckFound: true)])
        ->assertSee('Dockerfile HEALTHCHECK replaced')
        ->assertDontSee('Dockerfile HEALTHCHECK in use');

    Livewire::test(HealthChecks::class, ['resource' => keepDockerfileHealthcheckApplication(healthCheckEnabled: true, customHealthcheckFound: false)])
        ->assertDontSee('Dockerfile HEALTHCHECK');
});
