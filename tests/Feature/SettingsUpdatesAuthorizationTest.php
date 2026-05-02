<?php

use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('non-admin user is redirected from settings updates page', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(Updates::class)
        ->assertRedirect(route('dashboard'));
});

test('instance admin can access settings updates page', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::create(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Updates::class)
        ->assertOk()
        ->assertNoRedirect();
});
