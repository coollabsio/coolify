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

function makeBuildServerSelectionJob(Team $team, Server $deploymentServer, array $applicationAttributes = [], bool $buildServerEnabled = true, bool $restartOnly = false): array
{
    $project = new Project;
    $project->setRelation('team', $team);

    $environment = new Environment;
    $environment->setRelation('project', $project);

    $settings = new ApplicationSetting;
    $settings->is_build_server_enabled = $buildServerEnabled;

    $application = new Application;
    $application->forceFill(array_merge(['build_pack' => 'nixpacks'], $applicationAttributes));
    $application->setRelation('environment', $environment);
    $application->setRelation('settings', $settings);

    $deploymentQueue = Mockery::mock(ApplicationDeploymentQueue::class);
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    foreach ([
        'application' => $application,
        'application_deployment_queue' => $deploymentQueue,
        'server' => $deploymentServer,
        'restart_only' => $restartOnly,
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

function usesRemoteBuildServer(ApplicationDeploymentJob $job): bool
{
    return (new ReflectionProperty(ApplicationDeploymentJob::class, 'use_build_server'))->getValue($job);
}

function makeRoleServer(Team $team, string $role): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => $role,
        'is_build_server' => $role === 'build',
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);

    return $server->fresh();
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

test('strict teams do not treat combined servers as dedicated build servers', function () {
    $team = Team::factory()->create(['is_build_server_fallback_enabled' => false]);
    $deploymentServer = makeRoleServer($team, 'both');
    makeRoleServer($team, 'both');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer);

    $deploymentQueue->shouldNotReceive('addLogEntry');

    expect(fn () => invokeBuildServerSelection($job))
        ->toThrow(DeploymentException::class, 'No available dedicated build server was found.');
});

test('build server selection only picks dedicated build servers', function () {
    $team = Team::factory()->create();
    $deploymentServer = makeRoleServer($team, 'both');
    makeRoleServer($team, 'both');
    makeRoleServer($team, 'both');
    makeRoleServer($team, 'deployment');
    $buildServer = makeRoleServer($team, 'build');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer);

    $deploymentQueue->shouldReceive('setAttribute')->with('build_server_id', $buildServer->id)->once()->andReturnSelf();
    $deploymentQueue->shouldReceive('addLogEntry')->once()->with("Found a suitable build server ({$buildServer->name}).");

    invokeBuildServerSelection($job);

    expect(selectedBuildServer($job)->is($buildServer))->toBeTrue()
        ->and(usesRemoteBuildServer($job))->toBeTrue();
});

test('fallback builds on the deployment server instead of another combined server', function () {
    $team = Team::factory()->create();
    $deploymentServer = makeRoleServer($team, 'both');
    makeRoleServer($team, 'both');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer);

    $deploymentQueue->shouldNotReceive('setAttribute');
    $deploymentQueue->shouldReceive('addLogEntry')->once()->with('No suitable build server found. Using the deployment server.');

    invokeBuildServerSelection($job);

    expect(selectedBuildServer($job)->is($deploymentServer))->toBeTrue()
        ->and(usesRemoteBuildServer($job))->toBeFalse();
});

test('deployments-only servers build on a dedicated build server without the application setting', function () {
    $team = Team::factory()->create();
    $deploymentServer = makeRoleServer($team, 'deployment');
    makeRoleServer($team, 'both');
    $buildServer = makeRoleServer($team, 'build');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer, ['docker_registry_image_name' => 'ghcr.io/coollabsio/app'], buildServerEnabled: false);

    $deploymentQueue->shouldReceive('setAttribute')->with('build_server_id', $buildServer->id)->once()->andReturnSelf();
    $deploymentQueue->shouldReceive('addLogEntry')->once()->with("Found a suitable build server ({$buildServer->name}).");

    invokeBuildServerSelection($job);

    expect(selectedBuildServer($job)->is($buildServer))->toBeTrue()
        ->and(usesRemoteBuildServer($job))->toBeTrue();
});

test('deployments-only servers never fall back to building themselves', function (bool $buildServerEnabled) {
    $team = Team::factory()->create();
    $deploymentServer = makeRoleServer($team, 'deployment');
    makeRoleServer($team, 'both');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer, ['docker_registry_image_name' => 'ghcr.io/coollabsio/app'], $buildServerEnabled);

    $deploymentQueue->shouldNotReceive('addLogEntry');

    expect(fn () => invokeBuildServerSelection($job))
        ->toThrow(DeploymentException::class, "The deployment server ({$deploymentServer->name}) is set to deployments only");
})->with([
    'application uses a build server' => [true],
    'application builds on the deployment server' => [false],
]);

test('deployments-only servers need a registry image for remote builds', function () {
    $team = Team::factory()->create();
    $deploymentServer = makeRoleServer($team, 'deployment');
    makeRoleServer($team, 'build');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer, buildServerEnabled: false);

    $deploymentQueue->shouldNotReceive('addLogEntry');

    expect(fn () => invokeBuildServerSelection($job))
        ->toThrow(DeploymentException::class, 'Set a Docker image name');
});

test('deployments-only servers restart without a registry image', function () {
    $team = Team::factory()->create();
    $deploymentServer = makeRoleServer($team, 'deployment');
    $buildServer = makeRoleServer($team, 'build');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer, buildServerEnabled: false, restartOnly: true);

    $deploymentQueue->shouldReceive('setAttribute')->with('build_server_id', $buildServer->id)->once()->andReturnSelf();
    $deploymentQueue->shouldReceive('addLogEntry')->once()->with("Found a suitable build server ({$buildServer->name}).");

    invokeBuildServerSelection($job);

    expect(selectedBuildServer($job)->is($buildServer))->toBeTrue();
});

test('deployments-only servers can restart when no build server is available', function () {
    $team = Team::factory()->create();
    $deploymentServer = makeRoleServer($team, 'deployment');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer, buildServerEnabled: false, restartOnly: true);

    $deploymentQueue->shouldReceive('addLogEntry')->once()->with('No suitable build server found. Using the deployment server.');

    invokeBuildServerSelection($job);

    expect(selectedBuildServer($job)->is($deploymentServer))->toBeTrue()
        ->and(usesRemoteBuildServer($job))->toBeFalse();
});

test('deployments-only servers do not need a build server when nothing is built', function (string $buildPack) {
    $team = Team::factory()->create(['is_build_server_fallback_enabled' => false]);
    $deploymentServer = makeRoleServer($team, 'deployment');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer, ['build_pack' => $buildPack], buildServerEnabled: false);

    $deploymentQueue->shouldNotReceive('addLogEntry');

    invokeBuildServerSelection($job);

    expect(selectedBuildServer($job)->is($deploymentServer))->toBeTrue()
        ->and(usesRemoteBuildServer($job))->toBeFalse();
})->with(['dockerimage', 'dockercompose']);

test('applications on combined servers build locally unless they use a build server', function () {
    $team = Team::factory()->create(['is_build_server_fallback_enabled' => false]);
    $deploymentServer = makeRoleServer($team, 'both');
    makeRoleServer($team, 'build');
    [$job, $deploymentQueue] = makeBuildServerSelectionJob($team, $deploymentServer, buildServerEnabled: false);

    $deploymentQueue->shouldNotReceive('addLogEntry');

    invokeBuildServerSelection($job);

    expect(selectedBuildServer($job)->is($deploymentServer))->toBeTrue()
        ->and(usesRemoteBuildServer($job))->toBeFalse();
});
