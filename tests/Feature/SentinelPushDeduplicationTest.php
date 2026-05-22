<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Cache::flush();

    $user = User::factory()->create();
    $this->team = $user->teams()->first();

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $this->token = $this->server->settings->sentinel_token;
});

function pushSentinel(string $token, array $payload)
{
    return test()->postJson('/api/v1/sentinel/push', $payload, [
        'Authorization' => 'Bearer '.$token,
    ]);
}

function sentinelPayload(array $containers, ?float $diskPercentage = 42.0): array
{
    return [
        'containers' => $containers,
        'filesystem_usage_root' => ['used_percentage' => $diskPercentage],
    ];
}

$running = fn () => [['name' => 'app-1', 'state' => 'running', 'health_status' => 'healthy']];

it('dispatches the job on the first push', function () use ($running) {
    pushSentinel($this->token, sentinelPayload($running()))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 1);
});

it('skips the job when the second push is identical', function () use ($running) {
    pushSentinel($this->token, sentinelPayload($running()))->assertOk();
    pushSentinel($this->token, sentinelPayload($running()))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 1);
});

it('updates the heartbeat even when the job is skipped', function () use ($running) {
    pushSentinel($this->token, sentinelPayload($running()))->assertOk();

    $this->server->update(['sentinel_updated_at' => now()->subHour()]);

    pushSentinel($this->token, sentinelPayload($running()))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 1);
    expect(Carbon::parse($this->server->fresh()->sentinel_updated_at)->diffInSeconds(now()))->toBeLessThan(5);
});

it('dispatches the job when container state changes', function () use ($running) {
    pushSentinel($this->token, sentinelPayload($running()))->assertOk();

    $exited = [['name' => 'app-1', 'state' => 'exited', 'health_status' => 'unhealthy']];
    pushSentinel($this->token, sentinelPayload($exited))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 2);
});

it('ignores disk percentage changes (excluded from the hash)', function () use ($running) {
    pushSentinel($this->token, sentinelPayload($running(), diskPercentage: 42.0))->assertOk();
    pushSentinel($this->token, sentinelPayload($running(), diskPercentage: 88.0))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 1);
});

it('ignores container reordering (hash is sorted by name)', function () {
    $order1 = [
        ['name' => 'app-a', 'state' => 'running', 'health_status' => 'healthy'],
        ['name' => 'app-b', 'state' => 'running', 'health_status' => 'healthy'],
    ];
    $order2 = [
        ['name' => 'app-b', 'state' => 'running', 'health_status' => 'healthy'],
        ['name' => 'app-a', 'state' => 'running', 'health_status' => 'healthy'],
    ];

    pushSentinel($this->token, sentinelPayload($order1))->assertOk();
    pushSentinel($this->token, sentinelPayload($order2))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 1);
});

it('force-dispatches an identical push after the force window expires', function () use ($running) {
    pushSentinel($this->token, sentinelPayload($running()))->assertOk();

    // Simulate the force key TTL elapsing.
    Cache::forget('sentinel:push-force:'.$this->server->id);

    pushSentinel($this->token, sentinelPayload($running()))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 2);
});

it('rejects an invalid token without dispatching', function () use ($running) {
    pushSentinel('not-a-real-token', sentinelPayload($running()))->assertUnauthorized();

    Queue::assertNotPushed(PushServerUpdateJob::class);
});
