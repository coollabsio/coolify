<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids clients page for non-instance-admin', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $this->get('/clients')->assertForbidden();
});

it('allows clients page for instance-admin', function () {
    $rootTeam = Team::create([
        'id' => 0,
        'name' => 'Root Team',
        'personal_team' => false,
        'is_client' => false,
        'show_boarding' => false,
    ]);

    $user = User::factory()->create();
    $user->teams()->attach($rootTeam, ['role' => 'owner']);

    $this->actingAs($user);
    session(['currentTeam' => $rootTeam]);

    $this->get('/clients')->assertSuccessful();
});

