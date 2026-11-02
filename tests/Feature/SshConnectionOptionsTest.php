<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeSshOptionsTestServer(array $attributes = []): Server
{
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $privateKeyContent = "-----BEGIN OPENSSH PRIVATE KEY-----\n"
        ."b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n"
        ."QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk\n"
        ."hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA\n"
        ."AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV\n"
        ."uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==\n"
        .'-----END OPENSSH PRIVATE KEY-----';

    $privateKey = PrivateKey::create([
        'name' => 'ssh-options-test-key-'.uniqid(),
        'private_key' => $privateKeyContent,
        'team_id' => $team->id,
    ]);

    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKeyContent);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'root',
        'port' => 22,
        ...$attributes,
    ]);
}

function resolveSshCommand(string $command): string
{
    return (string) shell_exec(preg_replace('/\bssh /', 'ssh -G ', $command, 1).' 2>&1');
}

/**
 * Every option must come before `--`: ssh and scp read anything after it as the destination,
 * the remote command, or a path.
 */
function expectFixedOptionsBefore(string $command, string $afterEndOfOptions): void
{
    $endOfOptions = strpos($command, '-- '.$afterEndOfOptions);
    expect($endOfOptions)->not->toBeFalse("Missing `-- {$afterEndOfOptions}` in: {$command}");

    foreach ([
        '-F /dev/null',
        '-o IdentitiesOnly=yes',
        '-o PasswordAuthentication=no',
        '-o ClearAllForwardings=yes',
        '-o ForwardAgent=no',
    ] as $option) {
        $position = strpos($command, $option);
        expect($position)->not->toBeFalse("Missing `{$option}` in: {$command}");
        expect($position)->toBeLessThan($endOfOptions);
    }

    expect(substr_count($command, ' -- '))->toBe(1);
}

/**
 * Servers with `AuthenticationMethods publickey,keyboard-interactive` and a second factor
 * that does not prompt (for example Duo autopush) need keyboard-interactive after the key.
 */
it('keeps keyboard-interactive authentication available after the public key', function () {
    if (trim((string) shell_exec('command -v ssh')) === '') {
        $this->markTestSkipped('ssh client is not installed.');
    }
    config(['constants.ssh.mux_enabled' => false]);

    $command = SshMultiplexingHelper::generateSshStdinCommand(makeSshOptionsTestServer(), 'true');
    $resolved = resolveSshCommand($command);

    expect($resolved)
        ->toContain("identitiesonly yes\n")
        ->toContain("batchmode no\n")
        ->not->toMatch('/^preferredauthentications (?!.*keyboard-interactive)/m');
});

it('ends option parsing directly before the destination in every ssh and scp command', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $server = makeSshOptionsTestServer(['ip' => '10.0.0.5']);

    expectFixedOptionsBefore(SshMultiplexingHelper::generateSshCommand($server, 'echo ok'), "'root'@'10.0.0.5' 'sh -c");
    expectFixedOptionsBefore(SshMultiplexingHelper::generateSshStdinCommand($server, 'cat'), "'root'@'10.0.0.5' 'cat'");
    expectFixedOptionsBefore(SshMultiplexingHelper::generateScpCommand($server, '/tmp/a', '/tmp/b'), "'/tmp/a' 'root'@'10.0.0.5':'/tmp/b'");
    expectFixedOptionsBefore(SshMultiplexingHelper::generateScpDownloadCommand($server, '/tmp/b', '/tmp/a'), "'root'@'10.0.0.5':'/tmp/b' '/tmp/a'");

    $ipv6Server = makeSshOptionsTestServer(['ip' => '2001:db8::1']);
    expectFixedOptionsBefore(SshMultiplexingHelper::generateScpCommand($ipv6Server, '/tmp/a', '/tmp/b'), "'/tmp/a' 'root'@['2001:db8::1']:'/tmp/b'");
    expectFixedOptionsBefore(SshMultiplexingHelper::generateScpDownloadCommand($ipv6Server, '/tmp/b', '/tmp/a'), "'root'@['2001:db8::1']:'/tmp/b' '/tmp/a'");
});

it('ends option parsing before the destination in mux control commands', function () {
    $server = makeSshOptionsTestServer(['ip' => '10.0.0.5']);
    $muxControlCommand = new ReflectionMethod(SshMultiplexingHelper::class, 'muxControlCommand');

    expect($muxControlCommand->invoke(null, $server, 'check'))->toEndWith(" -- 'root'@'10.0.0.5'");
});

/**
 * The health check uses ControlMaster=auto. If the master stops before it runs, ssh opens a
 * direct connection, which must use the server's key and port, not the container's defaults.
 */
it('connects with the server key, port and fixed options when the health check falls back to a direct connection', function () {
    if (trim((string) shell_exec('command -v ssh')) === '') {
        $this->markTestSkipped('ssh client is not installed.');
    }
    Process::fake();
    $server = makeSshOptionsTestServer(['ip' => '10.0.0.5', 'port' => 2222]);

    SshMultiplexingHelper::isConnectionHealthy($server);

    $healthCommand = null;
    Process::assertRan(function ($process) use (&$healthCommand) {
        $healthCommand = $process->command;

        return true;
    });
    expectFixedOptionsBefore($healthCommand, "'root'@'10.0.0.5' 'echo");
    expect(resolveSshCommand($healthCommand))
        ->toContain("port 2222\n")
        ->toContain('identityfile '.$server->privateKey->getKeyLocation()."\n")
        ->toContain("identitiesonly yes\n");
});

it('ignores ssh config files and ends option parsing in the host key scan', function () {
    Process::fake();
    $server = makeSshOptionsTestServer(['ip' => '10.0.0.5']);

    SshMultiplexingHelper::scanHostKey($server);

    $scanCommand = null;
    Process::assertRan(function ($process) use (&$scanCommand) {
        $scanCommand = $process->command;

        return true;
    });
    expect($scanCommand)
        ->toContain('-F /dev/null ')
        ->toEndWith(" -- 'root'@'10.0.0.5' true");
    expect(strpos($scanCommand, '-F /dev/null'))->toBeLessThan(strpos($scanCommand, ' -- '));
});
