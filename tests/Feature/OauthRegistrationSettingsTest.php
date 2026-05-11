<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
        'is_oauth_password_login_enabled' => true,
    ]));

    OauthSetting::query()->create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);
});

function fakeGithubOauthUser(string $email = 'oauth-user@example.com', string $name = 'OAuth User'): void
{
    Socialite::shouldReceive('buildProvider')
        ->once()
        ->andReturn(new class($email, $name) {
            public function __construct(
                private readonly string $email,
                private readonly string $name,
            ) {
            }

            public function user(): object
            {
                return (object) [
                    'email' => $this->email,
                    'name' => $this->name,
                ];
            }
        });
}

it('allows oauth self registration when password registration is disabled', function () {
    instanceSettings()->update(['is_oauth_registration_enabled' => true]);

    fakeGithubOauthUser();

    $this->get('/auth/github/callback')
        ->assertRedirect('/');

    $user = User::whereEmail('oauth-user@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->oauth_provider)->toBe('github')
        ->and($user->password)->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('keeps oauth self registration blocked when oauth registration is disabled', function () {
    fakeGithubOauthUser();

    $this->get('/auth/github/callback')
        ->assertRedirect(route('login'));

    expect(User::whereEmail('oauth-user@example.com')->exists())->toBeFalse();
});

it('blocks password login for oauth accounts when oauth password login is disabled', function () {
    instanceSettings()->update(['is_oauth_password_login_enabled' => false]);

    User::factory()->create([
        'email' => 'oauth-user@example.com',
        'password' => Hash::make('password'),
        'oauth_provider' => 'github',
    ]);

    $this->post('/login', [
        'email' => 'oauth-user@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

it('prevents oauth accounts from setting a password when oauth password login is disabled', function () {
    instanceSettings()->update(['is_oauth_password_login_enabled' => false]);

    $user = User::factory()->create([
        'password' => null,
        'oauth_provider' => 'github',
    ]);

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]))->toThrow(ValidationException::class);

    expect($user->fresh()->password)->toBeNull();
});
