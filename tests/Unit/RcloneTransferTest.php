<?php

use App\Models\InstanceSettings;
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
    $storage->forceFill(['type' => 'sftp', 'endpoint' => 'sftp://1.1.1.1/backups', 'key' => 'backup', 'secret' => 'password', 'sftp_host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhw/KsYTF9TQOwQX1EAM3gI5oxDS9Daz5nNuCixSRXT']);

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

/**
 * Loads config/constants.php with the given environment variables (null unsets one).
 *
 * @param  array<string, ?string>  $variables
 */
function rcloneImageFromEnv(array $variables): string
{
    $original = [];
    foreach ($variables as $name => $value) {
        $original[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
        if ($value !== null) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        return (require config_path('constants.php'))['coolify']['rclone_image'];
    } finally {
        foreach ($original as $name => [$server, $env, $putenv]) {
            unset($_SERVER[$name], $_ENV[$name]);
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            putenv($putenv === false ? $name : "{$name}={$putenv}");
        }
    }
}

it('pulls the rclone image from the configured registry', function (?string $registry, string $expected) {
    expect(rcloneImageFromEnv(['REGISTRY_URL' => $registry, 'RCLONE_IMAGE' => null]))
        ->toBe($expected.'/rclone/rclone:1.75.2@sha256:2687085f718d3c628f7fdfb77c52a1d344332aed543110bb88088f2c70d43eb5');
})->with([
    'default' => [null, 'docker.io'],
    'docker hub' => ['docker.io', 'docker.io'],
    'github' => ['ghcr.io', 'ghcr.io'],
]);

it('uses RCLONE_IMAGE as given', function () {
    expect(rcloneImageFromEnv(['REGISTRY_URL' => 'ghcr.io', 'RCLONE_IMAGE' => 'mirror.example.com/rclone/rclone:1.75.2']))
        ->toBe('mirror.example.com/rclone/rclone:1.75.2');
});

it('labels the rclone container and stops it after the timeout of its job', function (?int $timeout, int $lifetime) {
    Process::fake();
    config(['constants.coolify.rclone_image' => 'docker.io/rclone/rclone:1.75.2']);
    $transfer = new RcloneTransfer(rcloneTransferTestStorage(), rcloneTransferTestServer(), 'backup-of-x');

    $transfer->start(timeout: $timeout);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(remoteProcessScript($process),
        "docker run -d --rm --name 'backup-of-x' --label coolify.type=rclone --entrypoint sleep 'docker.io/rclone/rclone:1.75.2' {$lifetime} >/dev/null"));
})->with([
    'job timeout' => [3600, 3900],
    'no timeout' => [null, 86400],
]);

it('follows the registry setting of the instance for the default rclone image', function (string $configured, string $expected) {
    Process::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['docker_registry_url' => 'ghcr.io']));
    config(['constants.coolify.registry_url' => 'docker.io', 'constants.coolify.rclone_image' => $configured]);
    $transfer = new RcloneTransfer(rcloneTransferTestStorage(), rcloneTransferTestServer(), 'backup-of-x');

    $transfer->start();

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(remoteProcessScript($process), "--entrypoint sleep '{$expected}' 86400"));
})->with([
    'default image' => ['docker.io/rclone/rclone:1.75.2@sha256:abc', 'ghcr.io/rclone/rclone:1.75.2@sha256:abc'],
    'custom image' => ['mirror.example.com/rclone/rclone:1.75.2', 'mirror.example.com/rclone/rclone:1.75.2'],
]);
