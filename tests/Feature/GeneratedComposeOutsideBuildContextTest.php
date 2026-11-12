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

/**
 * The workdir is the Docker build context. A generated docker-compose.yaml in it goes into the image
 * with `COPY . .` (and with Nixpacks and Railpack), and it contains the proxy labels with the basic
 * auth password hash. Coolify writes it only after the image build.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        'dockerfile' => "FROM nginx:alpine\nCOPY . /usr/share/nginx/html",
    ]);
    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'compose-outside-build-context',
        'status' => 'in_progress',
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);

    $this->commands = collect();
    Process::fake(function (PendingProcess $process) {
        $command = remoteProcessScript($process);
        $this->commands->push($command);

        return Process::result(output: match (true) {
            str_contains($command, 'State.Health.Status') => "\"healthy\"\n",
            str_contains($command, 'echo available') => "available\n",
            default => '',
        });
    });
    Queue::fake();
    Notification::fake();
    Sleep::fake();
});

it('writes the generated compose file to the workdir only after the image build', function (string $buildPack, string $deployMethod) {
    // Application clears the inline Dockerfile when the build pack changes away from dockerfile.
    $this->application->update(['build_pack' => $buildPack]);
    $job = new ApplicationDeploymentJob($this->deployment->id);
    // handle() detects buildx on the server before it starts a deployment.
    (new ReflectionProperty($job, 'dockerBuildxAvailable'))->setValue($job, true);
    try {
        (new ReflectionMethod($job, $deployMethod))->invoke($job);
    } catch (Throwable) {
        // The faked server stops the deployment after the start; only the order of the commands matters.
    }

    $buildIndex = $this->commands->search(fn (string $command) => str_contains($command, 'bash /artifacts/build.sh'));
    $composeWriteIndex = $this->commands->search(fn (string $command) => str_contains($command, 'tee /artifacts/compose-outside-build-context/docker-compose.yaml'));

    if ($buildPack === 'nixpacks') {
        expect($this->commands->contains(fn (string $command) => str_contains($command, 'nixpacks build')))->toBeTrue();
    }

    expect($buildIndex)->not->toBeFalse()
        ->and($composeWriteIndex)->not->toBeFalse()
        ->and($composeWriteIndex)->toBeGreaterThan($buildIndex);
})->with([
    'inline Dockerfile' => ['dockerfile', 'deploy_simple_dockerfile'],
    'Dockerfile' => ['dockerfile', 'deploy_dockerfile_buildpack'],
    'Nixpacks' => ['nixpacks', 'deploy_nixpacks_buildpack'],
    'Railpack' => ['railpack', 'deploy_railpack_buildpack'],
    'static' => ['static', 'deploy_static_buildpack'],
]);
