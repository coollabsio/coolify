<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        'replicas' => 2,
        'health_check_enabled' => true,
        'health_check_start_period' => 0,
    ]);

    Queue::fake();
    Notification::fake();
    Sleep::fake();
});

function replicasDeploymentJob(int $pullRequestId = 0): ApplicationDeploymentJob
{
    return new ApplicationDeploymentJob(ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => $pullRequestId,
    ])->id);
}

function replicasGeneratedService(ApplicationDeploymentJob $job): array
{
    Process::fake(['*' => Process::result(output: '')]);

    return (function (): array {
        $this->saved_outputs = new Collection;
        $this->production_image_name = 'example/app:latest';
        $this->generate_compose_file();

        return Arr::first(Yaml::parse($this->docker_compose)['services']);
    })->call($job);
}

function replicaContainerNames(ApplicationDeploymentJob $job): array
{
    return (fn (): array => $this->newContainerNames())->call($job);
}

function ranOnContainer(string $command, string $containerName): Closure
{
    return fn (PendingProcess $process) => str_contains(remoteProcessScript($process), $command)
        && str_contains(remoteProcessScript($process), $containerName);
}

it('deploys the replicas with deploy.replicas and the container names of Docker Compose', function () {
    $job = replicasDeploymentJob();
    $service = replicasGeneratedService($job);
    $name = (fn (): string => $this->container_name)->call($job);

    expect($service)->not->toHaveKey('container_name')
        ->and(data_get($service, 'deploy.replicas'))->toBe(2)
        ->and(replicaContainerNames($job))->toBe(["{$name}-1", "{$name}-2"]);
});

it('runs one container in a preview deployment', function () {
    ApplicationPreview::forceCreate(['application_id' => $this->application->id, 'pull_request_id' => 7, 'pull_request_html_url' => 'https://example.com/pull/7']);

    expect(replicaContainerNames(replicasDeploymentJob(pullRequestId: 7)))->toHaveCount(1);
});

it('waits until every replica is healthy', function () {
    fakeRemoteProcesses(['*State.Health.Status*' => Process::result(output: "\"healthy\"\n")]);
    $job = replicasDeploymentJob();

    (fn () => $this->health_check())->call($job);

    foreach (replicaContainerNames($job) as $containerName) {
        Process::assertRan(ranOnContainer('.State.Health.Status', $containerName));
    }
    expect((fn (): bool => $this->newVersionIsHealthy())->call($job))->toBeTrue()
        ->and(ApplicationDeploymentQueue::firstOrFail()->logs)->toContain('All 2 replicas are healthy.');
});

it('does not trust the deployment when a later replica is unhealthy', function () {
    $job = replicasDeploymentJob();
    [$first, $second] = replicaContainerNames($job);
    fakeRemoteProcesses([
        "*State.Health.Status*{$first}*" => Process::result(output: "\"healthy\"\n"),
        "*State.Health.Status*{$second}*" => Process::result(output: "\"unhealthy\"\n"),
    ]);

    (fn () => $this->health_check())->call($job);

    expect((fn (): bool => $this->newVersionIsHealthy())->call($job))->toBeFalse();
    Process::assertRan(ranOnContainer('docker logs', $second));
});

it('removes every replica when the deployment fails before the replicas became healthy', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $job = replicasDeploymentJob();

    $job->failed(new RuntimeException('Late failure'));

    foreach (replicaContainerNames($job) as $containerName) {
        Process::assertRan(ranOnContainer('docker rm -f', $containerName));
    }
});
