<?php

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\CleanupStaleMultiplexedConnections;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Tests for the explicit per-server mux lock that prevents concurrent workers
 * from racing on initial ControlMaster creation.
 */
uses(RefreshDatabase::class);

function makeMuxServer(): Server
{
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $privateKeyContent = '-----BEGIN OPENSSH PRIVATE KEY-----
'.
        'b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
'.
        'QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
'.
        'hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
'.
        'AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
'.
        'uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
'.
        '-----END OPENSSH PRIVATE KEY-----';

    $privateKey = PrivateKey::create([
        'name' => 'mux-test-key-'.uniqid(),
        'private_key' => $privateKeyContent,
        'team_id' => $team->id,
    ]);

    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKeyContent);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);

    Storage::disk('ssh-keys')->put("ssh_key@{$server->privateKey->uuid}", $server->privateKey->private_key);

    return $server;
}

it('establishes a master with ssh -fN and never the orphan-prone ssh -fNM', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 1),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN ')
        && ! str_contains($process->command, 'ssh -fNM'));
});

it('reuses an existing healthy master without spawning a new one', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_health_check_enabled' => true,
    ]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 0),
        '*health_check_ok*' => Process::result(output: 'health_check_ok', exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'ssh -fN'));
});

it('refreshes an expired master before reuse', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_health_check_enabled' => false,
        'constants.ssh.mux_max_age' => 10,
    ]);
    $server = makeMuxServer();
    Cache::put("ssh_mux_connection_time_{$server->uuid}", time() - 30, 3600);

    Process::fake([
        '*-O check*' => Process::result(exitCode: 0),
        '*-O exit*' => Process::result(exitCode: 0),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -O exit'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN '));
});

it('does not spawn a master when the per-server lock is already held', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_lock_timeout' => 0,
    ]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 1),
    ]);

    $lockKey = 'ssh_mux_lock_'.(gethostname() ?: 'unknown').'_'.$server->uuid;
    $held = Cache::lock($lockKey, 30);
    expect($held->get())->toBeTrue();

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'ssh -fN '));

    $held->release();
});

it('returns false and runs no ssh when multiplexing is disabled', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $server = makeMuxServer();

    Process::fake();

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeFalse();

    Process::assertNothingRan();
});

it('adds mux options to ssh commands only after the explicit master is ready', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 1),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo ok');

    expect($command)
        ->toContain('-o ControlMaster=auto')
        ->toContain("-o ControlPath=/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}")
        ->toContain('-o ControlPersist=3600')
        ->toContain("'bash -se' << \\")
        ->not->toContain('<< $delimiter');

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN '));
});

it('can generate terminal ssh commands without a hard command timeout', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $server = makeMuxServer();

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo ok', commandTimeout: 0);

    expect($command)
        ->toStartWith('ssh ')
        ->not->toStartWith('timeout ')
        ->not->toContain('timeout 3600 ssh');
});

it('omits multiplexing options and setup when disabled for a command', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();

    Process::fake();

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo ok', disableMultiplexing: true);

    expect($command)
        ->not->toContain('-o ControlMaster=auto')
        ->not->toContain('-o ControlPath=')
        ->not->toContain('-o ControlPersist=');

    Process::assertNothingRan();
});

it('adds mux options to scp commands only after the explicit master is ready', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 1),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    $command = SshMultiplexingHelper::generateScpCommand($server, '/tmp/source', '/tmp/dest');

    expect($command)
        ->toContain('-o ControlMaster=auto')
        ->toContain("-o ControlPath=/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}")
        ->toContain('-o ControlPersist=3600');

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN '));
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
        'ps*' => Process::result(output: "111 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$liveSocket} root@1.2.3.4
".
            "222 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$orphanSocket} root@1.2.3.4
".
            "333 1 30 ssh -fN -o ControlMaster=auto -o ControlPath={$youngSocket} root@1.2.3.4
"),
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

it('kills only old orphaned cloudflared proxies whose parent ssh is gone', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);

    Process::fake([
        'ps*' => Process::result(output: '100 1 5000 ssh -fN -o ControlMaster=auto root@1.2.3.4
'.
            '200 100 5000 cloudflared access ssh --hostname host.example.com
'.
            '300 2176 5000 cloudflared access ssh --hostname host.example.com
'.
            '400 2176 30 cloudflared access ssh --hostname host.example.com
'.
            '2176 1 9000 /usr/bin/some-supervisor
'),
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
        'ps*' => Process::result(output: "222 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$orphanSocket} root@1.2.3.4
"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill'));
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
