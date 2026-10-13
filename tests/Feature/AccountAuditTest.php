<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Livewire\Admin\Index as AdminIndex;
use App\Livewire\ForcePasswordReset;
use App\Livewire\Team\AdminView;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();

    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function accountAuditRow(string $event): AuditEvent
{
    return AuditEvent::query()->where('event', $event)->sole();
}

function accountAuditSerialized(AuditEvent $event): string
{
    return json_encode($event->toArray());
}

function accountAuditOauthUser(string|int $id, string $email, array $rawClaims = []): object
{
    return (object) [
        'id' => $id,
        'email' => $email,
        'name' => 'Provider User',
        'user' => $rawClaims,
    ];
}

test('records two factor lifecycle events without storing the secret or recovery codes', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $secret = decrypt($this->user->fresh()->two_factor_secret);
    $initialCodes = $this->user->fresh()->recoveryCodes();

    app(ConfirmTwoFactorAuthentication::class)($this->user, app(Google2FA::class)->getCurrentOtp($secret));
    app(GenerateNewRecoveryCodes::class)($this->user);
    $regeneratedCodes = $this->user->fresh()->recoveryCodes();
    app(DisableTwoFactorAuthentication::class)($this->user);

    foreach ([
        'auth.user.two_factor_enabled',
        'auth.user.two_factor_confirmed',
        'auth.user.recovery_codes_regenerated',
        'auth.user.two_factor_disabled',
    ] as $eventName) {
        $event = accountAuditRow($eventName);
        $serialized = accountAuditSerialized($event);

        expect($event->team_id)->toBe($this->team->id)
            ->and($event->actor_id)->toBe($this->user->id)
            ->and($serialized)->not->toContain($secret);

        foreach ([...$initialCodes, ...$regeneratedCodes] as $code) {
            expect($serialized)->not->toContain($code);
        }
    }
});

test('records a forced password change without the password', function () {
    $this->user->forceFill(['force_password_reset' => true])->save();
    $newPassword = 'forced-new-password-value';

    Livewire::test(ForcePasswordReset::class)
        ->set('password', $newPassword)
        ->set('password_confirmation', $newPassword)
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    $event = accountAuditRow('ui.user.password_changed');

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->actor_id)->toBe($this->user->id)
        ->and(data_get($event->metadata, 'reason'))->toBe('forced_reset')
        ->and(accountAuditSerialized($event))->not->toContain($newPassword);
});

test('does not record a forced password change when validation fails', function () {
    $this->user->forceFill(['force_password_reset' => true])->save();

    Livewire::test(ForcePasswordReset::class)
        ->set('password', 'forced-new-password-value')
        ->set('password_confirmation', 'different-password-value')
        ->call('submit');

    expect(AuditEvent::query()->where('event', 'ui.user.password_changed')->exists())->toBeFalse();
});

test('records an accepted team invitation', function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    $invitedTeam = Team::factory()->create();
    TeamInvitation::create([
        'team_id' => $invitedTeam->id,
        'uuid' => 'account-audit-invitation',
        'email' => $this->user->email,
        'role' => 'admin',
        'link' => url('/invitations/account-audit-invitation'),
        'via' => 'link',
    ]);

    $this->post('/invitations/account-audit-invitation')
        ->assertRedirect(route('team.index'));

    $event = accountAuditRow('ui.team_invitation.accepted');

    expect($event->team_id)->toBe($invitedTeam->id)
        ->and($event->actor_id)->toBe($this->user->id)
        ->and($event->resource_uuid)->toBe('account-audit-invitation')
        ->and(data_get($event->metadata, 'role'))->toBe('admin')
        ->and(data_get($event->metadata, 'member_email'))->toBe($this->user->email)
        ->and(data_get($event->metadata, 'already_member'))->toBeFalse();
});

test('records an accepted magic link invitation without the temporary password', function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Auth::logout();

    $invitedTeam = Team::factory()->create();
    $temporaryPassword = 'temporary-password-123';
    $invitee = User::factory()->create([
        'email' => 'magic-invitee@example.com',
        'password' => Hash::make($temporaryPassword),
        'force_password_reset' => true,
    ]);
    $uuid = (string) new Cuid2(32);
    $token = Crypt::encryptString("{$invitee->email}@@@{$uuid}@@@{$temporaryPassword}");
    TeamInvitation::create([
        'team_id' => $invitedTeam->id,
        'uuid' => $uuid,
        'email' => $invitee->email,
        'role' => 'member',
        'link' => route('auth.link', ['token' => $token]),
        'via' => 'link',
    ]);

    $this->post(route('auth.link.accept'), ['token' => $token])
        ->assertRedirect(route('dashboard'));

    $event = accountAuditRow('ui.team_invitation.accepted');
    $serialized = accountAuditSerialized($event);

    expect($event->team_id)->toBe($invitedTeam->id)
        ->and($event->actor_id)->toBe($invitee->id)
        ->and($event->actor_email)->toBe($invitee->email)
        ->and(data_get($event->metadata, 'role'))->toBe('member')
        ->and($serialized)->not->toContain($temporaryPassword)
        ->and($serialized)->not->toContain($token);
});

test('records a user deleted by an instance admin', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    $rootTeam->members()->attach($this->user->id, ['role' => 'admin']);
    $deletedUser = User::factory()->create(['email' => 'deleted-user@example.com']);

    Livewire::test(AdminView::class)
        ->call('delete', $deletedUser->id, 'password')
        ->assertReturned(true);

    $event = accountAuditRow('ui.user.deleted');

    expect(User::find($deletedUser->id))->toBeNull()
        ->and($event->team_id)->toBe($this->team->id)
        ->and($event->actor_id)->toBe($this->user->id)
        ->and(data_get($event->metadata, 'deleted_user_id'))->toBe($deletedUser->id)
        ->and(data_get($event->metadata, 'deleted_user_email'))->toBe('deleted-user@example.com');
});

test('does not record a user deletion when the password is wrong', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    $rootTeam->members()->attach($this->user->id, ['role' => 'admin']);
    $targetUser = User::factory()->create();

    Livewire::test(AdminView::class)
        ->call('delete', $targetUser->id, 'wrong-password');

    expect(User::find($targetUser->id))->not->toBeNull()
        ->and(AuditEvent::query()->where('event', 'ui.user.deleted')->exists())->toBeFalse();
});

test('records impersonation with the impersonating root user as actor', function () {
    config()->set('constants.coolify.self_hosted', false);

    $rootUser = User::factory()->create(['id' => 0]);
    $rootTeam = Team::find(0);
    $targetUser = User::factory()->create(['email' => 'impersonated@example.com']);

    $this->actingAs($rootUser);
    session(['currentTeam' => $rootTeam]);

    Livewire::test(AdminIndex::class)
        ->call('switchUser', $targetUser->id)
        ->assertRedirect(route('dashboard'));

    $event = accountAuditRow('ui.user.impersonated');

    expect($event->actor_id)->toBe(0)
        ->and($event->team_id)->toBe(0)
        ->and(data_get($event->metadata, 'target_user_id'))->toBe($targetUser->id)
        ->and(data_get($event->metadata, 'target_user_email'))->toBe('impersonated@example.com');
});

test('records an oauth identity linked to an existing user', function () {
    Auth::logout();
    $this->user->forceFill(['current_team_id' => $this->team->id])->save();
    $setting = OauthSetting::create([
        'provider' => 'github',
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'enabled' => true,
    ]);

    app(OauthLoginService::class)->login('github', accountAuditOauthUser(4242, $this->user->email), $setting);

    $event = accountAuditRow('auth.user.oauth_identity_linked');

    expect(OauthIdentity::query()->where('user_id', $this->user->id)->exists())->toBeTrue()
        ->and($event->team_id)->toBe($this->team->id)
        ->and($event->actor_id)->toBe($this->user->id)
        ->and($event->actor_email)->toBe($this->user->email)
        ->and(data_get($event->metadata, 'provider'))->toBe('github')
        ->and(accountAuditSerialized($event))->not->toContain('github-client-secret')
        ->and(AuditEvent::query()->where('event', 'auth.user.registered')->exists())->toBeFalse();
});

test('records registration of a user created by oauth login', function (bool $autoJoinRootTeam) {
    Auth::logout();
    Team::factory()->create(['id' => 0]);
    instanceSettings()->update(['is_registration_enabled' => true]);
    Once::flush();
    $setting = OauthSetting::create([
        'provider' => 'github',
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'enabled' => true,
        'auto_join_root_team' => $autoJoinRootTeam,
    ]);

    $user = app(OauthLoginService::class)->login('github', accountAuditOauthUser(5151, 'new-oauth-user@example.com'), $setting);

    $event = accountAuditRow('auth.user.registered');

    expect($event->actor_id)->toBe($user->id)
        ->and($event->actor_email)->toBe('new-oauth-user@example.com')
        ->and($event->team_id)->toBe($user->teams()->first()->id)
        ->and(data_get($event->metadata, 'provider'))->toBe('github')
        ->and(data_get($event->metadata, 'via'))->toBe('oauth')
        ->and(AuditEvent::query()->where('event', 'auth.user.oauth_identity_linked')->exists())->toBeFalse();
})->with([
    'personal team' => [false],
    'root team only' => [true],
]);
