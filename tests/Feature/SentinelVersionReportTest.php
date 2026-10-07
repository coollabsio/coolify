<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array']);

    Queue::fake();
    Cache::flush();
    Cache::put('coolify:versions:all', ['coolify' => ['sentinel' => ['version' => '1.0.2']]], 3600);

    $user = User::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $user->teams()->first()->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
});

function pushSentinelWithVersion(Server $server, ?string $version)
{
    $headers = ['Authorization' => 'Bearer '.$server->settings->sentinel_token];
    if ($version !== null) {
        $headers['X-Sentinel-Version'] = $version;
    }

    return test()->postJson('/api/v1/sentinel/push', ['containers' => []], $headers);
}

it('updates an outdated Sentinel once per hour', function () {
    pushSentinelWithVersion($this->server, '1.0.1')->assertOk();
    pushSentinelWithVersion($this->server, '1.0.1')->assertOk();

    Queue::assertPushed(CheckAndStartSentinelJob::class, 1);
    expect(Cache::get(Server::sentinelReportedVersionCacheKey($this->server->id)))->toBe('1.0.1');
});

it('does not update a current Sentinel', function (string $version) {
    pushSentinelWithVersion($this->server, $version)->assertOk();

    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
    expect(Cache::has(Server::sentinelReportedVersionCacheKey($this->server->id)))->toBeTrue();
})->with(['1.0.2', '1.1.0']);

it('keeps the hourly SSH version check for Sentinel without a valid version header', function (?string $version) {
    pushSentinelWithVersion($this->server, $version)->assertOk();

    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
    expect(Cache::has(Server::sentinelReportedVersionCacheKey($this->server->id)))->toBeFalse();
})->with(['no header' => null, 'not a version' => 'next']);

it('queues only one Sentinel check per server while one is waiting', function () {
    CheckAndStartSentinelJob::dispatch($this->server);
    CheckAndStartSentinelJob::dispatch($this->server);

    Queue::assertPushed(CheckAndStartSentinelJob::class, 1);
});
