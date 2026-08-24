<?php

use App\Livewire\Analytics;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class FakeLiveTrafficClient extends SentinelTrafficClient
{
    protected function raw(string $url): string
    {
        if (str_contains($url, '/traffic/overview')) {
            return json_encode([
                'requests' => 1000,
                'bytes_in' => 5000,
                'bytes_out' => 25000,
                'status' => ['s2xx' => 900, 's3xx' => 50, 's4xx' => 40, 's5xx' => 10],
                'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
                'unique_visitors' => 320,
            ]);
        }

        return '[]';
    }
}

function bootLiveServer(): Server
{
    Server::flushIdentityMap();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    app()->bind(SentinelTrafficClient::class, fn () => new FakeLiveTrafficClient($server));

    return $server;
}

it('is paused by default and does not poll until Live Refresh is turned on', function () {
    $server = bootLiveServer();

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSet('live', false)
        ->assertDontSeeHtml('wire:poll.60s')
        ->assertSee('Live Refresh');
});

it('starts polling when live is toggled on at the 24h range', function () {
    $server = bootLiveServer();

    loadLazy(Livewire::test(Analytics::class))
        ->assertDontSeeHtml('wire:poll.60s')
        ->call('toggleLive')
        ->assertSet('live', true)
        ->assertSeeHtml('wire:poll.60s')
        ->assertSee('Live Refresh');
});

it('hides the Live Refresh control and stops polling for the 7d and 30d ranges', function () {
    $server = bootLiveServer();

    $component = loadLazy(Livewire::test(Analytics::class))
        ->call('setRange', '7d')
        ->assertDontSeeHtml('wire:poll.60s')
        ->assertDontSee('Live Refresh');

    expect($component->instance()->isLivePollable())->toBeFalse();

    $component->call('setRange', '30d')
        ->assertDontSeeHtml('wire:poll.60s')
        ->assertDontSee('Live Refresh');
});

it('re-arms polling when returning to the 24h range after live was turned on', function () {
    $server = bootLiveServer();

    loadLazy(Livewire::test(Analytics::class))
        ->call('toggleLive')                 // arm live at 24h
        ->assertSeeHtml('wire:poll.60s')
        ->call('setRange', '7d')
        ->assertDontSeeHtml('wire:poll.60s')
        ->call('setRange', '24h')
        ->assertSeeHtml('wire:poll.60s');
});
