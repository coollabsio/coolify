<?php

use App\Livewire\Fleet\Metrics;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    $this->team = $team;
});

it('renders the metrics page with an empty state when no server has metrics enabled', function () {
    $component = loadLazy(Livewire::test(Metrics::class));

    $component
        ->assertOk()
        ->assertSee('Metrics')
        ->assertSee('Enable metrics on a server');
});

it('registers the metrics route', function () {
    expect(route('metrics'))->toEndWith('/metrics');
});
