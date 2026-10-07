<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process as LaravelProcess;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    // generateDefaultProxyConfiguration() persists the config over SSH; the rotation script itself
    // runs through Symfony Process below, which the Laravel fake does not intercept.
    LaravelProcess::fake();

    $user = User::factory()->create();
    $team = $user->teams()->first();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    $entrypoint = Yaml::parse(generateDefaultProxyConfiguration($server->fresh()))['services']['traefik-logrotate']['entrypoint'];

    // Docker Compose turns `$$` into a literal `$` before the container runs the script.
    $this->script = str_replace('$$', '$', $entrypoint[2]);
    $this->directory = sys_get_temp_dir().'/coolify-logrotate-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->directory);
    $this->log = $this->directory.'/access.log';
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

/**
 * @return array<int, string>
 */
function rotationScriptShells(): array
{
    $shells = ['/bin/sh'];
    foreach (['/usr/bin/busybox', '/bin/busybox'] as $busybox) {
        if (is_executable($busybox)) {
            $shells[] = $busybox;
            break;
        }
    }

    return $shells;
}

/**
 * Run the sidecar loop until $isDone() holds (or a timeout), then stop it.
 *
 * @param  array<string, string>  $env
 */
function runRotationScript(string $shell, string $script, string $log, callable $isDone, array $env = [], float $timeout = 20.0): void
{
    $command = str_ends_with($shell, 'busybox') ? [$shell, 'sh', '-c', $script] : [$shell, '-c', $script];
    $process = new Process($command, null, ['TRAEFIK_ACCESS_LOG' => $log, ...$env]);
    $process->start();

    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline && $process->isRunning()) {
        clearstatcache();
        if ($isDone()) {
            break;
        }
        usleep(50_000);
    }

    $running = $process->isRunning();
    $process->stop(0);

    expect($running)->toBeTrue('The rotation loop exited: '.$process->getErrorOutput());
}

/**
 * @return array<int, string>
 */
function rotatedAccessLogs(string $directory): array
{
    return collect(File::files($directory))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->filter(fn (string $name): bool => str_starts_with($name, 'access.log.'))
        ->sort()
        ->values()
        ->all();
}

it('rotates an access log larger than 20 MiB, truncates it in place and keeps at most 5 rotations', function (string $shell) {
    $line = '{"ClientHost":"203.0.113.10","RequestPath":"/","DownstreamStatus":200}'."\n";
    $content = str_repeat($line, intdiv(21 * 1024 * 1024, strlen($line)) + 1);
    File::put($this->log, $content);
    $inode = fileinode($this->log);

    foreach (range(1, 5) as $index) {
        File::put("{$this->log}.{$index}.gz", gzencode("rotation {$index}\n"));
    }

    runRotationScript($shell, $this->script, $this->log, fn (): bool => filesize($this->log) === 0
        && ! file_exists("{$this->log}.1")
        && file_exists("{$this->log}.1.gz"));

    clearstatcache();

    expect(filesize($this->log))->toBe(0)
        ->and(fileinode($this->log))->toBe($inode)
        ->and(rotatedAccessLogs($this->directory))->toBe([
            'access.log.1.gz',
            'access.log.2.gz',
            'access.log.3.gz',
            'access.log.4.gz',
            'access.log.5.gz',
        ])
        ->and(gzdecode(File::get("{$this->log}.1.gz")) === $content)->toBeTrue()
        ->and(gzdecode(File::get("{$this->log}.2.gz")))->toBe("rotation 1\n")
        ->and(gzdecode(File::get("{$this->log}.5.gz")))->toBe("rotation 4\n");
})->with(rotationScriptShells());

it('shifts rotations on every run and never keeps more than 5', function (string $shell) {
    foreach (range(1, 7) as $run) {
        File::put($this->log, str_repeat("run {$run}\n", 64));

        runRotationScript(
            $shell,
            $this->script,
            $this->log,
            fn (): bool => filesize($this->log) === 0 && ! file_exists("{$this->log}.1") && file_exists("{$this->log}.1.gz"),
            ['TRAEFIK_ACCESS_LOG_MAX_BYTES' => '100'],
        );

        expect(count(rotatedAccessLogs($this->directory)))->toBe(min($run, 5));
    }

    expect(gzdecode(File::get("{$this->log}.1.gz")))->toStartWith('run 7')
        ->and(gzdecode(File::get("{$this->log}.5.gz")))->toStartWith('run 3')
        ->and(file_exists("{$this->log}.6.gz"))->toBeFalse();
})->with(rotationScriptShells());

it('leaves an access log under the size limit and a missing access log alone', function (string $shell) {
    File::put($this->log, str_repeat("small\n", 1000));

    runRotationScript($shell, $this->script, $this->log, fn (): bool => false, timeout: 1.0);

    expect(filesize($this->log))->toBe(6000)
        ->and(rotatedAccessLogs($this->directory))->toBe([]);

    File::delete($this->log);

    runRotationScript($shell, $this->script, $this->log, fn (): bool => false, timeout: 1.0);

    expect(rotatedAccessLogs($this->directory))->toBe([]);
})->with(rotationScriptShells());
