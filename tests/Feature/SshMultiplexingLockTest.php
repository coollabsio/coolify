<?php

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\CleanupStaleMultiplexedConnections;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * SSH multiplexing now relies on OpenSSH's native lazy ControlMaster handling.
 * Coolify should add mux options to real ssh/scp commands, but must not pre-warm
 * background masters with separate `ssh -fN` processes.
 */
uses(RefreshDatabase::class);

function makeMuxServer(): Server
{
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $privateKeyContent = "-----BEGIN OPENSSH PRIVATE KEY-----\n".
        "b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n".
        "QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk\n".
        "hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA\n".
        "AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV\n".
        "uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==\n".
        '-----END OPENSSH PRIVATE KEY-----';

    $privateKey = PrivateKey::create([
        'name' => 'mux-test-key-'.uniqid(),
        'private_key' => $privateKeyContent,
        'team_id' => $team->id,
    ]);

    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKeyContent);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
}

it('does not prewarm a background ssh master', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();

    Process::fake();

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();

    Process::assertNothingRan();
});

it('adds native openssh multiplexing options to ssh commands', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();
    Storage::disk('ssh-keys')->put("ssh_key@{$server->privateKey->uuid}", $server->privateKey->private_key);

    Process::fake();

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo ok');

    expect($command)
        ->toContain('-o ControlMaster=auto')
        ->toContain("-o ControlPath=/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}")
        ->toContain('-o ControlPersist=3600')
        ->not->toContain('-O check')
        ->not->toContain('ssh -fN');

    Process::assertNothingRan();
});

it('omits native multiplexing options when ssh multiplexing is disabled for a command', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();
    Storage::disk('ssh-keys')->put("ssh_key@{$server->privateKey->uuid}", $server->privateKey->private_key);

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo ok', disableMultiplexing: true);

    expect($command)
        ->not->toContain('-o ControlMaster=auto')
        ->not->toContain('-o ControlPath=')
        ->not->toContain('-o ControlPersist=');
});

it('adds native openssh multiplexing options to scp commands', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();

    Process::fake();

    $command = SshMultiplexingHelper::generateScpCommand($server, '/tmp/source', '/tmp/dest');

    expect($command)
        ->toContain('-o ControlMaster=auto')
        ->toContain("-o ControlPath=/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}")
        ->toContain('-o ControlPersist=3600')
        ->not->toContain('-O check')
        ->not->toContain('ssh -fN');

    Process::assertNothingRan();
});

it('returns false and runs no process when multiplexing is globally disabled', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $server = makeMuxServer();

    Process::fake();

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeFalse();

    Process::assertNothingRan();
});

it('kills only old orphaned ssh masters whose control socket no longer exists', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);

    $liveSocket = $muxDir.'/mux_live_'.uniqid();
    $orphanSocket = $muxDir.'/mux_orphan_'.uniqid();
    $youngSocket = $muxDir.'/mux_young_'.uniqid();
    File::put($liveSocket, 'x');

    Process::fake([
        'ps*' => Process::result(output: "111 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$liveSocket} root@1.2.3.4\n".
            "222 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$orphanSocket} root@1.2.3.4\n".
            "333 1 30 ssh -fN -o ControlMaster=auto -o ControlPath={$youngSocket} root@1.2.3.4\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '222'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '111'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '333'));

    File::delete($liveSocket);
});

it('kills old orphaned native openssh mux masters whose control socket no longer exists', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);

    $liveSocket = $muxDir.'/mux_native_live_'.uniqid();
    $orphanSocket = $muxDir.'/mux_native_orphan_'.uniqid();
    File::put($liveSocket, 'x');

    Process::fake([
        'ps*' => Process::result(output: "111 1 5000 ssh: {$liveSocket} [mux]\n".
            "222 1 5000 ssh: {$orphanSocket} [mux]\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '222'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '111'));

    File::delete($liveSocket);
});

it('kills only old orphaned cloudflared proxies whose parent ssh is gone', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);

    Process::fake([
        'ps*' => Process::result(output: "100 1 5000 ssh -fN -o ControlMaster=auto root@1.2.3.4\n".
            "200 100 5000 cloudflared access ssh --hostname host.example.com\n".
            "300 2176 5000 cloudflared access ssh --hostname host.example.com\n".
            "400 2176 30 cloudflared access ssh --hostname host.example.com\n".
            "2176 1 9000 /usr/bin/some-supervisor\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedCloudflaredProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '300'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '200'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '400'));
});

it('dry-run mode logs orphans but kills nothing when reaping is disabled', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => false]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);

    $orphanSocket = $muxDir.'/mux_orphan_'.uniqid();

    Process::fake([
        'ps*' => Process::result(output: "222 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$orphanSocket} root@1.2.3.4\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill'));
});

it('resets duplicate ssh mux process groups atomically when reaping is enabled', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);
    $controlPath = $muxDir.'/mux_duplicate_'.uniqid();
    File::put($controlPath, 'socket');

    Process::fake([
        'ps*' => Process::result(output: "111 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$controlPath} root@1.2.3.4\n".
            "222 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$controlPath} root@1.2.3.4\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupDuplicateSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '111'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '222'));
    expect(file_exists($controlPath))->toBeFalse();
});

it('resets duplicate native openssh mux process groups atomically when reaping is enabled', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);
    $controlPath = $muxDir.'/mux_native_duplicate_'.uniqid();
    File::put($controlPath, 'socket');

    Process::fake([
        'ps*' => Process::result(output: "111 1 5000 ssh: {$controlPath} [mux]\n".
            "222 1 5000 ssh: {$controlPath} [mux]\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupDuplicateSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '111'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '222'));
    expect(file_exists($controlPath))->toBeFalse();
});

it('removes mux files for non-existent servers when reaping is enabled', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    Storage::fake('ssh-mux');
    $file = 'mux_ghost'.uniqid();
    Storage::disk('ssh-mux')->put($file, 'x');
    Process::fake();

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupNonExistentServerConnections');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(Storage::disk('ssh-mux')->exists($file))->toBeFalse();
});

it('keeps mux files for non-existent servers in dry-run mode', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => false]);
    Storage::fake('ssh-mux');
    $file = 'mux_ghost'.uniqid();
    Storage::disk('ssh-mux')->put($file, 'x');
    Process::fake();

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupNonExistentServerConnections');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(Storage::disk('ssh-mux')->exists($file))->toBeTrue();
    Process::assertNothingRan();
});
