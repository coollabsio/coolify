<?php

use App\Livewire\Team\Invitations;
use App\Livewire\Team\InviteLink;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], [
        'fqdn' => null,
        'smtp_enabled' => true,
        'smtp_from_address' => 'admin@example.com',
        'smtp_host' => 'coolify-mail',
        'smtp_port' => 1025,
    ]));
    Once::flush();
    Mail::fake();

    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->ownerA = User::factory()->create();
    $this->ownerB = User::factory()->create();
    $this->teamA->members()->attach($this->ownerA, ['role' => 'owner']);
    $this->teamB->members()->attach($this->ownerB, ['role' => 'owner']);

    $this->pendingUser = User::factory()->create([
        'email' => 'shared-invitee@example.com',
        'email_verified_at' => null,
        'force_password_reset' => true,
    ]);
    $this->invitationA = TeamInvitation::create([
        'team_id' => $this->teamA->id,
        'uuid' => 'team-a-shared-invitation',
        'email' => $this->pendingUser->email,
        'role' => 'member',
        'link' => 'https://example.test/invite/team-a-shared-invitation',
        'via' => 'link',
    ]);

    $this->actingAs($this->ownerB);
    session(['currentTeam' => $this->teamB]);
});

test('allows separate teams to invite the same email', function (string $method, string $via) {
    Livewire::test(InviteLink::class)
        ->set('email', $this->pendingUser->email)
        ->set('role', 'member')
        ->call($method)
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    $this->assertDatabaseHas('team_invitations', ['id' => $this->invitationA->id, 'team_id' => $this->teamA->id]);
    $this->assertDatabaseHas('team_invitations', ['team_id' => $this->teamB->id, 'email' => $this->pendingUser->email, 'via' => $via]);
    $this->assertDatabaseHas('users', ['id' => $this->pendingUser->id]);
})->with(['via link' => ['viaLink', 'link'], 'via email' => ['viaEmail', 'email']]);

test('keeps existing invitations and pending users when another team invites', function (string $method, string $via) {
    $this->invitationA->forceFill(['created_at' => now()->subDays(10)])->save();

    Livewire::test(InviteLink::class)
        ->set('email', $this->pendingUser->email)
        ->set('role', 'member')
        ->call($method)
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    $this->assertDatabaseHas('team_invitations', ['id' => $this->invitationA->id, 'team_id' => $this->teamA->id]);
    $this->assertDatabaseHas('team_invitations', ['team_id' => $this->teamB->id, 'email' => $this->pendingUser->email, 'via' => $via]);
    $this->assertDatabaseHas('users', ['id' => $this->pendingUser->id]);
})->with(['via link' => ['viaLink', 'link'], 'via email' => ['viaEmail', 'email']]);

test('keeps same-team duplicate handling', function (string $method) {
    $this->actingAs($this->ownerA);
    session(['currentTeam' => $this->teamA]);

    Livewire::test(InviteLink::class)
        ->set('email', $this->pendingUser->email)
        ->set('role', 'member')
        ->call($method)
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect(TeamInvitation::whereEmail($this->pendingUser->email)->count())->toBe(1);
    $this->assertDatabaseHas('team_invitations', ['id' => $this->invitationA->id]);
})->with(['viaLink', 'viaEmail']);

test('shows and changes only current-team invitations', function () {
    Livewire::test(Invitations::class, ['invitations' => TeamInvitation::ownedByCurrentTeam()->get()])
        ->assertDontSee($this->pendingUser->email)
        ->call('deleteInvitation', $this->invitationA->id)
        ->assertDispatched('error');

    $this->assertDatabaseHas('team_invitations', ['id' => $this->invitationA->id]);
    $this->assertDatabaseHas('users', ['id' => $this->pendingUser->id]);
});

test('requires invitation management access for both actions', function (string $method) {
    $memberB = User::factory()->create();
    $this->teamB->members()->attach($memberB, ['role' => 'member']);
    $this->actingAs($memberB);
    session(['currentTeam' => $this->teamB]);

    Livewire::test(InviteLink::class)
        ->set('email', $this->pendingUser->email)
        ->set('role', 'member')
        ->call($method)
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    $this->assertDatabaseHas('team_invitations', ['id' => $this->invitationA->id]);
    $this->assertDatabaseMissing('team_invitations', ['team_id' => $this->teamB->id, 'email' => $this->pendingUser->email]);
    $this->assertDatabaseHas('users', ['id' => $this->pendingUser->id]);
})->with(['viaLink', 'viaEmail']);

test('keeps a pending user with another invitation after expiration', function () {
    $invitationB = TeamInvitation::create([
        'team_id' => $this->teamB->id,
        'uuid' => 'team-b-shared-invitation',
        'email' => $this->pendingUser->email,
        'role' => 'member',
        'link' => 'https://example.test/invite/team-b-shared-invitation',
        'via' => 'link',
    ]);
    $this->invitationA->forceFill(['created_at' => now()->subDays(10)])->save();

    expect($this->invitationA->isValid())->toBeFalse();

    $this->assertDatabaseMissing('team_invitations', ['id' => $this->invitationA->id]);
    $this->assertDatabaseHas('team_invitations', ['id' => $invitationB->id]);
    $this->assertDatabaseHas('users', ['id' => $this->pendingUser->id]);
});

test('keeps a pending user with another invitation after revocation', function () {
    $invitationB = TeamInvitation::create([
        'team_id' => $this->teamB->id,
        'uuid' => 'team-b-shared-invitation',
        'email' => $this->pendingUser->email,
        'role' => 'member',
        'link' => 'https://example.test/invite/team-b-shared-invitation',
        'via' => 'link',
    ]);

    Livewire::test(Invitations::class, ['invitations' => TeamInvitation::ownedByCurrentTeam()->get()])
        ->call('deleteInvitation', $invitationB->id)
        ->assertDispatched('success');

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitationB->id]);
    $this->assertDatabaseHas('team_invitations', ['id' => $this->invitationA->id]);
    $this->assertDatabaseHas('users', ['id' => $this->pendingUser->id]);
});

test('removes an unused pending user after the last revocation', function () {
    $this->actingAs($this->ownerA);
    session(['currentTeam' => $this->teamA]);

    Livewire::test(Invitations::class, ['invitations' => TeamInvitation::ownedByCurrentTeam()->get()])
        ->call('deleteInvitation', $this->invitationA->id)
        ->assertDispatched('success');

    $this->assertDatabaseMissing('team_invitations', ['id' => $this->invitationA->id]);
    $this->assertDatabaseMissing('users', ['id' => $this->pendingUser->id]);
});

function invitationMagicLinkPassword(TeamInvitation $invitation): ?string
{
    if (parse_url($invitation->link, PHP_URL_PATH) !== '/auth/link') {
        return null;
    }
    parse_str((string) parse_url($invitation->link, PHP_URL_QUERY), $query);

    return explode('@@@', Crypt::decryptString($query['token']), 3)[2];
}

test('sends a working magic link when the same team invites again after expiration', function () {
    $this->actingAs($this->ownerA);
    session(['currentTeam' => $this->teamA]);
    $this->invitationA->forceFill(['created_at' => now()->subDays(10)])->save();

    Livewire::test(InviteLink::class)
        ->set('email', $this->pendingUser->email)
        ->call('viaLink')
        ->assertDispatched('success');

    $invitation = TeamInvitation::whereEmail($this->pendingUser->email)->sole();
    $user = User::whereEmail($this->pendingUser->email)->sole();
    $password = invitationMagicLinkPassword($invitation);

    expect($password)->not->toBeNull()
        ->and(Hash::check($password, $user->password))->toBeTrue();
});

test('sends a working magic link to an invited user who never set a password', function () {
    $user = User::factory()->create([
        'email' => 'removed-invitee@example.com',
        'email_verified_at' => null,
        'force_password_reset' => true,
    ]);

    Livewire::test(InviteLink::class)
        ->set('email', $user->email)
        ->call('viaLink')
        ->assertDispatched('success');

    $password = invitationMagicLinkPassword(TeamInvitation::whereEmail($user->email)->sole());

    expect($password)->not->toBeNull()
        ->and(Hash::check($password, $user->refresh()->password))->toBeTrue();
});

test('keeps the login link and password of an invited user who can sign in', function (array $attributes) {
    $user = User::factory()->create(array_merge(['email' => 'existing-invitee@example.com'], $attributes));
    $passwordHash = $user->password;
    if ($attributes['force_password_reset'] ?? false) {
        $this->teamA->members()->attach($user, ['role' => 'member']);
    }

    Livewire::test(InviteLink::class)
        ->set('email', $user->email)
        ->call('viaLink')
        ->assertDispatched('success');

    expect(invitationMagicLinkPassword(TeamInvitation::whereEmail($user->email)->sole()))->toBeNull()
        ->and($user->refresh()->password)->toBe($passwordHash);
})->with([
    'own password' => [['force_password_reset' => false]],
    'pending member of another team' => [['force_password_reset' => true, 'email_verified_at' => null]],
]);

test('keeps the inviter signed in when the invitee password is rotated', function () {
    $user = User::factory()->create([
        'email' => 'rotated-invitee@example.com',
        'email_verified_at' => null,
        'force_password_reset' => true,
    ]);

    Livewire::test(InviteLink::class)
        ->set('email', $user->email)
        ->call('viaLink');

    $this->assertAuthenticatedAs($this->ownerB);
    expect(data_get(session('currentTeam'), 'id'))->toBe($this->teamB->id);
});
