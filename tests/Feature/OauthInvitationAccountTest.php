<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Auth\Oidc\OidcUser;
use App\Exceptions\OauthLoginException;
use App\Livewire\Team\InviteLink;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Once;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], [
        'is_registration_enabled' => false,
        'smtp_enabled' => true,
        'smtp_from_address' => 'admin@example.com',
        'smtp_host' => 'coolify-mail',
        'smtp_port' => 1025,
    ]));
    Once::flush();
    Mail::fake();

    OauthSetting::create([
        'provider' => 'oidc',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://idp.example.com',
        'redirect_uri' => 'https://coolify.example.com/auth/oidc/callback',
        'allow_registration' => false,
    ]);

    $this->inviter = User::factory()->create(['email' => 'inviter@example.com']);
    $this->inviterTeam = $this->inviter->teams()->first();
});

function inviteByInviter(string $method = 'viaLink'): TeamInvitation
{
    test()->actingAs(test()->inviter);
    session(['currentTeam' => test()->inviterTeam]);
    Livewire::test(InviteLink::class)
        ->set('email', 'victim@example.com')
        ->set('role', 'member')
        ->call($method)
        ->assertDispatched('success');
    auth()->logout();
    session()->flush();

    return TeamInvitation::whereEmail('victim@example.com')->sole();
}

function redeemAndSetPassword(TeamInvitation $invitation, string $password): User
{
    parse_str((string) parse_url($invitation->link, PHP_URL_QUERY), $query);
    test()->post(route('auth.link.accept'), ['token' => $query['token']])->assertRedirect(route('dashboard'));

    $user = User::whereEmail('victim@example.com')->sole();
    $user->forceFill(['password' => Hash::make($password), 'force_password_reset' => false])->save();
    auth()->logout();
    session()->flush();

    return $user->refresh();
}

function signInWithOidcAsVictim()
{
    $oidcUser = (new OidcUser)->setRaw([
        'iss' => 'https://idp.example.com',
        'sub' => 'victim-subject',
        'email' => 'victim@example.com',
        'email_verified' => true,
        'name' => 'Victim',
    ])->map(['id' => 'victim-subject', 'name' => 'Victim', 'email' => 'victim@example.com']);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($oidcUser);
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

    return test()->from('/login')->get(route('auth.callback', 'oidc'));
}

it('refuses the first sso login of a used invitation account', function (string $method) {
    $user = redeemAndSetPassword(inviteByInviter($method), 'chosen-password');

    signInWithOidcAsVictim()->assertRedirect('/login');

    $this->assertGuest();
    expect(session('errors')->first())->toBe(__('auth.failed.oauth_invitation_account'))
        ->and(OauthIdentity::where('user_id', $user->id)->exists())->toBeFalse();
})->with(['viaLink', 'viaEmail']);

it('refuses the first github login of a used invitation account', function () {
    $user = redeemAndSetPassword(inviteByInviter(), 'chosen-password');
    $setting = OauthSetting::create(['provider' => 'github', 'client_id' => 'id', 'client_secret' => 'secret', 'enabled' => true]);

    expect(fn () => app(OauthLoginService::class)->login('github', (object) [
        'email' => 'victim@example.com',
        'name' => 'Victim',
        'id' => 'victim-github-id',
        'user' => [],
    ], $setting))->toThrow(OauthLoginException::class);

    $this->assertGuest();
    expect(OauthIdentity::where('user_id', $user->id)->exists())->toBeFalse();
});

it('links an unused invitation account and removes the temporary password', function () {
    $invitation = inviteByInviter();
    $user = User::whereEmail('victim@example.com')->sole();

    signInWithOidcAsVictim()->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect($user->password)->toBeNull()
        ->and($user->created_by_invitation)->toBeFalse()
        ->and($user->force_password_reset)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(OauthIdentity::where('user_id', $user->id)->exists())->toBeTrue();

    auth()->logout();
    session()->flush();
    parse_str((string) parse_url($invitation->link, PHP_URL_QUERY), $query);
    $this->post(route('auth.link.accept'), ['token' => $query['token']])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('links a used invitation account after the owner resets the password through email', function () {
    $user = redeemAndSetPassword(inviteByInviter(), 'chosen-password');

    app(ResetUserPassword::class)->reset($user, [
        'password' => 'Owner-Password-123!',
        'password_confirmation' => 'Owner-Password-123!',
    ]);

    signInWithOidcAsVictim()->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(OauthIdentity::where('user_id', $user->id)->exists())->toBeTrue();
});

it('still links a password account that no invitation created', function () {
    $user = User::factory()->create(['email' => 'victim@example.com']);

    signInWithOidcAsVictim()->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->password)->not->toBeNull();
});

it('marks only accounts that an invitation creates', function () {
    inviteByInviter();

    expect(User::whereEmail('victim@example.com')->sole()->created_by_invitation)->toBeTrue()
        ->and($this->inviter->refresh()->created_by_invitation)->toBeFalse();
});

it('lets an instance admin allow the sso link of a used invitation account without email', function () {
    $user = redeemAndSetPassword(inviteByInviter(), 'chosen-password');
    $user->createToken('token', teamId: $user->teams()->first()->id);
    $user->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()])->save();

    $this->artisan('user:allow-sso-link', ['email' => 'VICTIM@example.com', '--force' => true])->assertSuccessful();

    $user->refresh();
    expect($user->password)->toBeNull()
        ->and($user->created_by_invitation)->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
    $this->post('/login', ['email' => 'victim@example.com', 'password' => 'chosen-password']);
    $this->assertGuest();

    signInWithOidcAsVictim()->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('refuses to change an account that no invitation created', function () {
    $user = User::factory()->create(['email' => 'victim@example.com']);
    $passwordHash = $user->password;

    $this->artisan('user:allow-sso-link', ['email' => 'victim@example.com', '--force' => true])->assertFailed();

    expect($user->refresh()->password)->toBe($passwordHash);
});
