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
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Process::fake();
});

function makeCancelledDeploymentJob(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'cancelled-helper',
        'server_id' => $server->id,
        'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
    ]);

    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $deployment,
        'server' => $server,
        'saved_outputs' => collect(),
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    return [$job, $deployment];
}

test('a cancelled deployment still stops its helper container', function () {
    [$job, $deployment] = makeCancelledDeploymentJob();

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'graceful_shutdown_container'))->invoke($job, 'cancelled-helper', true);

    Process::assertRan(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), 'docker stop') && str_contains(remoteProcessScript($process), 'cancelled-helper'));
    expect($deployment->refresh()->logs ?? '')->not->toContain('Error stopping container');
});

test('a cancelled deployment does not run other remote commands', function () {
    [$job] = makeCancelledDeploymentJob();

    expect(fn () => $job->execute_remote_command(['echo build', 'hidden' => true]))
        ->toThrow(RuntimeException::class, 'Deployment cancelled by user');

    Process::assertNothingRan();
});
