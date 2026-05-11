<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

function createInstanceSettings(array $attributes = []): InstanceSettings
{
    return InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(array_merge([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
        'is_password_authentication_enabled' => true,
    ], $attributes)));
}

function enableGithubOauth(): void
{
    OauthSetting::query()->create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'redirect_uri' => route('auth.callback', 'github'),
    ]);
}

function mockOauthUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): void
{
    $provider = new class($email, $name)
    {
        public function __construct(private string $email, private string $name) {}

        public function user(): object
        {
            return (object) [
                'email' => $this->email,
                'name' => $this->name,
            ];
        }
    };

    Socialite::shouldReceive('buildProvider')->andReturn($provider);
}

test('password authentication can be disabled globally', function () {
    createInstanceSettings([
        'is_registration_enabled' => true,
        'is_password_authentication_enabled' => false,
    ]);

    User::factory()->create(['email' => 'user@example.com']);

    $this->get('/register')->assertRedirect(route('login'));
    $this->post('/login', [
        'email' => 'user@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors();
    $this->post('/forgot-password', [
        'email' => 'user@example.com',
    ])->assertForbidden();
});

test('login page is available for first oauth user when password registration is disabled', function () {
    createInstanceSettings([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
        'is_password_authentication_enabled' => true,
    ]);
    enableGithubOauth();

    $this->get('/login')
        ->assertOk()
        ->assertSee('auth/github/redirect');
});

test('oauth users can self register when general registration is disabled', function () {
    createInstanceSettings([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ]);
    User::factory()->create();
    enableGithubOauth();
    mockOauthUser();

    $this->get('/auth/github/callback')->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

test('first oauth registered user becomes the root user', function () {
    createInstanceSettings([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
        'is_password_authentication_enabled' => false,
    ]);
    enableGithubOauth();
    mockOauthUser('root@example.com', 'Root User');

    $this->get('/auth/github/callback')->assertRedirect('/');

    $user = User::whereEmail('root@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->id)->toBe(0)
        ->and($user->teams()->where('teams.id', 0)->exists())->toBeTrue();
});
