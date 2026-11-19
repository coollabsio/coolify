<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], [
        'webhook_allowed_internal_hosts' => ['localhost'],
        'webhook_allow_localhost' => true,
    ]));
});

afterEach(function () {
    Server::flushIdentityMap();
});

/**
 * @return array{0: Server, 1: S3Storage}
 */
function minioResolveTestSetup(string $endpoint, string $sshUser = 'root'): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => $sshUser,
        'ip' => '203.0.113.10',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $storage = S3Storage::create([
        'name' => 'Destination',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => $endpoint,
        'team_id' => $team->id,
        'is_usable' => true,
    ]);

    return [$server, $storage];
}

it('pins the IPv6 address of a dual-stack S3 host when only IPv6 connects from the upload network', function () {
    [$server, $storage] = minioResolveTestSetup('http://localhost:9000');
    fakeRemoteProcesses(['*/dev/tcp/*' => '::1', '*' => '']);

    $options = $storage->minioClientResolveOptions($server, 'ghcr.io/coollabsio/coolify-helper:1.0.0', 'coolify');

    expect($options)->toBe(" --resolve 'localhost:9000=::1'");
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "docker run --rm --network 'coolify' 'ghcr.io/coollabsio/coolify-helper:1.0.0' bash -c")
        && str_contains(remoteProcessScript($process), "for ip in '\\''127.0.0.1'\\'' '\\''::1'\\''; do")
        && str_contains(remoteProcessScript($process), '/dev/tcp/$ip/9000'));
});

it('pins the IPv4 address when it connects or when no address connects', function (string $probeOutput) {
    [$server, $storage] = minioResolveTestSetup('http://localhost:9000');
    fakeRemoteProcesses(['*/dev/tcp/*' => $probeOutput, '*' => '']);

    expect($storage->minioClientResolveOptions($server, 'helper:1.0.0'))->toBe(" --resolve 'localhost:9000=127.0.0.1'");
})->with([
    'IPv4 connects' => '127.0.0.1',
    'no address connects' => '',
    'unexpected output' => '10.0.0.1',
]);

it('runs the probe with sudo on servers with a non-root SSH user', function () {
    [$server, $storage] = minioResolveTestSetup('http://localhost:9000', 'deploy');
    fakeRemoteProcesses(['*/dev/tcp/*' => '::1', '*' => '']);

    expect($storage->minioClientResolveOptions($server, 'helper:1.0.0'))->toBe(" --resolve 'localhost:9000=::1'");
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "sudo docker run --rm 'helper:1.0.0' bash -c")
        && str_contains(remoteProcessScript($process), 'if timeout 5 bash -c "exec 3<>/dev/tcp/$ip/9000" 2>/dev/null; then echo "$ip"; exit 0; fi; done'));
});

it('does not pin or probe an S3 endpoint that is an IP address', function () {
    [$server, $storage] = minioResolveTestSetup('https://8.8.8.8');
    Process::fake();

    expect($storage->minioClientResolveOptions($server, 'helper:1.0.0'))->toBe('');
    Process::assertNothingRan();
});
