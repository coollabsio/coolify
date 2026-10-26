<?php

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\CleanupStaleMultiplexedConnections;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;

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

/**
 * A fake process that times out. Laravel does not record a fake that throws, so the
 * timeout of each call goes into $timeouts.
 *
 * @param  array<int, int|float|null>  $timeouts
 */
function muxProcessTimeout(array &$timeouts = []): Closure
{
    return function ($pendingProcess) use (&$timeouts) {
        $timeouts[] = $pendingProcess->timeout;
        $process = new SymfonyProcess(['ssh']);

        throw new ProcessTimedOutException(
            new SymfonyTimeoutException($process, SymfonyTimeoutException::TYPE_GENERAL),
            new ProcessResult($process),
        );
    };
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

it('reuses a healthy master regardless of its absolute age', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_health_check_enabled' => false,
    ]);
    $server = makeMuxServer();
    Cache::put("ssh_mux_connection_time_{$server->uuid}", time() - 7200, 10800);

    Process::fake([
        '*-O check*' => Process::result(exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'ssh -O stop'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'ssh -fN '));
});

it('bounds mux check commands so a wedged master cannot block callers', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_health_check_timeout' => 5,
    ]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(exitCode: 1),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    SshMultiplexingHelper::ensureMultiplexedConnection($server);

    Process::assertRan(fn ($process) => str_contains($process->command, '-O check'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, '-O check') && $process->timeout !== 5);
});

it('replaces a wedged master whose control check times out', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_health_check_timeout' => 5,
    ]);
    $server = makeMuxServer();
    $muxSocket = "/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}";

    File::partialMock()->shouldReceive('delete')->once()->with($muxSocket)->andReturnTrue();
    $timeouts = [];
    Process::fake([
        '*-O check*' => muxProcessTimeout($timeouts),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue()
        ->and($timeouts)->each->toBe(5);

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN '));
    Process::assertNotRan(fn ($process) => str_contains($process->command, '-O stop'));
});

it('does not delete the control socket when the master is simply absent', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();

    File::partialMock()->shouldNotReceive('delete');
    Process::fake([
        '*-O check*' => Process::result(exitCode: 255),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();
});

it('bounds the control commands used to stop a mux master', function () {
    config(['constants.ssh.mux_health_check_timeout' => 5]);
    $server = makeMuxServer();
    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=222)', exitCode: 0),
        '*-O stop*' => Process::result(exitCode: 0),
    ]);

    SshMultiplexingHelper::removeMuxFile($server);

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -O check') && $process->timeout === 5);
    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -O stop') && $process->timeout === 5);
});

it('replaces a master whose health check times out', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_health_check_enabled' => true,
    ]);
    $server = makeMuxServer();

    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=222)', exitCode: 0),
        '*health_check_ok*' => muxProcessTimeout(),
        '*-O stop*' => Process::result(exitCode: 0),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -O stop'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN '));
});

it('gives the mux health check at least the server connection timeout', function () {
    config([
        'constants.ssh.mux_enabled' => true,
        'constants.ssh.mux_health_check_enabled' => true,
        'constants.ssh.mux_health_check_timeout' => 5,
    ]);
    $server = makeMuxServer();
    $server->settings->update(['connection_timeout' => 30]);

    Process::fake([
        '*-O check*' => Process::result(exitCode: 0),
        '*health_check_ok*' => Process::result(output: 'health_check_ok', exitCode: 0),
    ]);

    expect(SshMultiplexingHelper::ensureMultiplexedConnection($server->fresh()))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'health_check_ok') && $process->timeout === 30);
    Process::assertNotRan(fn ($process) => str_contains($process->command, '-O check') && $process->timeout !== 5);
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
        ->toContain("-o ControlPath='/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}'")
        ->toContain('-o ControlPersist=3600')
        ->toContain("'sh -c \"if command -v bash >/dev/null 2>&1; then exec bash -se; else exec sh -se; fi\"' << \\")
        ->not->toContain('<< $delimiter');

    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN '));
});

it('quotes the mux socket and key paths in every local ssh command', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeMuxServer();
    // Ids with shell syntax, as an import bundle could have had them before ids were validated.
    $server->forceFill(['uuid' => 'x;touch /tmp/pwn;#'])->saveQuietly();
    $server->privateKey->forceFill(['uuid' => 'a $(id)'])->saveQuietly();
    $server = Server::find($server->id);
    Storage::disk('ssh-keys')->put('ssh_key@a $(id)', $server->privateKey->private_key);

    Process::fake([
        '*-O check*' => Process::result(exitCode: 1),
        '*-fN *' => Process::result(exitCode: 0),
    ]);

    $socket = escapeshellarg('/var/www/html/storage/app/ssh/mux/mux_x;touch /tmp/pwn;#');
    $key = escapeshellarg($server->privateKey->getKeyLocation());

    $sshCommand = SshMultiplexingHelper::generateSshCommand($server, 'echo ok');
    $scpCommand = SshMultiplexingHelper::generateScpCommand($server, '/tmp/source', '/tmp/dest');

    foreach ([$sshCommand, $scpCommand] as $command) {
        expect($command)->toContain("-o ControlPath={$socket} ")->toContain("-i {$key} ");
    }
    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -fN ')
        && str_contains($process->command, "-o ControlPath={$socket} ")
        && str_contains($process->command, "-i {$key} "));
    Process::assertRan(fn ($process) => str_contains($process->command, "ssh -O check -o ControlPath={$socket} "));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'mux_x;touch /tmp/pwn;# ')
        || str_contains($process->command, '-i /') || str_contains($process->command, 'ControlPath=/'));
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
        ->toContain("-o ControlPath='/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}'")
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
        '*ssh -O check*' => Process::result(errorOutput: 'Master running (pid=111)'),
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

it('kills ssh -fN processes that share a live ControlPath but do not own it', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);

    $socket = $muxDir.'/mux_shared_'.uniqid();
    File::put($socket, 'x');

    Process::fake([
        'ps*' => Process::result(output: "111 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$socket} root@1.2.3.4\n".
            "222 1 7000 ssh -fN -o ControlMaster=auto -o ControlPath={$socket} root@1.2.3.4\n".
            "333 1 9000 ssh -fN -o ControlMaster=auto -o ControlPath={$socket} root@1.2.3.4\n"),
        '*ssh -O check*' => Process::result(errorOutput: 'Master running (pid=111)'),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertRan(fn ($process) => $process->command === 'kill -9 '.escapeshellarg('222'));
    Process::assertRan(fn ($process) => $process->command === 'kill -9 '.escapeshellarg('333'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill') && str_contains($process->command, '111'));
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'ssh -O check'), 1);

    File::delete($socket);
});

it('does not kill ssh -fN processes when the socket owner is unknown', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);

    $socket = $muxDir.'/mux_unknown_'.uniqid();
    File::put($socket, 'x');

    Process::fake([
        'ps*' => Process::result(output: "111 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$socket} root@1.2.3.4\n"),
        '*ssh -O check*' => Process::result(exitCode: 255, errorOutput: 'Control socket connect: Connection refused'),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill'));

    File::delete($socket);
});

it('does not reap an ssh master that is intentionally retiring', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    $muxDir = storage_path('app/ssh/mux');
    $retiringSocket = $muxDir.'/mux_retiring_'.uniqid();
    SshMultiplexingHelper::markMuxProcessAsRetiring('222', $retiringSocket);

    Process::fake([
        'ps*' => Process::result(output: "222 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$retiringSocket} root@1.2.3.4\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->setAccessible(true);
    $method->invoke($job);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill'));
});

it('does not treat a reused pid as retiring', function () {
    $socket = storage_path('app/ssh/mux/mux_original');

    SshMultiplexingHelper::markMuxProcessAsRetiring('222', $socket, '1000');

    expect(SshMultiplexingHelper::isMuxProcessRetiring('222', $socket, '1000'))->toBeTrue()
        ->and(SshMultiplexingHelper::isMuxProcessRetiring('222', $socket, '2000'))->toBeFalse();
});

it('scopes retirement markers to the current host and pid namespace', function () {
    $method = new ReflectionMethod(SshMultiplexingHelper::class, 'processScope');
    $method->setAccessible(true);

    expect($method->invoke(null))
        ->toBeString()
        ->toStartWith((gethostname() ?: 'unknown').'|');
});

it('keeps a retirement marker for long-running ssh sessions', function () {
    $socket = storage_path('app/ssh/mux/mux_retired');

    SshMultiplexingHelper::markMuxProcessAsRetiring('222', $socket);
    $this->travel((int) config('constants.ssh.mux_persist_time') * 2 + 1)->seconds();

    expect(SshMultiplexingHelper::isMuxProcessRetiring('222', $socket))->toBeTrue();
});

it('reads the process start time used to distinguish pid reuse', function () {
    $method = new ReflectionMethod(SshMultiplexingHelper::class, 'processStartTime');
    $method->setAccessible(true);

    expect($method->invoke(null, (string) getmypid()))->toMatch('/^\d+$/');
});

it('marks a successfully stopped mux process as retiring', function () {
    $server = makeMuxServer();
    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=222)', exitCode: 0),
        '*-O stop*' => Process::result(exitCode: 0),
    ]);

    SshMultiplexingHelper::removeMuxFile($server);

    expect(SshMultiplexingHelper::isMuxProcessRetiring('222', "/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}"))->toBeTrue();
});

it('marks a mux process as retiring before stopping it', function () {
    $server = makeMuxServer();
    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=555)', exitCode: 0),
        '*-O stop*' => function () use ($server) {
            expect(SshMultiplexingHelper::isMuxProcessRetiring('555', "/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}"))->toBeTrue();

            return Process::result(exitCode: 0);
        },
    ]);

    SshMultiplexingHelper::removeMuxFile($server);
});

it('does not mark a mux process as retiring when stop fails', function () {
    $server = makeMuxServer();
    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=444)', exitCode: 0),
        '*-O stop*' => function () use ($server) {
            expect(SshMultiplexingHelper::isMuxProcessRetiring('444', "/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}"))->toBeTrue();

            return Process::result(exitCode: 1);
        },
    ]);

    SshMultiplexingHelper::removeMuxFile($server);

    expect(SshMultiplexingHelper::isMuxProcessRetiring('444', "/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}"))->toBeFalse();
});

it('removes the control socket when the mux master does not stop', function (bool $stopTimesOut) {
    $server = makeMuxServer();
    File::partialMock()->shouldReceive('delete')->once()->with("/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}");
    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=888)', exitCode: 0),
        '*-O stop*' => $stopTimesOut ? muxProcessTimeout() : Process::result(exitCode: 255),
    ]);

    SshMultiplexingHelper::removeMuxFile($server);

    expect(SshMultiplexingHelper::isMuxProcessRetiring('888', "/var/www/html/storage/app/ssh/mux/mux_{$server->uuid}"))->toBeFalse();
})->with([
    'stop fails' => false,
    'stop times out' => true,
]);

it('keeps the control socket untouched when the mux master stops', function () {
    $server = makeMuxServer();
    File::partialMock()->shouldNotReceive('delete');
    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=999)', exitCode: 0),
        '*-O stop*' => Process::result(exitCode: 0),
    ]);

    SshMultiplexingHelper::removeMuxFile($server);
});

it('kills only old cloudflared proxies whose stdin pipe no live ssh holds', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);

    // This test process stands in for a live ssh master: it holds the write end
    // of the reparented proxy's stdin pipe. The orphan's writer is closed.
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $reparented = proc_open(['sleep', '60'], $descriptors, $reparentedPipes);
    $orphan = proc_open(['sleep', '60'], $descriptors, $orphanPipes);
    $tunnelSsh = proc_open(['sleep', '60'], $descriptors, $tunnelSshPipes);
    fclose($orphanPipes[0]);
    fclose($tunnelSshPipes[0]);
    $reparentedPid = proc_get_status($reparented)['pid'];
    $orphanPid = proc_get_status($orphan)['pid'];
    $tunnelSshPid = proc_get_status($tunnelSsh)['pid'];
    $sshPid = getmypid();

    Process::fake([
        'ps*' => Process::result(output: "{$sshPid} 1 5000 ssh -fN -o ControlMaster=auto root@1.2.3.4\n".
            "200 {$sshPid} 5000 cloudflared access ssh --hostname host.example.com\n".
            "{$reparentedPid} 1 5000 cloudflared access ssh --hostname host.example.com\n".
            "{$orphanPid} 1 5000 cloudflared access ssh --hostname host.example.com\n".
            "400 1 30 cloudflared access ssh --hostname host.example.com\n".
            "{$tunnelSshPid} 1 5000 ssh -fN -o ProxyCommand=cloudflared access ssh --hostname %h root@1.2.3.4\n"),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedCloudflaredProcesses');
    $method->invoke($job);

    proc_terminate($reparented);
    proc_terminate($orphan);
    proc_terminate($tunnelSsh);
    proc_close($reparented);
    proc_close($orphan);
    proc_close($tunnelSsh);

    Process::assertRan(fn ($process) => $process->command === 'kill '.escapeshellarg((string) $orphanPid));
    Process::assertRanTimes(fn ($process) => str_starts_with($process->command, 'kill'), 1);
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
    Process::fake([
        '*-O check*' => Process::result(errorOutput: 'Master running (pid=333)', exitCode: 0),
        '*-O stop*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupNonExistentServerConnections');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(Storage::disk('ssh-mux')->exists($file))->toBeFalse();
    expect(SshMultiplexingHelper::isMuxProcessRetiring('333', "/var/www/html/storage/app/ssh/mux/{$file}"))->toBeTrue();
    Process::assertRan(fn ($process) => str_contains($process->command, 'ssh -O stop'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'ssh -O exit'));
});

it('marks a stale mux process as retiring before stopping it', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    Storage::fake('ssh-mux');
    $file = 'mux_ghost'.uniqid();
    Storage::disk('ssh-mux')->put($file, 'x');
    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=666)', exitCode: 0),
        '*-O stop*' => function () use ($file) {
            expect(SshMultiplexingHelper::isMuxProcessRetiring('666', "/var/www/html/storage/app/ssh/mux/{$file}"))->toBeTrue();

            return Process::result(exitCode: 0);
        },
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupNonExistentServerConnections');
    $method->setAccessible(true);
    $method->invoke($job);
});

it('removes a stale mux retirement marker when stopping fails', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    Storage::fake('ssh-mux');
    $file = 'mux_ghost'.uniqid();
    $muxSocket = "/var/www/html/storage/app/ssh/mux/{$file}";
    Storage::disk('ssh-mux')->put($file, 'x');
    Process::fake([
        '*-O check*' => Process::result(output: 'Master running (pid=777)', exitCode: 0),
        '*-O stop*' => function () use ($muxSocket) {
            expect(SshMultiplexingHelper::isMuxProcessRetiring('777', $muxSocket))->toBeTrue();

            return Process::result(exitCode: 1);
        },
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupNonExistentServerConnections');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(SshMultiplexingHelper::isMuxProcessRetiring('777', $muxSocket))->toBeFalse();
});

it('removes a mux connection whose control check times out during cleanup', function () {
    config([
        'constants.ssh.mux_orphan_reap_enabled' => true,
        'constants.ssh.mux_health_check_timeout' => 5,
    ]);
    Storage::fake('ssh-mux');
    $server = makeMuxServer();
    $file = "mux_{$server->uuid}";
    Storage::disk('ssh-mux')->put($file, 'x');

    $timeouts = [];
    Process::fake([
        '*-O check*' => muxProcessTimeout($timeouts),
        '*-O stop*' => muxProcessTimeout($timeouts),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupStaleConnections');
    $method->invoke($job);

    expect(Storage::disk('ssh-mux')->exists($file))->toBeFalse()
        ->and($timeouts)->toBe([5, 5, 5]);
    Process::assertNotRan(fn ($process) => str_contains($process->command, $server->ip));
});

it('does not reap ssh processes when the socket owner check times out', function () {
    config([
        'constants.ssh.mux_orphan_reap_enabled' => true,
        'constants.ssh.mux_health_check_timeout' => 5,
    ]);
    $muxDir = storage_path('app/ssh/mux');
    File::ensureDirectoryExists($muxDir);
    $socket = $muxDir.'/mux_wedged_'.uniqid();
    File::put($socket, 'x');

    $timeouts = [];
    Process::fake([
        'ps*' => Process::result(output: "111 1 5000 ssh -fN -o ControlMaster=auto -o ControlPath={$socket} root@1.2.3.4\n"),
        '*ssh -O check*' => muxProcessTimeout($timeouts),
        'kill*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupOrphanedSshProcesses');
    $method->invoke($job);

    expect($timeouts)->toBe([5]);
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kill'));

    File::delete($socket);
});

it('does not remove a healthy mux connection based on its absolute age', function () {
    config(['constants.ssh.mux_orphan_reap_enabled' => true]);
    Storage::fake('ssh-mux');
    $server = makeMuxServer();
    $file = "mux_{$server->uuid}";
    Storage::disk('ssh-mux')->put($file, str_repeat('x', 37).now()->subHours(2)->toIso8601String());

    Process::fake([
        '*-O check*' => Process::result(exitCode: 0),
    ]);

    $job = new CleanupStaleMultiplexedConnections;
    $method = new ReflectionMethod($job, 'cleanupStaleConnections');
    $method->setAccessible(true);
    $method->invoke($job);

    expect(Storage::disk('ssh-mux')->exists($file))->toBeTrue();
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'ssh -O stop'));
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
