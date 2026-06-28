<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
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
});

test('GET invitation shows landing page without accepting', function () {
    $this->actingAs($this->user);

    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertStatus(200);
    $response->assertViewIs('invitation.accept');
    $response->assertSee($this->team->name);
    $response->assertSee('Accept Invitation');

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
    $this->actingAs($otherUser);

    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertStatus(400);
});

test('wrong user cannot accept invitation via POST', function () {
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
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

    $response->assertStatus(404);
});

test('POST invitation for already-member user deletes invitation without duplicating', function () {
    $this->user->teams()->attach($this->team->id, ['role' => 'member']);
    $this->actingAs($this->user);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertRedirect(route('team.index'));

    // Invitation should be deleted
    $this->assertDatabaseMissing('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should still have exactly one membership in this team
    expect($this->user->teams()->where('team_id', $this->team->id)->count())->toBe(1);
});
