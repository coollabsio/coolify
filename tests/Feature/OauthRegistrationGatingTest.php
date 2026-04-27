<?php

use App\Livewire\Settings\Advanced as AdvancedSettings;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->updateOrCreate(
            ['id' => 0],
            [
                'is_registration_enabled' => false,
                'allow_oauth_when_registration_disabled' => false,
            ],
        );
    });

    // Required for OauthController::callback — get_socialite_provider() looks
    // up the OauthSetting row before invoking Socialite.
    OauthSetting::query()->updateOrCreate(
        ['provider' => 'github'],
        [
            'enabled' => true,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect_uri' => 'https://example.test/auth/github/callback',
        ],
    );
});

function fakeSocialiteUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = 'remote-id-1';
    $user->email = $email;
    $user->name = $name;
    $user->token = 'fake-token';

    return $user;
}

function mockSocialiteWith(SocialiteUser $oauthUser): void
{
    $providerMock = Mockery::mock(Laravel\Socialite\Two\AbstractProvider::class);
    $providerMock->shouldReceive('user')->andReturn($oauthUser);
    Socialite::shouldReceive('buildProvider')->andReturn($providerMock);
    Socialite::shouldReceive('driver')->andReturn($providerMock);
}

test('the new setting column exists and defaults to false', function () {
    $settings = InstanceSettings::find(0);
    expect($settings->allow_oauth_when_registration_disabled)->toBeFalse();
});

test('admin can toggle allow_oauth_when_registration_disabled via the Advanced settings Livewire component', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $rootUser = User::factory()->create(['id' => 0]);
    $rootTeam->members()->attach($rootUser->id, ['role' => 'owner']);

    $this->actingAs($rootUser);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(AdvancedSettings::class)
        ->assertSet('allow_oauth_when_registration_disabled', false)
        ->set('allow_oauth_when_registration_disabled', true)
        ->call('instantSave')
        ->assertHasNoErrors();

    expect(InstanceSettings::find(0)->fresh()->allow_oauth_when_registration_disabled)
        ->toBeTrue();
});

test('oauth callback creates new user when general registration is enabled', function () {
    $settings = instanceSettings();
    $settings->is_registration_enabled = true;
    $settings->save();

    mockSocialiteWith(fakeSocialiteUser());

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/');
    expect(User::whereEmail('oauth@example.com')->exists())->toBeTrue();
});

test('oauth callback blocks signup when both registration flags are off', function () {
    $settings = instanceSettings();
    $settings->is_registration_enabled = false;
    $settings->allow_oauth_when_registration_disabled = false;
    $settings->save();

    mockSocialiteWith(fakeSocialiteUser());

    $response = $this->get('/auth/github/callback');

    // The controller catches the abort(403) and redirects to /login with an
    // error message — either is acceptable as long as no user got created.
    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

test('oauth callback creates new user when registration is disabled but oauth-only mode is enabled', function () {
    $settings = instanceSettings();
    $settings->is_registration_enabled = false;
    $settings->allow_oauth_when_registration_disabled = true;
    $settings->save();

    mockSocialiteWith(fakeSocialiteUser());

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/');
    expect(User::whereEmail('oauth@example.com')->exists())->toBeTrue();
});

test('oauth callback signs in existing user regardless of registration flags', function () {
    $settings = instanceSettings();
    $settings->is_registration_enabled = false;
    $settings->allow_oauth_when_registration_disabled = false;
    $settings->save();

    User::factory()->create(['email' => 'existing@example.com']);

    mockSocialiteWith(fakeSocialiteUser('existing@example.com', 'Existing User'));

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/');
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->email)->toBe('existing@example.com');
});

test('password registration still aborts when registration is disabled even with oauth-only mode on', function () {
    // OAuth-only mode must NOT loosen the password registration gate —
    // that's the whole point of decoupling the two flows.
    $settings = instanceSettings();
    $settings->is_registration_enabled = false;
    $settings->allow_oauth_when_registration_disabled = true;
    $settings->save();

    $createNewUser = new App\Actions\Fortify\CreateNewUser;

    expect(fn () => $createNewUser->create([
        'name' => 'Pass Word',
        'email' => 'pw@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(User::whereEmail('pw@example.com')->exists())->toBeFalse();
});
