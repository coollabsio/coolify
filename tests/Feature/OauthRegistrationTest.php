<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => false,
        ]);
    });
});

it('allows oauth users to self register when password registration is disabled', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'redirect_uri' => route('auth.callback', 'github'),
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'name' => 'OAuth User',
        'email' => 'oauth-user@example.com',
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $this->get(route('auth.callback', 'github'))
        ->assertRedirect('/');

    $user = User::whereEmail('oauth-user@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('OAuth User')
        ->and($user->password)->toBeNull();
});

it('prevents password reset from creating passwords for oauth-only users', function () {
    $user = User::factory()->create([
        'email' => 'oauth-user@example.com',
        'password' => null,
    ]);

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'StrongPassword1!',
        'password_confirmation' => 'StrongPassword1!',
    ]))->toThrow(ValidationException::class);

    expect($user->refresh()->password)->toBeNull();
});
