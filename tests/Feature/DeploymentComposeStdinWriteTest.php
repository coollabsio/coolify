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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

class ComposeStdinWriteDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}
}

/**
 * Linux limits one exec argument to 128 KiB (MAX_ARG_STRLEN), so a large generated compose file
 * must reach the server over SSH stdin, not inside the command (issue #11737).
 */
beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);

    $this->processes = collect();
    Process::fake(function (PendingProcess $process) {
        $this->processes->push(['command' => $process->command, 'input' => $process->input]);

        return Process::result();
    });

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
    ]);
    $queue = ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'compose-stdin-deployment',
        'application_id' => $application->id,
        'server_id' => $this->server->id,
        'status' => 'in_progress',
    ]);

    $this->compose = 'services:'.collect(range(1, 400))
        ->map(fn (int $i) => "\n  service{$i}:\n    image: nginx\n    labels:\n      - coolify.test=".str_repeat('x', 500))
        ->implode('');
    $this->configurationDir = $application->workdir();

    $this->job = new ComposeStdinWriteDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'server' => $this->server,
        'mainServer' => $this->server,
        'deployment_uuid' => 'compose-stdin-deployment',
        'configuration_dir' => $this->configurationDir,
        'pull_request_id' => 0,
        'preserveRepository' => false,
        'use_build_server' => false,
        'generated_docker_compose' => $this->compose,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($this->job, $value);
    }
    $this->writeConfigurations = fn () => $reflection->getMethod('write_deployment_configurations')->invoke($this->job);
});

it('writes a compose file larger than one exec argument over stdin', function (string $user, string $tee) {
    $this->server->update(['user' => $user]);
    expect(strlen(base64_encode($this->compose)))->toBeGreaterThan(128 * 1024);

    ($this->writeConfigurations)();

    $composeWrite = $this->processes->firstWhere('input', $this->compose);
    expect($composeWrite)->not->toBeNull()
        ->and($composeWrite['command'])->toEndWith(escapeshellarg("{$tee} {$this->configurationDir}/docker-compose.yaml > /dev/null"));
    $this->processes->each(fn (array $process) => expect(strlen($process['command']))->toBeLessThan(8 * 1024));
})->with([
    'root' => ['root', 'tee'],
    'non-root' => ['cooluser', 'sudo tee'],
]);
