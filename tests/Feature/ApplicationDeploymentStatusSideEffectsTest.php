<?php

use App\Events\ApplicationConfigurationChanged;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Process::fake(['*' => Process::result(output: '')]);
    Queue::fake();
    Notification::fake();
});

function createDeploymentForSideEffectsTest(string $status, string $createdAt): ApplicationDeploymentQueue
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => $status,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);
    $deployment->forceFill(['created_at' => $createdAt])->save();

    return $deployment;
}

test('a failing post-success side effect keeps the deployment finished and starts the next queued deployment', function () {
    $current = createDeploymentForSideEffectsTest('in_progress', '2026-10-01 10:00:00');
    $next = createDeploymentForSideEffectsTest('queued', '2026-10-01 10:01:00');
    Event::listen(ApplicationConfigurationChanged::class, fn () => throw new RuntimeException('broadcast failed'));

    $job = new ApplicationDeploymentJob($current->id);
    (fn () => $this->completeDeployment())->call($job);

    $current->refresh();
    expect($current->status)->toBe('finished')
        ->and($current->logs)->toContain('broadcast failed')
        ->and($next->fresh()->status)->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $next->id);
});
