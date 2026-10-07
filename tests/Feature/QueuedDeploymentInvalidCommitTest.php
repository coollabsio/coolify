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
use App\Notifications\Application\DeploymentFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'deployment_failure_email_notifications' => true,
    ]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
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

function createQueuedDeploymentWithCommit(string $commit, string $createdAt): ApplicationDeploymentQueue
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => 'queued',
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'commit' => $commit,
        'pull_request_id' => 0,
    ]);
    $deployment->forceFill(['created_at' => $createdAt])->save();

    return $deployment;
}

test('a queued deployment with an invalid commit fails and the next queued deployment starts', function () {
    $invalidDeployment = createQueuedDeploymentWithCommit('abc;touch /tmp/pwned', '2026-10-01 10:00:00');
    $validDeployment = createQueuedDeploymentWithCommit('HEAD', '2026-10-01 10:01:00');

    queue_next_deployment($this->application);

    $invalidDeployment->refresh();
    expect($invalidDeployment->status)->toBe('failed')
        ->and($invalidDeployment->logs)->toContain('Deployment could not be started')
        ->and($invalidDeployment->logs)->toContain('Invalid deployment commit')
        ->and($validDeployment->fresh()->status)->toBe('in_progress');

    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $validDeployment->id);
    Notification::assertSentTo($this->team, DeploymentFailed::class);
});

test('force starting a queued deployment with an invalid commit fails it instead of leaving it in progress', function () {
    $invalidDeployment = createQueuedDeploymentWithCommit('$(id)', '2026-10-01 10:00:00');

    expect(force_start_deployment($invalidDeployment))->toBeFalse()
        ->and($invalidDeployment->fresh()->status)->toBe('failed');
    Queue::assertNothingPushed();
});
