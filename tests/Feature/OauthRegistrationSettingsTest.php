<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Livewire\SettingsOauth;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
    ]);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => false,
            'is_oauth_registration_enabled' => false,
            'disable_password_auth_for_oauth_users' => false,
        ]);
    });

    OauthSetting::query()->create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'http://localhost/auth/github/callback',
    ]);

    User::factory()->create([
        'id' => 0,
        'email' => 'admin@example.com',
    ]);
});

it('allows oauth registration when password registration is disabled', function () {
    instanceSettings()->update([
        'is_oauth_registration_enabled' => true,
    ]);

    Socialite::shouldReceive('buildProvider')
        ->once()
        ->andReturn(new class
        {
            public function user(): object
            {
                return (object) [
                    'name' => 'OAuth User',
                    'email' => 'oauth@example.com',
                ];
            }
        });

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'oauth@example.com',
        'oauth_provider' => 'github',
        'password' => null,
    ]);
});

it('keeps oauth registration disabled unless it is explicitly enabled', function () {
    Socialite::shouldReceive('buildProvider')
        ->once()
        ->andReturn(new class
        {
            public function user(): object
            {
                return (object) [
                    'name' => 'OAuth User',
                    'email' => 'oauth@example.com',
                ];
            }
        });

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

it('blocks password login for oauth users when password auth is disabled', function () {
    instanceSettings()->update([
        'disable_password_auth_for_oauth_users' => true,
    ]);

    User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => Hash::make('password'),
        'oauth_provider' => 'github',
    ]);

    $response = $this->post('/login', [
        'email' => 'oauth@example.com',
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest();
});

it('blocks password reset for oauth users when password auth is disabled', function () {
    instanceSettings()->update([
        'disable_password_auth_for_oauth_users' => true,
    ]);

    $user = User::factory()->create([
        'password' => null,
        'oauth_provider' => 'github',
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'Password1!@',
        'password_confirmation' => 'Password1!@',
    ]))->toThrow(ValidationException::class);

    expect($user->fresh()->password)->toBeNull();
});

it('blocks password changes for oauth users when password auth is disabled', function () {
    instanceSettings()->update([
        'disable_password_auth_for_oauth_users' => true,
    ]);

    $user = User::factory()->create([
        'password' => Hash::make('password'),
        'oauth_provider' => 'github',
    ]);

    expect(fn () => app(UpdateUserPassword::class)->update($user, [
        'current_password' => 'password',
        'password' => 'Password1!@',
        'password_confirmation' => 'Password1!@',
    ]))->toThrow(ValidationException::class);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('persists oauth registration controls from the oauth settings page', function () {
    $admin = User::findOrFail(0);

    $this->actingAs($admin);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(SettingsOauth::class)
        ->set('is_oauth_registration_enabled', true)
        ->set('disable_password_auth_for_oauth_users', true)
        ->call('submit')
        ->assertDispatched('success');

    $this->assertDatabaseHas('instance_settings', [
        'id' => 0,
        'is_oauth_registration_enabled' => true,
        'disable_password_auth_for_oauth_users' => true,
    ]);
});
