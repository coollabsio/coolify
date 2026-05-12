<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();

    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->saveQuietly();
    }
});

test('oauth callback can create a user when general registration is disabled', function () {
    $this->withoutExceptionHandling();
    $this->withoutMiddleware();

    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
    ]);
    Event::fake();

    InstanceSettings::find(0)->update(['is_registration_enabled' => false]);

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

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');
    $this->assertDatabaseHas('users', [
        'email' => 'oauth-user@example.com',
        'name' => 'OAuth User',
    ]);
    expect(auth()->user())->toBeInstanceOf(User::class);
    expect(auth()->user()->email)->toBe('oauth-user@example.com');
});
