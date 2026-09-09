<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Tests that per-call SSH command timeouts (e.g. a scheduled backup's configured
 * timeout) reach the shell-level `timeout N ssh` wrapper instead of being capped
 * by the global constants.ssh.command_timeout default.
 *
 * @see https://github.com/coollabsio/coolify DatabaseBackupJob/VolumeBackupJob pass
 *      a per-backup timeout to instant_remote_process()
 */
uses(RefreshDatabase::class);

function makeTimeoutTestServer(): Server
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
        'name' => 'timeout-test-key-'.uniqid(),
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

it('wraps ssh commands with an explicitly passed command timeout', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $server = makeTimeoutTestServer();

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo ok', commandTimeout: 7200);

    expect($command)->toStartWith('timeout 7200 ssh ');
});

it('wraps ssh commands with the configured default timeout when none is passed', function () {
    config([
        'constants.ssh.mux_enabled' => false,
        'constants.ssh.command_timeout' => 1234,
    ]);
    $server = makeTimeoutTestServer();

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo ok');

    expect($command)->toStartWith('timeout 1234 ssh ');
});

it('forwards the per-call timeout of instant_remote_process to the ssh timeout wrapper', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $server = makeTimeoutTestServer();

    Process::fake();

    instant_remote_process(['echo ok'], $server, timeout: 7200, disableMultiplexing: true);

    Process::assertRan(fn ($process) => str_starts_with($process->command, 'timeout 7200 ssh '));
});

it('uses the configured default timeout in instant_remote_process when no timeout is passed', function () {
    config([
        'constants.ssh.mux_enabled' => false,
        'constants.ssh.command_timeout' => 1234,
    ]);
    $server = makeTimeoutTestServer();

    Process::fake();

    instant_remote_process(['echo ok'], $server, disableMultiplexing: true);

    Process::assertRan(fn ($process) => str_starts_with($process->command, 'timeout 1234 ssh '));
});
