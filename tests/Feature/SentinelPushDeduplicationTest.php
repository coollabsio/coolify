<?php

use App\Http\Controllers\Api\SentinelController;
use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array']);

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

it('skips dispatch decision when sentinel lock acquisition times out', function () use ($running) {
    $lock = Mockery::mock();
    $lock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type('callable'))
        ->andThrow(LockTimeoutException::class);

    Cache::shouldReceive('lock')
        ->once()
        ->with('sentinel:push-lock:'.$this->server->id, 10)
        ->andReturn($lock);

    $controller = new SentinelController;
    $method = new ReflectionMethod($controller, 'shouldDispatchUpdate');
    $method->setAccessible(true);

    expect($method->invoke($controller, $this->server, sentinelPayload($running())))->toBeFalse();
});

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

it('accepts an empty container list as a heartbeat when no containers are running', function () {
    $this->server->update(['sentinel_updated_at' => now()->subHour()]);

    pushSentinel($this->token, sentinelPayload([]))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 1);
    expect(Carbon::parse($this->server->fresh()->sentinel_updated_at)->diffInSeconds(now()))->toBeLessThan(5);
});

it('rejects malformed sentinel payloads before touching server state', function (array $payload) {
    $this->server->update(['sentinel_updated_at' => now()->subHour()]);
    $originalHeartbeat = $this->server->fresh()->sentinel_updated_at;

    pushSentinel($this->token, $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors('containers');

    Queue::assertNotPushed(PushServerUpdateJob::class);
    expect($this->server->fresh()->sentinel_updated_at)->toBe($originalHeartbeat);
    expect(Cache::has('sentinel:push-hash:'.$this->server->id))->toBeFalse();
    expect(Cache::has('sentinel:push-force:'.$this->server->id))->toBeFalse();
})->with([
    'missing containers' => [[]],
    'non-array containers' => [['containers' => 'not-an-array']],
]);

it('guards the dedupe decision with a server scoped atomic cache lock', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/SentinelController.php'));

    expect($controller)
        ->toContain('$lockKey = "sentinel:push-lock:{$server->id}";')
        ->toContain('Cache::lock($lockKey, 10)->block(5, function () use ($hashKey, $forceKey, $hash): bool')
        ->toContain('Cache::put($hashKey, $hash, now()->addDay())')
        ->toContain("Cache::put(\$forceKey, true, config('constants.sentinel.push_force_interval_seconds', 300))");
});

it('dispatches the job when container state changes', function () use ($running) {
    pushSentinel($this->token, sentinelPayload($running()))->assertOk();

    $exited = [['name' => 'app-1', 'state' => 'exited', 'health_status' => 'unhealthy']];
    pushSentinel($this->token, sentinelPayload($exited))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 2);
});

it('ignores health status changes while container lifecycle state is unchanged', function () {
    $healthy = [['name' => 'app-1', 'state' => 'running', 'health_status' => 'healthy']];
    $unhealthy = [['name' => 'app-1', 'state' => 'running', 'health_status' => 'unhealthy']];

    pushSentinel($this->token, sentinelPayload($healthy))->assertOk();
    pushSentinel($this->token, sentinelPayload($unhealthy))->assertOk();

    Queue::assertPushed(PushServerUpdateJob::class, 1);
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
