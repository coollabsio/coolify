<?php

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
use Illuminate\Process\PendingProcess;
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
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);

    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);

    Process::fake([
        '*State.Health.Status*' => Process::result(output: "\"healthy\"\n"),
        '*' => Process::result(output: ''),
    ]);
    Queue::fake();
    Notification::fake();
    Sleep::fake();
});

/**
 * Laravel calls failed() on a copy of the job that it unserializes from the payload taken at dispatch,
 * so state that handle() keeps only in memory is lost there.
 */
function failQueuedCopy(string $queuedCopy): void
{
    unserialize($queuedCopy)->failed(new RuntimeException('Late failure'));
}

function removesContainer(string $containerName): Closure
{
    return fn (PendingProcess $process) => str_contains((string) $process->command, 'docker rm -f')
        && str_contains((string) $process->command, $containerName);
}

it('keeps the new container when a deployment fails after the new container became healthy', function () {
    $job = new ApplicationDeploymentJob($this->deployment->id);
    $queuedCopy = serialize(clone $job);
    $containerName = (fn () => $this->container_name)->call($job);
    (fn () => $this->health_check())->call($job);
    failQueuedCopy($queuedCopy);

    Process::assertNotRan(removesContainer($containerName));
    expect($this->deployment->fresh()->logs)->toContain('Keeping the new version of your application running.');
});

it('removes the new container when a deployment fails before the new container became healthy', function () {
    $job = new ApplicationDeploymentJob($this->deployment->id);
    $queuedCopy = serialize(clone $job);
    $containerName = (fn () => $this->container_name)->call($job);

    failQueuedCopy($queuedCopy);

    Process::assertRan(removesContainer($containerName));
    expect($this->deployment->fresh()->logs)->toContain('Removing the new version of your application.');
});
