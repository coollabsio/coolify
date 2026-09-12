<?php

use App\Jobs\RefreshConnectedNodesJob;
use App\Jobs\RefreshNodeContainersJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->node = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $key->id,
        'is_usable' => true,
    ]);
});

it('queues inventory only for usable Nodes with a recent Flux heartbeat without the development gate', function () {
    config()->set('constants.sentinel.host_enabled', false);
    $stale = Node::factory()->create(['is_usable' => true, 'private_key_id' => $this->node->private_key_id]);
    $unusable = Node::factory()->create(['is_usable' => false, 'private_key_id' => $this->node->private_key_id]);
    Cache::put($this->node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->subSeconds(30)->toIso8601String()]);
    Cache::put($stale->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->subMinutes(3)->toIso8601String()]);
    Cache::put($unusable->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String()]);
    Queue::fake();

    (new RefreshConnectedNodesJob)->handle();

    Queue::assertPushed(RefreshNodeContainersJob::class, 1);
    Queue::assertPushed(RefreshNodeContainersJob::class, fn ($job) => $job->nodeId === $this->node->id && $job->delay !== null);
});

it('refreshes the complete container inventory for one Node', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'secret');
    Http::fake(['*/v1/commands/container.list' => Http::response([
        'command_id' => 'inventory-1',
        'observed_at_unix_ms' => 1_700_000_000_000,
        'containers' => [],
    ])]);
    Cache::put($this->node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String()]);

    (new RefreshNodeContainersJob($this->node->id))->handle();

    Http::assertSentCount(1);
});

it('schedules connected Node inventory every minute on one scheduler', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => str_contains((string) $event->description, RefreshConnectedNodesJob::class),
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue();
});
