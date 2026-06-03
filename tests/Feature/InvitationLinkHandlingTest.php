<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();
    Config::set('app.maintenance.driver', 'file');
    Config::set('cache.default', 'array');
    Config::set('session.driver', 'array');

    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->saveQuietly();
    }
});

function createInvitationLinkFixture(array $invitationAttributes = []): array
{
    $team = Team::factory()->create();
    $password = 'temporary-password-123';
    $user = User::factory()->create([
        'email' => $invitationAttributes['email'] ?? 'invitee@example.com',
        'password' => Hash::make($password),
        'force_password_reset' => true,
        'email_verified_at' => null,
    ]);
    $uuid = (string) new Cuid2(32);
    $token = Crypt::encryptString("{$user->email}@@@{$uuid}@@@{$password}");
    $link = route('auth.link', ['token' => $token]);

    $invitation = TeamInvitation::create(array_merge([
        'team_id' => $team->id,
        'uuid' => $uuid,
        'email' => $user->email,
        'role' => 'member',
        'link' => $link,
        'via' => 'link',
    ], $invitationAttributes));

    return [$team, $user, $password, $token, $invitation];
}

it('accepts a valid magic link invitation only once and rotates the temporary password', function () {
    [$team, $user, $password, $token] = createInvitationLinkFixture();

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseMissing('team_invitations', ['email' => $user->email]);
    expect($user->teams()->where('team_id', $team->id)->exists())->toBeTrue();

    $user->refresh();
    expect(Hash::check($password, $user->password))->toBeFalse();

    auth()->logout();
    session()->flush();

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('rejects a magic link when the invitation was revoked', function () {
    [, $user, , $token, $invitation] = createInvitationLinkFixture();
    $invitation->delete();

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->teams()->where('personal_team', false)->exists())->toBeFalse();
});

it('rejects a magic link when another invitation exists for the same email', function () {
    [, $user, , $token, $invitation] = createInvitationLinkFixture();
    $invitation->delete();

    $otherTeam = Team::factory()->create();
    TeamInvitation::create([
        'team_id' => $otherTeam->id,
        'uuid' => (string) new Cuid2(32),
        'email' => $user->email,
        'role' => 'admin',
        'link' => url('/invitations/other-invitation'),
        'via' => 'link',
    ]);

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->teams()->where('team_id', $otherTeam->id)->exists())->toBeFalse();
});

it('rejects a magic link when the invitation expired', function () {
    [, $user, , $token, $invitation] = createInvitationLinkFixture();
    $invitation->forceFill([
        'created_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 1),
        'updated_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 1),
    ])->save();

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

it('rejects a malformed magic link token', function () {
    $this->get(route('auth.link', ['token' => 'not-a-valid-token']))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
