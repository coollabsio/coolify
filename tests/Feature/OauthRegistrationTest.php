<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([CheckForcePasswordReset::class, DecideWhatToDoWithUser::class]);
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
    ]);
    Once::flush();

    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->is_registration_enabled = false;
    $settings->saveQuietly();
});

it('allows oauth users to self-register when general registration is disabled', function () {
    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')
        ->once()
        ->andReturn((object) [
            'name' => 'OAuth User',
            'email' => 'oauth@example.com',
        ]);

    Socialite::shouldReceive('buildProvider')
        ->once()
        ->andReturn($provider);

    $this->get(route('auth.callback', 'github'))
        ->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('does not let oauth-only users create a password through password reset', function () {
    $user = User::factory()->create([
        'password' => null,
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'Password1!@',
        'password_confirmation' => 'Password1!@',
    ]))->toThrow(ValidationException::class);

    expect($user->refresh()->password)->toBeNull();
});
