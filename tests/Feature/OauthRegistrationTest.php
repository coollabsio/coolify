<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Once;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'cache.stores.redis' => config('cache.stores.array'),
    ]);
    Cache::setDefaultDriver('array');

    Redis::swap(new class
    {
        public function connection(): self
        {
            return $this;
        }

        public function __call(string $name, array $arguments): mixed
        {
            return null;
        }
    });

    InstanceSettings::query()->insert([
        'id' => 0,
        'is_registration_enabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Once::flush();
});

function fakeOauthUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): void
{
    $provider = new class($email, $name)
    {
        public function __construct(private string $email, private string $name) {}

        public function setConfig(mixed $config = null): self
        {
            return $this;
        }

        public function with(array $parameters = []): self
        {
            return $this;
        }

        public function user(): object
        {
            return (object) [
                'email' => $this->email,
                'name' => $this->name,
            ];
        }
    };

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

function createGoogleOauthSetting(array $overrides = []): OauthSetting
{
    return OauthSetting::query()->create(array_merge([
        'provider' => 'google',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.test/auth/google/callback',
        'is_registration_enabled' => false,
        'disable_password_auth' => false,
    ], $overrides));
}

it('allows oauth self-registration when password registration is disabled for the instance', function () {
    $this->withoutMiddleware([CheckForcePasswordReset::class, DecideWhatToDoWithUser::class]);

    createGoogleOauthSetting(['is_registration_enabled' => true]);
    fakeOauthUser();

    $this->get(route('auth.callback', 'google'))
        ->assertRedirect('/');

    $user = User::where('email', 'oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->oauth_provider)->toBe('google')
        ->and($user->password)->toBeNull()
        ->and(auth()->id())->toBe($user->id);
});

it('blocks oauth self-registration when both instance and provider registration are disabled', function () {
    $this->withoutMiddleware([CheckForcePasswordReset::class, DecideWhatToDoWithUser::class]);

    createGoogleOauthSetting(['is_registration_enabled' => false]);
    fakeOauthUser();

    $this->get(route('auth.callback', 'google'))
        ->assertRedirect(route('login'));

    expect(User::where('email', 'oauth@example.com')->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

it('blocks password login for users tied to an oauth-only provider', function () {
    createGoogleOauthSetting(['disable_password_auth' => true]);

    User::factory()->create([
        'email' => 'oauth-password@example.com',
        'password' => Hash::make('Password123!'),
        'oauth_provider' => 'google',
    ]);

    $this->post('/login', [
        'email' => 'oauth-password@example.com',
        'password' => 'Password123!',
    ])->assertSessionHasErrors();

    expect(auth()->check())->toBeFalse();
});
