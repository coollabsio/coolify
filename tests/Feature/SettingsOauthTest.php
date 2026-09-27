<?php

use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Livewire\SettingsOauth;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsInstanceAdmin(): User
{
    $team = Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => true]);
    $user = User::factory()->create(['id' => 0, 'email' => 'root@example.com', 'email_verified_at' => now()]);
    if (! $user->teams()->whereKey($team->id)->exists()) {
        $user->teams()->attach($team, ['role' => 'owner']);
    }
    session(['currentTeam' => $team]);
    test()->actingAs($user);

    return $user;
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.driver', 'file');

    InstanceSettings::forceCreate(['id' => 0, 'is_registration_enabled' => true]);
    Once::flush();
    OauthSetting::create(['provider' => 'oidc']);
    OauthSetting::create(['provider' => 'authentik']);
    OauthSetting::create(['provider' => 'bitbucket']);
});

it('uses the standard settings design and keeps every oauth provider on one page', function () {
    actingAsInstanceAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)
        ->get(route('settings.oauth'))
        ->assertSuccessful()
        ->assertSee('Authentication')
        ->assertSee('Registration')
        ->assertSee('Authentik')
        ->assertSee('Bitbucket')
        ->assertSee('OpenID Connect')
        ->assertSee('Disable password registration when OAuth is enabled')
        ->assertSee('Client secret')
        ->assertSee('application-settings-form', false)
        ->assertDontSee(route('settings.oauth.provider', 'authentik'), false);
});

it('lists openid connect before the other oauth providers', function () {
    actingAsInstanceAdmin();

    $providers = array_keys(Livewire::test(SettingsOauth::class)->get('oauth_settings_map'));

    expect($providers[0])->toBe('oidc');
});

it('has an icon for openid connect', function () {
    expect(public_path('svgs/oidc.svg'))->toBeFile();
});

it('auto saves registration policy without a general save button', function () {
    actingAsInstanceAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)
        ->get(route('settings.oauth'))
        ->assertSuccessful()
        ->assertSee("wire:click='saveRegistrationPolicy'", false)
        ->assertDontSee('Save</button>', false);

    Livewire::test(SettingsOauth::class)
        ->set('disable_registration_when_oauth_enabled', true)
        ->call('saveRegistrationPolicy')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect(instanceSettings()->fresh()->disable_registration_when_oauth_enabled)->toBeTrue();
});

it('shows oidc fields with a naked okta issuer url example', function () {
    actingAsInstanceAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)
        ->get(route('settings.oauth'))
        ->assertSuccessful()
        ->assertSee('OpenID Connect')
        ->assertSee('https://example.okta.com', false)
        ->assertDontSee('/oauth2/default', false);
});

it('groups oidc fields in the expected desktop order', function () {
    $view = file_get_contents(resource_path('views/livewire/settings-oauth.blade.php'));
    $fields = [
        'redirect_uri',
        'base_url',
        'client_id',
        'client_secret',
        'scopes',
        'clock_skew_seconds',
        'custom_label',
    ];
    $positions = array_map(
        fn (string $field): int|false => strpos($view, "id=\"oauth_settings_map.{{ \$provider }}.$field\""),
        $fields,
    );

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and($view)->toContain('<div class="lg:col-span-2">');
});

it('shows provider enable controls as settings section actions', function () {
    actingAsInstanceAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)
        ->get(route('settings.oauth'))
        ->assertSuccessful()
        ->assertSee('Enable')
        ->assertDontSee('label="Enabled"', false)
        ->assertDontSee('p-4 border dark:border-coolgray-300 border-neutral-200', false);
});

it('stacks oidc option checkboxes vertically', function () {
    actingAsInstanceAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)
        ->get(route('settings.oauth'))
        ->assertSuccessful()
        ->assertSee('Allow OIDC user creation')
        ->assertSee('Require verified email')
        ->assertSee('Use PKCE')
        ->assertDontSee('flex flex-col gap-2 pt-2 md:flex-row', false);
});

it('does not show unknown oauth providers', function () {
    actingAsInstanceAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)
        ->get('/settings/oauth/unknown')
        ->assertNotFound();
});

it('defaults oidc user creation and verified email requirement to enabled', function () {
    $setting = OauthSetting::where('provider', 'oidc')->first();

    expect($setting->allow_registration)->toBeTrue()
        ->and($setting->require_email_verified)->toBeTrue()
        ->and($setting->auto_join_root_team)->toBeFalse();
});

it('persists oidc oauth settings from livewire', function () {
    actingAsInstanceAdmin();

    Livewire::test(SettingsOauth::class)
        ->set('oauth_settings_map.oidc.enabled', true)
        ->set('oauth_settings_map.oidc.client_id', 'client-id')
        ->set('oauth_settings_map.oidc.client_secret', 'secret')
        ->set('oauth_settings_map.oidc.redirect_uri', 'https://coolify.example.com/auth/oidc/callback')
        ->set('oauth_settings_map.oidc.base_url', 'https://idp.example.com')
        ->set('oauth_settings_map.oidc.scopes', 'openid email profile groups')
        ->set('oauth_settings_map.oidc.custom_label', 'Login with Okta')
        ->set('oauth_settings_map.oidc.allow_registration', true)
        ->set('oauth_settings_map.oidc.auto_join_root_team', true)
        ->set('oauth_settings_map.oidc.require_email_verified', true)
        ->set('disable_registration_when_oauth_enabled', true)
        ->call('submit')
        ->assertHasNoErrors();

    $setting = OauthSetting::where('provider', 'oidc')->first();
    expect($setting->enabled)->toBeTrue()
        ->and($setting->redirect_uri)->toBe('https://coolify.example.com/auth/oidc/callback')
        ->and($setting->base_url)->toBe('https://idp.example.com')
        ->and($setting->custom_label)->toBe('Login with Okta')
        ->and($setting->scopeList())->toBe(['openid', 'email', 'profile', 'groups'])
        ->and($setting->allow_registration)->toBeTrue()
        ->and($setting->auto_join_root_team)->toBeTrue();

    expect(instanceSettings()->fresh()->disable_registration_when_oauth_enabled)->toBeTrue();
});

it('saves only the selected provider from provider pages', function () {
    actingAsInstanceAdmin();

    Livewire::test(SettingsOauth::class, ['provider' => 'authentik'])
        ->set('oauth_settings_map.oidc.redirect_uri', 'not-a-url')
        ->set('oauth_settings_map.authentik.enabled', true)
        ->set('oauth_settings_map.authentik.client_id', 'authentik-client')
        ->set('oauth_settings_map.authentik.client_secret', 'authentik-secret')
        ->set('oauth_settings_map.authentik.base_url', 'https://authentik.example.com')
        ->call('submit')
        ->assertHasNoErrors();

    $setting = OauthSetting::where('provider', 'authentik')->first();
    expect($setting->enabled)->toBeTrue()
        ->and($setting->client_id)->toBe('authentik-client')
        ->and($setting->base_url)->toBe('https://authentik.example.com');
});

it('validates oidc url fields before saving', function (string $field, string $value) {
    actingAsInstanceAdmin();

    Livewire::test(SettingsOauth::class)
        ->set('oauth_settings_map.oidc.client_id', 'client-id')
        ->set('oauth_settings_map.oidc.client_secret', 'secret')
        ->set('oauth_settings_map.oidc.base_url', 'https://idp.example.com')
        ->set("oauth_settings_map.oidc.$field", $value)
        ->call('submit')
        ->assertHasErrors(["oauth_settings_map.oidc.$field" => 'url']);

    $setting = OauthSetting::where('provider', 'oidc')->first();
    expect($setting->{$field})->toBeNull();
})->with([
    'invalid redirect uri' => ['redirect_uri', 'not-a-url'],
    'non-http redirect uri' => ['redirect_uri', 'javascript:alert(1)'],
    'invalid issuer url' => ['base_url', 'not-a-url'],
    'non-http issuer url' => ['base_url', 'ftp://idp.example.com'],
]);

it('does not enable oidc without required fields', function () {
    actingAsInstanceAdmin();

    Livewire::test(SettingsOauth::class)
        ->set('oauth_settings_map.oidc.enabled', true)
        ->call('instantSave', 'oidc')
        ->assertDispatched('error');

    expect(OauthSetting::where('provider', 'oidc')->first()->enabled)->toBeFalse();
});

it('keeps provider disabled in the ui when enable validation fails', function () {
    actingAsInstanceAdmin();

    Livewire::test(SettingsOauth::class, ['provider' => 'authentik'])
        ->call('toggleProvider', 'authentik')
        ->assertDispatched('error')
        ->assertSet('oauth_settings_map.authentik.enabled', false);

    expect(OauthSetting::where('provider', 'authentik')->first()->enabled)->toBeFalse();
});

it('disables an enabled provider gracefully when required fields become incomplete', function () {
    actingAsInstanceAdmin();

    OauthSetting::where('provider', 'authentik')->first()->forceFill([
        'enabled' => true,
        'client_id' => 'authentik-client',
        'client_secret' => 'authentik-secret',
        'base_url' => 'https://authentik.example.com',
    ])->save();

    Livewire::test(SettingsOauth::class, ['provider' => 'authentik'])
        ->set('oauth_settings_map.authentik.client_secret', '')
        ->call('submit')
        ->assertDispatched('error')
        ->assertSet('oauth_settings_map.authentik.enabled', false);

    expect(OauthSetting::where('provider', 'authentik')->first()->enabled)->toBeFalse();
});

it('toggles provider enabled state from the action button', function () {
    actingAsInstanceAdmin();

    Livewire::test(SettingsOauth::class, ['provider' => 'authentik'])
        ->set('oauth_settings_map.authentik.client_id', 'authentik-client')
        ->set('oauth_settings_map.authentik.client_secret', 'authentik-secret')
        ->set('oauth_settings_map.authentik.base_url', 'https://authentik.example.com')
        ->call('toggleProvider', 'authentik')
        ->assertHasNoErrors();

    expect(OauthSetting::where('provider', 'authentik')->first()->enabled)->toBeTrue();
});
