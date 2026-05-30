<?php

it('adds profile navigation with an appearance tab and route', function () {
    $routes = file_get_contents(base_path('routes/web.php'));
    $profileNavbar = file_get_contents(resource_path('views/components/profile/navbar.blade.php'));
    $profileView = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($routes)
        ->toContain("Route::get('/profile/appearance', ProfileAppearance::class)->name('profile.appearance')")
        ->and($profileNavbar)
        ->toContain('route(\'profile\')')
        ->toContain('route(\'profile.appearance\')')
        ->toContain('General')
        ->toContain('Appearance')
        ->and($profileView)
        ->toContain('<x-profile.navbar />')
        ->not->toContain('<h1>Profile</h1>\n    <div class="subtitle -mt-2">');
});

it('moves appearance preferences to the profile appearance view', function () {
    $appearanceView = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));

    expect($appearanceView)
        ->toContain('<x-profile.navbar />')
        ->toContain("setTheme('light')")
        ->toContain("setTheme('system')")
        ->toContain("setTheme('dark')")
        ->toContain("setWidth('center')")
        ->toContain("setWidth('full')")
        ->toContain("setZoom('100')")
        ->toContain("setZoom('90')")
        ->toContain('aria-label="Use light theme"')
        ->toContain('aria-label="Use system theme"')
        ->toContain('aria-label="Use dark theme"')
        ->toContain('aria-label="Use centered width"')
        ->toContain('aria-label="Use full width"')
        ->toContain('aria-label="Use 100 percent zoom"')
        ->toContain('aria-label="Use 90 percent zoom"')
        ->toContain('max-w-2xl')
        ->toContain('class="space-y-1.5"')
        ->toContain('gap-1.5')
        ->toContain('px-2 py-1 text-sm');
});
