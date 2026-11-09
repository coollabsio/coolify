<?php

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
    $this->stubDirectory = sys_get_temp_dir().'/service-restart-image-check-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->stubDirectory);
});

function serviceRestartImageCheckService(string $sshUser): Service
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
        'docker_compose_raw' => "services:\n  web:\n    image: busybox:latest\n  oneshot:\n    image: local/oneshot:1\n    restart: 'no'\n",
    ]);
}

/**
 * Runs the start script the way SSH does (`bash -se` with the script on stdin). The docker stub
 * fails the image check (`compose up --no-start`) when $imageMissing is true, as Compose does
 * when it cannot pull a missing image.
 *
 * @return array{successful: bool, calls: list<string>}
 */
function runServiceRestartScript(string $script, string $stubDirectory, bool $imageMissing): array
{
    File::ensureDirectoryExists($stubDirectory);
    $log = "{$stubDirectory}/calls.log";
    File::put("{$stubDirectory}/docker", <<<'SH'
#!/bin/sh
echo "docker $*" >> "$STUB_LOG"
case " $* " in
  *" compose "*" up --no-start "*)
    if [ "$STUB_IMAGE_MISSING" = 1 ]; then echo "pull access denied for local/oneshot" >&2; exit 1; fi
    ;;
esac
exit 0
SH);
    File::put("{$stubDirectory}/touch", "#!/bin/sh\nexit 0\n");
    File::put("{$stubDirectory}/sudo", "#!/bin/sh\nexec env \"\$@\"\n");
    foreach (['docker', 'touch', 'sudo'] as $stub) {
        chmod("{$stubDirectory}/{$stub}", 0755);
    }

    $process = new SymfonyProcess(['bash', '-se'], env: [
        'PATH' => $stubDirectory.':'.getenv('PATH'),
        'STUB_LOG' => $log,
        'STUB_IMAGE_MISSING' => $imageMissing ? '1' : '0',
    ], input: $script."\n", timeout: 30);
    $process->run();

    return [
        'successful' => $process->isSuccessful(),
        'calls' => File::exists($log) ? explode("\n", trim(File::get($log))) : [],
    ];
}

it('keeps the containers of a service when an image cannot be fetched during a restart', function (string $sshUser) {
    $service = serviceRestartImageCheckService($sshUser);

    $activity = StartService::run($service, stopBeforeStart: true);

    $result = runServiceRestartScript(RemoteProcessCommand::read($activity), $this->stubDirectory, imageMissing: true);
    $calls = collect($result['calls']);
    expect($result['successful'])->toBeFalse()
        ->and($calls->filter(fn (string $call) => str_contains($call, 'up --no-start --no-recreate'))->count())->toBe(1)
        ->and($calls->filter(fn (string $call) => str_starts_with($call, 'docker stop') || str_starts_with($call, 'docker rm'))->all())->toBe([])
        ->and($calls->filter(fn (string $call) => str_contains($call, 'up -d'))->all())->toBe([]);
})->with(['root' => 'root', 'non-root' => 'deploy']);

it('stops the containers of a service only after the image check of a restart', function (string $sshUser) {
    $service = serviceRestartImageCheckService($sshUser);

    $activity = StartService::run($service, stopBeforeStart: true);

    $result = runServiceRestartScript(RemoteProcessCommand::read($activity), $this->stubDirectory, imageMissing: false);
    $calls = $result['calls'];
    $position = fn (string $needle): int|false => collect($calls)->search(fn (string $call) => str_contains($call, $needle));
    expect($result['successful'])->toBeTrue()
        ->and($position('up --no-start --no-recreate'))->toBeInt()
        ->and($position('up --no-start --no-recreate'))->toBeLessThan($position('docker stop'))
        ->and($position('docker stop'))->toBeLessThan($position('up -d'))
        ->and($calls)->toContain("docker rm -f web-{$service->uuid} oneshot-{$service->uuid}");
})->with(['root' => 'root', 'non-root' => 'deploy']);
