<?php

use App\Livewire\Analytics;
use App\Livewire\Dashboard\TrafficAnalytics;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class AuthorizationTrafficClient extends SentinelTrafficClient
{
    public int $reads = 0;

    protected function raw(string $url): string
    {
        $this->reads++;

        return str_contains($url, '/traffic/overview')
            ? json_encode(['requests' => 123, 'unique_visitors' => 12])
            : '[]';
    }
}

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $key->id]);
    $this->server->settings()->update(['is_traffic_analytics_enabled' => true]);
    $this->client = new AuthorizationTrafficClient($this->server);
    app()->bind(SentinelTrafficClient::class, fn () => $this->client);
});

test('removed members cannot refresh mounted traffic analytics', function (string $componentClass) {
    $component = loadLazy(Livewire::test($componentClass))->assertSet('overview.requests', 123);
    $reads = $this->client->reads;

    $this->team->members()->detach($this->user->id);
    $this->actingAs($this->user->fresh());

    $component->call('loadData')->assertForbidden();
    expect($this->client->reads)->toBe($reads);
})->with([[Analytics::class], [TrafficAnalytics::class]]);

test('members can refresh mounted traffic after switching to their own team', function (string $componentClass) {
    $component = loadLazy(Livewire::test($componentClass))->assertSet('overview.requests', 123);
    $personalTeam = Team::factory()->create();
    $personalTeam->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $personalTeam]);

    $component->call('loadData')->assertOk()->assertSet('overview.requests', 123);
})->with([[Analytics::class], [TrafficAnalytics::class]]);

test('traffic analytics denies another teams mounted servers and clears previous traffic', function (string $componentClass) {
    $component = loadLazy(Livewire::test($componentClass))->assertSet('overview.requests', 123)->instance();
    $otherTeam = Team::factory()->create();
    $foreignServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $component->servers = collect([$this->server, $foreignServer]);
    $reads = $this->client->reads;

    expect(fn () => $component->loadData())->toThrow(AuthorizationException::class);
    expect($component->overview)->toBeNull()
        ->and($component->series)->toBe([])
        ->and($this->client->reads)->toBe($reads);

    if ($component instanceof Analytics) {
        expect($component->topApps)->toBe([])
            ->and($component->topHosts)->toBe([])
            ->and($component->topPaths)->toBe([])
            ->and($component->breakdowns)->toBe([]);
    }
})->with([[Analytics::class], [TrafficAnalytics::class]]);

test('another teams owner cannot refresh the mounted traffic analytics', function (string $componentClass) {
    $component = loadLazy(Livewire::test($componentClass))->assertSet('overview.requests', 123);
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);
    $reads = $this->client->reads;

    $component->call('loadData')->assertForbidden();
    expect($this->client->reads)->toBe($reads);
})->with([[Analytics::class], [TrafficAnalytics::class]]);
