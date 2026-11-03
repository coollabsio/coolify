<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
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
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');

    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->ownerA = User::factory()->create();
    $this->ownerB = User::factory()->create();
    $this->teamA->members()->attach($this->ownerA, ['role' => 'owner']);
    $this->teamB->members()->attach($this->ownerB, ['role' => 'owner']);
});

function takeoverInvite(User $inviter, Team $team, string $email, string $method = 'viaLink', string $role = 'member'): TeamInvitation
{
    test()->actingAs($inviter);
    session(['currentTeam' => $team]);
    Livewire::test(InviteLink::class)
        ->set('email', $email)
        ->set('role', $role)
        ->call($method)
        ->assertDispatched('success');
    auth()->logout();
    session()->flush();

    return TeamInvitation::whereTeamId($team->id)->whereEmail($email)->sole();
}

function takeoverMagicToken(TeamInvitation $invitation): ?string
{
    if (parse_url($invitation->link, PHP_URL_PATH) !== '/auth/link') {
        return null;
    }
    parse_str((string) parse_url($invitation->link, PHP_URL_QUERY), $query);

    return $query['token'] ?? null;
}

test('gives a second team only a plain link for an invitee with a pending magic link', function (string $method) {
    $invitationA = takeoverInvite($this->ownerA, $this->teamA, 'victim@example.com', 'viaEmail', 'admin');
    $passwordHash = User::whereEmail('victim@example.com')->sole()->password;

    $invitationB = takeoverInvite($this->ownerB, $this->teamB, 'victim@example.com', $method);

    expect(takeoverMagicToken($invitationB))->toBeNull()
        ->and($invitationB->link)->toEndWith('/invitations/'.$invitationB->uuid)
        ->and(User::whereEmail('victim@example.com')->sole()->password)->toBe($passwordHash);

    $this->post(route('auth.link.accept'), ['token' => takeoverMagicToken($invitationA)])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs(User::whereEmail('victim@example.com')->sole());
})->with(['viaLink', 'viaEmail']);

test('does not let a magic link session accept another team invitation', function (string $routeName, string $method) {
    $user = User::factory()->create([
        'email' => 'victim@example.com',
        'email_verified_at' => null,
        'force_password_reset' => true,
    ]);
    $invitationA = TeamInvitation::create([
        'team_id' => $this->teamA->id,
        'uuid' => 'team-a-plain-invitation',
        'email' => $user->email,
        'role' => 'admin',
        'link' => route('team.invitation.show', ['uuid' => 'team-a-plain-invitation']),
        'via' => 'email',
    ]);
    $password = 'temporary-invitation-password';
    $user->forceFill(['password' => Hash::make($password)])->save();
    $token = Crypt::encryptString("{$user->email}@@@team-b-magic-invitation@@@{$password}");
    TeamInvitation::create([
        'team_id' => $this->teamB->id,
        'uuid' => 'team-b-magic-invitation',
        'email' => $user->email,
        'role' => 'member',
        'link' => route('auth.link', ['token' => $token]),
        'via' => 'link',
    ]);

    $this->post(route('auth.link.accept'), ['token' => $token])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    $this->{$method}(route($routeName, $invitationA->uuid))->assertStatus(400);

    expect($user->teams()->whereKey($this->teamA->id)->exists())->toBeFalse();
    $this->assertDatabaseHas('team_invitations', ['id' => $invitationA->id]);
})->with([
    'show' => ['team.invitation.show', 'get'],
    'accept' => ['team.invitation.accept', 'post'],
]);

test('lets the invitee accept the second invitation after setting a password', function () {
    takeoverInvite($this->ownerA, $this->teamA, 'invitee@example.com', 'viaEmail');
    $invitationB = takeoverInvite($this->ownerB, $this->teamB, 'invitee@example.com');
    $user = User::whereEmail('invitee@example.com')->sole();
    $user->forceFill(['password' => Hash::make('own-password-123'), 'force_password_reset' => false])->save();

    $this->actingAs($user);
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    $this->post(route('team.invitation.accept', $invitationB->uuid))->assertRedirect(route('team.index'));

    expect($user->teams()->whereKey($this->teamB->id)->exists())->toBeTrue();
});

test('gives the first invitation of a new email a working magic link', function () {
    $invitation = takeoverInvite($this->ownerA, $this->teamA, 'new-invitee@example.com');

    $this->post(route('auth.link.accept'), ['token' => takeoverMagicToken($invitation)])
        ->assertRedirect(route('dashboard'));

    $user = User::whereEmail('new-invitee@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->teams()->whereKey($this->teamA->id)->exists())->toBeTrue();
});

test('verifies the email when the invitee logs in through an emailed invitation', function () {
    $invitation = takeoverInvite($this->ownerA, $this->teamA, 'emailed@example.com', 'viaEmail');

    $this->post(route('auth.link.accept'), ['token' => takeoverMagicToken($invitation)])
        ->assertRedirect(route('dashboard'));

    expect(User::whereEmail('emailed@example.com')->sole()->hasVerifiedEmail())->toBeTrue();
});

test('does not verify the email when the invitee logs in through a copied invitation link', function () {
    $invitation = takeoverInvite($this->ownerA, $this->teamA, 'linked@example.com', 'viaLink');

    $this->post(route('auth.link.accept'), ['token' => takeoverMagicToken($invitation)])
        ->assertRedirect(route('dashboard'));

    expect(User::whereEmail('linked@example.com')->sole()->hasVerifiedEmail())->toBeFalse();
});
