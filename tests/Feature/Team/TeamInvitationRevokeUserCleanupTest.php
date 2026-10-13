<?php

use App\Livewire\Team\Invitations;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['fqdn' => null]));

    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->ownerB = User::factory()->create();
    $this->teamB->members()->attach($this->ownerB->id, ['role' => 'owner']);

    $this->actingAs($this->ownerB);
    session(['currentTeam' => $this->teamB]);
});

/**
 * Mirrors InviteLink: a provisional user is created (with a personal team via the created hook).
 */
function revokeCleanupProvisionalInvitee(string $email): User
{
    return User::create([
        'name' => str($email)->before('@'),
        'email' => $email,
        'password' => bcrypt('secret-password'),
        'force_password_reset' => true,
    ]);
}

function revokeCleanupInvitation(Team $team, string $email, string $uuid): TeamInvitation
{
    return TeamInvitation::create([
        'team_id' => $team->id,
        'uuid' => $uuid,
        'email' => $email,
        'role' => 'member',
        'link' => "http://example.test/invitations/{$uuid}",
        'via' => 'link',
    ]);
}

it('does not delete an invitee who already joined another team when revoking an invitation', function () {
    $bob = revokeCleanupProvisionalInvitee('bob@example.com');
    $bob->teams()->attach($this->teamA->id, ['role' => 'member']);
    $invitation = revokeCleanupInvitation($this->teamB, $bob->email, 'team-b-invitation');

    Livewire::test(Invitations::class, ['invitations' => collect([$invitation])])
        ->call('deleteInvitation', $invitation->id)
        ->assertDispatched('success');

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseHas('users', ['id' => $bob->id]);
    $this->assertDatabaseHas('team_user', ['team_id' => $this->teamA->id, 'user_id' => $bob->id]);
    $this->assertDatabaseHas('teams', ['id' => $this->teamA->id]);
});

it('does not delete an invitee who is the sole member of another shared team when revoking', function () {
    $bob = revokeCleanupProvisionalInvitee('bob@example.com');
    $bob->teams()->attach($this->teamA->id, ['role' => 'owner']);
    $invitation = revokeCleanupInvitation($this->teamB, $bob->email, 'team-b-invitation');

    Livewire::test(Invitations::class, ['invitations' => collect([$invitation])])
        ->call('deleteInvitation', $invitation->id)
        ->assertDispatched('success');

    $this->assertDatabaseHas('users', ['id' => $bob->id]);
    $this->assertDatabaseHas('teams', ['id' => $this->teamA->id]);
});

it('still deletes a provisional invitee who never accepted any invitation when revoking', function () {
    $invitee = revokeCleanupProvisionalInvitee('never-accepted@example.com');
    $personalTeamId = $invitee->teams()->value('teams.id');
    $invitation = revokeCleanupInvitation($this->teamB, $invitee->email, 'never-accepted-invitation');

    Livewire::test(Invitations::class, ['invitations' => collect([$invitation])])
        ->call('deleteInvitation', $invitation->id)
        ->assertDispatched('success');

    $this->assertDatabaseMissing('users', ['id' => $invitee->id]);
    $this->assertDatabaseMissing('teams', ['id' => $personalTeamId]);
});

it('keeps a provisional invitee who still has another pending invitation when revoking', function () {
    $invitee = revokeCleanupProvisionalInvitee('two-invites@example.com');
    revokeCleanupInvitation($this->teamA, $invitee->email, 'team-a-pending');
    $invitation = revokeCleanupInvitation($this->teamB, $invitee->email, 'team-b-pending');

    Livewire::test(Invitations::class, ['invitations' => collect([$invitation])])
        ->call('deleteInvitation', $invitation->id)
        ->assertDispatched('success');

    $this->assertDatabaseHas('users', ['id' => $invitee->id]);
});

it('does not delete an invitee who already joined another team when an invitation expires', function () {
    $bob = revokeCleanupProvisionalInvitee('bob@example.com');
    $bob->teams()->attach($this->teamA->id, ['role' => 'member']);
    $invitation = revokeCleanupInvitation($this->teamB, $bob->email, 'team-b-expired');
    $invitation->forceFill(['created_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 2)])->save();

    expect($invitation->fresh()->isValid())->toBeFalse();

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseHas('users', ['id' => $bob->id]);
    $this->assertDatabaseHas('team_user', ['team_id' => $this->teamA->id, 'user_id' => $bob->id]);
});

it('still deletes a provisional invitee who never accepted when their only invitation expires', function () {
    $invitee = revokeCleanupProvisionalInvitee('expired@example.com');
    $invitation = revokeCleanupInvitation($this->teamB, $invitee->email, 'expired-invitation');
    $invitation->forceFill(['created_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 2)])->save();

    expect($invitation->fresh()->isValid())->toBeFalse();

    $this->assertDatabaseMissing('users', ['id' => $invitee->id]);
});
