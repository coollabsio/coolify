<?php

use App\Livewire\Team\Index;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->owner = User::factory()->create();

    // The owner's personal team (created by factory)
    $this->personalTeam = $this->owner->teams()->first();
    $this->owner->teams()->updateExistingPivot($this->personalTeam->id, ['role' => 'owner']);

    // A second team to delete
    $this->teamToDelete = Team::create(['name' => 'Deletable Team', 'personal_team' => false]);
    $this->teamToDelete->members()->attach($this->owner->id, ['role' => 'owner']);
});

test('deleting a team switches session to another team without error', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(Index::class)
        ->call('delete')
        ->assertRedirect(route('team.index'));

    // Team should be deleted from the database
    expect(Team::find($this->teamToDelete->id))->toBeNull();

    // Session should now have the personal team
    $sessionTeam = session('currentTeam');
    expect($sessionTeam)->not->toBeNull()
        ->and($sessionTeam->id)->toBe($this->personalTeam->id);
});

test('refreshSession clears session when no team exists', function () {
    $user = User::factory()->create();
    // Detach all teams so user has none
    $user->teams()->detach();
    $this->actingAs($user);
    session(['currentTeam' => null]);

    // Should not throw when no team can be resolved
    refreshSession(null);

    expect(session('currentTeam'))->toBeNull();
});
