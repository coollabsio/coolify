<?php

use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\User;
use App\Services\RcloneTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function rcloneTransferTestServer(string $user = 'root'): Server
{
    $owner = User::factory()->create();
    $team = $owner->teams()->first();
    Storage::fake('ssh-keys');

    return Server::factory()->create([
        'team_id' => $team->id,
        'user' => $user,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
}

function rcloneTransferTestStorage(): S3Storage
{
    $storage = new S3Storage;
    $storage->forceFill(['type' => 'sftp', 'endpoint' => 'sftp://1.1.1.1/backups', 'key' => 'backup', 'secret' => 'password']);

    return $storage;
}

it('deletes the partial files of a failed upload, and only of that file', function (string $user, string $docker) {
    Process::fake(fn (PendingProcess $process) => str_contains(remoteProcessScript($process), "'copyto'")
        ? Process::result(exitCode: 1, errorOutput: 'connection lost')
        : Process::result());
    $transfer = new RcloneTransfer(rcloneTransferTestStorage(), rcloneTransferTestServer($user), 'backup-of-x');

    expect(fn () => $transfer->upload('/data/coolify/backups/db/pg-[1].dmp', '/data/coolify/backups/db/pg-[1].dmp'))
        ->toThrow(RuntimeException::class, 'connection lost');

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(remoteProcessScript($process), "{$docker} exec 'backup-of-x' pkill -x rclone >/dev/null 2>&1 || ")
        && str_contains(remoteProcessScript($process), "{$docker} exec 'backup-of-x' rclone --config /tmp/rclone.conf --contimeout 15s 'delete' 'storage:/backups/data/coolify/backups/db' '--max-depth' '1' '--include' '/pg-\\[1\\].dmp.[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f].partial'"));
})->with([
    'root' => ['root', 'docker'],
    'non-root' => ['ubuntu', 'sudo docker'],
]);

it('does not clean up after a successful upload', function () {
    Process::fake();
    $transfer = new RcloneTransfer(rcloneTransferTestStorage(), rcloneTransferTestServer(), 'backup-of-x');

    $transfer->upload('/data/coolify/backups/db/pg.dmp', '/data/coolify/backups/db/pg.dmp');

    Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains(remoteProcessScript($process), '.partial'));
});
