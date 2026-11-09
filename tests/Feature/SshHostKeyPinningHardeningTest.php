<?php

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\ServerConnectionCheckJob;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Notifications\Server\HostKeyChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

const HARDENING_PINNED_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKgKlP9E2JBLjQ8DDxZUaI5vOJlvo8Uv06UAyqDbyC2I';
const HARDENING_KEY_B = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHjaL3Lqt0tZl4iVZw8x1v2b9fM8r6Yq0nH0bUq3dQ0h';
const HARDENING_KEY_C = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGq7Wb1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6r7s8t9u';

function makeHardeningServer(?string $pinnedKey = HARDENING_PINNED_KEY): Server
{
    $team = Team::factory()->create();
    $team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'server_unreachable_email_notifications' => true,
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'ip' => '10.0.0.1']);
    $server->settings->forceFill(['ssh_host_key' => $pinnedKey, 'is_reachable' => true, 'is_usable' => true])->save();

    return $server;
}

/**
 * Fake ssh: the host key scan writes $scanKey to its known_hosts file; every other command returns the probe result.
 */
function fakeHardeningSsh(?string $scanKey, string $probeError = '', int $probeExit = 0): void
{
    Process::fake(function (PendingProcess $process) use ($scanKey, $probeError, $probeExit) {
        if (str_contains($process->command, 'PubkeyAuthentication=no')) {
            if ($scanKey !== null && preg_match("/UserKnownHostsFile='([^']+)'/", $process->command, $matches)) {
                file_put_contents($matches[1], "alias {$scanKey}\n");
            }

            return Process::result(errorOutput: 'Permission denied (publickey).', exitCode: 255);
        }

        return Process::result(output: '{"Server":{"Version":"27.0.0"}}', errorOutput: $probeError, exitCode: $probeExit);
    });
}

function hardeningScanCount(): int
{
    $count = 0;
    Process::assertRan(function (PendingProcess $process) use (&$count) {
        $count += str_contains($process->command, 'PubkeyAuthentication=no') ? 1 : 0;

        return true;
    });

    return $count;
}

/**
 * The stderr of ssh when the server sends another key than the pinned one, as OpenSSH prints it.
 */
function hardeningMismatchError(Server $server, string $sentKey, bool $strict): string
{
    $fingerprint = explode(' ', ServerSetting::sshHostKeyFingerprints($sentKey)[0])[0];

    return "@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @\n"
        ."The fingerprint for the ED25519 key sent by the remote host is\n{$fingerprint}.\n"
        ."  ssh-keygen -f '/var/www/html/storage/app/ssh/known_hosts/known_hosts_{$server->uuid}_0' -R 'coolify-{$server->uuid}'\n"
        .($strict ? "Host key for coolify-{$server->uuid} has changed and you have requested strict checking.\nHost key verification failed.\n" : '');
}

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.host_key_checking' => 'warn']);
    Storage::fake('ssh-keys');
    Cache::flush();
    Sleep::fake();
    Server::flushIdentityMap();
});

it('does not scan the host key while it builds a command for a server that is unreachable', function () {
    fakeHardeningSsh(HARDENING_KEY_B);
    $server = makeHardeningServer(null);
    $server->forceFill(['unreachable_count' => 1])->save();

    SshMultiplexingHelper::generateSshCommand($server, 'ls');

    Process::assertNothingRan();
});

it('does not scan again for ten minutes after a failed scan', function () {
    fakeHardeningSsh(null);
    $server = makeHardeningServer(null);

    SshMultiplexingHelper::generateSshCommand($server, 'ls');
    $this->travel(9)->minutes();
    SshMultiplexingHelper::generateSshCommand($server, 'ls');

    expect(hardeningScanCount())->toBe(1);
});

it('learns the host key in the connection check after a successful connection', function () {
    fakeHardeningSsh(HARDENING_KEY_B);
    $server = makeHardeningServer(null);
    $server->forceFill(['unreachable_count' => 1])->save();

    (new ServerConnectionCheckJob($server->fresh()))->handle();

    expect($server->settings->fresh()->ssh_host_key)->toBe(HARDENING_KEY_B);
});

it('learns the host key in the connection check of an unreachable server in enforce mode', function () {
    config(['constants.ssh.host_key_checking' => 'enforce']);
    fakeHardeningSsh(HARDENING_KEY_B);
    $server = makeHardeningServer(null);
    $server->forceFill(['unreachable_count' => 1])->save();

    (new ServerConnectionCheckJob($server->fresh()))->handle();

    expect($server->settings->fresh())
        ->ssh_host_key->toBe(HARDENING_KEY_B)
        ->is_reachable->toBeTrue();
});

it('scans again in the connection check only after the retry time of a failed scan', function (string $mode, int $retrySeconds) {
    config(['constants.ssh.host_key_checking' => $mode]);
    fakeHardeningSsh(null);
    $server = makeHardeningServer(null);
    $server->forceFill(['unreachable_count' => 1])->save();

    (new ServerConnectionCheckJob($server->fresh()))->handle();
    fakeHardeningSsh(HARDENING_KEY_B);
    $this->travel($retrySeconds - 10)->seconds();
    (new ServerConnectionCheckJob($server->fresh()))->handle();

    expect(hardeningScanCount())->toBe(1)
        ->and($server->settings->fresh()->ssh_host_key)->toBeNull();

    $this->travel(20)->seconds();
    (new ServerConnectionCheckJob($server->fresh()))->handle();

    expect(hardeningScanCount())->toBe(2)
        ->and($server->settings->fresh())
        ->ssh_host_key->toBe(HARDENING_KEY_B)
        ->is_reachable->toBeTrue();
})->with([
    'warn' => ['warn', 600],
    'enforce' => ['enforce', 60],
]);

it('reports a normal connection error when the host key check fails but the server sends the pinned key', function () {
    config(['constants.ssh.host_key_checking' => 'enforce']);
    fakeHardeningSsh(HARDENING_PINNED_KEY, "No ED25519 host key is known for coolify-x and you have requested strict checking.\nHost key verification failed.\n", 255);
    $server = makeHardeningServer();

    (new ServerConnectionCheckJob($server->fresh()))->handle();

    $server->refresh();
    expect($server->unreachable_count)->toBe(1)
        ->and($server->settings->fresh())
        ->is_usable->toBeTrue()
        ->ssh_host_key_pending->toBeNull();
});

it('notifies once when a load balancer alternates between host keys', function () {
    Notification::fake();
    $server = makeHardeningServer();

    foreach ([HARDENING_KEY_B, HARDENING_KEY_C, HARDENING_KEY_B, HARDENING_KEY_C] as $key) {
        fakeHardeningSsh($key);
        expect($server->recordHostKeyMismatch())->toBeTrue();
        $this->travel(2)->hours();
    }

    Notification::assertSentToTimes($server->team, HostKeyChanged::class, 2);
    expect($server->settings->fresh()->ssh_host_key_pending)->toBe(HARDENING_KEY_C);
});

it('does not scan the host key again when ssh shows the fingerprint of the pending key', function () {
    $server = makeHardeningServer();
    $server->settings->forceFill(['ssh_host_key_pending' => HARDENING_KEY_B])->save();
    fakeHardeningSsh(HARDENING_KEY_B, hardeningMismatchError($server, HARDENING_KEY_B, strict: false));

    (new ServerConnectionCheckJob($server->fresh()))->handle();

    expect(hardeningScanCount())->toBe(0);
});

it('scans the host key at most every ten minutes while a changed key is pending', function () {
    $server = makeHardeningServer();
    $server->settings->forceFill(['ssh_host_key_pending' => HARDENING_KEY_B])->save();
    fakeHardeningSsh(HARDENING_KEY_B);

    expect($server->recordHostKeyMismatch("Host key verification failed.\n"))->toBeTrue();
    $this->travel(5)->minutes();
    expect($server->recordHostKeyMismatch("Host key verification failed.\n"))->toBeTrue();
    expect(hardeningScanCount())->toBe(1);

    $this->travel(6)->minutes();
    $server->recordHostKeyMismatch("Host key verification failed.\n");
    expect(hardeningScanCount())->toBe(2);
});

it('forgets the pinned host key when a placeholder IP gets the real address', function () {
    $server = makeHardeningServer();
    Server::query()->whereKey($server->id)->update(['ip' => '1.2.3.4']);
    $server->refresh();

    expect($server->backfillPlaceholderIp('203.0.113.5'))->toBeTrue()
        ->and($server->settings->fresh()->ssh_host_key)->toBeNull();
});

it('quotes the host key alias in the ssh command', function () {
    fakeHardeningSsh(null);
    $server = makeHardeningServer();

    expect(SshMultiplexingHelper::generateSshCommand($server, 'ls'))->toContain("-o HostKeyAlias='coolify-{$server->uuid}' ");
});

it('uses the saved host key settings in a job that loaded the server before they changed', function () {
    fakeHardeningSsh(null);
    $server = makeHardeningServer();
    expect(SshMultiplexingHelper::generateSshCommand($server, 'ls'))->toContain('-o StrictHostKeyChecking=no ');

    ServerSetting::query()->whereKey($server->settings->id)->update(['is_ssh_host_key_check_enforced' => true, 'ssh_host_key' => HARDENING_KEY_B]);

    expect(SshMultiplexingHelper::generateSshCommand($server, 'ls'))->toContain('-o StrictHostKeyChecking=yes ')
        ->and($server->settings->ssh_host_key)->toBe(HARDENING_KEY_B)
        ->and($server->settings->isDirty())->toBeFalse();
});

it('deletes the known_hosts file of an old host key', function () {
    fakeHardeningSsh(null);
    $server = makeHardeningServer();
    preg_match("/UserKnownHostsFile='([^']+)'/", SshMultiplexingHelper::generateSshCommand($server, 'ls'), $old);

    $server->settings->forceFill(['ssh_host_key' => HARDENING_KEY_B])->save();
    preg_match("/UserKnownHostsFile='([^']+)'/", SshMultiplexingHelper::generateSshCommand($server->fresh(), 'ls'), $new);

    expect(file_exists($old[1]))->toBeFalse()
        ->and(file_exists($new[1]))->toBeTrue();

    $server->forceDelete();

    expect(file_exists($new[1]))->toBeFalse();
});

it('does not check the connection when the ssh of another program reports a host key failure', function () {
    Queue::fake([ServerConnectionCheckJob::class]);
    fakeHardeningSsh(null, "Host key verification failed.\nfatal: Could not read from remote repository.\n", 128);
    $server = makeHardeningServer();

    expect(fn () => instant_remote_process(['git clone git@github.com:a/b'], $server))->toThrow(RuntimeException::class);

    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});
