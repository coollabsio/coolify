<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const LEARNED_HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKgKlP9E2JBLjQ8DDxZUaI5vOJlvo8Uv06UAyqDbyC2I';
const OTHER_HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHjaL3Lqt0tZl4iVZw8x1v2b9fM8r6Yq0nH0bUq3dQ0h';

function makeHostKeyTestServer(): Server
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
        'name' => 'host-key-test-key-'.uniqid(),
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

/**
 * Fake the host key scan: write the key to the known_hosts file of the scan, like ssh does.
 */
function fakeHostKeyScan(?string $hostKey, string $errorOutput = 'Permission denied (publickey).'): void
{
    Process::fake(function (PendingProcess $process) use ($hostKey, $errorOutput) {
        if ($hostKey !== null && preg_match("/UserKnownHostsFile='([^']+)'/", $process->command, $matches)) {
            file_put_contents($matches[1], "coolify-alias {$hostKey}\n");
        }

        return Process::result(errorOutput: $errorOutput, exitCode: 255);
    });
}

function knownHostsFileOf(string $sshCommand): string
{
    preg_match("/UserKnownHostsFile='([^']+)'/", $sshCommand, $matches);

    return $matches[1];
}

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.host_key_checking' => 'warn']);
    Cache::flush();
});

it('scans the host key with the connection timeout of the server, capped at 30 seconds', function (int $connectionTimeout, int $scanTimeout) {
    fakeHostKeyScan(LEARNED_HOST_KEY);
    $server = makeHostKeyTestServer();
    $server->settings->forceFill(['connection_timeout' => $connectionTimeout])->save();

    SshMultiplexingHelper::generateSshCommand($server, 'ls');

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'PubkeyAuthentication=no')
        && str_contains($process->command, "-o ConnectTimeout={$scanTimeout} ")
        && $process->timeout === $scanTimeout + 5);
    expect($server->settings->fresh()->ssh_host_key)->toBe(LEARNED_HOST_KEY);
})->with([
    'slow link' => [20, 20],
    'long timeout' => [120, 30],
]);

it('does not wait for another process that reads the host key in warn mode', function () {
    fakeHostKeyScan(LEARNED_HOST_KEY);
    $server = makeHostKeyTestServer();
    $lock = Cache::lock('ssh_host_key_learn_'.$server->uuid, 60);
    $lock->get();

    $startedAt = microtime(true);
    $command = SshMultiplexingHelper::generateSshCommand($server, 'ls');

    expect(microtime(true) - $startedAt)->toBeLessThan(2)
        ->and($command)->toContain('StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null');
    Process::assertNothingRan();
    $lock->release();
});

it('keeps unsaved settings changes of the caller when it learns the host key', function () {
    fakeHostKeyScan(LEARNED_HOST_KEY);
    $server = makeHostKeyTestServer();
    $server->settings->is_swarm_manager = true;

    SshMultiplexingHelper::generateSshCommand($server, 'ls');

    expect($server->settings->is_swarm_manager)->toBeTruthy()
        ->and($server->settings->ssh_host_key)->toBe(LEARNED_HOST_KEY)
        ->and($server->settings->fresh())
        ->is_swarm_manager->toBeFalsy()
        ->ssh_host_key->toBe(LEARNED_HOST_KEY);
});

it('does not let a process with an old key overwrite the known_hosts file of a newer key', function () {
    fakeHostKeyScan(null);
    $server = makeHostKeyTestServer();
    $server->settings->forceFill(['ssh_host_key' => LEARNED_HOST_KEY])->save();
    // A worker that loaded the server before an admin trusted the current key.
    $staleServer = Server::query()->findOrFail($server->id);
    $staleServer->settings->ssh_host_key = OTHER_HOST_KEY;

    $currentFile = knownHostsFileOf(SshMultiplexingHelper::generateSshCommand($server, 'ls'));
    SshMultiplexingHelper::generateSshCommand($staleServer, 'ls');

    expect(file_get_contents($currentFile))->toBe("coolify-{$server->uuid} ".LEARNED_HOST_KEY."\n");
});

it('shows why the host key scan failed in enforce mode', function () {
    config(['constants.ssh.host_key_checking' => 'enforce']);
    fakeHostKeyScan(null, 'ssh: connect to host 10.0.0.1 port 22: Connection refused');
    $server = makeHostKeyTestServer();

    $first = fn () => SshMultiplexingHelper::generateSshCommand($server, 'ls');

    expect($first)->toThrow(RuntimeException::class, 'Connection refused')
        // The failed scan is cached; the next command shows the same cause without a new scan.
        ->and($first)->toThrow(RuntimeException::class, 'Connection refused');
    Process::assertRanTimes(fn (PendingProcess $process) => str_contains($process->command, 'PubkeyAuthentication=no'), 1);
});
