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
    $primaryServer = Server::factory()->create(['team_id' => $team->id]);
    $this->additionalServer = Server::factory()->create(['team_id' => $team->id]);
    $this->additionalServer->settings->update(['concurrent_builds' => 1]);
    $primaryDestination = StandaloneDocker::where('server_id', $primaryServer->id)->firstOrFail();
    $this->additionalDestination = StandaloneDocker::where('server_id', $this->additionalServer->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $makeApplication = function (Server $deploymentServer, StandaloneDocker $deploymentDestination) use ($environment): Application {
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $deploymentDestination->id,
            'destination_type' => $deploymentDestination->getMorphClass(),
        ]);
        $application->settings->update(['is_consistent_container_name_enabled' => true]);

        return $application;
    };
    $this->multiServerApplication = $makeApplication($primaryServer, $primaryDestination);
    $this->multiServerApplication->additional_servers()->attach($this->additionalServer->id, [
        'standalone_docker_id' => $this->additionalDestination->id,
    ]);
    $this->otherApplication = $makeApplication($this->additionalServer, $this->additionalDestination);

    Process::fake(['*' => Process::result(output: '')]);
    Queue::fake();
    Notification::fake();
});

test('a finished additional-server deployment starts the next deployment queued on that server', function () {
    $childDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->multiServerApplication->id,
        'deployment_uuid' => 'prr-child-deployment',
        'status' => 'in_progress',
        'server_id' => $this->additionalServer->id,
        'destination_id' => $this->additionalDestination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
        'parent_deployment_uuid' => 'prr-parent-deployment',
    ]);
    $waitingDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->otherApplication->id,
        'deployment_uuid' => 'prr-waiting-deployment',
        'status' => 'queued',
        'server_id' => $this->additionalServer->id,
        'destination_id' => $this->additionalDestination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);

    (new ApplicationDeploymentJob($childDeployment->id))->failed(new RuntimeException('build failed'));

    expect($childDeployment->fresh()->status)->toBe('failed')
        ->and($waitingDeployment->fresh()->status)->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $waitingDeployment->id);
});
