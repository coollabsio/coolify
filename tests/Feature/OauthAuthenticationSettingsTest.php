<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

function fakeGithubOauthUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): void
{
    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'redirect_uri' => 'http://localhost/auth/github/callback',
    ]);

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

it('allows oauth registration when regular registration is disabled', function () {
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ]);
    fakeGithubOauthUser();

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/');
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'oauth@example.com',
        'name' => 'OAuth User',
    ]);
});

it('blocks new oauth users when both regular and oauth registration are disabled', function () {
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
    ]);
    fakeGithubOauthUser();

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

it('hides password login and registration when password authentication is disabled', function () {
    User::factory()->create([
        'id' => 0,
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);
    instanceSettings()->update([
        'is_registration_enabled' => true,
        'is_password_authentication_enabled' => false,
    ]);

    $response = $this->get('/login');

    $response->assertOk()
        ->assertSee('Password authentication is disabled')
        ->assertDontSee('name="password"', false)
        ->assertDontSee(__('auth.register_now'));

    $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors();
    $this->assertGuest();
});

it('redirects password registration when password authentication is disabled', function () {
    instanceSettings()->update([
        'is_registration_enabled' => true,
        'is_password_authentication_enabled' => false,
    ]);

    $this->get('/register')->assertRedirect(route('login'));
});
