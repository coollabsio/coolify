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

it('is paused by default and does not poll until live refresh is turned on', function () {
    bootLiveServer();

    loadLazy(Livewire::test(Analytics::class))
        ->assertOk()
        ->assertSet('live', false)
        ->assertDontSeeHtml('wire:poll.60s')
        ->assertSee('Paused');
});

it('polls when live refresh is turned on', function () {
    bootLiveServer();

    loadLazy(Livewire::test(Analytics::class))
        ->set('live', true)
        ->assertSeeHtml('wire:poll.60s')
        ->assertSee('Live');
});

it('keeps live refresh and polling on for every range', function (string $range) {
    bootLiveServer();

    loadLazy(Livewire::test(Analytics::class))
        ->set('live', true)
        ->call('setRange', $range)
        ->assertSet('range', $range)
        ->assertSet('live', true)
        ->assertSeeHtml('wire:poll.60s');
})->with(['7d', '30d']);

it('stops polling when live refresh is paused', function () {
    bootLiveServer();

    loadLazy(Livewire::test(Analytics::class))
        ->set('live', true)
        ->set('live', false)
        ->assertDontSeeHtml('wire:poll.60s')
        ->assertSee('Paused');
});
