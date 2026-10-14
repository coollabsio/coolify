<?php

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\StartService;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Bus::fake();
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);
    $this->stubDirectory = sys_get_temp_dir().'/service-compose-stdin-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->stubDirectory);
});

function serviceComposeStdinService(string $sshUser): Service
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => $sshUser,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'connect_to_docker_network' => true,
        'docker_compose_raw' => "services:\n  app:\n    image: busybox:latest\n    volumes:\n      - data:/data\nvolumes:\n  data:\n    labels:\n      example: changed\n",
    ]);
}

/**
 * Runs the generated script the way SSH does (`bash -se` with the script on stdin).
 * Process is faked for the SSH calls of the action, so this uses Symfony Process directly.
 * The `docker compose up`/`pull` stub reads one line from stdin, like the Compose prompt
 * "Volume ... exists but doesn't match configuration in compose file. Recreate?".
 *
 * @return list<string> the commands that ran, and the lines the prompt consumed
 */
function runServiceScriptLikeSsh(string $script, string $stubDirectory): array
{
    File::ensureDirectoryExists($stubDirectory);
    $log = "{$stubDirectory}/calls.log";
    File::put("{$stubDirectory}/docker", <<<'SH'
#!/bin/sh
echo "docker $*" >> "$STUB_LOG"
case " $* " in
  *" compose "*" up "*|*" compose "*" pull"*)
    if IFS= read -r answer; then echo "prompt read: $answer" >> "$STUB_LOG"; fi
    ;;
esac
exit 0
SH);
    File::put("{$stubDirectory}/touch", "#!/bin/sh\nexit 0\n");
    File::put("{$stubDirectory}/sudo", "#!/bin/sh\nexec \"\$@\"\n");
    foreach (['docker', 'touch', 'sudo'] as $stub) {
        chmod("{$stubDirectory}/{$stub}", 0755);
    }

    $process = new SymfonyProcess(['bash', '-se'], env: ['PATH' => $stubDirectory.':'.getenv('PATH'), 'STUB_LOG' => $log], input: $script."\n", timeout: 30);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return File::exists($log) ? explode("\n", trim(File::get($log))) : [];
}

function serviceComposeCommandLines(Activity $activity): array
{
    return array_values(array_filter(
        explode("\n", RemoteProcessCommand::read($activity)),
        fn (string $line) => str_contains($line, 'docker compose ') && (str_contains($line, ' up -d') || str_contains($line, ' pull')),
    ));
}

it('runs the proxy network connect after a service compose up that reads stdin', function (string $sshUser) {
    $service = serviceComposeStdinService($sshUser);

    $activity = StartService::run($service, pullLatestImages: true);

    $calls = runServiceScriptLikeSsh(RemoteProcessCommand::read($activity), $this->stubDirectory);
    expect($calls)
        ->toContain("docker network connect {$service->uuid} coolify-proxy")
        ->and(collect($calls)->filter(fn (string $call) => str_starts_with($call, 'prompt read:'))->all())->toBe([]);
})->with(['root' => 'root', 'non-root' => 'deploy']);

it('runs the proxy network connect after a single service application compose up that reads stdin', function (string $sshUser) {
    $service = serviceComposeStdinService($sshUser);
    $service->parse();

    $activity = DeployServiceApplication::run($service->applications()->firstOrFail(), pullLatestImages: true);

    $calls = runServiceScriptLikeSsh(RemoteProcessCommand::read($activity), $this->stubDirectory);
    expect($calls)
        ->toContain("docker network connect {$service->uuid} coolify-proxy")
        ->and(collect($calls)->filter(fn (string $call) => str_starts_with($call, 'prompt read:'))->all())->toBe([]);
})->with(['root' => 'root', 'non-root' => 'deploy']);

it('gives service compose up and pull no stdin and never answers the recreate prompt', function () {
    $service = serviceComposeStdinService('deploy');

    $lines = serviceComposeCommandLines(StartService::run($service, pullLatestImages: true));

    expect($lines)->toHaveCount(2);
    foreach ($lines as $line) {
        expect($line)->toStartWith('sudo docker compose ')
            ->toEndWith(' < /dev/null')
            ->not->toContain(' --yes')
            ->not->toContain(' -y ');
    }
});
