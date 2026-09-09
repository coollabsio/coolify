<?php

use App\Events\DatabaseImportFinished;
use App\Listeners\CleanupDatabaseImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
