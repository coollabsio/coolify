<?php

/**
 * Security / notifications family: mobile-only H1 on list views; list actions live in card headers.
 * Detail views keep the resource title on desktop.
 */
test('security navbar defaults to hiding the title on desktop', function () {
    $securityNavbar = file_get_contents(resource_path('views/components/security/navbar.blade.php'));
    $notificationNavbar = file_get_contents(resource_path('views/components/notification/navbar.blade.php'));

    expect($securityNavbar)
        ->toContain("'titleOnDesktop' => false")
        ->toContain(':titleOnDesktop="$titleOnDesktop"');

    expect($notificationNavbar)
        ->toContain("'titleOnDesktop' => false")
        ->toContain(':titleOnDesktop="$titleOnDesktop"');
});

test('security list views place create actions in collection card headers', function () {
    $pages = [
        resource_path('views/livewire/security/private-key/index.blade.php') => [
            'New private key',
            'New key',
            'Delete unused keys',
            'Delete unused',
        ],
        resource_path('views/livewire/security/cloud-provider-tokens.blade.php') => [
            'New token',
        ],
        resource_path('views/livewire/security/cloud-init-scripts.blade.php') => [
            'New script',
        ],
    ];

    foreach ($pages as $path => $labels) {
        $blade = file_get_contents($path);

        expect($blade)
            ->toContain('<x-application.settings-section')
            ->toContain('<x-slot:actions>')
            ->not->toContain('<x-slot:titleActions>');

        foreach ($labels as $label) {
            expect($blade)->toContain($label);
        }
    }
});

test('layer-2 navbar stacks tabs and actions on small screens', function () {
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    expect($navbar)
        ->toContain('flex-col gap-2 sm:flex-row')
        ->toContain('[&_.button]:whitespace-nowrap');
});

test('security detail views keep the resource title on desktop', function () {
    $shows = [
        resource_path('views/livewire/security/private-key/show.blade.php'),
        resource_path('views/livewire/security/cloud-provider-token/show.blade.php'),
        resource_path('views/livewire/security/cloud-init-script/show.blade.php'),
    ];

    foreach ($shows as $path) {
        expect(file_get_contents($path))
            ->toContain(':titleOnDesktop="true"')
            ->toContain('<x-slot:actions>');
    }
});
