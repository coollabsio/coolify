<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();
});

afterEach(function () {
    Server::flushIdentityMap();
});

function makeBuildServerSelectionJob(Team $team, Server $deploymentServer): array
{
    $project = new Project;
    $project->setRelation('team', $team);

    $environment = new Environment;
    $environment->setRelation('project', $project);

    $settings = new ApplicationSetting;
    $settings->is_build_server_enabled = true;

    $application = new Application;
    $application->setRelation('environment', $environment);
    $application->setRelation('settings', $settings);

    $deploymentQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $deploymentQueue,
        'server' => $deploymentServer,
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    return [$job, $deploymentQueue];
}

function invokeBuildServerSelection(ApplicationDeploymentJob $job): void
{
    (new ReflectionMethod(ApplicationDeploymentJob::class, 'selectBuildServer'))->invoke($job);
}

function selectedBuildServer(ApplicationDeploymentJob $job): Server
{
    return (new ReflectionProperty(ApplicationDeploymentJob::class, 'build_server'))->getValue($job);
}

test('teams allow deployment server fallback by default', function () {
    $team = Team::factory()->create();
    $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer);

    $deploymentQueue->shouldReceive('addLogEntry')
        ->once()
        ->with('No suitable build server found. Using the deployment server.');

    invokeBuildServerSelection($job);

    expect($team->fresh()->is_build_server_fallback_enabled)->toBeTrue()
        ->and(selectedBuildServer($job)->is($deploymentServer))->toBeTrue();
});

test('strict teams fail when no dedicated build server is available', function () {
    $team = Team::factory()->create(['is_build_server_fallback_enabled' => false]);
    $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer);

    $deploymentQueue->shouldNotReceive('addLogEntry');

    expect(fn () => invokeBuildServerSelection($job))
        ->toThrow(DeploymentException::class, 'No available dedicated build server was found.');
});

test('strict teams reject ineligible dedicated build servers', function (array $settings) {
    $team = Team::factory()->create(['is_build_server_fallback_enabled' => false]);
    $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
    $buildServer = Server::factory()->create(['team_id' => $team->id]);
    $buildServer->settings()->update(array_merge([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => 'build',
        'is_build_server' => true,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ], $settings));
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer);

    $deploymentQueue->shouldNotReceive('addLogEntry');

    expect(fn () => invokeBuildServerSelection($job))
        ->toThrow(DeploymentException::class, 'No available dedicated build server was found.');
})->with([
    'unreachable' => [['is_reachable' => false]],
    'unusable' => [['is_usable' => false]],
    'force-disabled' => [['force_disabled' => true]],
    'swarm worker' => [['is_swarm_worker' => true]],
]);

test('strict teams use an available dedicated build server', function () {
    $team = Team::factory()->create(['is_build_server_fallback_enabled' => false]);
    $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
    $buildServer = Server::factory()->create(['team_id' => $team->id]);
    $buildServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => 'build',
        'is_build_server' => true,
        'force_disabled' => false,
    ]);
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer);

    $deploymentQueue->shouldReceive('setAttribute')->with('build_server_id', $buildServer->id)->once()->andReturnSelf();
    $deploymentQueue->shouldReceive('addLogEntry')
        ->once()
        ->with("Found a suitable build server ({$buildServer->name}).");

    invokeBuildServerSelection($job);

    expect(selectedBuildServer($job)->is($buildServer))->toBeTrue();
});

test('a combined deployment server builds locally without remote build handling', function () {
    $team = Team::factory()->create(['is_build_server_fallback_enabled' => false]);
    $deploymentServer = Server::factory()->create(['team_id' => $team->id]);
    $deploymentServer->settings()->update([
        'server_role' => 'both',
        'is_build_server' => false,
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer);

    $deploymentQueue->shouldNotReceive('setAttribute');
    $deploymentQueue->shouldReceive('addLogEntry')
        ->once()
        ->with("Using deployment server ({$deploymentServer->name}) for the build.");

    invokeBuildServerSelection($job);

    $useBuildServer = (new ReflectionProperty(ApplicationDeploymentJob::class, 'use_build_server'))->getValue($job);

    expect(selectedBuildServer($job)->is($deploymentServer))->toBeTrue()
        ->and($useBuildServer)->toBeFalse();
});
