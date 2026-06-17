<?php

use App\Livewire\NavbarDeleteTeam;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->owner = User::factory()->create();
    $this->personalTeam = $this->owner->teams()->first();
    $this->owner->teams()->updateExistingPivot($this->personalTeam->id, ['role' => 'owner']);

    $this->teamToDelete = Team::create(['name' => 'Deletable Team', 'personal_team' => false]);
    $this->teamToDelete->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->member = User::factory()->create();
    $this->teamToDelete->members()->attach($this->member->id, ['role' => 'member']);
});

test('owner can delete team via navbar', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(NavbarDeleteTeam::class)
        ->call('delete', 'password')
        ->assertRedirect(route('team.index'));

    expect(Team::find($this->teamToDelete->id))->toBeNull();
});

test('member cannot delete team via navbar', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(NavbarDeleteTeam::class)
        ->call('delete', 'password')
        ->assertDispatched('error');

    expect(Team::find($this->teamToDelete->id))->not->toBeNull();
});
