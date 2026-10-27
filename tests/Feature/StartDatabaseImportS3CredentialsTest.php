<?php

use App\Actions\Database\StartDatabaseImport;
use App\Jobs\CoolifyTask;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Support\DatabaseImport\DatabaseImportCleanup;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\RemoteProcessCommand;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'root',
    ]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

test('s3 import activity command does not contain storage key or secret', function () {
    $accessKey = 'AKIA_TEST_ACCESS_KEY_LEAK';
    $secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYTESTSECRET';
    $storage = S3Storage::create([
        'name' => 'Import S3',
        'region' => 'us-east-1',
        'key' => $accessKey,
        'secret' => $secret,
        'bucket' => 'test-bucket',
        'endpoint' => 'https://8.8.8.8',
        'is_usable' => true,
        'team_id' => $this->team->id,
    ]);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with('backups/restore.sql')->andReturn(true);
    $disk->shouldReceive('size')->once()->with('backups/restore.sql')->andReturn(1024);
    $filesystem = Mockery::mock(FilesystemManager::class, [app()])->makePartial();
    $filesystem->shouldReceive('build')->once()->andReturn($disk);
    Storage::swap($filesystem);

    Process::fake();
    Queue::fake();

    $activity = app(StartDatabaseImport::class)->handle(
        $this->database,
        new DatabaseImportSource('s3', path: 'backups/restore.sql', s3StorageUuid: $storage->uuid),
        $this->team->id,
    );

    $command = (string) RemoteProcessCommand::read($activity);

    expect($command)
        ->not->toContain($accessKey)
        ->not->toContain($secret)
        ->not->toContain('.env')
        ->not->toContain('S3_ACCESS_KEY=')
        ->not->toContain('S3_SECRET_KEY=')
        ->not->toContain('mc alias set');

    Queue::assertPushed(CoolifyTask::class, function (CoolifyTask $job) {
        $cleanup = $job->call_event_data;

        return is_array($cleanup)
            && ! array_key_exists('credentialTmpPath', $cleanup)
            && filled($cleanup['containerName'] ?? null);
    });
});

test('s3 import activity stores the cleanup names and paths but no credential values', function () {
    $accessKey = 'AKIA_STORED_CLEANUP_KEY';
    $secret = 'stored/cleanup/SECRET/value';
    $endpoint = 'https://8.8.4.4';
    $storage = S3Storage::create([
        'name' => 'Import S3',
        'region' => 'us-east-1',
        'key' => $accessKey,
        'secret' => $secret,
        'bucket' => 'test-bucket',
        'endpoint' => $endpoint,
        'is_usable' => true,
        'team_id' => $this->team->id,
    ]);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with('backups/restore.sql')->andReturn(true);
    $disk->shouldReceive('size')->once()->with('backups/restore.sql')->andReturn(1024);
    $filesystem = Mockery::mock(FilesystemManager::class, [app()])->makePartial();
    $filesystem->shouldReceive('build')->once()->andReturn($disk);
    Storage::swap($filesystem);

    Process::fake();
    Queue::fake();

    $activity = app(StartDatabaseImport::class)->handle(
        $this->database,
        new DatabaseImportSource('s3', path: 'backups/restore.sql', s3StorageUuid: $storage->uuid),
        $this->team->id,
    );

    $stored = $activity->getExtraProperty(DatabaseImportCleanup::PROPERTY);
    $operation = $activity->getExtraProperty('operation_uuid');

    expect($stored)->toBe([
        'container' => $this->database->uuid,
        'containerTmpPath' => "/tmp/restore_{$operation}",
        'scriptPath' => "/tmp/restore_{$operation}.sh",
        'serverId' => $this->server->id,
        'operationUuid' => $operation,
        'containerName' => "s3-restore-{$operation}",
    ]);

    $properties = json_encode($activity->properties);
    expect($properties)
        ->not->toContain($accessKey)
        ->not->toContain($secret)
        ->not->toContain($endpoint)
        ->not->toContain('S3_ACCESS_KEY=');

    Queue::assertPushed(CoolifyTask::class, fn (CoolifyTask $job) => $job->call_event_data === $stored);
});

test('s3 import helper signs requests with the storage region', function () {
    $storage = S3Storage::create([
        'name' => 'Import S3',
        'region' => 'ru-central-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://8.8.8.8',
        'is_usable' => true,
        'team_id' => $this->team->id,
    ]);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('size')->andReturn(1024);
    $filesystem = Mockery::mock(FilesystemManager::class, [app()])->makePartial();
    $filesystem->shouldReceive('build')->andReturn($disk);
    Storage::swap($filesystem);

    Process::fake();
    Queue::fake();

    app(StartDatabaseImport::class)->handle(
        $this->database,
        new DatabaseImportSource('s3', path: 'backups/restore.sql', s3StorageUuid: $storage->uuid),
        $this->team->id,
    );

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "-e 'MC_REGION=ru-central-1'")
        && str_contains(remoteProcessScript($process), 'docker run -d'));
});

const IMPORT_S3_ARGV_KEY = 'AK/+$x"y';
const IMPORT_S3_ARGV_SECRET = "s3c/r+e\$t 'q\"uo\\te`d` \\n";

/**
 * Starts an S3 import against a faked SSH layer and records every SSH process with its stdin.
 *
 * @return array{0: Collection<int, array{command: string, input: ?string}>, 1: ?Activity, 2: ?Throwable}
 */
function importS3ArgvTestRun(object $test, string $sshUser, bool $aliasFails = false): array
{
    $test->server->update(['user' => $sshUser]);
    $storage = S3Storage::create([
        'name' => 'Import S3',
        'region' => 'ru-central-1',
        'key' => IMPORT_S3_ARGV_KEY,
        'secret' => IMPORT_S3_ARGV_SECRET,
        'bucket' => 'test-bucket',
        'endpoint' => 'https://8.8.8.8',
        'is_usable' => true,
        'team_id' => $test->team->id,
    ]);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('size')->andReturn(1024);
    $filesystem = Mockery::mock(FilesystemManager::class, [app()])->makePartial();
    $filesystem->shouldReceive('build')->andReturn($disk);
    Storage::swap($filesystem);

    $processes = collect();
    Process::fake(function ($process) use ($processes, $aliasFails) {
        $processes->push(['command' => $process->command, 'input' => $process->input]);

        return $aliasFails && str_contains($process->command, 'mc alias set')
            ? Process::result(errorOutput: 'mc: invalid credentials', exitCode: 1)
            : Process::result();
    });
    Queue::fake();

    $activity = null;
    $error = null;
    try {
        $activity = app(StartDatabaseImport::class)->handle(
            $test->database->fresh(),
            new DatabaseImportSource('s3', path: 'backups/restore.sql', s3StorageUuid: $storage->uuid),
            $test->team->id,
        );
    } catch (Throwable $e) {
        $error = $e;
    }

    return [$processes, $activity, $error];
}

/**
 * Runs the recorded `mc alias set` SSH process in a real shell with its stdin. Stubs for `timeout`, `ssh`
 * (runs the remote command in `sh -c`, like a login shell), `sudo`, `docker` and `mc` log their arguments.
 *
 * @param  array{command: string, input: ?string}  $aliasProcess
 * @return array{argv: string, mcStdin: string}
 */
function importS3ArgvTestRunAliasProcess(array $aliasProcess): array
{
    $bin = sys_get_temp_dir().'/import-s3-argv-'.bin2hex(random_bytes(6));
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

it('passes the S3 keys of an import to mc on stdin, not in a command line', function (string $sshUser) {
    [$processes, $activity, $error] = importS3ArgvTestRun($this, $sshUser);

    expect($error)->toBeNull();
    $commands = $processes->pluck('command')->push((string) RemoteProcessCommand::read($activity));
    foreach ($commands as $command) {
        expect($command)
            ->not->toContain(IMPORT_S3_ARGV_SECRET)
            ->not->toContain(escapeshellarg(IMPORT_S3_ARGV_SECRET))
            ->not->toContain(escapeshellarg(IMPORT_S3_ARGV_KEY))
            ->not->toContain(base64_encode(IMPORT_S3_ARGV_SECRET))
            ->not->toContain(base64_encode(IMPORT_S3_ARGV_KEY));
    }

    $aliasProcess = $processes->sole(fn (array $process) => str_contains($process['command'], 'mc alias set'));
    $run = importS3ArgvTestRunAliasProcess($aliasProcess);

    expect($run['mcStdin'])->toBe(IMPORT_S3_ARGV_KEY."\n".IMPORT_S3_ARGV_SECRET."\n")
        ->and($run['argv'])->toContain('mc alias set s3temp https://8.8.8.8')
        ->and($run['argv'])->not->toContain(IMPORT_S3_ARGV_SECRET)
        ->and($run['argv'])->not->toContain(IMPORT_S3_ARGV_KEY)
        ->and($processes->contains(fn (array $process) => str_contains($process['command']."\n".$process['input'], "-e 'MC_REGION=ru-central-1'")))->toBeTrue()
        ->and((string) RemoteProcessCommand::read($activity))->toContain('mc cp '.escapeshellarg('s3temp/test-bucket/backups/restore.sql'));
    if ($sshUser !== 'root') {
        expect($run['argv'])->toContain('sudo docker exec -i');
    }
})->with(['root', 'deploy']);

it('removes the S3 restore helper when the mc alias cannot be set', function () {
    [$processes, $activity, $error] = importS3ArgvTestRun($this, 'root', aliasFails: true);

    expect($activity)->toBeNull()
        ->and($error)->toBeInstanceOf(DatabaseImportException::class);
    $aliasIndex = $processes->search(fn (array $process) => str_contains($process['command'], 'mc alias set'));
    $removedAfterAlias = $processes->slice($aliasIndex + 1)
        ->contains(fn (array $process) => preg_match("/docker rm -f '?s3-restore-/", $process['command']."\n".$process['input']) === 1);
    expect($removedAfterAlias)->toBeTrue();
    Queue::assertNothingPushed();
});
