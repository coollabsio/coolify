<?php

it('uses shared sidebar navigation for keys and tokens pages', function () {
    $sidebar = file_get_contents(resource_path('views/components/security/settings-layout.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    foreach ([
        'security/private-key/index.blade.php',
        'security/private-key/show.blade.php',
        'security/cloud-tokens.blade.php',
        'security/cloud-provider-token/show.blade.php',
        'security/cloud-init-scripts.blade.php',
        'security/cloud-init-script/show.blade.php',
        'security/api-tokens.blade.php',
    ] as $viewPath) {
        expect(file_get_contents(resource_path("views/livewire/{$viewPath}")))
            ->toContain('<x-security.settings-layout>')
            ->not->toContain('<x-security.navbar');
    }

    expect($sidebar)
        ->toContain('application-settings-navigation')
        ->toContain("'label' => 'Private Keys'")
        ->toContain("'label' => 'Cloud Tokens'")
        ->toContain("'label' => 'Cloud-Init Scripts'")
        ->toContain("'label' => 'API Tokens'");

    expect($navbar)
        ->toContain("request()->routeIs('security.*')")
        ->not->toContain("['label' => 'API Tokens', 'route' => 'security.api-tokens'");
});
