<?php

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Personal teams start in onboarding, which redirects every page except onboarding itself.
 */
function finishInvitationTestOnboarding(): void
{
    Team::query()->update(['show_boarding' => false]);
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['email' => 'invited@example.com']);

    $this->invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => 'test-invitation-uuid',
        'email' => 'invited@example.com',
        'role' => 'member',
        'link' => url('/invitations/test-invitation-uuid'),
        'via' => 'link',
    ]);

    finishInvitationTestOnboarding();
});

test('GET invitation shows landing page without accepting', function () {
    $this->actingAs($this->user);

    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertStatus(200);
    $response->assertViewIs('invitation.accept');
    $response->assertSee($this->team->name);
    $response->assertSee('Accept invitation');

    // Invitation should NOT be deleted (not accepted yet)
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should NOT be added to the team
    expect($this->user->teams()->where('team_id', $this->team->id)->exists())->toBeFalse();
});

test('GET invitation with reset-password query param does not reset password', function () {
    $this->actingAs($this->user);
    $originalPassword = $this->user->password;

    $response = $this->get('/invitations/test-invitation-uuid?reset-password=1');

    $response->assertStatus(200);

    // Password should NOT be changed
    $this->user->refresh();
    expect($this->user->password)->toBe($originalPassword);

    // Invitation should NOT be accepted
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('POST invitation accepts and adds user to team', function () {
    $this->actingAs($this->user);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertRedirect(route('team.index'));

    // Invitation should be deleted
    $this->assertDatabaseMissing('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should be added to the team
    expect($this->user->teams()->where('team_id', $this->team->id)->exists())->toBeTrue();
});

test('POST invitation without CSRF token is rejected', function () {
    // Laravel skips CSRF verification while running unit tests, so enforce it explicitly
    $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $this->actingAs($this->user);

    $response = $this->withoutMiddleware(EncryptCookies::class)
        ->post('/invitations/test-invitation-uuid', [], [
            'X-CSRF-TOKEN' => 'invalid-token',
        ]);

    // Should be rejected with 419 (CSRF token mismatch)
    $response->assertStatus(419);

    // Invitation should NOT be accepted
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('unauthenticated user cannot view invitation', function () {
    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertRedirect();
});

test('wrong user cannot view invitation', function () {
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    finishInvitationTestOnboarding();
    $this->actingAs($otherUser);

    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertStatus(400);
});

test('wrong user cannot accept invitation via POST', function () {
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    finishInvitationTestOnboarding();
    $this->actingAs($otherUser);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertStatus(400);

    // Invitation should still exist
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('GET revoke route no longer exists', function () {
    $this->actingAs($this->user);

    $response = $this->get('/invitations/test-invitation-uuid/revoke');

    // Unknown paths fall through to the catch-all route, which redirects home
    expect(Route::has('team.invitation.revoke'))->toBeFalse();
    $response->assertRedirect(RouteServiceProvider::HOME);

    // The invitation must not be revoked by a GET request
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('POST invitation for already-member user deletes invitation without duplicating', function () {
    $this->user->teams()->attach($this->team->id, ['role' => 'member']);
    $this->actingAs($this->user);
    // With two teams, an active team must be selected or every page redirects to team selection
    session(['currentTeam' => $this->user->teams()->where('personal_team', true)->first()]);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertRedirect(route('team.index'));

    // Invitation should be deleted
    $this->assertDatabaseMissing('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should still have exactly one membership in this team
    expect($this->user->teams()->where('team_id', $this->team->id)->count())->toBe(1);
});
