<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\CleanupHelperContainersJob;
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
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    Queue::fake();
    Notification::fake();
});

function reserveDeployment(string $uuid): void
{
    ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->update(['horizon_job_id' => "job-{$uuid}"]);
}

function deploymentStatus(string $uuid): string
{
    return ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->value('status');
}

test('a deployment of a stopped worker fails and the queued deployment starts', function () {
    queue_application_deployment($this->application, 'stopped-worker', commit: 'aaaaaaa');
    reserveDeployment('stopped-worker');
    queue_application_deployment($this->application, 'waits-in-queue', commit: 'bbbbbbb');
    expect(deploymentStatus('waits-in-queue'))->toBe('queued');

    $this->travel(3600 + 601)->seconds();

    expect(fail_deployments_of_stopped_workers())->toBe(1)
        ->and(deploymentStatus('stopped-worker'))->toBe('failed')
        ->and(ApplicationDeploymentQueue::where('deployment_uuid', 'stopped-worker')->value('logs'))->toContain('the worker that ran it stopped')
        ->and(deploymentStatus('waits-in-queue'))->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class, 2);
    Queue::assertPushed(CleanupHelperContainersJob::class, fn (CleanupHelperContainersJob $job) => $job->server->is($this->server));
});

test('a deployment stays in progress while its worker can still run it', function () {
    $this->server->settings->update(['dynamic_timeout' => 7200]);
    queue_application_deployment($this->application, 'long-build');
    reserveDeployment('long-build');

    $this->travel(3600 + 601)->seconds();

    expect(fail_deployments_of_stopped_workers())->toBe(0)
        ->and(deploymentStatus('long-build'))->toBe('in_progress');

    $this->travel(3600)->seconds();

    expect(fail_deployments_of_stopped_workers())->toBe(1);
});

test('a deployment that waits for a free worker stays in progress', function () {
    queue_application_deployment($this->application, 'not-reserved');

    $this->travel(2)->days();

    expect(fail_deployments_of_stopped_workers())->toBe(0)
        ->and(deploymentStatus('not-reserved'))->toBe('in_progress');
});
