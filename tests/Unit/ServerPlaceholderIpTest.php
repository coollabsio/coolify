<?php

use App\Jobs\CleanupOrphanedPreviewContainersJob;
use App\Jobs\ScheduledJobManager;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createServerForPlaceholderIpTest(string $ip): Server
{
    $team = Team::create([
        'name' => 'Test Team',
        'personal_team' => false,
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => $ip,
    ]);
}

it('detects placeholder IPs', function () {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    foreach ([Server::PLACEHOLDER_IP, '0.0.0.0', '::'] as $ip) {
        $server->update(['ip' => $ip]);

        expect($server->fresh()->hasPlaceholderIp())->toBeTrue();
    }
});

it('does not treat a real IP as a placeholder', function () {
    $server = createServerForPlaceholderIpTest('203.0.113.5');

    expect($server->hasPlaceholderIp())->toBeFalse();
});

it('backfills a placeholder IP with the real address', function () {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    expect($server->backfillPlaceholderIp('203.0.113.5'))->toBeTrue();
    expect($server->fresh()->ip)->toBe('203.0.113.5');
});

it('does not overwrite a real IP when backfilling', function () {
    $server = createServerForPlaceholderIpTest('203.0.113.5');

    expect($server->backfillPlaceholderIp('198.51.100.9'))->toBeFalse();
    expect($server->fresh()->ip)->toBe('203.0.113.5');
});

it('ignores a missing IP when backfilling', function () {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    expect($server->backfillPlaceholderIp(null))->toBeFalse();
    expect($server->fresh()->ip)->toBe(Server::PLACEHOLDER_IP);
});

it('does not replace a placeholder with another placeholder', function (string $replacementIp) {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    expect($server->backfillPlaceholderIp($replacementIp))->toBeFalse();
    expect($server->fresh()->ip)->toBe(Server::PLACEHOLDER_IP);
})->with([
    'unspecified IPv4 address' => '0.0.0.0',
    'unspecified IPv6 address' => '::',
]);

it('does not overwrite an address configured after the placeholder model was loaded', function () {
    $staleServer = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);
    $concurrentServer = Server::query()->findOrFail($staleServer->id);

    $concurrentServer->update(['ip' => 'server.internal.example.com']);

    expect($staleServer->backfillPlaceholderIp('203.0.113.10'))->toBeFalse()
        ->and($staleServer->fresh()->ip)->toBe('server.internal.example.com');
});

it('skips servers with a placeholder IP in scheduled jobs', function () {
    $server = createServerForPlaceholderIpTest(Server::PLACEHOLDER_IP);

    expect($server->skipServer())->toBeTrue();
});

it('excludes every placeholder address from scheduled Docker cleanup', function () {
    $realServer = createServerForPlaceholderIpTest('203.0.113.10');

    foreach (Server::PLACEHOLDER_IPS as $placeholderIp) {
        Server::factory()->create([
            'team_id' => $realServer->team_id,
            'private_key_id' => $realServer->private_key_id,
            'ip' => $placeholderIp,
        ]);
    }

    $method = new ReflectionMethod(ScheduledJobManager::class, 'getServersForCleanupQuery');
    $servers = $method->invoke(new ScheduledJobManager)->get();

    expect($servers->modelKeys())->toBe([$realServer->id]);
});

it('excludes every placeholder address from orphaned preview cleanup', function () {
    $realServer = createServerForPlaceholderIpTest('203.0.113.10');
    $realServer->settings->update(['is_reachable' => true, 'is_usable' => true]);

    foreach (Server::PLACEHOLDER_IPS as $placeholderIp) {
        $server = Server::factory()->create([
            'team_id' => $realServer->team_id,
            'private_key_id' => $realServer->private_key_id,
            'ip' => $placeholderIp,
        ]);
        $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    }

    $method = new ReflectionMethod(CleanupOrphanedPreviewContainersJob::class, 'getServersToCheck');
    $servers = $method->invoke(new CleanupOrphanedPreviewContainersJob);

    expect($servers->modelKeys())->toBe([$realServer->id]);
});
