<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
