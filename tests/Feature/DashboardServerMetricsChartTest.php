<?php

use App\Livewire\Dashboard;
use App\Livewire\Server\Index as ServerIndex;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->team = $team;
});

it('renders a metrics chart only for servers with metrics enabled', function () {
    $enabledServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $enabledServer->settings->update(['is_metrics_enabled' => true]);

    $disabledServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $disabledServer->settings->update(['is_metrics_enabled' => false]);

    Livewire::test(Dashboard::class)
        ->assertSeeHtml("dashboard-server-metrics-{$enabledServer->uuid}")
        ->assertDontSeeHtml("dashboard-server-metrics-{$disabledServer->uuid}");
});

it('renders dashboard metrics charts on the server index grid', function () {
    $enabledServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $enabledServer->settings->update(['is_metrics_enabled' => true]);

    $disabledServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $disabledServer->settings->update(['is_metrics_enabled' => false]);

    Livewire::test(ServerIndex::class)
        ->assertSeeHtml("dashboard-server-metrics-{$enabledServer->uuid}")
        ->assertDontSeeHtml("dashboard-server-metrics-{$disabledServer->uuid}");
});
