<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('registers the metrics route', function () {
    expect(route('metrics'))->toEndWith('/metrics');
});
