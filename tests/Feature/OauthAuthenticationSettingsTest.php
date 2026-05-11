<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
        'is_oauth_login_only_enabled' => false,
        'smtp_enabled' => true,
    ]);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => route('auth.callback', 'github'),
    ]);
});

function mockOauthUser(string $email = 'oauth-user@example.com', string $name = 'OAuth User'): void
{
    Socialite::shouldReceive('buildProvider')
        ->once()
        ->andReturn(new class($email, $name)
        {
            public function __construct(private string $email, private string $name) {}

            public function user(): object
            {
                return (object) [
                    'email' => $this->email,
                    'name' => $this->name,
                ];
            }
        });
}

it('allows oauth self registration when regular registration is disabled', function () {
    InstanceSettings::get()->update(['is_oauth_registration_enabled' => true]);
    mockOauthUser();

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');
    $this->assertAuthenticated();

    $user = User::whereEmail('oauth-user@example.com')->firstOrFail();
    expect($user->oauth_provider)->toBe('github');
});

it('keeps oauth self registration blocked when neither registration path is enabled', function () {
    mockOauthUser('blocked@example.com');

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::whereEmail('blocked@example.com')->exists())->toBeFalse();
});

it('blocks password login for oauth users when oauth login only is enabled', function () {
    InstanceSettings::get()->update(['is_oauth_login_only_enabled' => true]);
    $user = User::factory()->create([
        'email' => 'oauth-user@example.com',
        'password' => Hash::make('password'),
        'oauth_provider' => 'github',
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest();
});

it('blocks password creation and password changes for oauth users when oauth login only is enabled', function () {
    InstanceSettings::get()->update(['is_oauth_login_only_enabled' => true]);
    $user = User::factory()->create([
        'password' => null,
        'oauth_provider' => 'github',
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'New-password-123!',
        'password_confirmation' => 'New-password-123!',
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(UpdateUserPassword::class)->update($user, [
        'current_password' => 'password',
        'password' => 'New-password-123!',
        'password_confirmation' => 'New-password-123!',
    ]))->toThrow(ValidationException::class);
});
