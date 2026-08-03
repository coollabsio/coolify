<?php

/**
 * Settings title sits above the workspace; sidebar is below it. Title shell and
 * workspace share max-w-[1180px] so their left edges align.
 */
test('settings navbar and workspace share the same max width shell', function () {
    $navbar = file_get_contents(resource_path('views/components/settings/navbar.blade.php'));

    expect($navbar)
        ->toContain('max-w-[1180px]')
        ->toContain("title' => 'Settings'")
        ->toContain(':titleOnDesktop="false"');

    $pages = [
        resource_path('views/livewire/settings/index.blade.php'),
        resource_path('views/livewire/settings/advanced.blade.php'),
        resource_path('views/livewire/settings/updates.blade.php'),
        resource_path('views/livewire/settings-oauth.blade.php'),
    ];

    foreach ($pages as $path) {
        $blade = file_get_contents($path);

        expect($blade)
            ->toContain('<x-settings.navbar')
            ->toContain('max-w-[1180px]')
            ->toContain('xl:grid-cols-[210px_minmax(0,1fr)]')
            ->not->toContain('x-settings.page-header');
    }
});

test('dashboard navbar hides family titles at lg by default', function () {
    $dashboardNavbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));
    $settingsNavbar = file_get_contents(resource_path('views/components/settings/navbar.blade.php'));

    expect($dashboardNavbar)
        ->toContain("'titleOnDesktop' => false")
        ->toContain("'lg:hidden' => ! \$titleOnDesktop");

    expect($settingsNavbar)
        ->toContain(':titleOnDesktop="false"');
});
