<?php

namespace App\Http\Controllers {
    function get_socialite_provider(string $provider)
    {
        return app('tests.oauth_provider');
    }
}

namespace {
    use App\Actions\Fortify\ResetUserPassword;
    use App\Models\InstanceSettings;
    use App\Models\User;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Validation\ValidationException;

    uses(RefreshDatabase::class);

    beforeEach(function () {
        config([
            'app.maintenance.driver' => 'file',
            'app.maintenance.store' => null,
            'cache.default' => 'array',
        ]);
        (new InstanceSettings)->forceFill(['id' => 0])->save();
    });

    function fakeOauthUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): object
    {
        return new class($email, $name)
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
    }

    it('allows oauth self registration when general registration is disabled but oauth registration is enabled', function () {
        instanceSettings()->update([
            'is_registration_enabled' => false,
            'is_oauth_registration_enabled' => true,
        ]);
        app()->instance('tests.oauth_provider', fakeOauthUser());

        $this->get('/auth/github/callback')->assertRedirect('/');

        $user = User::whereEmail('oauth@example.com')->first();

        expect($user)->not->toBeNull()
            ->and($user->oauth_provider)->toBe('github')
            ->and($user->password)->toBeNull();
    });

    it('keeps oauth self registration disabled by default when general registration is disabled', function () {
        instanceSettings()->update([
            'is_registration_enabled' => false,
            'is_oauth_registration_enabled' => false,
        ]);
        app()->instance('tests.oauth_provider', fakeOauthUser());

        $this->get('/auth/github/callback')->assertRedirect(route('login'));

        expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
    });

    it('blocks password login for oauth users when oauth login is required', function () {
        instanceSettings()->update([
            'is_oauth_password_login_disabled' => true,
        ]);
        User::factory()->create([
            'email' => 'oauth@example.com',
            'password' => Hash::make('password'),
            'oauth_provider' => 'github',
        ]);

        $this->post('/login', [
            'email' => 'oauth@example.com',
            'password' => 'password',
        ]);

        $this->assertGuest();
    });

    it('blocks password reset for oauth users when oauth login is required', function () {
        instanceSettings()->update([
            'is_oauth_password_login_disabled' => true,
        ]);
        $user = User::factory()->create([
            'email' => 'oauth@example.com',
            'oauth_provider' => 'github',
        ]);

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ]);
    })->throws(ValidationException::class);
}
