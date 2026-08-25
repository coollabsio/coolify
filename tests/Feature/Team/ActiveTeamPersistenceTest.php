<?php

use App\Actions\Team\DeleteTeam;
use App\Livewire\SelectTeam;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
});

/**
 * Create a user that belongs to two teams (their auto-created personal team
 * plus a second team). Boarding is disabled so the middleware does not bounce
 * the request to the onboarding screen.
 */
function userWithTwoTeams(): array
{
    $user = User::factory()->create();
    $personal = $user->teams->first();
    $personal->update(['show_boarding' => false]);

    $second = Team::factory()->create(['show_boarding' => false]);
    $user->teams()->attach($second, ['role' => 'owner']);
    $user->refresh();

    return [$user, $personal, $second];
}

it('resolves the stored team when the user still belongs to it', function () {
    [$user, , $second] = userWithTwoTeams();
    $user->update(['current_team_id' => $second->id]);

    expect($user->resolveStoredTeam()?->id)->toBe($second->id);
});

it('resolves the only team for single-team users without a stored choice', function () {
    $user = User::factory()->create();
    $user->teams->first()->update(['show_boarding' => false]);

    expect($user->resolveStoredTeam()?->id)->toBe($user->teams->first()->id);
});

it('returns null for multi-team users without a valid stored choice', function () {
    [$user] = userWithTwoTeams();

    expect($user->resolveStoredTeam())->toBeNull();
});

it('ignores a stored team the user no longer belongs to', function () {
    [$user, , $second] = userWithTwoTeams();
    $user->update(['current_team_id' => 99999]);

    expect($user->resolveStoredTeam())->toBeNull();
    // still ambiguous (2 teams), so must pick again
    $user->update(['current_team_id' => $second->id]);
    $user->refresh();
    expect($user->resolveStoredTeam()?->id)->toBe($second->id);
});

it('persists current_team_id when the active team changes via refreshSession', function () {
    [$user, , $second] = userWithTwoTeams();
    $this->actingAs($user);

    refreshSession($second);

    expect($user->fresh()->current_team_id)->toBe($second->id)
        ->and(data_get(session('currentTeam'), 'id'))->toBe($second->id);
});

it('redirects a multi-team user with no stored team to the select screen', function () {
    [$user] = userWithTwoTeams();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('team.select'));
});

it('restores the stored team for a returning multi-team user', function () {
    [$user, , $second] = userWithTwoTeams();
    $user->update(['current_team_id' => $second->id]);

    $this->actingAs($user)->get('/');

    expect(data_get(session('currentTeam'), 'id'))->toBe($second->id);
});

it('does not send a single-team user to the select screen', function () {
    $user = User::factory()->create();
    $only = $user->teams->first();
    $only->update(['show_boarding' => false]);

    // A single-team user who lands on the select screen is bounced straight to
    // the dashboard with their team activated, never shown a choice.
    $this->actingAs($user)
        ->get(route('team.select'))
        ->assertRedirect(route('dashboard'));

    expect(data_get(session('currentTeam'), 'id'))->toBe($only->id);
});

it('persists the choice and activates the team when selected on the screen', function () {
    [$user, , $second] = userWithTwoTeams();

    Livewire::actingAs($user)
        ->test(SelectTeam::class)
        ->call('selectTeam', $second->id)
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->current_team_id)->toBe($second->id)
        ->and(data_get(session('currentTeam'), 'id'))->toBe($second->id);
});

it('lets the livewire update endpoint through for an ambiguous user', function () {
    [$user] = userWithTwoTeams();

    // The selection action runs as a Livewire AJAX POST to /livewire/update.
    // The team gate must not hijack that request with a redirect to the
    // selection screen, or the click silently does nothing (HTML != JSON).
    $response = $this->actingAs($user)
        ->withHeaders(['X-Livewire' => 'true'])
        ->post('/livewire/update', []);

    expect($response->headers->get('Location'))->not->toBe(route('team.select'));
});

it('clears the stored team when the member is removed from it', function () {
    [$user, , $second] = userWithTwoTeams();
    $user->update(['current_team_id' => $second->id]);

    // Simulate the removal event (Team\Member::remove detaches then clears).
    $user->teams()->detach($second->id);
    $user->clearStoredTeamIfMatches($second->id);

    expect($user->fresh()->current_team_id)->toBeNull();
});

it('keeps the stored team when the member is removed from a different team', function () {
    [$user, $personal, $second] = userWithTwoTeams();
    $user->update(['current_team_id' => $second->id]);

    $user->teams()->detach($personal->id);
    $user->clearStoredTeamIfMatches($personal->id);

    expect($user->fresh()->current_team_id)->toBe($second->id);
});

it('clears the stored team for members when their team is deleted', function () {
    [$owner, , $shared] = userWithTwoTeams();
    $member = User::factory()->create();
    $member->teams()->attach($shared, ['role' => 'member']);
    $member->update(['current_team_id' => $shared->id]);

    app(DeleteTeam::class)->handle($shared->fresh(), $owner);

    expect($member->fresh()->current_team_id)->toBeNull();
});

it('clears the deleting owner stored team when they delete that team', function () {
    [$owner, $personal, $shared] = userWithTwoTeams();
    $owner->update(['current_team_id' => $shared->id]);

    app(DeleteTeam::class)->handle($shared->fresh(), $owner);

    expect($owner->fresh()->current_team_id)->toBeNull();
});

it('preserves a newer team selection when clearing a stale team', function () {
    [$user, $personal, $second] = userWithTwoTeams();
    // In-memory model still points at the team being removed ($second)...
    $user->update(['current_team_id' => $second->id]);
    // ...but a concurrent request already switched the stored choice to $personal.
    User::query()->whereKey($user->id)->update(['current_team_id' => $personal->id]);

    $user->clearStoredTeamIfMatches($second->id);

    // The atomic WHERE guard must not clobber the newer selection.
    expect($user->fresh()->current_team_id)->toBe($personal->id);
});

it('bounces users who already have an active team away from the select screen', function () {
    [$user, , $second] = userWithTwoTeams();
    $user->update(['current_team_id' => $second->id]);
    refreshSession($second);

    Livewire::actingAs($user)
        ->test(SelectTeam::class)
        ->assertRedirect(route('dashboard'));
});
