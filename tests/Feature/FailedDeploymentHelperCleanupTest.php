<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\CleanupHelperContainersJob;
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

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->buildServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
    ]);

    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $this->server->id,
        'build_server_id' => $this->buildServer->id,
        'destination_id' => $destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);

    Process::fake();
    Queue::fake();
    Notification::fake();
});

it('queues a delayed helper cleanup on the deployment and build servers when a deployment fails', function () {
    // failed() runs on a copy of the job, also when the worker kills the job and handle() never reaches its teardown.
    $queuedCopy = serialize(new ApplicationDeploymentJob($this->deployment->id));
    unserialize($queuedCopy)->failed(new RuntimeException('Worker timeout'));

    expect($this->deployment->fresh()->status)->toBe('failed');
    Queue::assertPushed(CleanupHelperContainersJob::class, 2);
    foreach ([$this->server, $this->buildServer] as $server) {
        Queue::assertPushed(
            CleanupHelperContainersJob::class,
            fn (CleanupHelperContainersJob $job) => $job->server->is($server) && $job->delay !== null,
        );
    }
});

it('queues the helper cleanup for the localhost server, which has id 0', function () {
    $localhost = Server::factory()->create(['id' => 0, 'team_id' => $this->team->id]);
    $this->deployment->update(['build_server_id' => $localhost->id]);

    unserialize(serialize(new ApplicationDeploymentJob($this->deployment->id)))->failed(new RuntimeException('Worker timeout'));

    Queue::assertPushed(CleanupHelperContainersJob::class, fn (CleanupHelperContainersJob $job) => $job->server->id === 0);
});

it('still queues the helper cleanup for every server when deleting an empty team fails', function () {
    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->saveQuietly();
    Team::factory()->create();
    Team::deleting(fn () => throw new RuntimeException('Team delete failed'));

    $this->artisan('cleanup:stucked-resources')->assertSuccessful();

    Queue::assertPushed(CleanupHelperContainersJob::class, fn (CleanupHelperContainersJob $job) => $job->server->is($this->server));
});
