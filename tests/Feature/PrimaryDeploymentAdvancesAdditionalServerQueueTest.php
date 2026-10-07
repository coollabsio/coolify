<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $this->primaryServer = Server::factory()->create(['team_id' => $team->id]);
    $this->additionalServer = Server::factory()->create(['team_id' => $team->id]);
    $this->primaryServer->settings->update(['concurrent_builds' => 1]);
    $this->additionalServer->settings->update(['concurrent_builds' => 1]);
    $this->primaryDestination = StandaloneDocker::where('server_id', $this->primaryServer->id)->firstOrFail();
    $this->additionalDestination = StandaloneDocker::where('server_id', $this->additionalServer->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $makeApplication = function (StandaloneDocker $destination) use ($environment): Application {
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $application->settings->update(['is_consistent_container_name_enabled' => true]);

        return $application;
    };
    $this->application = $makeApplication($this->primaryDestination);
    $this->application->additional_servers()->attach($this->additionalServer->id, [
        'standalone_docker_id' => $this->additionalDestination->id,
    ]);
    $this->otherApplication = $makeApplication($this->additionalDestination);

    $this->makeDeployment = fn (Application $application, Server $server, StandaloneDocker $destination, string $status, string $uuid): ApplicationDeploymentQueue => ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => $uuid,
        'status' => $status,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
        'only_this_server' => true,
    ]);

    Process::fake(['*' => Process::result(output: '')]);
    Queue::fake();
    Notification::fake();
});

test('a finished primary-server deployment starts the deployment of the same application queued on an additional server', function () {
    $primaryDeployment = ($this->makeDeployment)($this->application, $this->primaryServer, $this->primaryDestination, 'in_progress', 'prr-primary-deployment');
    $additionalDeployment = ($this->makeDeployment)($this->application, $this->additionalServer, $this->additionalDestination, 'queued', 'prr-additional-deployment');

    (new ApplicationDeploymentJob($primaryDeployment->id))->failed(new RuntimeException('build failed'));

    expect($primaryDeployment->fresh()->status)->toBe('failed')
        ->and($additionalDeployment->fresh()->status)->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $additionalDeployment->id);
});

test('a finished primary-server deployment keeps the concurrent build limit of the additional server', function () {
    $primaryDeployment = ($this->makeDeployment)($this->application, $this->primaryServer, $this->primaryDestination, 'in_progress', 'prr-primary-deployment');
    ($this->makeDeployment)($this->otherApplication, $this->additionalServer, $this->additionalDestination, 'in_progress', 'prr-busy-deployment');
    $additionalDeployment = ($this->makeDeployment)($this->application, $this->additionalServer, $this->additionalDestination, 'queued', 'prr-additional-deployment');

    (new ApplicationDeploymentJob($primaryDeployment->id))->failed(new RuntimeException('build failed'));

    expect($additionalDeployment->fresh()->status)->toBe('queued');
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});
