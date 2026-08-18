<?php

use App\Livewire\Team\DangerZone;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

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

    Livewire::test(DangerZone::class)
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

test('deleting a team deletes github and gitlab sources with the same primary key', function () {
    $githubApp = GithubApp::forceCreate([
        'id' => 42,
        'name' => 'GitHub source',
        'team_id' => $this->teamToDelete->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
    ]);
    $gitlabApp = GitlabApp::forceCreate([
        'id' => 42,
        'name' => 'GitLab source',
        'team_id' => $this->teamToDelete->id,
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'is_public' => false,
    ]);

    $this->teamToDelete->delete();

    expect(GithubApp::find($githubApp->id))->toBeNull()
        ->and(GitlabApp::find($gitlabApp->id))->toBeNull();
});
