<?php

use App\Actions\Server\StartSentinel;
use App\Livewire\Project\Shared\GetLogs;
use App\Livewire\Server\Sentinel\Logs;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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

it('does not show sync status or fetch logs when sentinel is disabled', function (bool $recentHeartbeat) {
    $this->server->sentinelHeartbeat(isReset: ! $recentHeartbeat);
    $this->server->settings()->update(['is_sentinel_enabled' => false, 'is_metrics_enabled' => false]);

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->assertSee('Sentinel is disabled')
        ->assertSeeHtml('wire:click="enableSentinel"')
        ->assertDontSee('Out of sync')
        ->assertDontSee('In sync')
        ->assertDontSeeLivewire(GetLogs::class);
})->with([false, true]);

it('shows sync status and logs when sentinel is enabled', function (bool $metricsOnly, bool $recentHeartbeat) {
    $this->server->sentinelHeartbeat(isReset: ! $recentHeartbeat);
    $this->server->settings()->update([
        'is_sentinel_enabled' => ! $metricsOnly,
        'is_metrics_enabled' => $metricsOnly,
        'is_build_server' => false,
    ]);

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->assertDontSee('Sentinel is disabled')
        ->assertSee($recentHeartbeat ? 'In sync' : 'Out of sync')
        ->assertSeeLivewire(GetLogs::class);
})->with([false, true])->with([false, true]);

it('enables sentinel from the logs page', function () {
    $this->server->settings()->update(['is_sentinel_enabled' => false, 'is_metrics_enabled' => false]);
    StartSentinel::shouldRun()->once()->withArgs(function (Server $server, bool $restart): bool {
        expect($server->id)->toBe($this->server->id);
        expect($restart)->toBeTrue();
        $server->settings->update(['is_sentinel_enabled' => true]);

        return true;
    });

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->call('enableSentinel')
        ->assertDontSee('Sentinel is disabled')
        ->assertDontSee('Enable Sentinel')
        ->assertSeeLivewire(GetLogs::class)
        ->assertDispatched('refreshServerShow')
        ->assertDispatched('success');

    expect($this->server->fresh()->isSentinelEnabled())->toBeTrue();
});

it('keeps sentinel disabled when startup fails', function () {
    $this->server->settings()->update(['is_sentinel_enabled' => false, 'is_metrics_enabled' => false]);
    StartSentinel::shouldRun()->once()->andThrow(new RuntimeException('Startup failed'));

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->call('enableSentinel')
        ->assertSee('Sentinel is disabled')
        ->assertDontSeeLivewire(GetLogs::class)
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->server->fresh()->isSentinelEnabled())->toBeFalse();
});

it('does not enable sentinel on unsupported servers', function (string $setting) {
    $this->server->settings()->update(['is_sentinel_enabled' => false, 'is_metrics_enabled' => false, $setting => true]);
    StartSentinel::shouldRun()->never();

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->call('enableSentinel')
        ->assertSee('Sentinel is disabled')
        ->assertDispatched('error');
})->with(['is_build_server', 'is_swarm_manager', 'is_swarm_worker']);

it('denies enabling sentinel to members and users outside the server team', function (bool $crossTeam) {
    $this->server->settings()->update(['is_sentinel_enabled' => false, 'is_metrics_enabled' => false]);
    $user = User::factory()->create();
    if (! $crossTeam) {
        $this->server->team->members()->attach($user->id, ['role' => 'member']);
    }
    $this->actingAs($user);
    StartSentinel::shouldRun()->never();
    $component = new Logs;
    $component->server = $this->server->fresh();

    expect(fn () => $component->enableSentinel())
        ->toThrow(AuthorizationException::class);
    expect($this->server->fresh()->isSentinelEnabled())->toBeFalse();
})->with([false, true]);

it('does not restart sentinel when it is already enabled', function () {
    $this->server->settings()->update(['is_sentinel_enabled' => true, 'is_build_server' => false]);
    StartSentinel::shouldRun()->never();

    Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(Logs::class)
        ->assertSeeLivewire(GetLogs::class)
        ->call('enableSentinel')
        ->assertNotDispatched('success');
});
