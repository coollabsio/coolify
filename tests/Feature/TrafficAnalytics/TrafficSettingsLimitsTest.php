<?php

use App\Livewire\Server\TrafficAnalyticsSettings;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Unbounded values would let one setting grow Sentinel's storage without limit.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
    ]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_traffic_analytics_enabled' => false]);
});

dataset('traffic setting limits', [
    'top-n' => ['trafficTopn', 'traffic_topn', 1000],
    'hourly retention' => ['trafficRetention1hDays', 'traffic_retention_1h_days', 365],
    'daily retention' => ['trafficRetention1dDays', 'traffic_retention_1d_days', 3650],
]);

it('rejects traffic settings above the limit in the UI', function (string $property, string $column, int $max) {
    $this->actingAs($this->user);
    $before = $this->server->settings->fresh()->{$column};

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $this->server])
        ->set($property, $max + 1)
        ->call('saveTrafficAnalyticsSettings')
        ->assertHasErrors($property);

    expect($this->server->settings->fresh()->{$column})->toBe($before);

    Livewire::test(TrafficAnalyticsSettings::class, ['server' => $this->server])
        ->set($property, $max)
        ->call('saveTrafficAnalyticsSettings')
        ->assertHasNoErrors();

    expect($this->server->settings->fresh()->{$column})->toBe($max);
})->with('traffic setting limits');

it('rejects traffic settings above the limit in the API', function (string $property, string $column, int $max) {
    $token = $this->user->createToken('traffic-limits', ['*'])->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    $before = $this->server->settings->fresh()->{$column};

    $this->withHeaders($headers)
        ->patchJson("/api/v1/servers/{$this->server->uuid}/sentinel", [$column => $max + 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($column);

    expect($this->server->settings->fresh()->{$column})->toBe($before);

    $this->withHeaders($headers)
        ->patchJson("/api/v1/servers/{$this->server->uuid}/sentinel", [$column => $max])
        ->assertOk()
        ->assertJsonPath($column, $max);
})->with('traffic setting limits');
