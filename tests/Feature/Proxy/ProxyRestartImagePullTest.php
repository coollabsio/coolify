<?php

use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\RestartProxyJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Event::fake([ProxyStatusChanged::class, ProxyStatusChangedUI::class]);
    Queue::fake();
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    $this->stubDirectory = sys_get_temp_dir().'/proxy-restart-image-pull-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->stubDirectory);
});

function proxyRestartScript(string $sshUser, string $proxyType): string
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => $sshUser,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $server->proxy->type = $proxyType;
    $server->proxy->status = 'running';
    $server->save();

    $job = new RestartProxyJob($server->refresh());
    $job->handle();

    return RemoteProcessCommand::read(Activity::findOrFail($job->activity_id));
}

/**
 * Runs the script like SSH does (`bash -se`, script on stdin). The docker stub reports a running
 * coolify-proxy until it is removed, and fails `compose pull` like a rate-limited registry when $pullFails is true.
 *
 * @return array{successful: bool, calls: list<string>}
 */
function runProxyRestartScript(string $script, string $stubDirectory, bool $pullFails): array
{
    File::ensureDirectoryExists($stubDirectory);
    $log = "{$stubDirectory}/calls.log";
    File::put("{$stubDirectory}/docker", <<<'SH'
#!/bin/sh
echo "docker $*" >> "$STUB_LOG"
case " $* " in
  *" compose "*" pull "*)
    if [ "$STUB_PULL_FAILS" = 1 ]; then echo "toomanyrequests: You have reached your pull rate limit" >&2; exit 1; fi ;;
  *" ps "*"{{.Names}}"*)
    [ -e "$STUB_LOG.removed" ] || echo coolify-proxy ;;
  *" rm -f coolify-proxy "*)
    : > "$STUB_LOG.removed" ;;
esac
exit 0
SH);
    File::put("{$stubDirectory}/sudo", "#!/bin/sh\nexec env \"\$@\"\n");
    File::put("{$stubDirectory}/tee", "#!/bin/sh\ncat > /dev/null\n");
    foreach (['mkdir', 'find', 'chmod', 'chown'] as $stub) {
        File::put("{$stubDirectory}/{$stub}", "#!/bin/sh\nexit 0\n");
    }
    foreach (File::files($stubDirectory) as $stub) {
        chmod($stub->getPathname(), 0755);
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

it('keeps the proxy running when the image pull of a restart fails', function (string $sshUser, string $proxyType) {
    $result = runProxyRestartScript(proxyRestartScript($sshUser, $proxyType), $this->stubDirectory, pullFails: true);
    $calls = collect($result['calls']);

    expect($result['successful'])->toBeFalse()
        ->and($calls->filter(fn (string $call): bool => str_ends_with($call, ' pull'))->count())->toBe(1)
        ->and($calls->filter(fn (string $call): bool => str_starts_with($call, 'docker stop') || str_starts_with($call, 'docker rm') || str_contains($call, ' up -d'))->all())->toBe([]);
})->with(['root' => 'root', 'non-root' => 'deploy'])->with([ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value]);

it('replaces the proxy only after the image pull of a restart', function (string $sshUser, string $proxyType) {
    $result = runProxyRestartScript(proxyRestartScript($sshUser, $proxyType), $this->stubDirectory, pullFails: false);
    $calls = collect($result['calls']);
    $position = fn (string $pattern): int|false => $calls->search(fn (string $call): bool => preg_match($pattern, $call) === 1);

    expect($result['successful'])->toBeTrue()
        ->and($calls->filter(fn (string $call): bool => str_ends_with($call, ' pull'))->count())->toBe(1)
        ->and($position('/ pull$/'))->toBeInt()
        ->and($position('/^docker stop --time(out)?=30 coolify-proxy$/'))->toBeGreaterThan($position('/ pull$/'))
        ->and($position('/^docker rm -f coolify-proxy$/'))->toBeGreaterThan($position('/^docker stop --time(out)?=30 coolify-proxy$/'))
        ->and($position('/ up -d --wait --remove-orphans$/'))->toBeGreaterThan($position('/^docker rm -f coolify-proxy$/'));
})->with(['root' => 'root', 'non-root' => 'deploy'])->with([ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value]);
