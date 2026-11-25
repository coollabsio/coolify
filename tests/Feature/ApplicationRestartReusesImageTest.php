<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    Queue::fake();
    Notification::fake();
    Sleep::fake();
});

function restartTestApplication(array $attributes = []): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        ...$attributes,
    ]);
}

function restartTestJob(Application $application, bool $restartOnly): ApplicationDeploymentJob
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
        'restart_only' => $restartOnly,
    ]);

    return new ApplicationDeploymentJob($deployment->id);
}

/**
 * A finished deployment with the current configuration, so no build setting changed since then.
 */
function markRestartTestApplicationDeployed(Application $application): void
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);
    $application->markDeploymentConfigurationApplied($deployment);
}

function restartTestLogs(ApplicationDeploymentJob $job): string
{
    return (fn () => $this->application_deployment_queue)->call($job)->fresh()->logs;
}

test('a disabled build cache forces a rebuild for a deployment but not for a restart', function (bool $restartOnly, bool $forceRebuild) {
    $application = restartTestApplication();
    $application->settings->update(['disable_build_cache' => true]);

    $job = restartTestJob($application, $restartOnly);

    expect((fn () => $this->force_rebuild)->call($job))->toBe($forceRebuild);
})->with([
    'deployment' => [false, true],
    'restart' => [true, false],
]);

test('a restart that must build does not use the disabled build cache', function () {
    $application = restartTestApplication();
    $application->settings->update(['disable_build_cache' => true]);
    fakeRemoteProcesses([]);

    $job = restartTestJob($application, restartOnly: true);
    (fn () => $this->generate_image_names())->call($job);

    expect((fn () => $this->should_skip_build())->call($job))->toBeFalse()
        ->and((fn () => $this->force_rebuild)->call($job))->toBeTrue();
});

test('a restart of an inline Dockerfile application starts the existing image without a build', function () {
    $application = restartTestApplication(['dockerfile' => "FROM nginx:alpine\n"]);
    markRestartTestApplicationDeployed($application);
    fakeRemoteProcesses(['*docker images -q*' => Process::result(output: "sha256:abc\n")]);

    $job = restartTestJob($application, restartOnly: true);
    (fn () => $this->deploy_simple_dockerfile())->call($job);

    expect(restartTestLogs($job))
        ->toContain("No build configuration changed & image found ({$application->uuid}:latest). Build step skipped.")
        ->not->toContain('Building docker image started.');
});

test('an inline Dockerfile application builds again on a restart when the Dockerfile changed', function () {
    $application = restartTestApplication(['dockerfile' => "FROM nginx:alpine\n"]);
    markRestartTestApplicationDeployed($application);
    $application->update(['dockerfile' => "FROM caddy:alpine\n"]);
    fakeRemoteProcesses(['*docker images -q*' => Process::result(output: "sha256:abc\n")]);

    $job = restartTestJob($application, restartOnly: true);
    (fn () => $this->deploy_simple_dockerfile())->call($job);

    expect(restartTestLogs($job))
        ->toContain('Build configuration changed. Rebuilding image.')
        ->toContain('Building docker image started.');
});

test('a deployment of an inline Dockerfile application builds the image again', function () {
    $application = restartTestApplication(['dockerfile' => "FROM nginx:alpine\n"]);
    markRestartTestApplicationDeployed($application);
    fakeRemoteProcesses(['*docker images -q*' => Process::result(output: "sha256:abc\n")]);

    $job = restartTestJob($application, restartOnly: false);
    (fn () => $this->deploy_simple_dockerfile())->call($job);

    expect(restartTestLogs($job))->toContain('Building docker image started.')
        ->not->toContain('Build step skipped.');
});
