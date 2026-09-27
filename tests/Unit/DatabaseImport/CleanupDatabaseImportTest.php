<?php

use App\Events\DatabaseImportFinished;
use App\Listeners\CleanupDatabaseImport;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function importCleanupPayload(array $overrides = []): array
{
    return array_merge([
        'container' => 'postgres-abc',
        'containerTmpPath' => '/tmp/restore_op',
        'scriptPath' => '/tmp/restore_op.sh',
        'serverId' => 1,
    ], $overrides);
}

test('the finished event keeps the payload and does not import Server', function () {
    $data = importCleanupPayload(['serverTmpPath' => '/tmp/database-import-op']);
    $event = new DatabaseImportFinished($data);

    expect($event->data)->toBe($data)
        ->and(file_get_contents(app_path('Events/DatabaseImportFinished.php')))
        ->not->toContain('instant_remote_process')
        ->not->toContain('use App\Models\Server');
});

test('the cleanup listener is queued and discovered', function () {
    expect(class_implements(CleanupDatabaseImport::class))->toContain(ShouldQueue::class);

    $listener = new CleanupDatabaseImport;
    expect($listener->tries)->toBe(3)
        ->and($listener->backoff)->toBe([5, 15, 30]);

    Event::fake();
    Event::assertListening(DatabaseImportFinished::class, CleanupDatabaseImport::class);
});

test('builds S3, upload, and server-path cleanup commands', function () {
    $listener = new CleanupDatabaseImport;

    expect($listener->commands(importCleanupPayload([
        'containerName' => 's3-restore-op',
        'serverTmpPath' => '/tmp/s3-restore-op',
        'credentialTmpPath' => '/tmp/s3-restore-op.env',
    ])))->toBe([
        'docker rm -f '.escapeshellarg('s3-restore-op').' 2>/dev/null || true',
        'rm -f '.escapeshellarg('/tmp/s3-restore-op').' 2>/dev/null || true',
        'rm -f '.escapeshellarg('/tmp/s3-restore-op.env').' 2>/dev/null || true',
        'docker exec '.escapeshellarg('postgres-abc').' rm -f '.escapeshellarg('/tmp/restore_op').' 2>/dev/null || true',
        'docker exec '.escapeshellarg('postgres-abc').' rm -f '.escapeshellarg('/tmp/restore_op.sh').' 2>/dev/null || true',
    ]);

    expect($listener->commands(importCleanupPayload([
        'serverTmpPath' => '/tmp/database-import-op',
    ])))->toBe([
        'rm -f '.escapeshellarg('/tmp/database-import-op').' 2>/dev/null || true',
        'docker exec '.escapeshellarg('postgres-abc').' rm -f '.escapeshellarg('/tmp/restore_op').' 2>/dev/null || true',
        'docker exec '.escapeshellarg('postgres-abc').' rm -f '.escapeshellarg('/tmp/restore_op.sh').' 2>/dev/null || true',
    ]);

    expect($listener->commands(importCleanupPayload()))->toBe([
        'docker exec '.escapeshellarg('postgres-abc').' rm -f '.escapeshellarg('/tmp/restore_op').' 2>/dev/null || true',
        'docker exec '.escapeshellarg('postgres-abc').' rm -f '.escapeshellarg('/tmp/restore_op.sh').' 2>/dev/null || true',
    ]);
});

test('omits unsafe paths, missing container execs, and empty payloads', function () {
    $listener = new CleanupDatabaseImport;

    expect($listener->commands(importCleanupPayload([
        'containerName' => 's3-restore-op',
        'serverTmpPath' => '/tmp/../etc/passwd',
        'credentialTmpPath' => '/tmp/../etc/shadow',
        'containerTmpPath' => '/etc/shadow',
        'scriptPath' => '/tmp/../../etc/shadow',
    ])))->toBe([
        'docker rm -f '.escapeshellarg('s3-restore-op').' 2>/dev/null || true',
    ]);

    expect($listener->commands([
        'serverTmpPath' => '/tmp/database-import-op',
        'containerTmpPath' => '/tmp/restore_op',
        'scriptPath' => '/tmp/restore_op.sh',
    ]))->toBe([
        'rm -f '.escapeshellarg('/tmp/database-import-op').' 2>/dev/null || true',
    ]);

    expect($listener->commands([]))->toBe([]);
});

test('handle skips remote process when the server is missing', function () {
    $event = new DatabaseImportFinished(importCleanupPayload([
        'serverId' => 999999,
        'containerName' => 's3-restore-op',
    ]));

    expect(fn () => (new CleanupDatabaseImport)->handle($event))->not->toThrow(Throwable::class);
});

test('adds the restore stop command only when a stop is requested for a valid operation', function () {
    $listener = new CleanupDatabaseImport;
    $operation = '0b6f2f4e-3a55-4d8c-9d42-6c1c6c1f5a10';
    $payload = importCleanupPayload([
        'operationUuid' => $operation,
        'containerTmpPath' => "/tmp/restore_{$operation}",
        'scriptPath' => "/tmp/restore_{$operation}.sh",
        'containerName' => "s3-restore-{$operation}",
    ]);

    expect(collect($listener->commands($payload))->filter(fn ($command) => str_contains($command, 'kill')))->toBeEmpty();

    $commands = $listener->commands([...$payload, 'stopRestore' => true]);
    expect($commands[0])->toBe($listener->stopRestoreCommand('postgres-abc', $operation))
        ->and($commands[1])->toBe('docker rm -f '.escapeshellarg("s3-restore-{$operation}").' 2>/dev/null || true')
        ->and($commands)->toHaveCount(4);

    foreach (["x'; reboot; '", '../../etc', '', 'op'] as $invalid) {
        expect(collect($listener->commands([...$payload, 'operationUuid' => $invalid, 'stopRestore' => true]))
            ->filter(fn ($command) => str_contains($command, 'kill')))->toBeEmpty();
    }
});

test('the restore stop command quotes the container and targets only the operation path', function () {
    $listener = new CleanupDatabaseImport;
    $operation = '0b6f2f4e-3a55-4d8c-9d42-6c1c6c1f5a10';
    $command = $listener->stopRestoreCommand("db'; reboot; '", $operation);

    expect($command)
        ->toStartWith('docker exec '.escapeshellarg("db'; reboot; '").' sh -c ')
        ->toEndWith(' 2>/dev/null || true')
        ->toContain("/tmp/restore_{$operation}")
        ->not->toContain('pkill')
        ->not->toContain('pgrep')
        ->not->toContain('$(');

    expect(fn () => $listener->stopRestoreCommand('db', 'not-a-uuid'))->toThrow(InvalidArgumentException::class);
});

test('the restore stop command passes the non-root sudo parser and stops only the matching restore', function () {
    $operation = (string) Str::uuid();
    $other = (string) Str::uuid();
    $dir = sys_get_temp_dir().'/coolify-stop-restore-'.Str::random(8);
    mkdir($dir);
    // Fake sudo and docker: `docker exec <container> ...` runs the command on this machine.
    file_put_contents("{$dir}/sudo", "#!/bin/sh\nexec \"\$@\"\n");
    file_put_contents("{$dir}/docker", "#!/bin/sh\n[ \"\$1\" = exec ] || exit 1\nshift 2\nexec \"\$@\"\n");
    chmod("{$dir}/sudo", 0755);
    chmod("{$dir}/docker", 0755);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $server->user = 'ubuntu';

    $parsed = parseCommandsByLineForSudo(collect([(new CleanupDatabaseImport)->stopRestoreCommand('postgres-abc', $operation)]), $server);
    expect($parsed)->toHaveCount(1)
        ->and($parsed[0])->toStartWith("sudo bash -c 'docker exec ");
    file_put_contents("{$dir}/stop.sh", implode("\n", $parsed)."\n");

    // The restore shell has the operation path in its command line; its children do not.
    // The shell must reap each stopped child itself: it exits by itself after the last one.
    $restore = new Process(['sh', '-c', "sleep 60 | cat; sleep 60; : /tmp/restore_{$operation}.sh"]);
    // A top-level process without children, for example a direct `docker exec` of a restore tool.
    $leaf = new Process(['bash', '-c', "exec -a 'restore-tool /tmp/restore_{$operation}' sleep 60"]);
    $unrelated = new Process(['sh', '-c', "sleep 60; : /tmp/restore_{$other}.sh"]);
    $restore->start();
    $leaf->start();
    $unrelated->start();

    try {
        usleep(300_000);
        $children = array_filter(explode("\n", trim((string) shell_exec('pgrep -P '.(int) $restore->getPid()))));
        expect($children)->toHaveCount(2);

        $stop = new Process(['bash', "{$dir}/stop.sh"], env: ['PATH' => "{$dir}:".getenv('PATH')]);
        $stop->setTimeout(60)->run();

        expect($stop->getExitCode())->toBe(0)
            ->and($stop->getOutput())->toContain('Stopping the database import processes (TERM):');
        usleep(200_000);
        $isAlive = fn (string $pid): bool => file_exists("/proc/{$pid}")
            && ! str_contains((string) @file_get_contents("/proc/{$pid}/status"), "State:\tZ");

        expect($restore->isRunning())->toBeFalse()
            ->and($restore->hasBeenSignaled())->toBeFalse()
            ->and($leaf->isRunning())->toBeFalse()
            ->and(collect($children)->filter($isAlive))->toBeEmpty()
            ->and($unrelated->isRunning())->toBeTrue();
    } finally {
        $restore->stop(0);
        $leaf->stop(0);
        $unrelated->stop(0);
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }
})->skip(fn () => PHP_OS_FAMILY !== 'Linux' || ! is_dir('/proc/self'), 'Needs Linux /proc.');
