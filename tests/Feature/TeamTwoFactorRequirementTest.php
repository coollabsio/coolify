<?php

use App\Livewire\SwitchTeam;
use App\Livewire\Team\Index;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create(['show_boarding' => false]);
});

function twoFactorTeamUser(Team $team, string $role = 'member', bool $twoFactorEnabled = false): User
{
    $user = User::factory()->create([
        'name' => fake()->name(),
        'two_factor_confirmed_at' => $twoFactorEnabled ? now() : null,
    ]);
    $team->members()->attach($user->id, ['role' => $role]);

    return $user;
}

test('a member without 2fa is sent to the setup page when the team requires 2fa', function () {
    $user = twoFactorTeamUser($this->team);
    // The session holds the team from before the change.
    $teamBeforeChange = $this->team->fresh();
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $teamBeforeChange]);

    $this->get('/profile')->assertRedirect(route('two-factor.required'));
    $this->getJson('/profile')->assertForbidden();
});

test('a member without 2fa can open the 2fa setup pages', function () {
    $user = twoFactorTeamUser($this->team);
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);

    $this->get('/two-factor-required')->assertOk()->assertSee('Configure 2FA');
    $this->get('/user/confirm-password')->assertOk();
});

test('a member with 2fa can use a team that requires 2fa', function () {
    $user = twoFactorTeamUser($this->team, twoFactorEnabled: true);
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);

    $this->get('/profile')->assertOk();
    $this->get('/two-factor-required')->assertRedirect(route('dashboard'));
});

test('a member without 2fa can use a team that does not require 2fa', function () {
    $user = twoFactorTeamUser($this->team);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);

    $this->get('/profile')->assertOk();
});

test('an admin with 2fa can require 2fa for the team', function () {
    $admin = twoFactorTeamUser($this->team, 'admin', twoFactorEnabled: true);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Index::class)
        ->set('is_two_factor_required', true)
        ->call('submit')
        ->assertDispatched('success');

    expect($this->team->fresh()->is_two_factor_required)->toBeTrue();
});

test('an admin without 2fa cannot require 2fa for the team', function () {
    $admin = twoFactorTeamUser($this->team, 'admin');
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Index::class)
        ->set('is_two_factor_required', true)
        ->call('submit')
        ->assertDispatched('error');

    expect($this->team->fresh()->is_two_factor_required)->toBeFalse();
});

test('a member cannot require 2fa for the team', function () {
    $member = twoFactorTeamUser($this->team, twoFactorEnabled: true);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Index::class)
        ->set('is_two_factor_required', true)
        ->call('submit');

    expect($this->team->fresh()->is_two_factor_required)->toBeFalse();
});

test('the setup page shows the error for a wrong 2fa code', function () {
    $user = twoFactorTeamUser($this->team);
    $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team, 'auth.password_confirmed_at' => time()]);

    $this->from('/two-factor-required')
        ->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertRedirect('/two-factor-required');

    $this->get('/two-factor-required')
        ->assertOk()
        ->assertSee('The provided two factor authentication code was invalid.');
});

test('a member without 2fa can confirm with a passkey on the setup page', function () {
    $user = twoFactorTeamUser($this->team);
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);

    $this->getJson('/passkeys/confirm/options')->assertOk();
    $this->postJson('/passkeys/confirm', [])->assertUnprocessable();
});

test('a member with a password must confirm it before enabling 2fa', function () {
    $user = twoFactorTeamUser($this->team);
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);

    $this->post('/user/two-factor-authentication')->assertRedirect(route('password.confirm'));

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('an oauth member without a known password can enable 2fa without a password confirmation', function () {
    $user = twoFactorTeamUser($this->team);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'oidc-user-1',
        'email' => $user->email,
    ]);
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);

    $this->from('/two-factor-required')
        ->post('/user/two-factor-authentication')
        ->assertRedirect('/two-factor-required');

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('an invited member who must set a password can open the password page in a team that requires 2fa', function () {
    $user = twoFactorTeamUser($this->team);
    $user->forceFill(['force_password_reset' => true])->save();
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);

    $this->get('/force-password-reset')->assertOk();
    $this->get('/two-factor-required')->assertRedirect(route('auth.force-password-reset'));
});

test('a member without 2fa can switch from the setup page to a team that does not require 2fa', function () {
    $user = twoFactorTeamUser($this->team);
    $personalTeam = $user->teams()->where('personal_team', true)->firstOrFail();
    $personalTeam->update(['show_boarding' => false]);
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);
    $this->get('/two-factor-required')->assertSee($personalTeam->name);

    $this->post(route('two-factor.required.switch-team'), ['team_id' => $personalTeam->id])
        ->assertRedirect(route('dashboard'));

    expect(data_get(session('currentTeam'), 'id'))->toBe($personalTeam->id);
    $this->get('/profile')->assertOk();
});

test('a member without 2fa cannot switch from the setup page to a team that requires 2fa or is not theirs', function () {
    $user = twoFactorTeamUser($this->team);
    $otherRequiredTeam = Team::factory()->create(['show_boarding' => false, 'is_two_factor_required' => true]);
    $otherRequiredTeam->members()->attach($user->id, ['role' => 'member']);
    $foreignTeam = Team::factory()->create(['show_boarding' => false]);
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user)->withSession(['currentTeam' => $this->team]);

    $this->post(route('two-factor.required.switch-team'), ['team_id' => $otherRequiredTeam->id])
        ->assertRedirect(route('two-factor.required'));
    $this->post(route('two-factor.required.switch-team'), ['team_id' => $foreignTeam->id])
        ->assertRedirect(route('two-factor.required'));

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->team->id);
});

test('a member without 2fa who switches back to a team that requires 2fa is sent to the setup page again', function () {
    $user = twoFactorTeamUser($this->team);
    $personalTeam = $user->teams()->where('personal_team', true)->firstOrFail();
    $personalTeam->update(['show_boarding' => false]);
    $this->team->update(['is_two_factor_required' => true]);
    $this->actingAs($user);
    session(['currentTeam' => $personalTeam]);

    Livewire::test(SwitchTeam::class)->call('switch_to', $this->team->id);

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->team->id);
    $this->get('/profile')->assertRedirect(route('two-factor.required'));
});
