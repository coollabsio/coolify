<?php

use App\Enums\CancelPreviousDeployments;
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
use Illuminate\Queue\TimeoutExceededException;
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
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);

    Process::fake();
    Queue::fake();
    Notification::fake();
    Sleep::fake();
});

function inProgressDeployment(Server $server, Application $application): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $server->id,
        'destination_id' => $application->destination_id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);
}

test('a deployment that exceeds its timeout records when it ended', function () {
    $deployment = inProgressDeployment($this->server, $this->application);
    $queuedCopy = serialize(new ApplicationDeploymentJob($deployment->id));

    $this->travel(500)->seconds();
    // The worker kills handle() at the timeout, so only failed() runs, on a copy of the job.
    unserialize($queuedCopy)->failed(new TimeoutExceededException('Timed out'));

    $deployment->refresh();
    expect($deployment->status)->toBe('failed')
        ->and($deployment->finished_at?->timestamp)->toBe(now()->timestamp);
});

test('a status change to a final status keeps an end time that is already recorded', function () {
    $deployment = inProgressDeployment($this->server, $this->application);
    $deployment->update(['finished_at' => now()->subMinute()]);
    $recorded = $deployment->finished_at->timestamp;

    $deployment->update(['status' => 'failed']);

    expect($deployment->refresh()->finished_at->timestamp)->toBe($recorded);
});

test('a deployment that is still in progress has no end time', function () {
    $deployment = inProgressDeployment($this->server, $this->application);
    $deployment->update(['status' => 'queued']);

    expect($deployment->refresh()->finished_at)->toBeNull();
});

test('a deployment of a stopped worker records when it ended', function () {
    queue_application_deployment($this->application, 'stopped-worker');
    ApplicationDeploymentQueue::where('deployment_uuid', 'stopped-worker')->update(['horizon_job_id' => 'job-1']);

    $this->travel(3600 + 601)->seconds();
    fail_deployments_of_stopped_workers();

    expect(ApplicationDeploymentQueue::where('deployment_uuid', 'stopped-worker')->first()->finished_at?->timestamp)
        ->toBe(now()->timestamp);
});

test('a deployment that a newer deployment cancels records when it ended', function () {
    queue_application_deployment($this->application, 'older', commit: 'aaaaaaa', is_api: true);
    queue_application_deployment($this->application, 'newer', commit: 'bbbbbbb', is_api: true, cancel_previous: CancelPreviousDeployments::BUILDING);

    $older = ApplicationDeploymentQueue::where('deployment_uuid', 'older')->first();
    expect($older->status)->toBe('cancelled-by-user')
        ->and($older->finished_at)->not->toBeNull();
});

test('the migration fills the end time of ended deployments from their last update', function () {
    $ended = inProgressDeployment($this->server, $this->application);
    $running = inProgressDeployment($this->server, $this->application);
    ApplicationDeploymentQueue::whereKey($ended->id)->update(['status' => 'failed', 'finished_at' => null, 'updated_at' => now()->subDay()]);

    (require database_path('migrations/2026_11_15_130000_backfill_finished_at_of_ended_deployments.php'))->up();

    expect($ended->refresh()->finished_at->timestamp)->toBe(now()->subDay()->timestamp)
        ->and($running->refresh()->finished_at)->toBeNull();
});
