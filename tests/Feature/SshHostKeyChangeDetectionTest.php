<?php

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\ServerConnectionCheckJob;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const DETECTION_PINNED_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKgKlP9E2JBLjQ8DDxZUaI5vOJlvo8Uv06UAyqDbyC2I';
const DETECTION_PENDING_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHjaL3Lqt0tZl4iVZw8x1v2b9fM8r6Yq0nH0bUq3dQ0h';
const HOST_KEY_CHANGED_BANNER = "@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@\n@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @\n@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@";

/**
 * The warning of ssh names the host key alias of the server, as OpenSSH prints it.
 */
function hostKeyChangedBannerFor(Server $server): string
{
    return HOST_KEY_CHANGED_BANNER."\n  ssh-keygen -f '/var/www/html/storage/app/ssh/known_hosts/known_hosts_{$server->uuid}_0' -R 'coolify-{$server->uuid}'\n";
}

function makeDetectionTestServer(): Server
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
        'name' => 'detection-test-key-'.uniqid(),
        'private_key' => $privateKeyContent,
        'team_id' => $team->id,
    ]);

    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKeyContent);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '10.0.0.1',
    ]);
    $server->settings->forceFill([
        'ssh_host_key' => DETECTION_PINNED_KEY,
        'ssh_host_key_source' => 'tofu',
        'ssh_host_key_trusted_at' => now(),
        'ssh_host_key_pending' => DETECTION_PENDING_KEY,
    ])->save();

    return $server;
}

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.host_key_checking' => 'warn']);
    Cache::flush();
    Queue::fake();
});

it('checks the connection once when a command in warn mode reports a changed host key', function () {
    $server = makeDetectionTestServer();
    Process::fake(['*' => Process::result(output: 'ok', errorOutput: hostKeyChangedBannerFor($server))]);

    expect(instant_remote_process(['ls'], $server))->toBe('ok')
        ->and(instant_remote_process(['ls'], $server))->toBe('ok');

    Queue::assertPushed(ServerConnectionCheckJob::class, 1);
});

it('checks the connection when enforce mode refuses a changed host key', function () {
    config(['constants.ssh.host_key_checking' => 'enforce']);
    $server = makeDetectionTestServer();
    Process::fake(['*' => Process::result(errorOutput: hostKeyChangedBannerFor($server)."Host key for coolify-{$server->uuid} has changed and you have requested strict checking.\nHost key verification failed.\n", exitCode: 255)]);

    expect(fn () => instant_remote_process(['ls'], $server))->toThrow(RuntimeException::class);

    Queue::assertPushed(ServerConnectionCheckJob::class, fn (ServerConnectionCheckJob $job) => $job->server->is($server));
});

it('checks the connection when the multiplexed master connection reports a changed host key', function () {
    config(['constants.ssh.mux_enabled' => true]);
    $server = makeDetectionTestServer();
    Process::fake(['*' => Process::result(errorOutput: hostKeyChangedBannerFor($server))]);

    SshMultiplexingHelper::establishNewMultiplexedConnection($server);

    Queue::assertPushed(ServerConnectionCheckJob::class, 1);
});

it('does not check the connection for a normal command', function () {
    Process::fake(['*' => Process::result(output: 'ok')]);
    $server = makeDetectionTestServer();

    instant_remote_process(['ls'], $server);

    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});

it('forgets the host key when the address of the server changes', function (array $change) {
    Process::fake();
    $server = makeDetectionTestServer();

    $server->update($change);

    expect($server->settings->fresh())
        ->ssh_host_key->toBeNull()
        ->ssh_host_key_source->toBeNull()
        ->ssh_host_key_trusted_at->toBeNull()
        ->ssh_host_key_pending->toBeNull();
})->with([
    'ip' => [['ip' => '10.0.0.2']],
    'port' => [['port' => 2222]],
]);

it('forgets the host key when the Cloudflare tunnel setting changes', function () {
    Process::fake();
    $server = makeDetectionTestServer();

    $server->settings->update(['is_cloudflare_tunnel' => true]);

    expect($server->settings->fresh()->ssh_host_key)->toBeNull();
});

it('keeps the host key when another server field changes', function () {
    Process::fake();
    $server = makeDetectionTestServer();

    $server->update(['name' => 'renamed', 'user' => 'deploy']);

    expect($server->settings->fresh())
        ->ssh_host_key->toBe(DETECTION_PINNED_KEY)
        ->ssh_host_key_pending->toBe(DETECTION_PENDING_KEY);
});
