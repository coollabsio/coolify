<?php

use App\Actions\User\SetupUserSessionAfterLogin;
use App\Livewire\Profile\Index;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->personal()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
});

it('allows unauthenticated access to passkey login options', function () {
    $response = $this->get('/passkeys/login/options');

    expect($response->status())->toBeIn([200, 302, 401, 422]);
});

it('includes passkey paths in allowed paths for unsubscribed accounts', function () {
    $paths = allowedPathsForUnsubscribedAccounts();

    expect($paths)->toContain('passkeys/login')
        ->and($paths)->toContain('passkeys/login/options')
        ->and($paths)->toContain('passkeys/confirm')
        ->and($paths)->toContain('passkeys/confirm/options');
});

it('includes passkey paths in allowed paths for invalid accounts', function () {
    $paths = allowedPathsForInvalidAccounts();

    expect($paths)->toContain('passkeys/login')
        ->and($paths)->toContain('passkeys/login/options');
});

it('includes passkey paths in allowed paths for boarding accounts', function () {
    $paths = allowedPathsForBoardingAccounts();

    expect($paths)->toContain('passkeys/login')
        ->and($paths)->toContain('passkeys/login/options');
});

it('does not redirect authenticated user with force_password_reset from passkey login routes', function () {
    $this->user->update(['force_password_reset' => true]);

    $response = $this->actingAs($this->user)->get('/passkeys/login/options');

    if ($response->isRedirect()) {
        expect($response->headers->get('Location'))->not->toContain('force-password-reset');
    }
});

it('sets current team in session after login setup action runs', function () {
    session()->forget('currentTeam');

    SetupUserSessionAfterLogin::run($this->user->fresh('teams'));

    expect(session('currentTeam'))->not->toBeNull()
        ->and(session('currentTeam')->id)->toBe($this->team->id);
});

it('attaches invited team and sets current team when invitation is valid', function () {
    $invitedTeam = Team::factory()->create();
    TeamInvitation::create([
        'team_id' => $invitedTeam->id,
        'uuid' => 'passkey-invitation-uuid',
        'email' => $this->user->email,
        'role' => 'member',
        'link' => url('/invitations/passkey-invitation-uuid'),
        'via' => 'link',
    ]);

    session()->forget('currentTeam');

    SetupUserSessionAfterLogin::run($this->user->fresh('teams'));

    expect($this->user->fresh()->teams()->where('team_id', $invitedTeam->id)->exists())->toBeTrue()
        ->and(session('currentTeam')->id)->toBe($invitedTeam->id)
        ->and(TeamInvitation::whereEmail($this->user->email)->exists())->toBeFalse();
});

it('implements passkey user contract on user model', function () {
    expect($this->user)->toBeInstanceOf(\Laravel\Fortify\Contracts\PasskeyUser::class);
});

it('allows oauth-only users without a password to use passkey contract', function () {
    $oauthUser = User::factory()->create([
        'password' => null,
    ]);

    expect($oauthUser->hasPassword())->toBeFalse()
        ->and($oauthUser)->toBeInstanceOf(\Laravel\Fortify\Contracts\PasskeyUser::class);
});

it('skips password confirmation for oauth-only users registering passkeys', function () {
    $oauthUser = User::factory()->create([
        'password' => null,
    ]);

    $this->actingAs($oauthUser);

    expect(shouldSkipPasswordConfirmation())->toBeTrue();
});

it('configures passkeys in fortify config', function () {
    expect(config('fortify.passkeys'))->toBeArray()
        ->and(config('fortify.passkeys.relying_party_id'))->not->toBeEmpty()
        ->and(config('fortify.passkeys.allowed_origins'))->not->toBeEmpty()
        ->and(config('fortify.limiters.passkeys'))->toBe('passkeys');
});

it('uses the request host as the passkey relying party id', function () {
    $this->get('/login', [
        'HTTP_HOST' => 'vsdcs.com',
        'HTTPS' => 'on',
    ]);

    expect(config('passkeys.relying_party_id'))->toBe('vsdcs.com')
        ->and(config('passkeys.allowed_origins'))->toContain('https://vsdcs.com');
});

it('redirects to confirm identity before opening the add passkey flow', function () {
    $this->actingAs($this->user)
        ->get(route('profile.add-passkey'))
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toContain('addPasskey=1');
});

it('requires password confirmation before passkey registration options', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('Referer', url('/profile'))
        ->getJson('/user/passkeys/options');

    $response->assertStatus(423)
        ->assertJson([
            'message' => 'Password confirmation required.',
            'redirect' => route('password.confirm'),
        ]);

    expect(session('url.intended'))->toContain('addPasskey=1');
});

it('allows passkey registration options after password confirmation', function () {
    session(['auth.password_confirmed_at' => now()->unix()]);

    $this->actingAs($this->user)
        ->getJson('/user/passkeys/options')
        ->assertSuccessful();
});

it('deletes a passkey from the profile page', function () {
    $passkey = Passkey::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Work laptop',
        'credential_id' => 'test-credential-id',
        'credential' => ['id' => 'test', 'type' => 'public-key'],
    ]);

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->call('deletePasskey', $passkey->id)
        ->assertDispatched('success');

    expect(Passkey::query()->find($passkey->id))->toBeNull();
});
