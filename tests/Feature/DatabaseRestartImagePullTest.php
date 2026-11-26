<?php

use App\Actions\Database\RestartDatabase;
use App\Enums\ProcessStatus;
use App\Jobs\DatabaseStartJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);
    $this->stubDirectory = sys_get_temp_dir().'/database-restart-image-pull-'.bin2hex(random_bytes(6));
    $this->executor = new class
    {
        public array $commands = [];

        public function execute(array $commands, $database, Activity $activity): Activity
        {
            $this->commands = $commands;
            $activity->properties = $activity->properties->merge(['status' => ProcessStatus::FINISHED->value]);
            $activity->save();

            return $activity;
        }
    };
    app()->instance(DatabaseStartCommandExecutor::class, $this->executor);
});

afterEach(function () {
    File::deleteDirectory($this->stubDirectory);
});

function databaseRestartImagePullDatabase(string $sshUser): StandalonePostgresql
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => $sshUser,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return create_standalone_postgresql($environment->id, $destination, ['status' => 'running:healthy']);
}

function databaseRestartImagePullScript(StandalonePostgresql $database, object $executor): string
{
    expect(RestartDatabase::run($database))->toBeInstanceOf(Activity::class)
        ->and($database->fresh()->status)->toBe('running:healthy');
    Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains(remoteProcessScript($process), "docker rm -f {$database->uuid}"));

    Queue::pushed(DatabaseStartJob::class)->sole()->handle();

    $commands = collect($executor->commands);
    $pull = $commands->search(fn (string $command): bool => str_starts_with($command, "echo 'Pulling"));
    expect($pull)->toBeInt()
        ->and($commands->take($pull)->filter(fn (string $command): bool => preg_match('/\bdocker (stop|rm)\b/', $command) === 1)->all())->toBe([]);

    $script = $commands->slice($pull)->values();
    $server = $database->destination->server;

    return implode("\n", $server->isNonRoot() ? parseCommandsByLineForSudo($script, $server) : $script->all());
}

function runDatabaseRestartImagePullScript(string $script, string $stubDirectory, bool $pullFails): array
{
    File::ensureDirectoryExists($stubDirectory);
    $log = "{$stubDirectory}/calls.log";
    File::put("{$stubDirectory}/docker", <<<'SH'
#!/bin/sh
echo "docker $*" >> "$STUB_LOG"
case " $* " in
  *" pull "*)
    if [ "$STUB_PULL_FAILS" = 1 ]; then echo "toomanyrequests: You have reached your pull rate limit" >&2; exit 1; fi ;;
esac
exit 0
SH);
    File::put("{$stubDirectory}/sudo", "#!/bin/sh\nexec env \"\$@\"\n");
    foreach (['docker', 'sudo'] as $stub) {
        chmod("{$stubDirectory}/{$stub}", 0755);
    }

    $process = new SymfonyProcess(['bash', '-se'], env: [
        'PATH' => $stubDirectory.':'.getenv('PATH'),
        'STUB_LOG' => $log,
        'STUB_PULL_FAILS' => $pullFails ? '1' : '0',
    ], input: $script."\n", timeout: 30);
    $process->run();

    return [
        'successful' => $process->isSuccessful(),
        'calls' => File::exists($log) ? explode("\n", trim(File::get($log))) : [],
    ];
}

it('keeps the database container when the image pull of a restart fails', function (string $sshUser) {
    $database = databaseRestartImagePullDatabase($sshUser);

    $result = runDatabaseRestartImagePullScript(databaseRestartImagePullScript($database, $this->executor), $this->stubDirectory, pullFails: true);
    $calls = collect($result['calls']);

    expect($result['successful'])->toBeFalse()
        ->and($calls->filter(fn (string $call): bool => str_ends_with($call, ' pull'))->count())->toBe(1)
        ->and($calls->filter(fn (string $call): bool => str_starts_with($call, 'docker stop') || str_starts_with($call, 'docker rm') || str_contains($call, ' up -d'))->all())->toBe([]);
})->with(['root' => 'root', 'non-root' => 'deploy']);

it('stops the database gracefully only after the image pull of a restart', function (string $sshUser) {
    $database = databaseRestartImagePullDatabase($sshUser);

    $result = runDatabaseRestartImagePullScript(databaseRestartImagePullScript($database, $this->executor), $this->stubDirectory, pullFails: false);
    $position = fn (string $pattern): int|false => collect($result['calls'])->search(fn (string $call): bool => preg_match($pattern, $call) === 1);

    expect($result['successful'])->toBeTrue()
        ->and($position('/ pull$/'))->toBeInt()
        ->and($position("/^docker stop --time(out)?=30 {$database->uuid}$/"))->toBeGreaterThan($position('/ pull$/'))
        ->and($position("/^docker rm -f {$database->uuid}$/"))->toBeGreaterThan($position("/^docker stop --time(out)?=30 {$database->uuid}$/"))
        ->and($position('/ up -d$/'))->toBeGreaterThan($position("/^docker rm -f {$database->uuid}$/"));
})->with(['root' => 'root', 'non-root' => 'deploy']);
