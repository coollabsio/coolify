<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Livewire\Server\Sentinel\Logs;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);
    $this->server = Server::factory()->create(['team_id' => $team->id]);
});

it('shows Sentinel status and logs when the legacy flag and metrics are disabled', function (bool $recentHeartbeat) {
    $this->server->sentinelHeartbeat(isReset: ! $recentHeartbeat);
    $this->server->settings()->update(['is_sentinel_enabled' => false, 'is_metrics_enabled' => false]);

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->assertSee($recentHeartbeat ? 'In sync' : 'Out of sync')
        ->assertDontSee('Enable Sentinel')
        ->assertDontSee('Sentinel is disabled')
        ->assertSeeLivewire(GetLogs::class);
})->with([false, true]);

it('shows Sentinel status independently of optional metrics', function (bool $metricsEnabled, bool $recentHeartbeat) {
    $this->server->sentinelHeartbeat(isReset: ! $recentHeartbeat);
    $this->server->settings()->update([
        'is_sentinel_enabled' => false,
        'is_metrics_enabled' => $metricsEnabled,
    ]);

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->assertSee($recentHeartbeat ? 'In sync' : 'Out of sync')
        ->assertSeeLivewire(GetLogs::class);
})->with([false, true])->with([false, true]);

it('does not offer Sentinel controls or logs on unsupported servers', function (string $setting) {
    $this->server->settings()->update([$setting => true]);

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->assertDontSee('Enable Sentinel')
        ->assertDontSeeLivewire(GetLogs::class);
})->with(['is_build_server', 'is_swarm_manager', 'is_swarm_worker']);
