<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    // Ensure we have instance settings
    if (! InstanceSettings::find(0)) {
        InstanceSettings::create(['id' => 0]);
    }

    // Create a root user so we're not in first-user flow
    if (User::count() === 0) {
        User::factory()->create(['id' => 0, 'email' => 'admin@example.com']);
    }

    // Create an OAuth setting for testing
    OauthSetting::updateOrCreate(
        ['provider' => 'github'],
        ['enabled' => true, 'client_id' => 'test-id', 'client_secret' => 'test-secret']
    );
});

function mockSocialiteProvider(string $email = 'oauth-user@example.com', string $name = 'OAuth User'): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '12345',
        'name' => $name,
        'email' => $email,
        'avatar' => null,
    ]);

    $mockProvider = Mockery::mock();
    $mockProvider->shouldReceive('user')->andReturn($socialiteUser);

    // Mock the global helper function by overriding it via the app container
    app()->bind('get_socialite_provider_github', fn () => $mockProvider);

    // We need to mock the actual Socialite call. Since get_socialite_provider is a global
    // function, we'll mock Socialite::buildProvider to return our mock
    Laravel\Socialite\Facades\Socialite::shouldReceive('buildProvider')
        ->andReturn($mockProvider);
}

it('allows oauth registration when general registration is disabled but oauth registration is enabled', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = false;
    $settings->is_oauth_registration_enabled = true;
    $settings->save();

    $email = 'new-oauth-user@example.com';
    mockSocialiteProvider($email);

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/');
    expect(User::whereEmail($email)->exists())->toBeTrue();
    expect(Auth::check())->toBeTrue();
    expect(Auth::user()->email)->toBe($email);
});

it('blocks oauth registration when both general and oauth registration are disabled', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = false;
    $settings->is_oauth_registration_enabled = false;
    $settings->save();

    $email = 'blocked-oauth-user@example.com';
    mockSocialiteProvider($email);

    $response = $this->get('/auth/github/callback');

    // The 403 is caught by the exception handler and redirected to login with error
    expect(User::whereEmail($email)->exists())->toBeFalse();
});

it('allows oauth login for existing users regardless of registration settings', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = false;
    $settings->is_oauth_registration_enabled = false;
    $settings->save();

    $existingUser = User::factory()->create(['email' => 'existing@example.com']);
    mockSocialiteProvider('existing@example.com', $existingUser->name);

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/');
    expect(Auth::check())->toBeTrue();
    expect(Auth::user()->email)->toBe('existing@example.com');
});

it('allows oauth registration when general registration is also enabled', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = true;
    $settings->is_oauth_registration_enabled = true;
    $settings->save();

    $email = 'both-enabled@example.com';
    mockSocialiteProvider($email);

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/');
    expect(User::whereEmail($email)->exists())->toBeTrue();
});

it('has is_oauth_registration_enabled setting defaulting to true', function () {
    $settings = InstanceSettings::find(0);
    // Fresh instance should default to true
    expect($settings->is_oauth_registration_enabled)->toBeTrue();
});
