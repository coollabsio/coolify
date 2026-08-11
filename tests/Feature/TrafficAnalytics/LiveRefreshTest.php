<?php

use App\Livewire\Server\Analytics;
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

it('polls for realtime data by default at the 24h range', function () {
    $server = bootLiveServer();

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertOk()
        ->assertSet('live', true)
        ->assertSeeHtml('wire:poll.60s')
        ->assertSee('Live');
});

it('stops polling when live is toggled off', function () {
    $server = bootLiveServer();

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->assertSeeHtml('wire:poll.60s')
        ->call('toggleLive')
        ->assertSet('live', false)
        ->assertDontSeeHtml('wire:poll.60s');
});

it('disables realtime polling for the 7d and 30d ranges', function () {
    $server = bootLiveServer();

    $component = Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->call('setRange', '7d')
        ->assertDontSeeHtml('wire:poll.60s');

    expect($component->instance()->isLivePollable())->toBeFalse();

    // toggleLive is a no-op outside the 24h range.
    $component->call('toggleLive')->assertDontSeeHtml('wire:poll.60s');

    $component->call('setRange', '30d')->assertDontSeeHtml('wire:poll.60s');
});

it('re-arms polling when returning to the 24h range', function () {
    $server = bootLiveServer();

    Livewire::test(Analytics::class, ['server_uuid' => $server->uuid])
        ->call('setRange', '7d')
        ->assertDontSeeHtml('wire:poll.60s')
        ->call('setRange', '24h')
        ->assertSeeHtml('wire:poll.60s');
});
