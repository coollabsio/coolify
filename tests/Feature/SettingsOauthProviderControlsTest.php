<?php

use App\Livewire\SettingsOauth;

it('uses per-provider enable buttons with browser validation', function () {
    $view = file_get_contents(resource_path('views/livewire/settings-oauth.blade.php'));

    expect($view)
        ->toContain("{{ \$oauth_setting['enabled'] ? 'Disable' : 'Enable' }}")
        ->toContain('x-data="{ enabled: @js((bool) $oauth_setting[\'enabled\']), provider: @js($provider) }"')
        ->toContain('invalidField.reportValidity()')
        ->toContain('$wire.toggleProvider(provider)')
        ->toContain('label="Client ID" required')
        ->toContain('autocomplete="new-password" required')
        ->not->toContain('label="Provider status"');
});

it('requires the provider-specific fields used by oauth enablement', function () {
    $component = new SettingsOauth;
    $method = new ReflectionMethod($component, 'providerRules');

    expect($method->invoke($component, 'github'))->toBe([
        'oauth_settings_map.github.client_id' => 'required',
        'oauth_settings_map.github.client_secret' => 'required',
    ])->and($method->invoke($component, 'azure'))->toHaveKeys([
        'oauth_settings_map.azure.client_id',
        'oauth_settings_map.azure.client_secret',
        'oauth_settings_map.azure.tenant',
    ])->and($method->invoke($component, 'authentik'))->toHaveKeys([
        'oauth_settings_map.authentik.client_id',
        'oauth_settings_map.authentik.client_secret',
        'oauth_settings_map.authentik.base_url',
    ]);
});

it('uses monochrome brand logos for each oauth provider in the sidebar', function () {
    $view = file_get_contents(resource_path('views/livewire/settings-oauth.blade.php'));

    $providers = [
        'authentik',
        'azure',
        'bitbucket',
        'clerk',
        'discord',
        'github',
        'gitlab',
        'google',
        'infomaniak',
        'zitadel',
    ];

    expect($view)
        ->toContain("asset('svgs/' . \$provider . '.svg')")
        ->toContain('mask:')
        ->toContain('bg-current')
        ->not->toContain('<x-reicon name="keys" class="menu-item-icon" />');

    foreach ($providers as $provider) {
        $svg = public_path("svgs/{$provider}.svg");

        expect($svg)->toBeFile();

        $contents = file_get_contents($svg);

        expect($contents)
            ->toContain('<svg')
            ->toContain('<path')
            ->not->toMatch('/fill="#[0-9a-fA-F]{3,8}"/')
            ->not->toContain('fill="#e24329"')
            ->not->toContain('fill="#4285F4"');
    }

    // Keep GitLab at the natural simple-icons 24x24 mark (no optical scale).
    expect(file_get_contents(public_path('svgs/gitlab.svg')))
        ->not->toContain('scale(');
});
