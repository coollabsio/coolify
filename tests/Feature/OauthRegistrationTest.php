<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->instanceSettings = InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
    ]);

    // Create OAuth setting for testing
    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect_uri' => 'http://localhost/auth/github/callback',
    ]);
});

describe('OAuth registration', function () {
    test('OAuth registration blocked when both settings disabled', function () {
        $this->instanceSettings->update([
            'is_registration_enabled' => false,
            'is_oauth_registration_enabled' => false,
        ]);

        $oauthUser = Mockery::mock('Laravel\Socialite\Two\User');
        $oauthUser->shouldReceive('getName')->andReturn('Test User');
        $oauthUser->shouldReceive('getEmail')->andReturn('test@example.com');
        $oauthUser->name = 'Test User';
        $oauthUser->email = 'test@example.com';

        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($oauthUser);
        Socialite::shouldReceive('driver')->andReturn($provider);

        $response = $this->get('/auth/github/callback');

        expect($response->getStatusCode())->toBe(403);
        expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
    });

    test('OAuth registration allowed when general registration enabled', function () {
        $this->instanceSettings->update([
            'is_registration_enabled' => true,
            'is_oauth_registration_enabled' => false,
        ]);

        $oauthUser = Mockery::mock('Laravel\Socialite\Two\User');
        $oauthUser->shouldReceive('getName')->andReturn('Test User');
        $oauthUser->shouldReceive('getEmail')->andReturn('test@example.com');
        $oauthUser->name = 'Test User';
        $oauthUser->email = 'test@example.com';

        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($oauthUser);
        Socialite::shouldReceive('driver')->andReturn($provider);

        $response = $this->get('/auth/github/callback');

        expect($response->getStatusCode())->toBe(302);
        $user = User::where('email', 'test@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->oauth_provider)->toBe('github');
        expect($user->password_login_disabled)->toBeTrue();
    });

    test('OAuth registration allowed when OAuth registration enabled but general registration disabled', function () {
        $this->instanceSettings->update([
            'is_registration_enabled' => false,
            'is_oauth_registration_enabled' => true,
        ]);

        $oauthUser = Mockery::mock('Laravel\Socialite\Two\User');
        $oauthUser->shouldReceive('getName')->andReturn('Test User');
        $oauthUser->shouldReceive('getEmail')->andReturn('test@example.com');
        $oauthUser->name = 'Test User';
        $oauthUser->email = 'test@example.com';

        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($oauthUser);
        Socialite::shouldReceive('driver')->andReturn($provider);

        $response = $this->get('/auth/github/callback');

        expect($response->getStatusCode())->toBe(302);
        $user = User::where('email', 'test@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->oauth_provider)->toBe('github');
        expect($user->password_login_disabled)->toBeTrue();
    });

    test('existing OAuth user can login', function () {
        $existingUser = User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'oauth_provider' => 'github',
            'password_login_disabled' => true,
        ]);

        $oauthUser = Mockery::mock('Laravel\Socialite\Two\User');
        $oauthUser->shouldReceive('getName')->andReturn('Existing User');
        $oauthUser->shouldReceive('getEmail')->andReturn('existing@example.com');
        $oauthUser->name = 'Existing User';
        $oauthUser->email = 'existing@example.com';

        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($oauthUser);
        Socialite::shouldReceive('driver')->andReturn($provider);

        $response = $this->get('/auth/github/callback');

        expect($response->getStatusCode())->toBe(302);
        expect(Auth::check())->toBeTrue();
        expect(Auth::user()->email)->toBe('existing@example.com');
    });
});

describe('password login blocking', function () {
    test('OAuth user with password_login_disabled cannot login with password', function () {
        $user = User::create([
            'name' => 'OAuth User',
            'email' => 'oauth@example.com',
            'password' => bcrypt('password'),
            'oauth_provider' => 'github',
            'password_login_disabled' => true,
        ]);

        $response = $this->post('/login', [
            'email' => 'oauth@example.com',
            'password' => 'password',
        ]);

        expect(Auth::check())->toBeFalse();
    });

    test('regular user can login with password', function () {
        $user = User::create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'password' => bcrypt('password'),
            'oauth_provider' => null,
            'password_login_disabled' => false,
        ]);

        $response = $this->post('/login', [
            'email' => 'regular@example.com',
            'password' => 'password',
        ]);

        expect(Auth::check())->toBeTrue();
        expect(Auth::user()->email)->toBe('regular@example.com');
    });
});
