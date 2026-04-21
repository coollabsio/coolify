<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::find(0)) {
        $instanceSettings = new InstanceSettings;
        $instanceSettings->forceFill([
            'id' => 0,
            'is_registration_enabled' => false,
            'is_oauth_self_registration_enabled' => false,
            'disable_password_login_for_oauth_users' => false,
            'smtp_enabled' => false,
        ])->save();
    }

    // Make sure we have an existing user so the login/registration redirects don't trigger.
    User::factory()->create([
        'email' => 'existing-admin@example.com',
        'password' => Hash::make('password'),
    ]);

    OauthSetting::firstOrCreate(['provider' => 'authentik'], [
        'enabled' => true,
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://localhost/auth/authentik/callback',
        'base_url' => 'https://authentik.example.com',
    ]);
});

function fakeSocialiteUser(string $email, string $name = 'OAuth User'): void
{
    $abstractUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $abstractUser->shouldReceive('getEmail')->andReturn($email);
    $abstractUser->shouldReceive('getName')->andReturn($name);
    $abstractUser->email = $email;
    $abstractUser->name = $name;

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($abstractUser);
    $provider->shouldReceive('redirect')->andReturn(redirect('/'));
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('setHost')->andReturnSelf();
    $provider->shouldReceive('with')->andReturnSelf();

    Socialite::shouldReceive('driver')->andReturn($provider);
    Socialite::shouldReceive('buildProvider')->andReturn($provider);
}

it('blocks oauth registration when both general and oauth registration are disabled', function () {
    $this->withoutMiddleware();
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = false;
    $settings->is_oauth_self_registration_enabled = false;
    $settings->save();

    fakeSocialiteUser('newcomer@example.com');

    $response = $this->get('/auth/authentik/callback');

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
    expect(User::where('email', 'newcomer@example.com')->exists())->toBeFalse();
});

it('allows oauth registration when oauth self-registration is enabled even if general registration is disabled', function () {
    $this->withoutMiddleware();
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = false;
    $settings->is_oauth_self_registration_enabled = true;
    $settings->save();

    fakeSocialiteUser('newcomer@example.com', 'New Comer');

    $this->get('/auth/authentik/callback');

    $created = User::where('email', 'newcomer@example.com')->first();
    expect($created)->not->toBeNull();
    expect($created->oauth_provider)->toBe('authentik');
    expect($created->password)->toBeNull();
});

it('marks pre-existing users with the oauth provider on first oauth login', function () {
    $this->withoutMiddleware();
    $settings = InstanceSettings::find(0);
    $settings->is_oauth_self_registration_enabled = true;
    $settings->save();

    $user = User::factory()->create([
        'email' => 'preexisting@example.com',
        'oauth_provider' => null,
    ]);

    fakeSocialiteUser('preexisting@example.com');

    $this->get('/auth/authentik/callback');

    expect($user->fresh()->oauth_provider)->toBe('authentik');
});

it('blocks password login for oauth users when disable_password_login_for_oauth_users is enabled', function () {
    $this->withoutMiddleware();

    $settings = InstanceSettings::find(0);
    $settings->disable_password_login_for_oauth_users = true;
    $settings->save();

    User::factory()->create([
        'email' => 'oauth-only@example.com',
        'password' => Hash::make('correct-password'),
        'oauth_provider' => 'authentik',
    ]);

    $this->post('/login', [
        'email' => 'oauth-only@example.com',
        'password' => 'correct-password',
    ]);

    expect(auth()->check())->toBeFalse();
});

it('still allows password login for non-oauth users when the restriction is enabled', function () {
    $this->withoutMiddleware();

    $settings = InstanceSettings::find(0);
    $settings->disable_password_login_for_oauth_users = true;
    $settings->save();

    User::factory()->create([
        'email' => 'local-user@example.com',
        'password' => Hash::make('correct-password'),
        'oauth_provider' => null,
    ]);

    $this->post('/login', [
        'email' => 'local-user@example.com',
        'password' => 'correct-password',
    ]);

    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->email)->toBe('local-user@example.com');
});

it('blocks password reset for oauth-only users when the restriction is enabled', function () {
    $settings = InstanceSettings::find(0);
    $settings->disable_password_login_for_oauth_users = true;
    $settings->save();

    $user = User::factory()->create([
        'email' => 'oauth-reset@example.com',
        'password' => Hash::make('old'),
        'oauth_provider' => 'authentik',
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'NewPassword!1',
        'password_confirmation' => 'NewPassword!1',
    ]))->toThrow(HttpException::class);
});

it('blocks password update for oauth-only users when the restriction is enabled', function () {
    $settings = InstanceSettings::find(0);
    $settings->disable_password_login_for_oauth_users = true;
    $settings->save();

    $user = User::factory()->create([
        'email' => 'oauth-update@example.com',
        'password' => Hash::make('old'),
        'oauth_provider' => 'authentik',
    ]);

    expect(fn () => app(UpdateUserPassword::class)->update($user, [
        'current_password' => 'old',
        'password' => 'NewPassword!1',
        'password_confirmation' => 'NewPassword!1',
    ]))->toThrow(HttpException::class);
});
