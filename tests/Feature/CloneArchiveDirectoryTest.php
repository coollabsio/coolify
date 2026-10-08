<?php

use App\Jobs\HostPathCloneJob;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A non-root SSH user stages clone archives in /var/tmp, which every local user can write. The jobs
 * must use a random directory from mktemp (never a name derived from the volume or path), give it to
 * the SSH user with mode 700, use it in every step, and remove it afterwards.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->makeServer = fn (string $user) => Server::factory()->create([
        'team_id' => $team->id,
        'user' => $user,
        'private_key_id' => $privateKey->id,
    ]);
    $this->volume = LocalPersistentVolume::forceCreate([
        'name' => 'clone-target',
        'mount_path' => '/data',
        'resource_type' => Application::class,
        'resource_id' => 1,
    ]);
    $this->mktempDirectories = [];
});

/**
 * Fakes the servers: mktemp prints a new random directory, `docker volume inspect` prints nothing,
 * and scp fails when $failScp is set.
 */
function fakeCloneArchiveServers(object $test, bool $failScp = false): void
{
    Process::fake(function ($process) use ($test, $failScp) {
        if (str_contains($process->command, 'mktemp -d')) {
            $directory = '/var/tmp/coolify-clone.'.Str::random(10);
            $test->mktempDirectories[] = $directory;

            return Process::result(output: $directory);
        }
        if ($failScp && str_contains($process->command, ' scp ')) {
            return Process::result(output: '', errorOutput: 'scp failed', exitCode: 1);
        }

        return Process::result(output: str_contains($process->command, 'docker volume inspect') ? '[]' : '');
    });
}

/**
 * @return list<string>
 */
function cloneArchiveCommands(): array
{
    $commands = [];
    Process::assertRan(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return true;
    });

    return $commands;
}

dataset('clone jobs', [
    'volume clone' => [fn (Server $source, Server $target) => new VolumeCloneJob('clone-source', 'clone-target', $source, $target, test()->volume), 'volume-data.tar.gz'],
    'host path clone' => [fn (Server $source, Server $target) => new HostPathCloneJob('/srv/app-data', '/srv/app-copy', $source, $target), 'hostpath-data.tar.gz'],
]);

it('stages a non-root clone in random mktemp directories with mode 700 and removes them', function (Closure $makeJob, string $archive) {
    $source = ($this->makeServer)('cooluser');
    $target = ($this->makeServer)('cooluser');
    fakeCloneArchiveServers($this);

    $makeJob($source, $target)->handle();

    $commands = implode("\n", cloneArchiveCommands());
    [$sourceDirectory, $targetDirectory] = $this->mktempDirectories;

    expect($this->mktempDirectories)->toHaveCount(2)
        ->and($sourceDirectory)->not->toBe($targetDirectory)
        ->and(substr_count($commands, 'sudo mktemp -d /var/tmp/coolify-clone.XXXXXXXXXX'))->toBe(2)
        ->and($commands)->not->toContain('/var/tmp/coolify-clone/')
        ->and($commands)->not->toContain('/data/coolify/clone');

    foreach ([$sourceDirectory, $targetDirectory] as $directory) {
        $escaped = escapeshellarg($directory);
        expect($commands)->toContain("sudo chown 'cooluser' {$escaped}")
            ->and($commands)->toContain("sudo chmod 700 {$escaped}")
            ->and($commands)->toContain("{$directory}/{$archive}")
            ->and($commands)->toContain("sudo rm -rf {$escaped}");
    }
    // The sudo parser wraps each docker run line in `sudo bash -c '...'`.
    expect($commands)->toContain("-v '\\''{$sourceDirectory}'\\'':/clone")
        ->and($commands)->toContain("-v '\\''{$targetDirectory}'\\'':/clone");
})->with('clone jobs');

it('removes the random non-root clone directories when the copy fails', function (Closure $makeJob) {
    $source = ($this->makeServer)('cooluser');
    $target = ($this->makeServer)('cooluser');
    fakeCloneArchiveServers($this, failScp: true);

    expect(fn () => $makeJob($source, $target)->handle())->toThrow(RuntimeException::class);

    $commands = implode("\n", cloneArchiveCommands());
    expect($this->mktempDirectories)->toHaveCount(2);
    foreach ($this->mktempDirectories as $directory) {
        expect($commands)->toContain('sudo rm -rf '.escapeshellarg($directory));
    }
    expect($commands)->not->toContain('tar xzf');
})->with('clone jobs');

it('keeps the /data/coolify/clone directory for a root SSH user and removes it', function (Closure $makeJob, string $archive, bool $fail) {
    $source = ($this->makeServer)('root');
    $target = ($this->makeServer)('root');
    fakeCloneArchiveServers($this, failScp: $fail);

    $run = fn () => $makeJob($source, $target)->handle();
    if ($fail) {
        expect($run)->toThrow(RuntimeException::class);
    } else {
        $run();
    }

    $commands = cloneArchiveCommands();
    $all = implode("\n", $commands);
    $directories = [];
    foreach ($commands as $command) {
        if (preg_match("#mkdir -p '(/data/coolify/clone/[^']+)'#", $command, $matches)) {
            $directories[] = $matches[1];
        }
    }

    expect($this->mktempDirectories)->toBe([])
        ->and($all)->not->toContain('/var/tmp')
        ->and($directories)->toHaveCount(2);
    foreach ($directories as $directory) {
        expect($all)->toContain('chmod 777 '.escapeshellarg($directory))
            ->and($all)->toContain('rm -rf '.escapeshellarg($directory));
    }
})->with('clone jobs')->with(['success' => false, 'failure' => true]);
