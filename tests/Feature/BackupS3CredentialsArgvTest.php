<?php

use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

const S3_ARGV_TEST_KEY = 'AK/+$x"y';
const S3_ARGV_TEST_SECRET = "s3c/r+e\$t 'q\"uo\\te`d`";

beforeEach(function () {
    Server::flushIdentityMap();
    config(['broadcasting.default' => 'null']);
    defined('CURLOPT_RESOLVE') || define('CURLOPT_RESOLVE', 10203);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
});

afterEach(function () {
    Server::flushIdentityMap();
});

/**
 * @return array{0: Team, 1: Server, 2: S3Storage}
 */
function s3ArgvTestSetup(string $sshUser): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => $sshUser,
        'ip' => '203.0.113.10',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $storage = S3Storage::create([
        'name' => 'Destination',
        'region' => 'ru-central-1',
        'key' => S3_ARGV_TEST_KEY,
        'secret' => S3_ARGV_TEST_SECRET,
        'bucket' => 'bucket',
        'endpoint' => 'https://8.8.8.8',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->zeroOrMoreTimes()->andReturn([]);
    $disk->shouldReceive('delete')->zeroOrMoreTimes()->andReturnTrue();
    $sshDisk = Storage::fake('ssh-keys');
    Storage::shouldReceive('disk')->with('ssh-keys')->andReturn($sshDisk);
    Storage::shouldReceive('build')->zeroOrMoreTimes()->andReturn($disk);

    return [$team, $server, $storage];
}

/**
 * Records the SSH processes that a backup job runs, with their stdin.
 *
 * @return Collection<int, array{command: string, input: ?string}>
 */
function s3ArgvTestFakeSsh(): Collection
{
    $processes = collect();
    Process::fake(function ($process) use ($processes) {
        $processes->push(['command' => $process->command, 'input' => $process->input]);
        $output = match (true) {
            str_contains(remoteProcessScript($process), 'du -b') => '128',
            str_contains(remoteProcessScript($process), 'mc pipe') => '128',
            default => '',
        };

        return Process::result(output: $output);
    });

    return $processes;
}

/**
 * Runs the recorded `mc alias set` SSH process in a real shell with its stdin. Stubs for `timeout`, `ssh`
 * (runs the remote command in `sh -c`, like a login shell), `sudo`, `docker` and `mc` log their arguments.
 *
 * @param  array{command: string, input: ?string}  $aliasProcess
 * @return array{argv: string, mcStdin: string}
 */
function s3ArgvTestRunAliasProcess(array $aliasProcess): array
{
    $bin = sys_get_temp_dir().'/s3-argv-'.bin2hex(random_bytes(6));
    mkdir($bin);
    $log = "{$bin}/argv.log";
    $stubs = [
        'timeout' => "shift\nexec \"\$@\"",
        'ssh' => "for last; do :; done\nexec sh -c \"\$last\"",
        'sudo' => 'exec "$@"',
        'docker' => "[ \"\$1\" = exec ] || exit 0\nshift\nwhile [ \"\${1#-}\" != \"\$1\" ]; do shift; done\nshift\nexec \"\$@\"",
        'mc' => "cat > {$bin}/mc.stdin",
    ];
    foreach ($stubs as $name => $body) {
        file_put_contents("{$bin}/{$name}", "#!/bin/sh\nprintf '%s\\n' \"{$name} \$*\" >> {$log}\n{$body}\n");
        chmod("{$bin}/{$name}", 0755);
    }

    try {
        (new SymfonyProcess(['bash', '-c', $aliasProcess['command']], env: ['PATH' => "{$bin}:/usr/bin:/bin"], input: $aliasProcess['input']))->mustRun();

        return ['argv' => (string) file_get_contents($log), 'mcStdin' => (string) file_get_contents("{$bin}/mc.stdin")];
    } finally {
        array_map('unlink', glob("{$bin}/*"));
        rmdir($bin);
    }
}

function s3ArgvTestAssertSecretOnlyOnStdin(Collection $processes, bool $nonRoot): void
{
    foreach ($processes as $process) {
        expect($process['command'])->not->toContain(S3_ARGV_TEST_SECRET)
            ->and($process['command'])->not->toContain(escapeshellarg(S3_ARGV_TEST_SECRET))
            ->and($process['command'])->not->toContain(escapeshellarg(S3_ARGV_TEST_KEY))
            ->and($process['command'])->not->toContain(base64_encode(S3_ARGV_TEST_SECRET));
    }

    $aliasProcess = $processes->sole(fn (array $process) => str_contains($process['command'], 'mc alias set'));
    $run = s3ArgvTestRunAliasProcess($aliasProcess);

    expect($run['mcStdin'])->toBe(S3_ARGV_TEST_KEY."\n".S3_ARGV_TEST_SECRET."\n")
        ->and($run['argv'])->toContain('mc alias set')
        ->and($run['argv'])->toContain('temporary https://8.8.8.8')
        ->and($run['argv'])->not->toContain(S3_ARGV_TEST_SECRET)
        ->and($run['argv'])->not->toContain(S3_ARGV_TEST_KEY);
    if ($nonRoot) {
        expect($run['argv'])->toContain('sudo docker exec -i');
    }
    expect($processes->contains(fn (array $process) => str_contains($process['command']."\n".$process['input'], "-e 'MC_REGION=ru-central-1'")))->toBeTrue();
}

it('passes the S3 keys of a database backup upload to mc on stdin, not in a command line', function (string $sshUser) {
    [$team, $server, $storage] = s3ArgvTestSetup($sshUser);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination, ['status' => 'running:healthy']);
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => true,
        'disable_local_backup' => true,
        's3_storage_id' => $storage->id,
        'frequency' => 'daily',
        'databases_to_backup' => 'app',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
    ])->save();
    $backup->syncS3Storages([$storage->id]);
    $processes = s3ArgvTestFakeSsh();

    (new DatabaseBackupJob($backup->fresh()))->handle();

    expect($backup->executions()->sole()->s3_uploaded)->toBeTrue();
    s3ArgvTestAssertSecretOnlyOnStdin($processes, $sshUser !== 'root');
})->with(['root', 'deploy']);

it('makes mc use virtual-hosted-style URLs when the storage turns path-style URLs off', function (bool $usePathStyleEndpoint, string $expectedAlias) {
    [$team, $server, $storage] = s3ArgvTestSetup('root');
    $storage->update(['use_path_style_endpoint' => $usePathStyleEndpoint]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $database = create_standalone_postgresql($environment->id, $destination, ['status' => 'running:healthy']);
    $backup = new ScheduledDatabaseBackup;
    $backup->forceFill([
        'enabled' => true,
        'save_s3' => true,
        'disable_local_backup' => true,
        's3_storage_id' => $storage->id,
        'frequency' => 'daily',
        'databases_to_backup' => 'app',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
    ])->save();
    $backup->syncS3Storages([$storage->id]);
    $processes = s3ArgvTestFakeSsh();

    (new DatabaseBackupJob($backup->fresh()))->handle();

    $aliasProcess = $processes->sole(fn (array $process) => str_contains($process['command'], 'mc alias set'));
    expect(s3ArgvTestRunAliasProcess($aliasProcess)['argv'])->toContain($expectedAlias);
})->with([
    'path-style' => [true, 'mc alias set --api S3v4 temporary https://8.8.8.8'],
    'virtual-hosted-style' => [false, 'mc alias set --api S3v4 --path off temporary https://8.8.8.8'],
]);

it('passes the S3 keys of a volume backup to mc on stdin, not in a command line', function (string $sshUser, bool $streamToS3) {
    [$team, $server, $storage] = s3ArgvTestSetup($sshUser);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'save_s3' => true,
        'disable_local_backup' => $streamToS3,
        's3_storage_id' => $storage->id,
    ]);
    $processes = s3ArgvTestFakeSsh();

    (new VolumeBackupJob($backup))->handle();

    $execution = ScheduledVolumeBackupExecution::query()->sole();
    expect($execution->status)->toBe('success')
        ->and($execution->s3_uploaded)->toBeTrue()
        ->and($processes->contains(fn (array $process) => str_contains($process['command']."\n".$process['input'], 'mc pipe')))->toBe($streamToS3);
    s3ArgvTestAssertSecretOnlyOnStdin($processes, $sshUser !== 'root');
})->with(['root', 'deploy'])->with(['local archive upload' => false, 'S3-only stream' => true]);
