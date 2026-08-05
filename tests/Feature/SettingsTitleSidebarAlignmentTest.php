<?php

/**
 * Settings title sits above the workspace; sidebar is below it. Title shell and
 * workspace share max-w-[1180px] so their left edges align.
 */
test('instance settings pages use one shared sidebar workspace', function () {
    $layout = file_get_contents(resource_path('views/components/settings/layout.blade.php'));

    expect($layout)
        ->toContain('max-w-[1180px]')
        ->toContain('application-settings-navigation')
        ->toContain("'Configuration' =>")
        ->toContain("'Instance' =>");

    $pages = [
        resource_path('views/livewire/settings/index.blade.php'),
        resource_path('views/livewire/settings/advanced.blade.php'),
        resource_path('views/livewire/settings/updates.blade.php'),
        resource_path('views/livewire/settings-oauth.blade.php'),
        resource_path('views/livewire/settings-backup.blade.php'),
        resource_path('views/livewire/settings-email.blade.php'),
        resource_path('views/livewire/settings/scheduled-jobs.blade.php'),
    ];

    foreach ($pages as $path) {
        $blade = file_get_contents($path);

        expect($blade)
            ->toContain('<x-settings.layout>')
            ->not->toContain('<x-settings.navbar')
            ->not->toContain('x-settings.page-header');
    }

    $oauth = file_get_contents(resource_path('views/livewire/settings-oauth.blade.php'));
    expect($oauth)
        ->toContain('<x-slot:submenu>')
        ->toContain('aria-label="OAuth providers"')
        ->toContain('window.scrollToSettingsSection?.')
        ->toContain('history.replaceState')
        ->not->toContain('xl:grid-cols-[210px_minmax(0,1fr)]');
});

test('dashboard navbar hides family titles at lg by default', function () {
    $dashboardNavbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    expect($dashboardNavbar)
        ->toContain("'titleOnDesktop' => false")
        ->toContain("'lg:hidden' => \$mobileTitleOnly || (! \$titleOnDesktop && \$showNav)");

});
